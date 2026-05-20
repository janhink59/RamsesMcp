drop procedure if exists mcp_tool_get_user_context
GO

create procedure mcp_tool_get_user_context
as
select s.user_login
	,ru.fname first_name
	,ru.lname last_name
	,convert(varchar(40),s.organization_uuid) UUID
	,o.name organization_name
	,r.name review_name
	,r.language
from dbsession s
	join ramses_user ru on ru.ramses_user=s.ramses_user
	join organization o on o.organization=s.organization
	left outer join crr_review r on r.crr_review=s.crr_review
where spid=@@spid
GO
