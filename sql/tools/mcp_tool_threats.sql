drop procedure if exists mcp_tool_threats
set nocount on
GO
create procedure mcp_tool_threats
as
select f.threat_id, f.name, f.category_name, f.descrip description
from f_select_threat() f

GO
