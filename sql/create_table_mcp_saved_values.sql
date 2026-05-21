drop table if exists mcp_saved_values
GO
-- =========================================================================================
-- * RamsesMcp - create_mcp_saved_values.sql
-- *
-- * ARCHITEKTONICKİ KONTEXT (PRO AI):
-- * Tato tabulka slouí jako "úschovna" (Claim Check pattern) pro vısledky analytickıch
-- * MCP nástrojù. Na základì inspirace typem tt_array umoòuje ukládat nejen
-- * skalární hodnoty, ale i celá pole (díky sloupci row_index).
-- * Kadı prvek pole (napø. seznam vyfiltrovanıch UUID) je uloen jako samostatnı záznam.
-- *
-- * BEZPEÈNOST A INTEGRITA:
-- * Sloupec save_as je chránìn CHECK constraintem, kterı povoluje VİHRADNÌ malá písmena,
-- * èíslice a podtrítko. Tím se absolutnì pøedchází problémùm s kompatibilitou pøi migraci
-- * na Case-Sensitive (CS) databázové instance a unifikuje se formát klíèù z orchestrátoru.
-- * Vypoèítané sloupce (uuid_value, bigint_value) usnadòují a extrémnì zrychlují
-- * typové JOINy uvnitø cílovıch procedur bez nutnosti parsování textu za bìhu.
-- =========================================================================================
CREATE TABLE mcp_saved_values (
	id INT IDENTITY(1,1) CONSTRAINT pk_mcp_saved_values PRIMARY KEY,
	wwwsession VARCHAR(50) NOT NULL CONSTRAINT fk_mcp_saved_values_session REFERENCES wwwsession(wwwsession) ON DELETE CASCADE,
	-- Maska '%[^a-z0-9_]%' detekuje jakıkoliv znak, kterı NENÍ malım písmenem, èíslicí nebo podtrítkem.
	-- NOT LIKE zajišuje, e takovı neplatnı znak se nesmí vyskytovat nikde v øetìzci.
	save_as VARCHAR(40) NOT NULL CONSTRAINT chk_mcp_saved_values_save_as CHECK (save_as NOT LIKE '%[^a-z0-9_]%'),
	row_index INT NOT NULL CONSTRAINT df_mcp_saved_values_row_index DEFAULT 0,
	saved_data NVARCHAR(200) NULL,
	
	-- Vypoèítané sloupce pro rychlé a bezpeèné napojení v cílovıch procedurách reportù
	uuid_value AS TRY_CONVERT(UNIQUEIDENTIFIER, saved_data),
	bigint_value AS TRY_CONVERT(BIGINT, saved_data),

	-- Zajištìní unikátnosti kadého indexu pole v rámci jedné promìnné a session.
	-- SQL Server nad tímto constraintem automaticky vytváøí index (wwwsession, save_as, row_index).
	-- Díky principu levého prefixu (Left-Prefix Rule) je tento index plnì dostaèující
	-- i pro hledání vıhradnì podle (wwwsession, save_as), take ádnı další index není nutnı.
	CONSTRAINT uq_mcp_saved_values_key UNIQUE (wwwsession, save_as, row_index)
);
GO
