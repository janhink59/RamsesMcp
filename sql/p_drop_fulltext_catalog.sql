execute dropni 'p_drop_fulltext_catalog'
GO
CREATE PROCEDURE p_drop_fulltext_catalog
    @CatalogName sysname = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- 1. KONTROLA INSTALACE FULL-TEXT KOMPONENTY
    IF SERVERPROPERTY('IsFullTextInstalled') <> 1
    BEGIN
        PRINT 'Fulltextová podpora není nainstalována!';
        RETURN;
    END

    -- 2. ZJIŠTÌNÍ NÁZVU KATALOGU (pokud nebyl pøedán)
    -- Udržujeme stejnou logiku jako pøi vytváøení (file_id = 1)
    IF @CatalogName IS NULL
    BEGIN
        SELECT 
            @CatalogName = [name] 
        FROM 
            sys.database_files 
        WHERE 
            [file_id] = 1;
    END

    -- 3. KONTROLA EXISTENCE KATALOGU A ZÍSKÁNÍ JEHO ID
    DECLARE @CatalogId INT;
    
    SELECT 
        @CatalogId = fulltext_catalog_id 
    FROM 
        sys.fulltext_catalogs 
    WHERE 
        [name] COLLATE DATABASE_DEFAULT = @CatalogName COLLATE DATABASE_DEFAULT;

    IF @CatalogId IS NULL
    BEGIN
        PRINT 'Fulltextový katalog [' + @CatalogName + '] neexistuje. Není co odstraòovat.';
        RETURN;
    END

    -- 4. ODSTRANÌNÍ VŠECH ZÁVISLÝCH FULL-TEXT INDEXÙ
    -- Pomocí metadat dynamicky sestavíme DROP pøíkazy pro všechny tabulky v tomto katalogu.
    -- Je nutné pøipojit sys.objects a sys.schemas pro získání pøesného názvu tabulek.
    DECLARE @DropIndexesSql NVARCHAR(MAX) = N'';

    SELECT @DropIndexesSql += N'DROP FULLTEXT INDEX ON ' + 
                              QUOTENAME(s.[name]) + N'.' + QUOTENAME(o.[name]) + N'; ' + CHAR(13)
    FROM sys.fulltext_indexes fi
    INNER JOIN sys.objects o ON fi.[object_id] = o.[object_id]
    INNER JOIN sys.schemas s ON o.[schema_id] = s.[schema_id]
    WHERE fi.fulltext_catalog_id = @CatalogId;

    IF @DropIndexesSql <> N''
    BEGIN
        PRINT 'Nalezeny závislé fulltextové indexy. Zahajuji jejich odstraòování...';
        -- PRINT @DropIndexesSql; -- Odkomentujte pro debugování vygenerovaného kódu
        EXEC sp_executesql @DropIndexesSql;
        PRINT 'Závislé indexy byly úspìšnì odstranìny.';
    END
    ELSE
    BEGIN
        PRINT 'V katalogu nebyly nalezeny žádné závislé indexy.';
    END

    -- 5. ODSTRANÌNÍ SAMOTNÉHO KATALOGU
    DECLARE @DropCatalogSql NVARCHAR(MAX);
    
    SET @DropCatalogSql = N'DROP FULLTEXT CATALOG ' + QUOTENAME(@CatalogName) + N';';
    
    EXEC sp_executesql @DropCatalogSql;
    
    PRINT 'Fulltextový katalog [' + @CatalogName + '] byl úspìšnì odstranìn.';
END;
GO
