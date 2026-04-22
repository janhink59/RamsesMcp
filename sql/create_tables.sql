drop table if exists mcp_tool_param
drop table if exists mcp_tool

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
