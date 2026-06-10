execute dropni 'p_xlsx_fulltext_stoplist'
GO
-- ============================================================================
-- Procedura:   dbo.p_xlsx_fulltext_stoplist
-- Popis:       Importuje a synchronizuje fulltextová stop-slova ze staging 
--              tabulky [xlsx_stoplist$] do specifikovaného Full-Text stoplistu.
--              Ošetøeno proti Collation konfliktùm pøi ètení systémových pohledù.
-- Parametry:
--   @fsl_name     - Název cílového stoplistu (výchozí 'cz').
--   @fsl_language - Identifikátor jazyka, tzv. LCID (výchozí 1029 pro èeštinu).
--   @verbose      - 0 = tichý chod (implicitní), 1 = vypisuje prùbìh do zpráv.
-- ============================================================================
CREATE OR ALTER PROCEDURE p_xlsx_fulltext_stoplist
    @fsl_name VARCHAR(20) = 'cz',
    @fsl_language INT = 1029,
    @verbose INT = 0,
	@import_mode varchar(1)='1'
AS
BEGIN
    SET NOCOUNT ON;

    IF @verbose = 1
        PRINT N'Zahajuji proces aktualizace Full-Text stoplistu [' + @fsl_name + N'] ze staging tabulky [xlsx_stoplist$]...';

    DECLARE @Sql NVARCHAR(MAX);

    -- 1. Založení stoplistu, pokud v databázi ještì neexistuje
    -- Pøidáno COLLATE DATABASE_DEFAULT pro bezpeèné porovnání názvu
    IF NOT EXISTS (SELECT 1 FROM sys.fulltext_stoplists WHERE name COLLATE DATABASE_DEFAULT = @fsl_name COLLATE DATABASE_DEFAULT)
    BEGIN
        SET @Sql = N'CREATE FULLTEXT STOPLIST ' + QUOTENAME(@fsl_name) + N';';
        EXEC sp_executesql @Sql;
        
        IF @verbose = 1
            PRINT N'Stoplist [' + @fsl_name + N'] nebyl nalezen a byl úspìšnì vytvoøen.';
    END
    ELSE
    BEGIN
        IF @verbose = 1
            PRINT N'Stoplist [' + @fsl_name + N'] již existuje. Probìhne synchronizace nových slov.';
    END

    -- 2. Deklarace pracovních promìnných
    DECLARE @StopWord NVARCHAR(150);
    DECLARE @PocetVlozenych INT = 0;

    -- 3. Definice kurzoru pro prùchod nových slov z excelového importu
    -- OŠETØENÍ CHYBY 468: Ve vnoøeném SELECTu používáme COLLATE DATABASE_DEFAULT 
    -- pro fsl.name i fsw.stopword, aby si rozumìly s tabulkou xlsx_stoplist$
    DECLARE StopwordCursor CURSOR LOCAL FAST_FORWARD FOR
    SELECT DISTINCT LTRIM(RTRIM(s.[stopword]))
    FROM dbo.[xlsx_stoplist$] s
    WHERE s.[stopword] IS NOT NULL 
      AND LTRIM(RTRIM(s.[stopword])) <> N''
      AND NOT EXISTS (
          SELECT 1 
          FROM sys.fulltext_stopwords fsw
          INNER JOIN sys.fulltext_stoplists fsl ON fsw.stoplist_id = fsl.stoplist_id
          WHERE fsl.name COLLATE DATABASE_DEFAULT = @fsl_name COLLATE DATABASE_DEFAULT
            AND fsw.language_id = @fsl_language 
            AND fsw.stopword COLLATE DATABASE_DEFAULT = LTRIM(RTRIM(s.[stopword])) COLLATE DATABASE_DEFAULT
      );

    OPEN StopwordCursor;
    FETCH NEXT FROM StopwordCursor INTO @StopWord;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @Sql = N'ALTER FULLTEXT STOPLIST ' + QUOTENAME(@fsl_name) + 
                   N' ADD ''' + REPLACE(@StopWord, '''', '''''') + 
                   N''' LANGUAGE ' + CAST(@fsl_language AS NVARCHAR(10)) + N';';
        
        BEGIN TRY
            EXEC sp_executesql @Sql;
            SET @PocetVlozenych = @PocetVlozenych + 1;
        END TRY
        BEGIN CATCH
            IF @verbose = 1
                PRINT N'Varování - Chyba pøi vkládání slova [' + @StopWord + N']: ' + ERROR_MESSAGE();
        END CATCH

        FETCH NEXT FROM StopwordCursor INTO @StopWord;
    END

    CLOSE StopwordCursor;
    DEALLOCATE StopwordCursor;

    IF @verbose = 1
        PRINT N'Proces úspìšnì dokonèen. Poèet novì naimportovaných stop-slov: ' + CAST(@PocetVlozenych AS NVARCHAR(20));
END;
GO
