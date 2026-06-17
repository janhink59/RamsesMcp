execute dropni 'p_create_fulltext_catalog'
GO
CREATE PROCEDURE [dbo].[p_create_fulltext_catalog]
    @CatalogName sysname = NULL,
	@fsl_name varchar(200)='cz',
	@verbose int=0
AS
BEGIN
    SET NOCOUNT ON;

    -- 1. KONTROLA INSTALACE FULL-TEXT KOMPONENTY
    -- Pokud není volitelná součást nainstalována, tiše opouštíme proceduru.
    IF SERVERPROPERTY('IsFullTextInstalled') <> 1
    BEGIN
        print 'Fulltextová podpora není nainstalována!'
        RETURN;
    END

    -- 2. ZJIŠTĚNÍ LOGICKÉHO NÁZVU PRIMÁRNÍHO SOUBORU
    -- Pokud nebyl parametr @CatalogName předán (je NULL), sáhneme do systémového
    -- zobrazení sys.database_files. Primární datový soubor (MDF) má vždy file_id = 1.
    IF @CatalogName IS NULL
    BEGIN
        SELECT 
            @CatalogName = [name] 
        FROM 
            sys.database_files 
        WHERE 
            [file_id] = 1;
    END

    -- 3. KONTROLA EXISTENCE JAKÉHOKOLIV KATALOGU
    -- Využíváme systémové zobrazení sys.fulltext_catalogs.
    -- Pokud dotaz vrátí alespoň jeden řádek, znamená to, že v databázi 
    -- již nějaký katalog existuje a procedura bez provedení změn končí.

    IF EXISTS (SELECT 1 FROM sys.fulltext_catalogs)
    BEGIN
        --PRINT 'V databázi již existuje alespoň jeden fulltextový katalog. Vytváření přeskočeno.';
        goto stoplist
    END


    -- 4. DYNAMICKÝ SQL A BEZPEČNÉ VYTVOŘENÍ
    DECLARE @Sql NVARCHAR(MAX);
    
    -- QUOTENAME zajistí bezpečné obalení názvu do hranatých závorek.
    -- PŘIDÁNO: WITH ACCENT_SENSITIVITY = OFF vynutí ignorování diakritiky (háčků a čárek).
    SET @Sql = N'CREATE FULLTEXT CATALOG ' + QUOTENAME(@CatalogName) + N' WITH ACCENT_SENSITIVITY = OFF AS DEFAULT;';
	EXEC sp_executesql @Sql;

    PRINT 'Defaultní fulltextový katalog [' + @CatalogName + '] (Accent Insensitive) byl úspěšně vytvořen.';

	stoplist:
    IF NOT EXISTS (SELECT 1 FROM sys.fulltext_stoplists WHERE name COLLATE DATABASE_DEFAULT = @fsl_name COLLATE DATABASE_DEFAULT)
    BEGIN
        SET @Sql = N'CREATE FULLTEXT STOPLIST ' + QUOTENAME(@fsl_name) + N';';
        EXEC sp_executesql @Sql;
        
        IF @verbose = 1
            PRINT N'Stoplist [' + @fsl_name + N'] nebyl nalezen a byl úspěšně vytvořen.';
    END
    ELSE
    BEGIN
        IF @verbose = 1
            PRINT N'Stoplist [' + @fsl_name + N'] již existuje. Proběhne synchronizace nových slov.';
    END

END;
GO
execute p_create_fulltext_catalog
GO
