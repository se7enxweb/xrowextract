<?php /* #?ini charset="utf-8"?

# Example named fetches (fetchalias.ini) the xrowextract "Named fetch" picker can apply: node, class, sort,
# depth, limit/offset, main-locations and a condition, read back into the export view exactly as applying
# any other fetchalias.ini alias does (see classes/xrowextractfetchalias.php). They also work from the
# command line: bin/php/csv.php --fetch-alias=xrowextract_recent_content --alias-param="parent_node_id=2,limit=20"

# Newest first, below a node you choose when applying it (Parameter[parent_node_id]) or fill in with
# --alias-param parent_node_id=<node>; the row limit is fillable the same way.
[xrowextract_recent_content]
Module=content
FunctionName=tree
Constant[sort_by]=modified;0
Parameter[parent_node_id]=parent_node_id
Parameter[limit]=limit

# Below a node, sorted by name; the condition is left to fill in via "Parameters" or --alias-param, e.g.
# attribute_filter=section;eq;5 (our own operator names) or attribute_filter=section;=;5 (the kernel's).
[xrowextract_by_section]
Module=content
FunctionName=tree
Constant[sort_by]=name;1
Parameter[parent_node_id]=parent_node_id
Parameter[attribute_filter]=attribute_filter

*/ ?>
