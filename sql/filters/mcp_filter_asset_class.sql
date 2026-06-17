execute dropni 'mcp_filter_asset_class'
GO

/*
	Specifický filtr: asset_class
	ČISTĚ DATOVÁ VRSTVA: Pouze vrací data, veškerou logiku kolem 
	ukládání identifikátorů a zpráv pro LLM řeší PHP orchestrátor.
*/
CREATE PROCEDURE mcp_filter_asset_class
	@free_text NVARCHAR(MAX) = NULL,
	@top_n INT = 10
AS
BEGIN
	SET NOCOUNT ON;

	-- 1. Validace
	IF ISNULL(@free_text, '') = ''
	BEGIN
		-- Pokud pošleme sloupec "result", PHP to rozpozná jako zprávu a nezpracuje to jako data.
		SELECT 'CHYBA: Pro filtr třídy aktiv musíte zadat hledaný text v parametru free_text.' AS result;
		RETURN;
	END

	BEGIN TRY
		-- 2. Nalezení nejlepších shod (Fuzzy Match)
		DECLARE @BaseMatches TABLE (class_id BIGINT, max_child_id BIGINT);

		INSERT INTO @BaseMatches (class_id, max_child_id)
		SELECT TOP 5 class_id, ISNULL(max_child_id, class_id)
		FROM dbo.f_select_ast_cls()
		WHERE dbo.f_fuzzy_match(@free_text, full_class_name) > 0.3
		ORDER BY dbo.f_fuzzy_match(@free_text, full_class_name) DESC;

		-- 3. Expanze kandidátů
		DECLARE @Matches TABLE (row_idx INT IDENTITY(0,1), class_id BIGINT, class_name NVARCHAR(MAX));

		INSERT INTO @Matches (class_id, class_name)
		SELECT DISTINCT l.class_id, l.name
		FROM dbo.f_select_ast_cls() l
		INNER JOIN @BaseMatches b ON l.class_id BETWEEN b.class_id AND b.max_child_id
		WHERE ISNULL(l.leaf, '') = 'Y';

		-- 4. Odeslání čistých dat (Orchestrátor už se o zbytek postará)
		SELECT TOP (ISNULL(@top_n, 10))
			'__block_name' = 'Nalezená data', 
			class_id, 
			class_name 
		FROM @Matches 
		ORDER BY row_idx;

	END TRY
	BEGIN CATCH
		SELECT 'CHYBA V LOGICE FILTRU ASSET_CLASS: ' + ERROR_MESSAGE() AS result;
	END CATCH
END
GO
