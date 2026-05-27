CREATE OR ALTER PROCEDURE [dbo].[mcp_tool_read_system_workflow]
	@scenario_code VARCHAR(50)
AS
BEGIN
	SET NOCOUNT ON;

	SELECT 
		instructions 
	FROM 
		[dbo].[mcp_scenario] 
	WHERE 
		scenario_code = @scenario_code;
END
GO
