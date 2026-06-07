execute dropni 'mcp_tool_set_filter'
GO

/*
	Nástroj: set_filter (Generický MCP Tool - Dispečer / Fasáda)
	Účel:    Přijímá požadavek od LLM, ošetří vstupy a dynamicky volá 
	         specifické implementace filtrů (např. mcp_filter_asset_class).
	Výstup:  Vrací jednosloupcové TSV (sloupec "result") s textovou zprávou
	         o úspěchu, nebo chybové hlášení (aby nedošlo k pádu LLM agenta).
*/
CREATE PROCEDURE mcp_tool_set_filter
	@filter_code	VARCHAR(100),           -- Kód filtru z tabulky mcp_filter
	@free_text		NVARCHAR(MAX) = NULL,   -- Hledaný text (nepovinný, záleží na logice konkrétního filtru)
	@save_as		VARCHAR(100) = NULL,     -- Sjednocený název klíče do kontextu (fallback na @filter_code)
	@top_n          int=3
AS
BEGIN
	SET NOCOUNT ON;

	-- 1. Očištění a příprava parametrů
	SET @filter_code = LTRIM(RTRIM(@filter_code));
	SET @free_text = LTRIM(RTRIM(ISNULL(@free_text, '')));

	-- Pokud LLM (nebo uživatel) nepředá save_as, použijeme jako klíč samotný filter_code
	IF NULLIF(LTRIM(RTRIM(@save_as)), '') IS NULL
	BEGIN
		SET @save_as = @filter_code;
	END

	-- 2. Základní validace
	IF @filter_code = '' OR @filter_code IS NULL
	BEGIN
		SELECT 'CHYBA: Parametr filter_code je povinný a nebyl předán.' AS result;
		RETURN;
	END

	-- 3. Sestavení názvu cílové uložené procedury
	DECLARE @proc_name NVARCHAR(128) = N'dbo.mcp_filter_' + @filter_code;

	-- 4. BEZPEČNOSTNÍ KONTROLA (Prevence SQL Injection)
	IF OBJECT_ID(@proc_name, 'P') IS NULL
	BEGIN
		SELECT 'CHYBA: Filtr s kódem ''' + @filter_code + ''' aktuálně není na serveru implementován.' AS result;
		RETURN;
	END

	-- 5. Dynamické spuštění konkrétní procedury filtru
	BEGIN TRY
		-- ZDE Byla chyba: Dispečer dříve předával @variable_name
		-- Nyní správně předáváme parametr @save_as
		EXEC @proc_name 
			@free_text = @free_text, 
			@save_as = @save_as;
	END TRY
	BEGIN CATCH
		SELECT 'CHYBA DATABÁZE PŘI VÝPOČTU FILTRU: ' + ERROR_MESSAGE() AS result;
	END CATCH
END
GO
