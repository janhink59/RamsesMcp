execute dropni 'mcp_join_session_by_ip'
GO

/**
 * RamsesMcp - mcp_join_session_by_ip
 * Úèel: Bezpeènì spáruje webový prohlížeè s kontextem LLM asistenta.
 * Odstranìny všechny "záchranné fallbacky". Pokud se relace nenajde, vrátí NULL a nic nedìlá.
 */
CREATE OR ALTER PROCEDURE mcp_join_session_by_ip
	@wwwsession varchar(50),                                    -- Nová/živá session aktuálního prohlížeèe (standardní hexa)
	@ip varchar(200)                                            -- Kompletní øetìzec IP adres z PHP
AS
BEGIN
	SET XACT_ABORT ON;
	SET NOCOUNT ON;

	DECLARE @mcp_session varchar(50) = NULL;
	DECLARE @time_limit datetime = DATEADD(minute, -60, GETDATE());

	-- ============================================================================
	-- 1. STRIKTNÍ DOHLEDÁNÍ MCP RELACE (Musí to být MCP!)
	-- ============================================================================
	SELECT TOP 1 
		@mcp_session = wwwsession
	FROM	[dbo].[wwwsession]
	WHERE	client_ip = @ip
	  AND	wwwsession LIKE 'mcp_%'                             -- Bezpeènostní pojistka: Hledáme výhradnì AI session
	  AND	request_date > @time_limit
	ORDER BY request_date DESC;

	-- ============================================================================
	-- 2. KLONOVÁNÍ KONTEXTU (Provede se pouze pøi 100% nálezu)
	-- ============================================================================
	-- Jelikož @wwwsession je standardní hexa kód (z prohlížeèe) a @mcp_session zaèíná 
	-- striktnì na 'mcp_', nikdy se logicky nemohou rovnat. Žádný self-delete nehrozí.
	IF @mcp_session IS NOT NULL
	BEGIN
		BEGIN TRANSACTION;

		IF OBJECT_ID('tempdb..#w') IS NOT NULL DROP TABLE #w;

		-- Nabere ostrá data z MCP relace
		SELECT * INTO #w FROM [dbo].[wwwsession] WHERE wwwsession = @mcp_session;

		-- Smaže tu stávající holou relaci, kterou si pøed vteøinou vytvoøilo PHP pro prohlížeè
		DELETE FROM [dbo].[wwwsession] WHERE wwwsession = @wwwsession;

		-- Napasuje MCP práva do aktuálního prohlížeèe a aktuálního procesního SPIDu
		UPDATE	#w 
		SET 
			wwwsession = @wwwsession,
			spid = @@SPID,
			request_date = GETDATE()
		;

		-- Zapíše spárovaného uživatele zpìt
		INSERT INTO [dbo].[wwwsession] SELECT * FROM #w;

		DROP TABLE #w;

		COMMIT TRANSACTION;
	END

	-- ============================================================================
	-- 3. NÁVRAT VÝSLEDKU (ID nebo NULL)
	-- ============================================================================
	SELECT @mcp_session AS llm_session;

END
GO
