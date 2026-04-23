drop table if exists mcp_tool_param
drop table if exists mcp_tool
drop table if exists mcp_log

-- Tabulka nástrojù
CREATE TABLE mcp_tool (
    mcp_tool UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    name VARCHAR(100) NOT NULL UNIQUE,
	title varchar(100) default '' not null,
	is_generic bit default 1 not null,
    description VARCHAR(MAX) NOT NULL
);

-- Tabulka parametrù
CREATE TABLE mcp_tool_param (
    mcp_tool UNIQUEIDENTIFIER NOT NULL references mcp_tool on delete cascade,
    param_name VARCHAR(100) NOT NULL,
	param_title varchar(100) default '' not null,
    param_type VARCHAR(50) NOT NULL, -- "string", "number", "uuid"
    description VARCHAR(MAX),
    is_required BIT DEFAULT 1,
);

-- Tabulka pro logování MCP requestù a odpovìdí
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
