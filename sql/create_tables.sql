drop table if exists mcp_tool_params
drop table if exists mcp_tools

-- Tabulka nástrojù
CREATE TABLE mcp_tools (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(MAX) NOT NULL
);

-- Tabulka parametrù
CREATE TABLE mcp_tool_params (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    tool_id UNIQUEIDENTIFIER NOT NULL,
    param_name VARCHAR(100) NOT NULL,
    param_type VARCHAR(50) NOT NULL, -- "string", "number", "uuid"
    description VARCHAR(MAX),
    is_required BIT DEFAULT 1,
    CONSTRAINT fk_tool FOREIGN KEY (tool_id) REFERENCES mcp_tools(id) ON DELETE CASCADE
);

