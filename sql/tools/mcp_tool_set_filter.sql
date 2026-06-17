execute dropni 'mcp_tool_set_filter'
GO

/*
	Nástroj: set_filter (Generický MCP Tool - Dispečer / Fasáda)
	Účel:    Přijímá požadavek od LLM a dynamicky volá specifické filtry.
	         Parametry @save_as a @save_only se sem z PHP vůbec nedostanou!
*/
CREATE PROCEDURE mcp_tool_set_filter
	@filter_code	VARCHAR(100),           
	@free_text		NVARCHAR(MAX) = NULL,   
	@top_n          INT = NULL
AS
BEGIN
	SET NOCOUNT ON;

	SET @filter_code = LTRIM(RTRIM(@filter_code));
	SET @free_text = LTRIM(RTRIM(ISNULL(@free_text, '')));

	IF @filter_code = '' OR @filter_code IS NULL
	BEGIN
		SELECT 'CHYBA: Parametr filter_code je povinný.' AS result;
		RETURN;
	END

	DECLARE @proc_name NVARCHAR(128) = N'dbo.mcp_filter_' + @filter_code;

	IF OBJECT_ID(@proc_name, 'P') IS NULL
	BEGIN
		SELECT 'CHYBA: Filtr s kódem ''' + @filter_code + ''' aktuálně není na serveru implementován.' AS result;
		RETURN;
	END

	BEGIN TRY
		-- Dispečer prostě jen pošle parametry dál do konkrétního filtru
		EXEC @proc_name 
			@free_text = @free_text, 
			@top_n = @top_n;
	END TRY
	BEGIN CATCH
		SELECT 'CHYBA DATABÁZE PŘI VÝPOČTU FILTRU: ' + ERROR_MESSAGE() AS result;
	END CATCH
END
GO