
CREATE or alter PROCEDURE mcp_tool_organizations_for_user
	@login NVARCHAR(MAX)
AS
BEGIN
	SET NOCOUNT ON;

	select ru.login
		,o.name
		,o.organization_uuid organization_guid
		,ou.right_orgadmin
		,ou.right_reader
		,o.disabled oorganization_is_disabled
	from ramses_user ru
		join org_user ou on ou.ramses_user=ru.ramses_user
			and ou.disabled=0
		join organization o on o.organization=ou.organization
	where ru.login=@login
	order by 1,2

END
GO
