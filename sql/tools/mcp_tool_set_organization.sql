/*
	Nástroj: set_organization
	Název:   Nastavení kontextu organizace
	Popis:   Použije se v případě, že se uživatel chce přihlásit k jiné organizaci.
	         Zpracovává volný text pomocí hybridní heuristiky a vrací více sad výsledků.
*/
CREATE OR ALTER PROCEDURE mcp_tool_set_organization
	@free_text NVARCHAR(MAX) = NULL
AS
BEGIN
	SET NOCOUNT ON;

	-- 1. Zjištění aktuálního uživatele z dbsession
	DECLARE @ramses_user BIGINT, @is_sysadmin BIT;
	SELECT @ramses_user = ramses_user, @is_sysadmin = right_sysadmin
	FROM dbsession
	WHERE spid = @@SPID;

	IF @ramses_user IS NULL
	BEGIN
		SELECT 'Status' AS __block_name, 'Chyba' AS Status, 'Nelze identifikovat přihlášeného uživatele pro aktuální SPID.' AS Message;
		RETURN;
	END

	SET @free_text = TRIM(ISNULL(@free_text, ''));
	IF LEN(@free_text) = 0
	BEGIN
		SELECT 'Status' AS __block_name, 'Chyba' AS Status, 'Nebyl zadán žádný text pro vyhledání.' AS Message;
		RETURN;
	END

	-- 2. Načtení skóre pomocí vylepšené funkce f_fuzzy_match
	DECLARE @Candidates TABLE (
		org_id BIGINT,
		org_name NVARCHAR(255),
		match_score FLOAT
	);

	INSERT INTO @Candidates (org_id, org_name, match_score)
	SELECT 
		o.organization, 
		o.name,
		(
			SELECT MAX(v) FROM (VALUES 
				(dbo.f_fuzzy_match(@free_text, o.name)), 
				(dbo.f_fuzzy_match(@free_text, ISNULL(o.shortname, '')))
			) AS value(v)
		) AS match_score
	FROM organization o
	LEFT JOIN org_user ou ON ou.organization = o.organization AND ou.ramses_user = @ramses_user AND ou.disabled = 0
	WHERE (@is_sysadmin = 1 OR ou.ramses_user IS NOT NULL);

	-- 3. Vyčištění balastu (skóre < 0.15 znamená, že to vůbec nesedí)
	DELETE FROM @Candidates WHERE match_score < 0.15;

	DECLARE @match_count INT;
	SELECT @match_count = COUNT(*) FROM @Candidates;

	IF @match_count = 0
	BEGIN
		SELECT 'Status' AS __block_name, 'Nenalezeno' AS Status, 'Organizace nebyla nalezena, nebo k ní nemáte přístup.' AS Message;
		RETURN;
	END

	-- 4. Výběr pouze organizací se sdíleným nejlepším skóre
	DECLARE @best_score FLOAT;
	SELECT @best_score = MAX(match_score) FROM @Candidates;
	
	DELETE FROM @Candidates WHERE match_score < @best_score;
	SELECT @match_count = COUNT(*) FROM @Candidates;

	-- 5. Finální akce / Odezva pro AI
	IF @match_count = 1
	BEGIN
		DECLARE @target_org BIGINT, @target_name NVARCHAR(255);
		SELECT TOP 1 @target_org = org_id, @target_name = org_name FROM @Candidates;

		EXEC wwwr_setorganization @organization = @target_org, @noresult = 1;

		SELECT 'Status' AS __block_name, 'OK' AS Status, 'Kontext úspěšně přepnut na organizaci: ' + @target_name AS Message;
	END
	ELSE
	BEGIN
		-- ==============================================================================
		-- Nalezeno VÍCE kandidátů (Ambiguita) -> Vracíme vícenásobný result-set (Multi-RS)
		-- ==============================================================================
		
		-- Blok 1: Informace pro AI o tom, co se stalo a co má dělat
		SELECT 
			'Status' AS __block_name,
			'Více shod' AS Status,
			'Nalezeno více možností. Vyžádejte si od uživatele upřesnění z přiložené tabulky organizací.' AS Message;

		-- Blok 2: Čistá datová tabulka (seznam kandidátů)
		SELECT 
			'Nalezené organizace' AS __block_name,
			org_name AS [Název organizace]
		FROM @Candidates
		ORDER BY org_name;
		
	END
END
GO
--debuglogin 'hink'
--begin tran
--execute mcp_tool_set_organization 'mp'
--rollback
GO
