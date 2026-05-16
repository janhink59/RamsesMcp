execute dropni 'p_create_fulltext_index'
GO
CREATE PROCEDURE [dbo].[p_create_fulltext_index]
	@tabname sysname,
	@key sysname,
	@syntax NVARCHAR(MAX),
	@force BIT = 0
AS
BEGIN
	SET NOCOUNT ON;

	-- 1. Kontrola instalace Full-Text komponenty na instanci
	IF SERVERPROPERTY('IsFullTextInstalled') <> 1
	BEGIN
		RETURN;
	END

	-- 2. Zjištìní, zda fulltextový index na tabulce již existuje
	-- Využíváme systémové zobrazení sys.fulltext_indexes, které mapuje 
	-- existenci FT indexù pøímo na object_id tabulek.
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
		--PRINT 'Fulltextový index na tabulce ' + @tabname + ' již existuje a parametr @force je roven 0. Akce zrušena.';
		RETURN;
	END

	DECLARE @Sql NVARCHAR(MAX);

	-- 4. Odstranìní pùvodního indexu, pokud existuje a je vynucen @force = 1
	IF @IndexExists = 1 AND @force = 1
	BEGIN
		SET @Sql = N'DROP FULLTEXT INDEX ON ' + @tabname + N';';
		EXEC sp_executesql @Sql;
		PRINT 'Pùvodní fulltextový index na tabulce ' + @tabname + ' byl odstranìn (force=1).';
	END

	-- 5. Sestavení a spuštìní DDL pøíkazu pro vytvoøení indexu
	-- Parametr @syntax vkládáme pøímo do závorek pro definici sloupcù.
	-- Klauzule KEY INDEX vyžaduje název unikátního indexu, pøedaný v parametru @key.
	SET @Sql = N'CREATE FULLTEXT INDEX ON ' + @tabname + N' (' + @syntax + N') ' +
	           N'KEY INDEX ' + @key + N';';

	EXEC sp_executesql @Sql;
	PRINT 'Fulltextový index na tabulce ' + @tabname + ' byl úspìšnì vytvoøen.';
END;
GO
