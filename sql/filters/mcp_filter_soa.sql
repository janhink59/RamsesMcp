execute dropni 'mcp_filter_soa'
GO
/*
	Nástroj: mcp_filter_soa
	Popis:   Vyhledá dostupný SoA pøedpis v pohledu v_repo_regulation.
	         Pokud nalezne právì jeden, uloží jeho builtin_code pod zadaný klíè.
	         Pokud jich nalezne více, pøedá sémantické rozhodnutí na LLM.
*/
CREATE PROCEDURE mcp_filter_soa
	@free_text	NVARCHAR(MAX),
	@save_as	VARCHAR(40),
	@top_n		INT = 10
AS
BEGIN
	SET NOCOUNT ON;

	-- 1. Zabezpeèená identifikace aktuální webové relace (Claim-Check pattern)
	DECLARE @wwwsession VARCHAR(50);
	
	SELECT @wwwsession = wwwsession 
	FROM dbsession 
	WHERE spid = @@SPID;

	IF @wwwsession IS NULL
	BEGIN
		SELECT 
			'Status' AS __block_name, 
			'Chyba' AS Status, 
			'Nelze identifikovat platnou relaci (wwwsession) pro aktuální SPID.' AS Message;
		RETURN;
	END

	-- Preventivní vyèištìní historické hodnoty pro stejný klíè
	DELETE FROM mcp_saved_values 
	WHERE wwwsession = @wwwsession AND save_as = @save_as;

	-- 2. Validace vstupu
	SET @free_text = LTRIM(RTRIM(ISNULL(@free_text, '')));
	IF LEN(@free_text) = 0
	BEGIN
		SELECT 
			'Status' AS __block_name, 
			'Chyba' AS Status, 
			'Nebyl zadán text pro vyhledání SoA pøedpisu.' AS Message;
		RETURN;
	END

	-- Ošetøení chybìjícího nebo neplatného top_n
	IF ISNULL(@top_n, 0) <= 0 SET @top_n = 10;

	-- 3. Získání kandidátù z view v_repo_regulation (omezeno na my_access = 1)
	-- Sloupec fulltext_rank je pøipraven pro budoucí implementaci plnohodnotného fulltextu.
	-- Prosté vložení (LIKE) zatím rank simuluje nulou.
	DECLARE @Candidates TABLE (
		builtin_code		VARCHAR(100),
		caption				NVARCHAR(500),
		description_text	NVARCHAR(MAX),
		fulltext_rank		INT
	);

	INSERT INTO @Candidates (builtin_code, caption, description_text, fulltext_rank)
	SELECT TOP (@top_n)
		builtin_code, 
		caption, 
		description_text,
		0 AS fulltext_rank
	FROM v_repo_regulation
	WHERE my_access = 1
	  AND (
		builtin_code LIKE '%' + @free_text + '%'
		OR caption LIKE '%' + @free_text + '%'
		OR description_text LIKE '%' + @free_text + '%'
	  )
	ORDER BY caption; -- Prozatímní øazení, dokud nebude fungovat fulltext skóre

	DECLARE @match_count INT;
	SELECT @match_count = COUNT(*) FROM @Candidates;

	-- 4. Zpracování scénáøù pro AI agenta

	-- SCÉNÁØ 0: Nic nenalezeno
	IF @match_count = 0
	BEGIN
		SELECT 
			'Status' AS __block_name, 
			'Nenalezeno' AS Status, 
			'Žádný pøístupný pøedpis neodpovídá výrazu: ''' + @free_text + '''. Použij obecnìjší pojem nebo se zeptej uživatele na upøesnìní.' AS Message;
		RETURN;
	END

	-- SCÉNÁØ 1: Nalezena pøesná shoda -> Automatický zápis do kontextu
	IF @match_count = 1
	BEGIN
		DECLARE @target_code VARCHAR(100), @target_caption NVARCHAR(500);
		SELECT TOP 1 @target_code = builtin_code, @target_caption = caption FROM @Candidates;

		-- Ukládáme jako deterministický koøenový objekt pole (row_index = 0)
		INSERT INTO mcp_saved_values (wwwsession, save_as, row_index, saved_data)
		VALUES (@wwwsession, @save_as, 0, CAST(@target_code AS NVARCHAR(MAX)));

		SELECT 
			'Status' AS __block_name, 
			'OK' AS Status, 
			'Pøedpis ''' + @target_caption + ''' byl úspìšnì nalezen a jeho kód byl uložen do promìnné ''' + @save_as + '''. Mùžeš plynule pokraèovat k pøípravì reportu.' AS Message;
		RETURN;
	END

	-- SCÉNÁØ 2: Ambiguita (Více výsledkù) -> Delegace deduplikace na sémantiku LLM
	IF @match_count > 1
	BEGIN
		-- Blok 1: Metainstrukce pro LLM
		SELECT 
			'Status' AS __block_name,
			'Více shod' AS Status,
			'Bylo nalezeno více možností. Pøeèti si následující tabulku: Pokud z kontextu chatu vyhodnotíš jednoznaèného favorita (napø. DORA nebo ISO27001), ulož jeho hodnotu [Kód pøedpisu] ruènì voláním set_context_variable. Pokud váháš, požádej uživatele o upøesnìní.' AS Message;

		-- Blok 2: Dataset kandidátù pro sémantickou analýzu
		SELECT 
			'Nabízené pøedpisy SoA' AS __block_name,
			builtin_code AS [Kód pøedpisu (variable_value)],
			caption AS [Název normy],
			description_text AS [Popis/Rozsah],
			fulltext_rank AS [Relevance]
		FROM @Candidates
		ORDER BY fulltext_rank DESC, caption ASC;
	END
END
GO
debuglogin 'hink'
execute mcp_filter_soa '%',''
GO
