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
    -- Využití systémového zobrazení sys.fulltext_indexes pro přesnou identifikaci přes object_id
    DECLARE @IndexExists BIT = 0;
    
    IF EXISTS (
        SELECT 1 
        FROM sys.fulltext_indexes 
        WHERE [object_id] = OBJECT_ID(@tabname)
    )
    BEGIN
        SET @IndexExists = 1;
    END

    -- 3. Vyhodnocení podmínek pro zápis (Existence vs. Parametr @force)
    IF @IndexExists = 1 AND @force = 0
    BEGIN
        -- PRINT 'Fulltextový index na tabulce ' + @tabname + ' již existuje a parametr @force je roven 0. Akce zrušena.';
        RETURN;
    END

    DECLARE @Sql NVARCHAR(MAX);

    -- 4. Odstranění původního indexu, pokud existuje a je vynucen @force = 1
    IF @IndexExists = 1 AND @force = 1
    BEGIN
        SET @Sql = N'DROP FULLTEXT INDEX ON ' + QUOTENAME(@tabname) + N';';
        EXEC sp_executesql @Sql;
        PRINT 'Původní fulltextový index na tabulce ' + @tabname + ' byl odstraněn (force=1).';
    END

    -- 5. Generování @syntax ze seznamu @column_list pomocí uživatelské funkce
    DECLARE @syntax NVARCHAR(MAX) = N'';

    -- Spojení výsledků z f_string_list do jednoho řetězce pomocí FOR XML PATH
    -- Využíváme QUOTENAME pro bezpečné zabalení názvů sloupců do závorek []
    SELECT @syntax = STUFF((
        SELECT N', ' + QUOTENAME(dbo.f_php_trim(s)) + N' LANGUAGE 1029'
        FROM dbo.f_string_list(@column_list)
        WHERE s IS NOT NULL AND s <> ''
        ORDER BY ordr -- Zachová původní pořadí sloupců ze zadání
        FOR XML PATH(N''), TYPE).value(N'.', N'NVARCHAR(MAX)'), 1, 2, N'');

    -- Validace, zda po zpracování existují platné sloupce
    IF @syntax IS NULL OR @syntax = N''
    BEGIN
        PRINT 'Chyba: Nebyly předány žádné platné sloupce k indexaci.';
        RETURN;
    END

    -- 6. Sestavení a spuštění DDL příkazu pro vytvoření indexu
    SET @Sql = N'CREATE FULLTEXT INDEX ON ' + QUOTENAME(@tabname) + N' (' + @syntax + N') ' +
               N'KEY INDEX ' + QUOTENAME(@key) + N' WITH (STOPLIST = [cz]);';

    PRINT @Sql;
    EXEC sp_executesql @Sql;
    PRINT 'Fulltextový index na tabulce ' + @tabname + ' byl úspěšně vytvořen a navázán na stoplist [cz].';
END;
GO
