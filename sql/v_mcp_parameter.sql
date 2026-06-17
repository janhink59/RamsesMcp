execute dropni 'v_mcp_tool_param'
GO
create view v_mcp_tool_param
as
select t.name tool_name
	,p.*
	,t.title
	,t.is_generic
from mcp_tool_param p
	join mcp_tool t on t.mcp_tool=p.mcp_tool
GO
select * from v_mcp_tool_param
