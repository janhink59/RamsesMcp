execute dropni 'mcp_filter_asset_class'
GO

/*
    Specifický filtr: asset_class
    ČISTĚ DATOVÁ VRSTVA: Pouze vrací data, veškerou logiku kolem 
    ukládání identifikátorů a zpráv pro LLM řeší PHP orchestrátor.
*/
CREATE PROCEDURE mcp_filter_asset_class
    @free_text varchar(8000) = NULL,
    @top_n INT = 10
AS
BEGIN
    SET NOCOUNT ON;

    declare @l varchar(2)
    declare @p bigint
    select @p=crp_profile, @l=language from dbsession s where s.spid=@@spid

    -- 1. Validace
    set @free_text=ISNULL(@free_text, '')

    -- NÁVRAT K VAŠÍ BEZPEČNÉ STRATEGII: @search nikdy nebude prázdný, což chrání FREETEXTTABLE před pádem
    declare @search varchar(8000) = @free_text + ' Nesmysl'

-- 1. Deklarace tabulkové proměnné včetně názvu (přesně podle vaší struktury)
DECLARE @direct_matches TABLE (
    crp_ast_cls BIGINT NOT NULL PRIMARY KEY,
    class_id INT NOT NULL,
    [name] NVARCHAR(255) NOT NULL, 
    parent_class_id INT NULL,
    [type] VARCHAR(1) NOT NULL,
    leaf VARCHAR(1) NULL,
    BaseRank INT NOT NULL
);

-- 2. Načtení všech dat jedním průchodem do paměti (včetně názvu)
INSERT INTO @direct_matches (crp_ast_cls, class_id, [name], parent_class_id, [type], leaf, BaseRank)
SELECT 
    ac.crp_ast_cls,
    ac.class_id,
    ac.[name], 
    ac.parent_class_id,
    ac.[type],
    ac.leaf,
    CASE 
        WHEN f.[KEY] IS NOT NULL THEN 
            CASE 
                WHEN ac.leaf = 'Y' AND f.[RANK] + 50 > 1000 THEN 1000
                WHEN ac.leaf = 'Y' THEN f.[RANK] + 50
                ELSE f.[RANK] 
            END
        ELSE 0 
    END AS BaseRank
FROM dbo.crp_ast_cls ac
    LEFT OUTER JOIN FREETEXTTABLE(dbo.crp_ast_cls, name, @search) f ON ac.crp_ast_cls = f.[KEY]
where ac.crp_profile=@p
;

-- 3. Rekurze – pouze směrem DOLŮ (na podřízené prvky)
;WITH Children AS (
    -- Kombinace vaší ochrany a rekurzivní podmínky:
    -- Pokud je text prázdný, 'Nesmysl' ve fulltextu nic nenašel (všechny řádky mají BaseRank = 0).
    -- Podmínka (@free_text = '') ale propustí VŠECHNY řádky z @direct_matches a natvrdo jim nastaví rank 1000.
    SELECT 
        crp_ast_cls, 
        class_id, 
        parent_class_id, 
        [type], 
        leaf, 
        CASE WHEN @free_text = '' THEN 1000 ELSE BaseRank END AS PropagatedRank
    FROM @direct_matches 
    WHERE (@free_text = '') OR (BaseRank > 0)

    UNION ALL

    -- Rekurzivní krok DOLŮ: hledáme potomky (ac.parent_class_id = c.class_id)
    SELECT 
        ac.crp_ast_cls, 
        ac.class_id, 
        ac.parent_class_id, 
        ac.[type], 
        ac.leaf, 
        (c.PropagatedRank * 80) / 100 
    FROM @direct_matches ac
    INNER JOIN Children c ON ac.parent_class_id = c.class_id AND ac.[type] = c.[type]
    WHERE (c.PropagatedRank * 80) / 100 >= 1
)

-- 4. Finální sestavení výsledků – dotazujeme přímo rekurzi Children
SELECT top(@top_n)
    dm.crp_ast_cls,
    dm.class_id,
    dm.[name], 
    dm.[type],
    dm.leaf,
    dm.parent_class_id,
    MAX(ch.PropagatedRank) AS FinalRank
FROM Children ch
INNER JOIN @direct_matches dm ON dm.crp_ast_cls = ch.crp_ast_cls
GROUP BY dm.crp_ast_cls, dm.class_id, dm.[name], dm.[type], dm.leaf, dm.parent_class_id
ORDER BY FinalRank desc, class_id;
END
GO
debuglogin 'hink'
execute mcp_filter_asset_class ''
GO
