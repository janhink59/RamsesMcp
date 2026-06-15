execute dropni 'p_xlsx_mcp_tools'
GO
/*
	Standardní import dat z Excelu (Knowledge Base)
	Zajišťuje plný import nástrojů, reportů, agentických scénářů a filtrů z jednoho zdroje.

	execute p_drop_excel_tables

*/
create procedure p_xlsx_mcp_tools
	@import_mode varchar(1)='1'
as

--execute debuglogin 'mcp_server'

-- Smazání původních dat (zajištění idempotence pro bezpečné opakované spouštění)
delete from mcp_filter
delete from mcp_tool_param
delete from mcp_tool
delete from mcp_scenario
delete from mcp_report_param
delete from mcp_report

-- =====================================================================
-- 1. IMPORT NÁSTROJŮ (MCP TOOLS)
-- =====================================================================
insert into mcp_tool(mcp_tool,name,title,description,is_generic,more_results)
select hashbytes('MD5',N'mcp_tool.'+t.name)
	,t.name
	,t.title
	,t.description
	,coalesce(t.is_generic,0)
	,coalesce(t.more_results,0)
from XLSX_mcp_tool$ t

insert into mcp_tool_param(mcp_tool,param_name,param_title,param_type,description,is_required)
select hashbytes('MD5',N'mcp_tool.'+p.name)
	,p.param_name
	,p.param_title
	,p.param_type
	,p.description
	,p.is_required
from XLSX_mcp_tool_param$ p
	join mcp_tool t on t.mcp_tool=hashbytes('MD5',N'mcp_tool.'+p.name)

-- Přidání globálního parametru 'save_as' ke všem generickým nástrojům
INSERT INTO mcp_tool_param (mcp_tool, param_name, param_title, param_type, description, is_required)
SELECT 
	t.mcp_tool, 
	'save_as', 
	'Uložit výsledek (Alias)', 
	'string', 
	'Volitelný parametr. Zadejte textový alias (pouze malá písmena, číslice a podtržítko), pokud chcete získaná data uložit do dočasné paměti místo jejich vypsání. Použijte, pokud se chystáte předat data jinému nástroji.', 
	0
FROM 
	mcp_tool t
WHERE 
	t.is_generic = 1
	AND NOT EXISTS (
		-- Ochrana proti duplicitnímu vložení
		SELECT 1 
		FROM mcp_tool_param p 
		WHERE p.mcp_tool = t.mcp_tool AND p.param_name = 'save_as'
	);

-- =====================================================================
-- 2. IMPORT REPORTŮ (MCP REPORTS)
-- =====================================================================
insert into mcp_report(report_code, title, procedure_name, description, is_generic, more_results)
select r.report_code
	,r.title
	,r.procedure_name
	,r.description
	,coalesce(r.is_generic,0)
	,coalesce(r.more_results,0)
from XLSX_mcp_report$ r

insert into mcp_report_param(report_code, param_name, param_title, param_type, is_array, description, is_required)
select p.report_code
	,p.param_name
	,p.param_title
	,p.param_type
	,p.is_array
	,p.description
	,p.is_required
from XLSX_mcp_report_param$ p
	join mcp_report r on r.report_code = p.report_code -- Ochrana referenční integrity

-- =====================================================================
-- 3. IMPORT SCÉNÁŘŮ (MCP SCENARIOS)
-- =====================================================================
insert into mcp_scenario(scenario_code, title, intent, keywords, when_to_use, when_not_to_use, instructions)
select s.scenario_code
	,s.title
	,s.intent
	,s.keywords
	,s.when_to_use
	,s.when_not_to_use
	,s.instructions
from XLSX_mcp_scenario$ s

-- =====================================================================
-- 4. IMPORT FILTRŮ (MCP FILTERS)
-- =====================================================================
insert into mcp_filter(filter_code, free_text_description)
select f.filter_code
	,f.free_text_description
from XLSX_mcp_filter$ f

GO
--begin tran

--execute p_xlsx_mcp_tools '1'

--rollback
GO
