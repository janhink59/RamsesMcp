-- =========================================================================================
-- * RamsesMcp - create_report_metadata_tables.sql
-- *
-- * ARCHITEKTONICKÝ KONTEXT (PRO AI):
-- * Tento skript definuje databázové struktury pro ukládání metadat komplexních reportů.
-- * Tyto tabulky jsou nezávislé na popisech agentických workflow (mcp_scenario).
-- * Slouží jako zdroj pravdy pro flexible_report.php, který na základě zde definovaných
-- * datových typů provádí striktní typovou kontrolu (Type Casting) předaných parametrů.
-- *
-- * VZTAH K OSTATNÍM VRSTVÁM:
-- * mcp_scenario definuje textový návod pro AI, kdy má uživateli nabídnout odkaz na report.
-- * mcp_report (tato tabulka) definuje technický kontrakt mezi MCP serverem a jádrem Ramses.
-- =========================================================================================

if not exists(select * from v_syscolumns where tabname='mcp_report' and colname='report_code') begin
	execute dropni 'mcp_report_param'
	execute dropni 'mcp_report'
end

-- Tabulka definic samotných reportů

IF OBJECT_ID('mcp_report') IS NULL
CREATE TABLE mcp_report (
	report_code VARCHAR(50) CONSTRAINT pk_mcp_report PRIMARY KEY,
	title NVARCHAR(200) NOT NULL,
	description NVARCHAR(MAX) NULL
);
GO
-- Tabulka definic parametrů reportů pro typovou validaci
IF OBJECT_ID('mcp_report_param') IS NULL
CREATE TABLE mcp_report_param (
	report_code VARCHAR(50) NOT NULL CONSTRAINT fk_mcp_report_param_report REFERENCES mcp_report(report_code) ON DELETE CASCADE,
	param_name VARCHAR(100) NOT NULL,
	param_title NVARCHAR(100) NOT NULL DEFAULT '',
	param_type VARCHAR(50) NOT NULL CONSTRAINT chk_mcp_report_param_type CHECK (param_type IN ('bit', 'tinyint', 'smallint', 'int', 'bigint', 'string', 'date', 'datetime', 'uuid')),
	is_array BIT CONSTRAINT df_mcp_report_param_is_array DEFAULT 0 NOT NULL,
	description NVARCHAR(500) NULL,
	is_required BIT CONSTRAINT df_mcp_report_param_required DEFAULT 1 NOT NULL,
	CONSTRAINT pk_mcp_report_param PRIMARY KEY (report_code, param_name)
);
GO
