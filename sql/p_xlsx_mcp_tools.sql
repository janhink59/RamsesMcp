execute dropni 'p_xlsx_mcp_tools'
GO
create procedure p_xlsx_mcp_tools
as

execute debuglogin 'mcp_server'

delete from mcp_tool_param
delete from mcp_tool

--select hashbytes('MD5',N'mcp_tool.'+i.name)
--	,i.*
--from XLSX_CONTENT_INFO$ i

insert into mcp_tool(mcp_tool,name,description)
select hashbytes('MD5',N'mcp_tool.'+t.name)
	,t.name
	,t.description
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
GO
begin tran

execute p_xlsx_mcp_tools

rollback
GO
