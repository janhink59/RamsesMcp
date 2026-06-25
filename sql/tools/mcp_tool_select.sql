execute dropni 'mcp_tool_set_filter'
GO
execute dropni 'mcp_tool_select'
GO

/*
	Nástroj: select (Generický MCP Tool - Dispečer / Fasáda)
	Účel:    Přijímá požadavek od LLM a dynamicky volá specifické agendy/filtry.
	         Parametry @save_as a @save_only se sem z PHP vůbec nedostanou!
*/
CREATE PROCEDURE mcp_tool_select
	@filter_code	VARCHAR(100),           
	@free_text		NVARCHAR(MAX) = NULL,   
	@top_n			INT = NULL
AS
BEGIN
	SET NOCOUNT ON;

	SET @filter_code = LTRIM(RTRIM(ISNULL(@filter_code, '')));
	SET @free_text = LTRIM(RTRIM(ISNULL(@free_text, '')));

	IF @filter_code = ''
	BEGIN
		SELECT 'CHYBA: Parametr filter_code (kód entity) je povinný.' AS result;
		RETURN;
	END

	DECLARE @proc_name NVARCHAR(128) = N'dbo.mcp_filter_' + @filter_code;

	-- Kontrola, zda požadovaný filter/agenda na serveru vůbec existuje
	IF OBJECT_ID(@proc_name, 'P') IS NULL
	BEGIN
		SELECT 'CHYBA: Entita s kódem ''' + @filter_code + ''' aktuálně není na serveru implementována (chybí procedura ' + @proc_name + ').' AS result;
		RETURN;
	END

	BEGIN TRY
		-- Dispečer pošle parametry dál do konkrétní procedury
		EXEC @proc_name 
			@free_text = @free_text, 
			@top_n = @top_n;
	END TRY
	BEGIN CATCH
		SELECT 'CHYBA DATABÁZE PŘI ČTENÍ ENTITY: ' + ERROR_MESSAGE() AS result;
	END CATCH
END
GO
