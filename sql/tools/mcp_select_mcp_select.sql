execute dropni 'mcp_select_mcp_select'
GO
/*
	Nástroj pro získání dostupných selectù
*/
create procedure mcp_select_mcp_select
	@free_text varchar(8000)='',
	@top_n int=10
as

set @free_text=isnull(@free_text,'')
declare @ft varchar(8000)=@free_text+' xyxy'

select * from mcp_select ms
	left outer join freetexttable(mcp_select,*,@ft) ft on ft.[KEY]=ms.select_code
where ft.[KEY] is not null or @free_text=''
order by ms.select_code
GO
--execute mcp_select_mcp_select 'dopady'
GO
