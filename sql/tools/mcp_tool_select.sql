execute dropni 'mcp_tool_set_filter'
execute dropni 'mcp_tool_select'
GO

/*
	Nástroj: select (Generický MCP Tool - Dispečer / Fasáda)
	Účel:    Přijímá požadavek od LLM a dynamicky volá specifické agendy/výběry.
	         Parametry @save_as a @save_only se sem z PHP vůbec nedostanou!
*/
CREATE PROCEDURE mcp_tool_select
	@select_code	VARCHAR(100),           
	@free_text		NVARCHAR(MAX) = NULL,   
	@top_n			INT = NULL
AS
BEGIN
	SET NOCOUNT ON;

	SET @select_code = LTRIM(RTRIM(ISNULL(@select_code, '')));
	SET @free_text = LTRIM(RTRIM(ISNULL(@free_text, '')));

	IF @select_code = ''
	BEGIN
		SELECT 'CHYBA: Parametr select_code (kód entity) je povinný.' AS result;
		RETURN;
	END

	-- Změna prefixu volaných procedur na mcp_select_
	DECLARE @proc_name NVARCHAR(128) = N'dbo.mcp_select_' + @select_code;

	-- Kontrola, zda požadovaný výběr/agenda na serveru vůbec existuje
	IF OBJECT_ID(@proc_name, 'P') IS NULL
	BEGIN
		SELECT 'CHYBA: Entita s kódem ''' + @select_code + ''' aktuálně není na serveru implementována (chybí procedura ' + @proc_name + ').' AS result;
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
