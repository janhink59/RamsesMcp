execute dropni 'mcp_filter_review_list'
GO
create procedure mcp_filter_review_list
	@free_text varchar(max)='',
	@top_n int=0
as


select r.crr_review, r.name
	,coalesce(ru.right_revedit,0) i_am_editor
from dbsession s
	join crr_review r on r.organization=s.organization
	left outer join review_user ru on ru.crr_review=r.crr_review and ru.ramses_user=s.ramses_user
where s.spid=@@spid
return
GO
debuglogin 'hink'
execute mcp_filter_review_list
GO

