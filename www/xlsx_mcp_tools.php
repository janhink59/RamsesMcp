<?php
class xlsx_mcp_tools extends xlsx_abstract{

	function prepare()
	{
		$this->setDataType("name,title,description","varchar","mcp_tool")
		->setDataType("is_generic,more_results","bit")
				
		->setDataType("name,param_name,param_title,param_type,description","varchar","mcp_tool_param")
		->setDataType("is_required","bit")
				
		->setDataType("scenario_code,title,intent,keywords,when_to_use,when_not_to_use,instructions","varchar","mcp_scenario")
				
		->setDataType("report_code,title,procedure_name,description,select_columns,order_by","varchar","mcp_report")
		->setDataType("is_generic,more_results","bit")
 
		->setDataType("report_code,param_name,param_title,param_type,is_array,description,is_required","varchar","mcp_report_param")
 				
		->setDataType("select_code,free_text_description","varchar","mcp_select")
				
		->setDataType("report_code,name,header","varchar","mcp_report_columns");
		;
	}
}