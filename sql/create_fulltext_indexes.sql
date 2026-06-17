-- Index na aktivech

execute p_create_fulltext_catalog

execute p_create_missing_column 'crr_asset','fulltext_key','int identity not null constraint fulltext_key unique'


execute p_create_fulltext_index 'crr_asset','
name,
descrip'
,'fulltext_key'

execute p_create_fulltext_index 'mcp_scenario','
title,
intent ,
keywords'

execute p_create_fulltext_index 'repo_regulation','
builtin_code,
caption,shortname,
description_text,help_text'
GO
-- Fulltextové indexy na tøídách aktiv
execute p_create_missing_column 'l_ast_cls','fulltext_key','int identity not null'
execute sp_create_index 'l_ast_cls','fulltext_key','fulltext_key','unique'
execute p_create_fulltext_index 'l_ast_cls','name','fulltext_key'
execute p_create_fulltext_index 'crp_ast_cls','name'
