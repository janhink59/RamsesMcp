-- =========================================================================================
-- * RamsesMcp - create_report_metadata_tables.sql
-- *
-- * ARCHITEKTONICKÝ KONTEXT (PRO AI):
-- * Tento skript definuje databázové struktury pro ukládání metadat komplexních reportů.
-- =========================================================================================

-- Odstranění stávajících tabulek
IF OBJECT_ID('mcp_report_columns', 'U') IS NOT NULL
    DROP TABLE mcp_report_columns;
GO

IF OBJECT_ID('mcp_report_param', 'U') IS NOT NULL
    DROP TABLE mcp_report_param;
GO

IF OBJECT_ID('mcp_report', 'U') IS NOT NULL
    DROP TABLE mcp_report;
GO

-- Tabulka definic samotných reportů
CREATE TABLE mcp_report (
    report_code VARCHAR(50) CONSTRAINT pk_mcp_report PRIMARY KEY,
    procedure_name VARCHAR(128) NOT NULL,
    is_generic BIT DEFAULT 1 NOT NULL,
    more_results BIT DEFAULT 0 NOT NULL,
    title NVARCHAR(200) NOT NULL,
    description NVARCHAR(MAX) NULL,
    select_columns NVARCHAR(MAX) DEFAULT '*', -- Nový sloupec pro definici výběru (pouze pro Views)
    order_by NVARCHAR(MAX) NULL               -- Nový sloupec pro řazení (pouze pro Views)
);
GO

-- Tabulka definic parametrů reportů pro typovou validaci
CREATE TABLE mcp_report_param (
    report_code VARCHAR(50) NOT NULL,
    param_name VARCHAR(100) NOT NULL,
    param_title NVARCHAR(100) NOT NULL DEFAULT '',
    param_type VARCHAR(50) NOT NULL CONSTRAINT chk_mcp_report_param_type CHECK (param_type IN ('bit', 'tinyint', 'smallint', 'int', 'bigint', 'string', 'date', 'datetime', 'uuid')),
    is_array BIT DEFAULT 0 NOT NULL,
    description NVARCHAR(500) NULL,
    is_required BIT DEFAULT 1 NOT NULL,
    CONSTRAINT pk_mcp_report_param PRIMARY KEY (report_code, param_name)
);
GO

-- Tabulka pro aliasy sloupců (záhlaví reportů)
-- report_code = '' (prázdný řetězec) znamená globální výchozí záznam pro všechny reporty
CREATE TABLE mcp_report_columns (
    report_code VARCHAR(50) NOT NULL DEFAULT '',
    column_name VARCHAR(128) NOT NULL,
    header_title NVARCHAR(255) NOT NULL,
    CONSTRAINT pk_mcp_report_columns PRIMARY KEY (report_code, column_name)
);
GO