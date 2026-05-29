execute dropni 'mcp_filter_asset_class'
GO

/*
	Specifický filtr: asset_class (Pracovní procedura)
	Účel:    Vyhledá třídy aktiv pomocí f_fuzzy_match v hierarchii z f_select_ast_cls().
	         Pokud najde nadřízenou složku, automaticky expanduje hledání na všechny 
	         její podřízené listové uzly (využití max_child_id).
	         Uloží nalezená class_id do kontextu session (mcp_saved_values jako pole).
	Výstup:  TSV zpráva pro LLM / Test UI. Bezpečné pro kontextové okno (zkracuje výpis).
*/
CREATE PROCEDURE mcp_filter_asset_class
	@free_text NVARCHAR(MAX),
	@save_as   VARCHAR(40)
AS
BEGIN
	SET NOCOUNT ON;

	-- 1. Zjištění platné session (wwwsession)
	DECLARE @wwwsession VARCHAR(50);
	
	SELECT @wwwsession = wwwsession 
	FROM dbsession 
	WHERE spid = @@SPID;

	IF @wwwsession IS NULL
	BEGIN
		SELECT 'CHYBA: Nelze identifikovat platnou webovou relaci (wwwsession) pro aktuální spojení.' AS result;
		RETURN;
	END

	-- 2. Ošetření vstupu
	IF ISNULL(@free_text, '') = ''
	BEGIN
		SELECT 'CHYBA: Pro filtr třídy aktiv musíte zadat hledaný text v parametru free_text.' AS result;
		RETURN;
	END

	BEGIN TRY
		-- =========================================================================
		-- 3a. Fáze 1: Nalezení nejlepších shod (Kandidáti - Rodiče i Listy)
		-- Omezíme to na TOP 5 nejsilnějších shod, abychom nenabrali nesmysly.
		-- =========================================================================
		DECLARE @BaseMatches TABLE (
			class_id BIGINT,
			max_child_id BIGINT
		);

		INSERT INTO @BaseMatches (class_id, max_child_id)
		SELECT TOP 5 
			class_id, 
			ISNULL(max_child_id, class_id)
		FROM dbo.f_select_ast_cls()
		WHERE dbo.f_fuzzy_match(@free_text, full_class_name) > 0.3
		ORDER BY dbo.f_fuzzy_match(@free_text, full_class_name) DESC;

		-- =========================================================================
		-- 3b. Fáze 2: Expanze kandidátů na konkrétní listové uzly (leaf = 'Y')
		-- =========================================================================
		DECLARE @Matches TABLE (
			row_idx INT IDENTITY(0,1),
			class_id BIGINT,
			class_name NVARCHAR(MAX)
		);

		INSERT INTO @Matches (class_id, class_name)
		SELECT DISTINCT 
			l.class_id, 
			l.name
		FROM dbo.f_select_ast_cls() l
		INNER JOIN @BaseMatches b ON l.class_id BETWEEN b.class_id AND b.max_child_id
		WHERE ISNULL(l.leaf, '') = 'Y';

		DECLARE @found_count INT;
		SELECT @found_count = COUNT(*) FROM @Matches;

		-- 4. Vyhodnocení výsledku hledání
		IF @found_count = 0
		BEGIN
			SELECT 'UPOZORNĚNÍ: Nebyla nalezena žádná třída aktiv (ani podřízené prvky) odpovídající textu: ''' + @free_text + '''. Zkuste jiný výraz.' AS result;
			RETURN;
		END

		-- 5. Bezpečná příprava textu pro LLM (Ochrana proti zahlcení kontextu)
		DECLARE @found_names NVARCHAR(MAX);
		SELECT @found_names = STRING_AGG(class_name, ', ') 
		FROM (
			SELECT TOP 10 class_name 
			FROM @Matches 
			ORDER BY class_id
		) t;

		IF @found_count > 10
		BEGIN
			SET @found_names = @found_names + ' ... a ' + CAST(@found_count - 10 AS VARCHAR) + ' dalších';
		END

		-- 6. Uložení nalezených ID do kontextu (SPRÁVNÉ NÁZVY SLOUPCŮ)
		DELETE FROM mcp_saved_values 
		WHERE wwwsession = @wwwsession AND save_as = @save_as;
		
		INSERT INTO mcp_saved_values (wwwsession, save_as, row_index, saved_data)
		SELECT 
			@wwwsession, 
			@save_as, 
			row_idx, 
			CAST(class_id AS NVARCHAR(200))
		FROM @Matches;

		-- 7. KONEČNÝ VÝSTUP PRO LLM (Smart Prompting)
		IF @found_count = 1
		BEGIN
			SELECT 'ÚSPĚCH: Nalezena přesně 1 třída aktiv (' + @found_names + '). Filtrační ID bylo uloženo do proměnné ''' + @save_as + '''. Nyní můžete bezpečně pokračovat v dalším kroku.' AS result;
		END
		ELSE
		BEGIN
			SELECT 'ÚSPĚCH: Nalezeno ' + CAST(@found_count AS VARCHAR) + ' tříd aktiv (' + ISNULL(@found_names, '') + '). Filtrační IDs byla uložena do proměnné ''' + @save_as + '''. POZOR: Bylo nalezeno více možností. Pokud je požadavek uživatele příliš obecný, ZASTAV SE a zeptej se ho na upřesnění. Pokud jsi si jistý (např. uživatel výslovně chtěl celou nadřazenou kategorii), pokračuj v dalším kroku.' AS result;
		END

	END TRY
	BEGIN CATCH
		SELECT 'CHYBA V LOGICE FILTRU ASSET_CLASS: ' + ERROR_MESSAGE() AS result;
	END CATCH
END
GO
