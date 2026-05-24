/* První řádek je zde kvůli detekci UTF-8 */

--drop table if exists mcp_tool_param
--drop table if exists mcp_tool
--drop table if exists mcp_log
--drop table if exists mcp_scenario

-- Tabulka nástrojů
if object_id('mcp_tool') is null
CREATE TABLE mcp_tool (
    mcp_tool UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    name VARCHAR(100) NOT NULL UNIQUE,
	title varchar(100) default '' not null,
	is_generic bit default 1 not null,
    description VARCHAR(MAX) NOT NULL
);

-- Tabulka parametrů
if object_id('mcp_tool_param') is null
CREATE TABLE mcp_tool_param (
    mcp_tool UNIQUEIDENTIFIER NOT NULL references mcp_tool on delete cascade,
    param_name VARCHAR(100) NOT NULL,
	param_title varchar(100) default '' not null,
    param_type VARCHAR(50) NOT NULL, -- "string", "number", "uuid"
    description VARCHAR(MAX),
    is_required BIT DEFAULT 1,
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
