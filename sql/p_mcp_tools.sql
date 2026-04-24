/*
	Procedury z této dávky slouží k získání dat pro AI
*/
drop procedure if exists mcp_tool_threats
drop procedure if exists mcp_tool_threat_impact
drop procedure if exists mcp_tool_user_by_name
drop procedure if exists mcp_tool_threat_impact
GO
create procedure mcp_tool_threats
as
select f.threat_id, f.name, f.category_name, f.descrip description
from f_select_threat() f

GO
-- test funkce
execute debuglogin 'mcp_server'
execute mcp_tool_threats
GO
