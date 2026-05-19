CREATE OR ALTER PROCEDURE [dbo].[mcp_tool_find_system_workflow]
	@keywords NVARCHAR(1000)
	,@top int=3
AS
/*
=========================================================================================
* RamsesMcp - mcp_tool_find_system_workflow (Agentic Workflow Navigator)
*
* ARCHITEKTONICKİ KONTEXT (PRO AI):
* Toto je hlavní "navigaèní" nástroj pro LLM modely (Ollama). 
* Slouí jako JIT (Just-In-Time) RAG. Místo toho, aby mìla AI v kontextovém oknì 
* naètené všechny manuály, zavolá tuto proceduru a ta jí vrátí ty nejrelevantnìjší.
*
* EXEKUCE A ROUTING:
* Procedura plnì zapadá do jmenné konvence "mcp_tool_*". 
* Volá se dynamicky z PHP skriptu McpGenericStoredProc, kterı si sám vezme její 
* vısledek (SELECT) a naformátuje ho do tokenovì úsporného TSV pro umìlou inteligenci.
*
* KLÍÈOVÉ PRINCIPY:
* 1. Šetøení tokenù: Procedura NIKDY nevrací sloupec "instructions" (plnı manuál).
* Vrací pouze metadata, aby si AI mohla vybrat a následnì zavolat konkrétní nástroj.
* 2. Full-Text Optimalizace: Pouívá FREETEXTTABLE s vloenım parametrem @top_n. 
* To zajišuje, e oøez (TOP 3) probìhne u uvnitø vyhledávacího enginu 
* pøed samotnım JOINem na fyzickou tabulku.
=========================================================================================
*/
BEGIN
	-- Vypnutí vracení poètu ovlivnìnıch øádkù (šetøí síovı traffic a brání zmatení PDO/sqlsrv ovladaèe)
	SET NOCOUNT ON;

	-- 1. Ochrana proti halucinacím a prázdnım dotazùm
	-- Pokud AI z nìjakého dùvodu nepošle klíèová slova, okamitì proceduru ukonèíme.
	-- Generickı PHP skript zachytí prázdnı vısledek a vrátí "ádná data nebyla nalezena."
	IF @keywords IS NULL OR LTRIM(RTRIM(@keywords)) = ''
	BEGIN
		RETURN;
	END

	-- 2. Nativní Full-Text Vyhledávání
	-- Prohledáváme sloupce title, intent a keywords.
	-- Èíslo '3' na konci parametru je interní @top_n pro FREETEXTTABLE.
	SELECT 
		m.scenario_code, 
		m.title, 
		m.intent, 
		m.when_to_use, 
		m.when_not_to_use
	FROM 
		[dbo].[mcp_scenario] m
	INNER JOIN 
		FREETEXTTABLE([dbo].[mcp_scenario], (title, intent, keywords), @keywords, @top) ft
			ON ft.[KEY] = m.scenario_code
	ORDER BY 
		ft.RANK DESC;

END
GO
