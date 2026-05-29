/* První řádek je zde kvůli detekci UTF-8 */

--drop table if exists mcp_tool_param
--drop table if exists mcp_tool
--drop table if exists mcp_log
--drop table if exists mcp_scenario

execute p_drop_excel_tables
GO
-- Zde se odstraní tabulky, které byly změměny
if not exists(select * from v_syscolumns where tabname = 'mcp_tool' and colname='more_results')
	or not exists(select * from v_syscolumns where tabname = 'mcp_report' and colname='more_results')

begin
    execute dropni 'mcp_tool_param'
    execute dropni 'mcp_tool'
    execute dropni 'mcp_report_param'
    execute dropni 'mcp_report'
end
GO
-- Tabulka nástrojů
if object_id('mcp_tool') is null
CREATE TABLE mcp_tool (
    mcp_tool UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    name VARCHAR(100) NOT NULL UNIQUE,
	title varchar(100) default '' not null,
	is_generic bit default 1 not null,
    more_results bit default 0 not null,
    description VARCHAR(MAX) NOT NULL
);

-- Tabulka parametrů
if object_id('mcp_tool_param') is null
CREATE TABLE mcp_tool_param (
    mcp_tool UNIQUEIDENTIFIER NOT NULL /* references mcp_tool on delete cascade */,
    param_name VARCHAR(100) NOT NULL,
	param_title varchar(100) default '' not null,
    param_type VARCHAR(50) NOT NULL, -- "string", "number", "uuid"
    description VARCHAR(MAX),
    is_required BIT DEFAULT 1,
    primary key (mcp_tool, param_name)
);

-- Tabulka pro logování MCP requestů a odpovědí
if object_id('mcp_log') is null
CREATE TABLE mcp_log (
	log_id BIGINT IDENTITY(1,1) PRIMARY KEY,
	created_at DATETIME2 DEFAULT SYSDATETIME(),
	request_id VARCHAR(100),
	method VARCHAR(100),
	payload_in NVARCHAR(MAX),
	payload_out NVARCHAR(MAX),
	duration_ms INT,
	error_flag BIT DEFAULT 0
);

if object_id('mcp_scenario') is null
CREATE TABLE mcp_scenario (
    scenario_code VARCHAR(50) constraint pk_scenario_code PRIMARY KEY,
    title NVARCHAR(200) NOT NULL,
    intent NVARCHAR(500) NOT NULL,
    keywords NVARCHAR(500) NOT NULL,
    when_to_use NVARCHAR(500) NULL,
    when_not_to_use NVARCHAR(500) NULL,
    instructions NVARCHAR(MAX) NOT NULL
);
GO
IF OBJECT_ID('mcp_filter', 'U') is null
/*
	Tabulka mcp_filter
	Slouží jako číselník a metadata pro chytré filtry (Smart Tools).
	Data se plní primárně importem z Excelu (list mcp_filter).
*/
CREATE TABLE mcp_filter (
	filter_code				VARCHAR(100) NOT NULL PRIMARY KEY,	-- Unikátní identifikátor filtru (např. 'asset_class')
	free_text_description	NVARCHAR(MAX) NULL					-- Instrukce/popis pro LLM, co má uživatel zadat
);
GO
