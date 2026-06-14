execute dropni 'mcp_join_session_by_ip'
GO

/**
 * RamsesMcp - mcp_join_session_by_ip
 * Úèel: Bezpeènì spáruje anonymní webový prohlížeè s kontextem LLM asistenta.
 * Vyhledání probíhá v tabulce wwwsession na základì unikátní zøetìzené IP stopy.
 * Pokud se relace najde, zkopíruje ji pøes #w, pokud ne, vrací prokazatelný NULL.
 */
CREATE OR ALTER PROCEDURE mcp_join_session_by_ip
	@wwwsession varchar(50),                                    -- Nová session aktuálního prohlížeèe z PHP
	@ip varchar(200)                                            -- Kompletní øetìzec IP adres z PHP (get_client_ip_path)
AS
BEGIN
	-- Ochrana transakèní integrity: Pøi jakékoliv chybì okamžitý ROLLBACK
	SET XACT_ABORT ON;
	SET NOCOUNT ON;

	DECLARE @mcp_session varchar(50) = NULL;
	DECLARE @time_limit datetime = DATEADD(minute, -60, GETDATE());

	-- ============================================================================
	-- 1. DOHLEDÁNÍ PÙVODNÍ MCP RELACE PODLE SÍOVÉHO OTISKU
	-- ============================================================================
	-- Hledáme nejnovìjší MCP relaci v tabulce wwwsession, která pøišla z naprosto totožné proxy trasy
	SELECT TOP 1 
		@mcp_session = wwwsession
	FROM	[dbo].[wwwsession]
	WHERE	client_ip = @ip
	  AND	wwwsession LIKE 'mcp_%'
	  AND	request_date > @time_limit
	ORDER BY request_date DESC;

	-- ============================================================================
	-- 2. TRANSAKÈNÍ ADOPCE A VYTVOØENÍ WEBOVÉ RELACE (SELECT INTO #w)
	-- ============================================================================
	IF @mcp_session IS NOT NULL
	BEGIN
		BEGIN TRANSACTION;

		-- Úklid pøípadného visícího tempu v tomto databázovém spojení
		IF OBJECT_ID('tempdb..#w') IS NOT NULL DROP TABLE #w;

		-- Smazání prázdné/neautorizované session, pokud ji PHP už stihlo do tabulky zanést
		DELETE FROM [dbo].[wwwsession] WHERE wwwsession = @wwwsession;

		-- Vytvoøení plného strukturního klonu z nalezené MCP session (vèetnì loginu a práv)
		SELECT * INTO #w FROM [dbo].[wwwsession] WHERE wwwsession = @mcp_session;

		-- Modifikace provozních parametrù klonu pro potøeby aktuálního prohlížeèe
		UPDATE	#w 
		SET 
			wwwsession = @wwwsession,
			spid = @@SPID,                                      -- Napojení na aktuální bìžící PHP proces reportu
			request_date = GETDATE()                            -- Aktualizace èasu požadavku
		;

		-- Vložení kompletnì autorizované relace zpìt do ostré tabulky wwwsession
		INSERT INTO [dbo].[wwwsession] SELECT * FROM #w;

		DROP TABLE #w;

		COMMIT TRANSACTION;
	END

	-- ============================================================================
	-- 3. NÁVRAT ID PÙVODNÍ RELACE PRO PHP (ÚSPÌCH = ID, CHYBA = NULL)
	-- ============================================================================
	-- Pokud se relace nenašla, @mcp_session zùstalo NULL, což detekuje mcp_report.php
	SELECT @mcp_session AS llm_session;

END
GO
select * from wwwsession
begin tran
execute mcp_join_session_by_ip 'XY','127.0.0.1'
select * from wwwsession
rollback