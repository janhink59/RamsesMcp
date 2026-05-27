execute dropni 'p_create_fulltext_catalog'
GO
CREATE PROCEDURE [dbo].[p_create_fulltext_catalog]
	@CatalogName sysname = NULL
AS
BEGIN
	SET NOCOUNT ON;

	-- 1. KONTROLA INSTALACE FULL-TEXT KOMPONENTY
	-- Pokud není volitelná souèást nainstalována, tiše opouštíme proceduru.
	IF SERVERPROPERTY('IsFullTextInstalled') <> 1
	BEGIN
		print 'Fulltextová podpora není nainstalována!'
		RETURN;
	END

	-- 2. KONTROLA EXISTENCE JAKÉHOKOLIV KATALOGU
	-- Využíváme systémové zobrazení sys.fulltext_catalogs.
	-- Pokud dotaz vrátí alespoò jeden øádek, znamená to, že v databázi 
	-- již nìjaký katalog existuje a procedura bez provedení zmìn konèí.
	IF EXISTS (SELECT 1 FROM sys.fulltext_catalogs)
	BEGIN
		--PRINT 'V databázi již existuje alespoò jeden fulltextový katalog. Vytváøení pøeskoèeno.';
		RETURN;
	END

	-- 3. ZJIŠTÌNÍ LOGICKÉHO NÁZVU PRIMÁRNÍHO SOUBORU
	-- Pokud nebyl parametr @CatalogName pøedán (je NULL), sáhneme do systémového
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

	-- 4. DYNAMICKÝ SQL A BEZPEÈNÉ VYTVOØENÍ
	DECLARE @Sql NVARCHAR(MAX);
	
	-- QUOTENAME zajistí bezpeèné obalení názvu do hranatých závorek,
	-- což nás chrání pøed SQL injection a syntaktickými chybami.
	SET @Sql = N'CREATE FULLTEXT CATALOG ' + QUOTENAME(@CatalogName) + N' AS DEFAULT;';
	
	EXEC sp_executesql @Sql;
	
	PRINT 'Defaultní fulltextový katalog [' + @CatalogName + '] byl úspìšnì vytvoøen.';
END;
GO
execute p_create_fulltext_catalog
GO
