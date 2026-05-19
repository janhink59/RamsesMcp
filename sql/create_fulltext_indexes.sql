-- Index na aktivech

execute p_create_missing_column 'crr_asset','fulltext_key','int identity not null constraint fulltext_key unique'
execute p_create_fulltext_index 'crr_asset','fulltext_key','
name language 1029,
descrip language 1029'

execute p_create_fulltext_index 'mcp_scenario','pk_scenario_code','
title language 1029,
intent language 1029,
keywords language 1029'
