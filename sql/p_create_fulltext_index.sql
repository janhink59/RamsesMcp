execute dropni 'p_create_fulltext_index'
GO
CREATE PROCEDURE p_create_fulltext_index
    @tabname sysname,
    @column_list NVARCHAR(MAX),
    @key sysname=null,
    @force BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    IF @key IS NULL
    BEGIN
        -- Zjednodušené hledání: vezmeme prostě název primárního klíče z metadat
        SELECT @key = name
        FROM sys.indexes
        WHERE [object_id] = OBJECT_ID(@tabname)
          AND is_primary_key = 1;

        IF @key IS NULL
        BEGIN
            PRINT 'Chyba: Tabulka ' + @tabname + ' nemá primární klíč a parametr @key nebyl zadán.';
            RETURN;
        END
    END	

    -- 1. Kontrola instalace Full-Text komponenty na instanci
    IF SERVERPROPERTY('IsFullTextInstalled') <> 1
    BEGIN
        PRINT 'Full-Text komponenta není na tomto serveru nainstalována.';
        RETURN;
    END

    -- 2. Zjištění, zda fulltextový index na tabulce již existuje
    DECLARE @IndexExists BIT = 0;
    
    IF EXISTS (
        SELECT 1 
        FROM sys.fulltext_indexes 
        WHERE [object_id] = OBJECT_ID(@tabname)
    )
    BEGIN
        SET @IndexExists = 1;
    END

    -- 3. Vyhodnocení podmínek pro zápis
    IF @IndexExists = 1 AND @force = 0
    BEGIN
        RETURN;
    END

    DECLARE @Sql NVARCHAR(MAX);

    -- 4. Odstranění původního indexu a virtuálního sloupce (pokud force=1)
    IF @IndexExists = 1 AND @force = 1
    BEGIN
        SET @Sql = N'DROP FULLTEXT INDEX ON ' + QUOTENAME(@tabname) + N';';
        EXEC sp_executesql @Sql;
        PRINT 'Původní fulltextový index na tabulce ' + @tabname + ' byl odstraněn (force=1).';
    END

    -- Zajištění, že původní virtuální sloupec nezůstal v tabulce z předchozího běhu
    IF COL_LENGTH(@tabname, '_fts_search_text') IS NOT NULL
    BEGIN
        SET @Sql = N'ALTER TABLE ' + QUOTENAME(@tabname) + N' DROP COLUMN _fts_search_text;';
        EXEC sp_executesql @Sql;
    END

    -- 5. Generování sloučeného výrazu pro virtuální sloupec (Computed Column)
    DECLARE @computed_expr NVARCHAR(MAX) = N'';

    -- Sestavení argumentů pro funkci CONCAT: [sloupec1], ' ', [sloupec2], ' ', ...
    SELECT @computed_expr = STUFF((
        SELECT N', '' '', ' + QUOTENAME(dbo.f_php_trim(s))
        FROM dbo.f_string_list(@column_list)
        WHERE s IS NOT NULL AND s <> ''
        ORDER BY ordr 
        FOR XML PATH(N''), TYPE).value(N'.', N'NVARCHAR(MAX)'), 1, 7, N'');

    IF @computed_expr IS NULL OR @computed_expr = N''
    BEGIN
        PRINT 'Chyba: Nebyly předány žádné platné sloupce k indexaci.';
        RETURN;
    END

    -- Fyzické přidání virtuálního sloupce do tabulky
    SET @Sql = N'ALTER TABLE ' + QUOTENAME(@tabname) + N' ADD _fts_search_text AS CONCAT(' + @computed_expr + N');';
    EXEC sp_executesql @Sql;
    PRINT 'Vytvořen virtuální sloupec _fts_search_text.';

    -- 6. Sestavení a spuštění DDL příkazu pro vytvoření indexu pouze na tento nový sloupec
    SET @Sql = N'CREATE FULLTEXT INDEX ON ' + QUOTENAME(@tabname) + N' ([_fts_search_text] LANGUAGE 1029) ' +
               N'KEY INDEX ' + QUOTENAME(@key) + N' WITH (STOPLIST = [cz]);';

    PRINT @Sql;
    EXEC sp_executesql @Sql;
    PRINT 'Fulltextový index na tabulce ' + @tabname + ' byl úspěšně vytvořen nad sloupcem _fts_search_text.';
END;
GO
