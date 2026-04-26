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
CREATE PROCEDURE mcp_tool_user_by_name
	@lname NVARCHAR(MAX)
AS
BEGIN
	SET NOCOUNT ON;

	select ru.login
		,ru.sex, ru.lname, ru.fname
		,ru.right_login
		,ru.right_sysadmin
		,ru.datecreated
		,ru.email
		,ru.company_name
		,ru.removed
	from ramses_user ru
	where lname=@lname
END
GO
CREATE PROCEDURE mcp_tool_threat_impact
	@threat_id INT
AS
BEGIN
	SET NOCOUNT ON;

	-- TODO: Zde implementujte vaši SQL logiku
	-- Model AI oèekává, že procedura vrátí standardní SELECT (result set).

	SELECT 'Logika zatím není implementována' AS Status;
END
GO
-- test funkce
execute debuglogin 'mcp_server'
execute mcp_tool_user_by_name 'Hink'
GO
