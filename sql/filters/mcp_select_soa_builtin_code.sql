execute dropni 'mcp_filter_soa'
execute dropni 'mcp_filter_soa_builtin_code'
execute dropni 'mcp_select_soa_builtin_code'
GO
/*
	Nástroj: mcp_select_soa_builtin_code
	Popis:   Vyhledá dostupný SoA předpis v pohledu v_repo_regulation.
	         ČISTÁ DATOVÁ VRSTVA: Pouze vrací data, veškerou logiku kolem 
	         ukládání identifikátorů a zpráv pro LLM řeší PHP orchestrátor.
*/
CREATE PROCEDURE mcp_select_soa_builtin_code
	@free_text	varchar(8000),
	@top_n		INT = 10
AS
BEGIN
	SET NOCOUNT ON;

	-- 1. Validace vstupu
	SET @free_text = LTRIM(RTRIM(ISNULL(@free_text, '')));
	IF LEN(@free_text) = 0
	BEGIN
		SELECT 'CHYBA: Nebyl zadán text pro vyhledání SoA předpisu.' AS result;
		RETURN;
	END

	-- Ošetření chybějícího nebo neplatného top_n
	IF ISNULL(@top_n, 0) <= 0 SET @top_n = 10;

	declare @ft table(original uuid primary key, rank int)
	insert into @ft
	select r.original, max(fr.rank) rank
	from freetexttable(repo_regulation,*,@free_text,@top_n) fr
		join repo_regulation r on r.uuid=fr.[KEY]
	group by original

	-- 2. Získání kandidátů z view v_repo_regulation (omezeno na my_access = 1)
	-- Sloupec fulltext_rank je připraven pro budoucí implementaci plnohodnotného fulltextu.
	DECLARE @Candidates TABLE (
		builtin_code		VARCHAR(100),
		caption				NVARCHAR(500),
		description_text	NVARCHAR(MAX),
		fulltext_rank		INT
	);

	INSERT INTO @Candidates (builtin_code, caption, description_text, fulltext_rank)
	SELECT TOP (@top_n)
		v.builtin_code, 
		v.caption, 
		v.description_text,
		ft.rank fulltext_rank
	FROM v_repo_regulation v
		join @ft ft on ft.original=v.original
	WHERE my_access = 1
	ORDER BY ft.rank desc;

	-- 3. Odeslání čistých dat (Orchestrátor už se o zbytek postará)
	-- První sloupec za __block_name (builtin_code) bude orchestrátorem automaticky uložen 
	-- do mcp_saved_values a do textové zprávy pro AI.
	SELECT 
		'Nabízené předpisy SoA' AS __block_name,
		builtin_code AS [Kód předpisu],
		caption AS [Název normy],
		description_text AS [Popis/Rozsah],
		fulltext_rank AS [Relevance]
	FROM @Candidates
	ORDER BY fulltext_rank DESC, caption ASC;

END
GO
--debuglogin 'hink'
--execute mcp_select_soa_builtin_code 'cloud'
GO
