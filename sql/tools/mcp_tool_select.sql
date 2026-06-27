execute dropni 'mcp_tool_set_filter'
execute dropni 'mcp_tool_select'
GO

/*
	Nástroj: select (Generický MCP Tool - Dispečer / Fasáda)
	Účel:    Přijímá požadavek od LLM a dynamicky volá specifické agendy/výběry.
	         Parametry @save_as a @save_only řeší orchestrátor v PHP.
	         
	         POZNÁMKA PRO AI: Metadata z Excelu nemají NULL hodnoty, prázdná 
	         pole jsou importována jako prázdné řetězce ('').
	         Parametr @top_n je svázán s use_free_text.
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

	-- 1. Inicializace a načtení metadat (ošetření prázdných řetězců z Excelu)
	DECLARE @procedure_name  NVARCHAR(128) = N'';
	DECLARE @use_free_text   BIT = 0;
	DECLARE @select_columns  NVARCHAR(MAX) = N'';
	DECLARE @order_by        NVARCHAR(MAX) = N'';

	SELECT 
		@procedure_name = LTRIM(RTRIM(ISNULL(procedure_name, ''))),
		@use_free_text  = ISNULL(use_free_text, 0),
		@select_columns = LTRIM(RTRIM(ISNULL(select_columns, ''))),
		@order_by       = LTRIM(RTRIM(ISNULL(order_by, '')))
	FROM dbo.mcp_select
	WHERE select_code = @select_code;

	-- Pokud název objektu v konfiguraci chybí (je prázdný řetězec), použije se výchozí konvence
	IF @procedure_name = N''
	BEGIN
		SET @procedure_name = N'mcp_select_' + @select_code;
	END

	-- Pokud sloupce nejsou definovány, vracíme všechny (*)
	IF @select_columns = N''
	BEGIN
		SET @select_columns = N'*';
	END

	-- 2. Zjištění typu databázového objektu ze systémového katalogu
	DECLARE @obj_type CHAR(2) = NULL;
	
	SELECT @obj_type = RTRIM(type)
	FROM sys.objects 
	WHERE object_id = OBJECT_ID(@procedure_name);

	-- Kontrola, zda cílový objekt na serveru reálně existuje
	IF @obj_type IS NULL
	BEGIN
		SELECT 'CHYBA: Entita s kódem ''' + @select_code + ''' (očekávaný objekt ' + @procedure_name + ') aktuálně není na serveru k dispozici.' AS result;
		RETURN;
	END

	-- 3. Sestavení dynamického T-SQL dotazu podle typu cílového objektu
	DECLARE @sql NVARCHAR(MAX) = N'';
	DECLARE @params_def NVARCHAR(MAX) = N'@free_text NVARCHAR(MAX)';

	IF @obj_type = 'P' -- ULOŽENÁ PROCEDURA
	BEGIN
		SET @sql = N'EXECUTE ' + @procedure_name;
		
		IF @use_free_text = 1 AND @free_text <> ''
		BEGIN
			SET @sql = @sql + N' @free_text = @free_text';
		END
	END
	ELSE IF @obj_type IN ('IF', 'TF', 'FT') -- TABULKOVÁ FUNKCE (Table-Valued Function)
	BEGIN
		SET @sql = N'SELECT ';
		
		-- Aplikace TOP N POUZE pokud je use_free_text aktivní
		IF @use_free_text = 1 AND @top_n IS NOT NULL AND @top_n > 0
		BEGIN
			SET @sql = @sql + N'TOP (' + CAST(@top_n AS NVARCHAR(10)) + N') ';
		END
		
		SET @sql = @sql + @select_columns + N' FROM ' + @procedure_name;
		
		-- Funkce vyžadují závorky pro parametry
		IF @use_free_text = 1
		BEGIN
			SET @sql = @sql + N'(@free_text)';
		END
		ELSE
		BEGIN
			SET @sql = @sql + N'()';
		END

		-- Aplikace řazení, pokud je zadáno
		IF @order_by <> N''
		BEGIN
			SET @sql = @sql + N' ORDER BY ' + @order_by;
		END
	END
	ELSE -- VIEW ('V') NEBO TABULKA ('U')
	BEGIN
		SET @sql = N'SELECT ';
		
		-- Aplikace TOP N POUZE pokud je use_free_text aktivní
		IF @use_free_text = 1 AND @top_n IS NOT NULL AND @top_n > 0
		BEGIN
			SET @sql = @sql + N'TOP (' + CAST(@top_n AS NVARCHAR(10)) + N') ';
		END
		
		SET @sql = @sql + @select_columns + N' FROM ' + @procedure_name;

		-- Aplikace řazení, pokud je zadáno
		IF @order_by <> N''
		BEGIN
			SET @sql = @sql + N' ORDER BY ' + @order_by;
		END
	END

	-- 4. Bezpečné spuštění vygenerovaného dotazu a zachycení případných chyb
	BEGIN TRY
		EXECUTE sp_executesql @sql, @params_def, @free_text = @free_text;
	END TRY
	BEGIN CATCH
		SELECT 'CHYBA DATABÁZE PŘI ČTENÍ ENTITY: ' + ERROR_MESSAGE() AS result;
	END CATCH
END
GO
