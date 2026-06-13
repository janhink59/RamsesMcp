execute dropni 'mcp_adopt_values'
GO
CREATE OR ALTER PROCEDURE mcp_adopt_values
	@wwwsession varchar(50)                                     -- Zdrojové session_id z MCP (pøedané v POST parametru z LLM)
AS
BEGIN
	-- Ochrana transakèní integrity: Pøi jakékoliv chybì okamžitý ROLLBACK a THROW do klienta
	SET XACT_ABORT ON;
	SET NOCOUNT ON;

	DECLARE @current_session varchar(50);

	-- ============================================================================
	-- 1. IDENTIFIKACE CÍLOVÉHO KONTEXTU PROHLÍŽEÈE
	-- ============================================================================
	SELECT	@current_session = wwwsession
	FROM	[dbo].[dbsession]
	WHERE	spid = @@SPID;

	-- ============================================================================
	-- 2. SANITY CHECK / DEFENSIVNÍ OCHRANA
	-- ============================================================================
	-- Pokud webová session chybí nebo je shodná s MCP, není co klonovat
	IF @current_session IS NULL OR @current_session = @wwwsession
	BEGIN
		RETURN;
	END

	-- ============================================================================
	-- 3. TRANSAKÈNÍ ADOPCE DAT (DESTRUCTIVE FLUSH & CLONE)
	-- ============================================================================
	BEGIN TRANSACTION;

	-- Plošné vyèištìní cílového kontextu prohlížeèe (ochrana proti sirotèím datùm)
	DELETE FROM [dbo].[mcp_saved_values]
	WHERE	wwwsession = @current_session;

	-- Naklonování dat z pamìti umìlé inteligence do relace prohlížeèe
	INSERT INTO [dbo].[mcp_saved_values] (
		wwwsession,
		save_as,
		row_index,
		saved_data
	)
	SELECT	@current_session,
		save_as,
		row_index,
		saved_data
	FROM	[dbo].[mcp_saved_values]
	WHERE	wwwsession = @wwwsession;

	COMMIT TRANSACTION;
END
GO
