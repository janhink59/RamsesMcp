drop procedure if exists mcp_tool_user_by_name
set nocount on
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
