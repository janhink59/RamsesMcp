execute dropni 'p_create_fulltext_catalog'
GO
CREATE PROCEDURE [dbo].[p_create_fulltext_catalog]
    @CatalogName sysname = NULL
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

    -- 2. KONTROLA EXISTENCE JAKÉHOKOLIV KATALOGU
    -- Využíváme systémové zobrazení sys.fulltext_catalogs.
    -- Pokud dotaz vrátí alespoň jeden řádek, znamená to, že v databázi 
    -- již nějaký katalog existuje a procedura bez provedení změn končí.
    IF EXISTS (SELECT 1 FROM sys.fulltext_catalogs)
    BEGIN
        --PRINT 'V databázi již existuje alespoň jeden fulltextový katalog. Vytváření přeskočeno.';
        RETURN;
    END

    -- 3. ZJIŠTĚNÍ LOGICKÉHO NÁZVU PRIMÁRNÍHO SOUBORU
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

    -- 4. DYNAMICKÝ SQL A BEZPEČNÉ VYTVOŘENÍ
    DECLARE @Sql NVARCHAR(MAX);
    
    -- QUOTENAME zajistí bezpečné obalení názvu do hranatých závorek.
    -- PŘIDÁNO: WITH ACCENT_SENSITIVITY = OFF vynutí ignorování diakritiky (háčků a čárek).
    SET @Sql = N'CREATE FULLTEXT CATALOG ' + QUOTENAME(@CatalogName) + N' WITH ACCENT_SENSITIVITY = OFF AS DEFAULT;';
	EXEC sp_executesql @Sql;

    PRINT 'Defaultní fulltextový katalog [' + @CatalogName + '] (Accent Insensitive) byl úspěšně vytvořen.';
END;
GO
execute p_create_fulltext_catalog
GO
