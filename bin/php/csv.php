#!/usr/bin/env php
<?php
/**
 * One class as a CSV file, from the command line: everything the
 * xrowextract/csv view does (node or whole site, depth, main locations,
 * offset/limit, columns and their names, separator, line endings, quoting,
 * a preview), with the same code.
 *
 * Usage (from the installation root; ./console ext:xrowextract:csv runs it too):
 *   php extension/xrowextract/bin/php/csv.php --class=ng_article --node=2 --output=articles.csv
 *   php extension/xrowextract/bin/php/csv.php --class=image --scope=all --separator=';' --line-endings=win32
 *   php extension/xrowextract/bin/php/csv.php --class=4 --node=5 --columns=first_name,last_name,ezuser.email
 *   php extension/xrowextract/bin/php/csv.php --class=ng_article --node=2 --preview=10
 *   php extension/xrowextract/bin/php/csv.php --list-classes --node=2
 *   php extension/xrowextract/bin/php/csv.php --list-columns --class=ng_article
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array(
    'description'    => "Exports one class as a CSV file: below a node or the whole site, with the columns, names and format of your choice.",
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
) );
$script->startup();
$options = $script->getOptions(
    '[class:][node:][scope:][depth:][depth-operator:][main-only][offset:][limit:][columns:][add:][sets:][names:][separator:][line-endings:][unquoted]' .
    '[languages:][format:][date-field:][since:][before:][date:][section:][state:][visibility:][name:][where:][sort:][order:][sort2:][order2:]' .
    '[extended-filter:][extended-params:][fetch-alias:][alias-param:][preset:][param:][list-presets][show-preset:]' .
    '[output:][preview;][list-classes][list-columns][user:][progress-file:]',
    '',
    array(
        'class'        => 'Class id or identifier (required to export, unless --fetch-alias names one)',
        'node'         => 'Node id to export below (default: export.ini StartNodeID or the user placement)',
        'scope'        => 'node (default): below --node; all: every object of the class in the whole site',
        'depth'        => 'tree (default): the whole subtree; list: direct children only; or a whole number: exactly/at most/at least that many levels below --node, per --depth-operator',
        'depth-operator' => 'eq (default), le or ge — only with a numeric --depth',
        'main-only'    => 'Only main locations: an object with several locations is one row',
        'offset'       => 'Skip this many rows (default 0)',
        'limit'        => 'Take at most this many rows (default 0 = all)',
        'columns'      => 'Comma list of attribute identifiers and special column ids (default: every attribute of the class)',
        'add'          => 'Comma list of columns to add after the default or --columns ones (e.g. ezcontentobject.id,ezuser.email)',
        'sets'         => 'Comma list of column sets to add: identity, urls, publishing, location, migration',
        'names'        => 'Column names in the file: id=name,id=name (default: the identifier)',
        'separator'    => 'One character, or comma, semicolon, tab, pipe (default comma)',
        'line-endings' => 'win32/crlf, unix/lf (default), mac/cr',
        'unquoted'     => 'Do not quote cells (line breaks are removed; a separator in a value shifts the columns)',
        'languages'    => 'all (default) or a comma list of locales (eng-US,ger-DE): one row per object per language',
        'format'       => 'csv (default), json (an array of objects), xml',
        'date-field'   => 'The date the date filters use: modified (default), published, or a date attribute identifier',
        'since'        => 'Only objects dated on or after this: 2026-09-01, 2026-09-01 12:00, or 7d / 2w / 3m / 1y ago',
        'before'       => 'Only objects dated on or before this (same forms)',
        'date'         => 'A date range by name: today, 7, 30, 90, 365 (last days), future, past',
        'section'      => 'Only objects in this section (id or identifier)',
        'state'        => 'Only objects in this object state (id)',
        'visibility'   => 'visible or hidden',
        'name'         => 'Only objects whose name contains this',
        'sort'         => 'tree (default: as the node sorts), name, published, modified, priority, path, or an attribute identifier',
        'order'        => 'asc (default) or desc',
        'sort2'        => 'A second sort field, breaking ties in --sort (same choices, no tree)',
        'order2'       => 'asc (default) or desc, for --sort2',
        'where'        => 'One or more conditions: "<field> <op> <value>", several joined with " && " (and) or " || " (or) but not both in one value. ' .
                          'field: a class attribute, or name, published, modified, section, owner, priority, depth, class_identifier, node_id, contentobject_id, state. ' .
                          'op: contains, starts, eq (=), ne (!=), gt (>), lt (<), gte (>=), lte (<=), empty, filled, in, not_in (comma list), between, not_between ("A..B"), like, not_like (* wildcard)',
        'extended-filter' => 'An extendedattributefilter.ini id to chain with the language filter (e.g. an eztags filter); --list-columns lists the ids',
        'extended-params' => 'Its params as a JSON object, e.g. {"tag_id":12}',
        'fetch-alias'  => 'Apply a fetchalias.ini named fetch (Module=content, FunctionName tree/list/tree_count/list_count): its node, class, sort, depth, limit/offset, main-only and a simple condition, where they can be read back',
        'alias-param'  => 'Values for the named fetch\'s own Parameter[] entries (besides parent_node_id, which takes --node): key=value,key=value',
        'preset'       => 'Apply a saved export preset ("user:<id>" or "site:<id>"; --list-presets shows them): its whole definition — node, class, columns, languages, every filter, sort and output setting',
        'param'        => 'Values for the preset\'s own {placeholder} tokens (and, through it, an extended fetch alias\'s Parameter[] entries): key=value,key=value',
        'list-presets' => 'List the presets this login may see: your own, shared ones, and the site\'s',
        'show-preset'  => 'Print one preset\'s resolved definition (its own Extends chain followed, no --param applied) as JSON',
        'output'       => 'File to write (default: <node name>_export.csv, or <class>_all_export.csv for --scope=all); - for stdout',
        'preview'      => 'Print the first rows (default 10) as a table instead of writing a file',
        'list-classes' => 'List the classes with how many objects each has in the selection',
        'list-columns' => 'List the attributes of --class with datatype and meta information, and the special columns',
        'user'         => 'Export with the read access of this login (default: admin)',
        'progress-file' => 'Write {"done":n,"total":m,"phase":"<locale>"} to this path after every batch (for a background job)',
    )
);
$script->initialize();

$fail = function ( $message ) use ( $cli, $script )
{
    $cli->error( $message );
    $script->shutdown( 1 );
};

// Whose read access applies
$login = $options['user'] ? $options['user'] : 'admin';
$user = eZUser::fetchByName( $login );
if ( !$user instanceof eZUser )
    $fail( "No user with login $login (--user)." );
$user->loginCurrent();

$exportINI = eZINI::instance( 'export.ini' );
$siteINI = eZINI::instance();

// A named fetch (fetchalias.ini): its Constant values become defaults for the node, class, sort, depth,
// limit/offset, main-only and (where it can be read back) a condition — the same options below still work,
// but the alias's own values take over the moment it names one, exactly as applying it in the view does.
$aliasApplied = array(); $aliasUnknown = array(); $aliasValues = array(); $aliasFunction = '';
if ( $options['fetch-alias'] )
{
    $aliasDefinition = XrowExtractFetchAlias::find( $options['fetch-alias'], '' );
    if ( !$aliasDefinition )
        $fail( "No named fetch {$options['fetch-alias']} for content tree/list/tree_count/list_count (--fetch-alias)." );
    $aliasParamOverrides = array();
    if ( $options['alias-param'] )
    {
        foreach ( explode( ',', $options['alias-param'] ) as $pair )
        {
            $pair = trim( $pair );
            if ( $pair === '' || strpos( $pair, '=' ) === false )
                continue;
            list( $pKey, $pValue ) = array_map( 'trim', explode( '=', $pair, 2 ) );
            if ( $pKey !== '' )
                $aliasParamOverrides[$pKey] = $pValue;
        }
    }
    $aliasResult = XrowExtractFetchAlias::apply( $aliasDefinition, $options['node'] ? (int)$options['node'] : 0, $aliasParamOverrides );
    $aliasApplied = $aliasResult['applied'];
    $aliasUnknown = $aliasResult['unknown'];
    $aliasValues = $aliasResult['values'];
    $aliasFunction = $aliasDefinition['function'];
    $cli->output( 'Named fetch ' . $options['fetch-alias'] . ': ' . ( $aliasApplied ? implode( '; ', $aliasApplied ) : 'nothing recognised' ) );
    foreach ( $aliasUnknown as $note )
        $cli->output( '  not understood: ' . $note );
    $aliasFillable = XrowExtractFetchAlias::fillableParameters( $aliasDefinition );
    if ( $aliasFillable )
        $cli->output( '  fillable with --alias-param: ' . implode( ', ', $aliasFillable ) );
}

$allowAllPresets = eZUser::currentUser()->hasAccessTo( 'xrowextract', 'all_jobs' );
$allowAllPresets = $allowAllPresets['accessWord'] !== 'no';
if ( $options['list-presets'] )
{
    foreach ( XrowExtractPreset::fetchVisible( $login, $allowAllPresets ) as $preset )
        $cli->output( sprintf( '  %-28s %-8s %-32s %s', $preset['ref'], $preset['site'] ? 'site' : ( $preset['shared'] ? 'shared' : 'private' ), $preset['name'], $preset['description'] ) );
    $script->shutdown( 0 );
}
if ( $options['show-preset'] )
{
    $resolvedShow = XrowExtractPreset::resolve( $options['show-preset'] );
    if ( $resolvedShow['error'] !== '' )
        $fail( "--show-preset: {$resolvedShow['error']}" );
    $cli->output( 'Extends chain: ' . implode( ' -> ', $resolvedShow['chain'] ) );
    $cli->output( json_encode( $resolvedShow['definition'], JSON_PRETTY_PRINT ) );
    if ( $resolvedShow['placeholders'] )
        $cli->output( 'Placeholders: ' . json_encode( $resolvedShow['placeholders'] ) );
    $script->shutdown( 0 );
}

// A saved export preset: its whole definition (node, class, columns, languages, every filter, sort,
// output setting) — the CLI options below still work; an explicit one is used instead of what the preset
// says for that specific piece, the same way --fetch-alias's own options already behave.
$presetDef = array();
$presetParamOverrides = array();
if ( $options['preset'] )
{
    if ( $options['param'] )
    {
        foreach ( explode( ',', $options['param'] ) as $pair )
        {
            $pair = trim( $pair );
            if ( $pair === '' || strpos( $pair, '=' ) === false )
                continue;
            list( $pKey, $pValue ) = array_map( 'trim', explode( '=', $pair, 2 ) );
            if ( $pKey !== '' )
                $presetParamOverrides[$pKey] = $pValue;
        }
    }
    $resolvedForRun = XrowExtractPreset::resolve( $options['preset'], $options['node'] ? (int)$options['node'] : 0, $presetParamOverrides );
    if ( $resolvedForRun['error'] !== '' )
        $fail( "--preset: {$resolvedForRun['error']}" );
    $filledForRun = XrowExtractPreset::fillPlaceholders( $resolvedForRun['definition'], $resolvedForRun['placeholders'], $presetParamOverrides );
    $presetDef = $filledForRun['definition'];
    if ( $filledForRun['unresolved'] )
        $cli->output( 'Preset placeholders with no value: ' . implode( ', ', $filledForRun['unresolved'] ) );
    $presetValues = array();
    if ( isset( $presetDef['subtree'] ) )
        $presetValues['parent_node_id'] = $presetDef['subtree'];
    if ( !empty( $presetDef['class_identifier'] ) )
    {
        $presetClassForRun = eZContentClass::fetchByIdentifier( $presetDef['class_identifier'] );
        if ( $presetClassForRun instanceof eZContentClass )
            $presetValues['class_id'] = (int)$presetClassForRun->attribute( 'id' );
    }
    if ( !isset( $presetValues['class_id'] ) && isset( $presetDef['class_id'] ) )
        $presetValues['class_id'] = (int)$presetDef['class_id'];
    if ( isset( $presetDef['sort_field'] ) && $presetDef['sort_field'] !== 'tree' )
    {
        $presetValues['sort_by'] = array( $presetDef['sort_field'], !isset( $presetDef['sort_ascending'] ) || (bool)$presetDef['sort_ascending'] );
        if ( !empty( $presetDef['sort_field2'] ) )
            $presetValues['sort_by'] = array( $presetValues['sort_by'], array( $presetDef['sort_field2'], !isset( $presetDef['sort_ascending2'] ) || (bool)$presetDef['sort_ascending2'] ) );
    }
    if ( isset( $presetDef['filters']['depth_mode'] ) && $presetDef['filters']['depth_mode'] !== 'any' )
    {
        $presetDepthModeMap = array( 'exact' => 'eq', 'atmost' => 'le', 'atleast' => 'ge' );
        if ( isset( $presetDepthModeMap[$presetDef['filters']['depth_mode']] ) )
        {
            $presetValues['depth'] = (int)$presetDef['filters']['depth_value'];
            $presetValues['depth_operator'] = $presetDepthModeMap[$presetDef['filters']['depth_mode']];
        }
    }
    if ( isset( $presetDef['limit'] ) )
        $presetValues['limit'] = (int)$presetDef['limit'];
    if ( isset( $presetDef['offset'] ) )
        $presetValues['offset'] = (int)$presetDef['offset'];
    if ( isset( $presetDef['mainnodeonly'] ) )
        $presetValues['main_node_only'] = (string)$presetDef['mainnodeonly'] === '1';
    // An explicit CLI option (already read into $aliasValues by --fetch-alias, if any) still wins over
    // the preset for the same piece; the preset only fills what neither --fetch-alias nor a plain option gave
    $aliasValues = array_merge( $presetValues, $aliasValues );
}

// Selection
$scope = $options['scope'] === 'all' ? 'all' : 'node';
if ( $options['scope'] && !in_array( $options['scope'], array( 'node', 'all' ), true ) )
    $fail( '--scope is node or all.' );
$nodeID = isset( $aliasValues['parent_node_id'] ) ? $aliasValues['parent_node_id']
        : ( $options['node'] ? (int)$options['node']
          : (int)( $exportINI->variable( 'ExportSettings', 'StartNodeID' ) ?: $siteINI->variable( 'UserSettings', 'DefaultUserPlacement' ) ) );
$depthOption = $options['depth'];
if ( $depthOption === '' && $aliasFunction !== '' )
    $depthOption = in_array( $aliasFunction, array( 'list', 'list_count' ), true ) ? 'list' : 'tree';
if ( $depthOption === 'list' )
{
    $depth = 1;
    $depthOperator = 'eq';
}
elseif ( $depthOption && $depthOption !== 'tree' )
{
    if ( !ctype_digit( (string)$depthOption ) )
        $fail( '--depth is tree, list, or a whole number (the depth below --node).' );
    $depth = (int)$depthOption;
    $depthOperator = $options['depth-operator'] ? $options['depth-operator'] : 'eq';
    if ( !in_array( $depthOperator, array( 'eq', 'le', 'ge' ), true ) )
        $fail( '--depth-operator is eq, le or ge.' );
}
elseif ( isset( $aliasValues['depth'] ) )
{
    $depth = $aliasValues['depth'];
    $depthOperator = in_array( $aliasValues['depth_operator'], array( 'eq', 'le', 'ge' ), true ) ? $aliasValues['depth_operator'] : 'le';
}
else
{
    $depth = false;
    $depthOperator = false;
}
$mainOnly = isset( $aliasValues['main_node_only'] ) ? (bool)$aliasValues['main_node_only'] : (bool)$options['main-only'];
$fetchNode = $nodeID;
if ( $scope === 'all' )
{
    $fetchNode = 1;
    $depth = false;
    $depthOperator = false;
    $mainOnly = true;
}
else
{
    $node = eZContentObjectTreeNode::fetch( $nodeID );
    if ( !$node instanceof eZContentObjectTreeNode || !$node->canRead() )
        $fail( "Node $nodeID does not exist or $login may not read it (--node)." );
}
$offset = isset( $aliasValues['offset'] ) ? $aliasValues['offset'] : max( 0, (int)$options['offset'] );
$limit = isset( $aliasValues['limit'] ) ? $aliasValues['limit'] : max( 0, (int)$options['limit'] );

// Languages: all, or the given locales, in the order of the content languages (site default first)
$contentLanguages = array_keys( XrowExtractColumns::contentLanguages() );
if ( !$options['languages'] && isset( $presetDef['languages'] ) && is_array( $presetDef['languages'] ) && $presetDef['languages'] )
    $languages = array_values( array_intersect( $contentLanguages, $presetDef['languages'] ) );
elseif ( !$options['languages'] || $options['languages'] === 'all' )
    $languages = $contentLanguages;
else
{
    $languages = array_values( array_filter( array_map( 'trim', explode( ',', $options['languages'] ) ) ) );
    foreach ( $languages as $locale )
        if ( !in_array( $locale, $contentLanguages, true ) )
            $fail( "Unknown language $locale (--languages). Languages: " . implode( ', ', $contentLanguages ) . '.' );
}
// Filters, as in the view
$filterValues = array();
$dateMode = 'any';
if ( $options['since'] && $options['before'] )
    $dateMode = 'between';
elseif ( $options['since'] )
    $dateMode = 'since';
elseif ( $options['before'] )
    $dateMode = 'before';
if ( $options['date'] )
{
    if ( !in_array( $options['date'], array( 'today', '7', '30', '90', '365', 'future', 'past' ), true ) )
        $fail( '--date is today, 7, 30, 90, 365, future or past.' );
    $dateMode = $options['date'];
}
foreach ( array( 'since', 'before' ) as $dateOption )
    if ( $options[$dateOption] && XrowExtractFilters::timestamp( $options[$dateOption] ) === false )
        $fail( "Cannot read the date in --$dateOption: {$options[$dateOption]}" );
$filterValues['date_mode'] = $dateMode;
$filterValues['date_from'] = $options['since'] ? date( 'Y-m-d H:i:s', XrowExtractFilters::timestamp( $options['since'] ) ) : '';
$filterValues['date_to'] = $options['before'] ? date( 'Y-m-d H:i:s', XrowExtractFilters::timestamp( $options['before'], true ) ) : '';
$filterValues['date_field'] = $options['date-field'] ? $options['date-field'] : 'modified';
if ( $options['section'] )
{
    $section = ctype_digit( (string)$options['section'] ) ? eZSection::fetch( (int)$options['section'] ) : eZSection::fetchByIdentifier( $options['section'] );
    if ( !$section )
        $fail( "No section {$options['section']} (--section)." );
    $filterValues['section'] = (int)$section->attribute( 'id' );
}
$filterValues['state'] = (int)$options['state'];
if ( $options['visibility'] && !in_array( $options['visibility'], array( 'visible', 'hidden' ), true ) )
    $fail( '--visibility is visible or hidden.' );
$filterValues['visibility'] = $options['visibility'] ? $options['visibility'] : 'any';
$filterValues['name'] = (string)$options['name'];
$filterValues['conditions'] = array();
if ( $options['where'] )
{
    $whereText = $options['where'];
    $hasAnd = strpos( $whereText, ' && ' ) !== false;
    $hasOr = strpos( $whereText, ' || ' ) !== false;
    if ( $hasAnd && $hasOr )
        $fail( '--where: a single value cannot mix " && " and " || " (the fetch has one join for the whole condition set).' );
    $join = $hasOr ? 'or' : 'and';
    $pieces = preg_split( $hasOr ? '/\s*\|\|\s*/' : '/\s*&&\s*/', $whereText );
    $opAlt = 'not_between|between|not_in|in|not_like|like|contains|starts|empty|filled|gte|lte|eq|ne|gt|lt|>=|<=|!=|=|>|<';
    $symbolMap = array( '=' => 'eq', '!=' => 'ne', '>' => 'gt', '<' => 'lt', '>=' => 'gte', '<=' => 'lte' );
    foreach ( $pieces as $piece )
    {
        if ( trim( $piece ) === '' )
            continue;
        if ( !preg_match( '/^\s*([A-Za-z0-9_]+)\s+(not\s+)?(' . $opAlt . ')\s*(.*)$/', $piece, $m ) )
            $fail( "--where: \"$piece\" is not \"<field> [not] <op> [value]\". op: contains, starts, eq, ne, gt, lt, gte, lte, empty, filled, in, not_in, between, not_between, like, not_like." );
        $field = $m[1];
        $negate = $m[2] !== '';
        $op = isset( $symbolMap[$m[3]] ) ? $symbolMap[$m[3]] : $m[3];
        $value = trim( $m[4], " \"'" );
        $value2 = '';
        if ( in_array( $op, XrowExtractFilters::twoValueOperators(), true ) )
        {
            $dotPos = strpos( $value, '..' );
            if ( $dotPos === false )
                $fail( "--where: \"$piece\": $op needs \"A..B\"." );
            $value2 = trim( substr( $value, $dotPos + 2 ) );
            $value = trim( substr( $value, 0, $dotPos ) );
        }
        $filterValues['conditions'][] = array( 'field' => $field, 'op' => $op, 'value' => $value, 'value2' => $value2, 'negate' => $negate );
    }
    $filterValues['conditions_join'] = $join;
}
if ( isset( $aliasValues['condition'] ) )
{
    // The named fetch's own condition, folded in the same way the view folds it
    $filterValues['conditions'] = array( array_merge( array( 'value2' => '' ), $aliasValues['condition'] ) );
    $filterValues['conditions_join'] = 'and';
}
if ( $options['extended-filter'] )
{
    if ( !array_key_exists( $options['extended-filter'], XrowExtractFilters::extendedFilters() ) )
        $fail( "--extended-filter: {$options['extended-filter']} is not registered in extendedattributefilter.ini." );
    $filterValues['extended_filter'] = $options['extended-filter'];
    if ( $options['extended-params'] )
    {
        json_decode( $options['extended-params'], true );
        if ( json_last_error() !== JSON_ERROR_NONE )
            $fail( '--extended-params is not valid JSON.' );
        $filterValues['extended_params'] = $options['extended-params'];
    }
}
// A preset's own filters, used wholesale when no filter option was explicitly given (an explicit one,
// same as --fetch-alias's own options, is used instead of what the preset says)
$explicitFilterOption = $options['since'] || $options['before'] || $options['date'] || $options['section']
                       || $options['state'] || $options['visibility'] || $options['name'] || $options['where'] || $options['extended-filter'];
if ( !$explicitFilterOption && isset( $presetDef['filters'] ) && is_array( $presetDef['filters'] ) )
    $filterValues = $presetDef['filters'];
$filters = new XrowExtractFilters( $filterValues );
$attributeFilter = false;   // set once the class is known

$countIn = function ( $classID, $locale = false ) use ( $fetchNode, $depth, $depthOperator, $mainOnly, &$attributeFilter, $filters )
{
    $result = eZContentFunctionCollection::fetchObjectTreeCount( $fetchNode, $locale !== false, $locale, 'include', array( (int)$classID ),
                                                                 $attributeFilter === false ? $filters->attributeFilter( false ) : $attributeFilter,
                                                                 $depth, $depthOperator, true, false, $mainOnly,
                                                                 $locale !== false ? XrowExtractTranslationFilter::chainedParams( $locale, $filters->values['extended_filter'], $filters->extendedParamsArray() ) : false, false );
    return isset( $result['result'] ) ? (int)$result['result'] : 0;
};

if ( $options['list-classes'] )
{
    $cli->output( $scope === 'all' ? 'Classes in the whole site:' : "Classes below node $nodeID:" );
    foreach ( eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, true, false, array( 'name' => 'asc' ) ) as $class )
    {
        $count = $countIn( $class->attribute( 'id' ) );
        if ( $count > 0 )
        {
            $perLanguage = array();
            foreach ( $languages as $locale )
                $perLanguage[] = $locale . ' ' . $countIn( $class->attribute( 'id' ), $locale );
            $cli->output( sprintf( '  %5d  %-32s %-40s %d objects  (%s)', $class->attribute( 'id' ), $class->attribute( 'identifier' ), $class->attribute( 'name' ), $count, implode( ', ', $perLanguage ) ) );
        }
    }
    $script->shutdown( 0 );
}

// The class: --class, or the named fetch's own class_id / class_filter_array (its first class)
$classOption = $options['class'];
if ( !$classOption && isset( $aliasValues['class_id'] ) )
    $classOption = $aliasValues['class_id'];
elseif ( !$classOption && isset( $aliasValues['class_filter_array'] ) && $aliasValues['class_filter_array'] )
    $classOption = reset( $aliasValues['class_filter_array'] );
if ( !$classOption )
    $fail( 'Missing --class (id or identifier). --list-classes shows them.' );
$class = ctype_digit( (string)$classOption ) ? eZContentClass::fetch( (int)$classOption ) : eZContentClass::fetchByIdentifier( $classOption );
if ( !$class instanceof eZContentClass )
    $fail( "No class $classOption (--class)." );
$classID = (int)$class->attribute( 'id' );
$conditionFields = array_merge( XrowExtractFilters::classFields( $classID ), XrowExtractFilters::objectFields() );
foreach ( $filters->values['conditions'] as $condition )
{
    if ( $condition['field'] !== '' && !array_key_exists( $condition['field'], $conditionFields ) )
        $fail( "--where: {$condition['field']} is not a class attribute of " . $class->attribute( 'identifier' ) . ' or a known object field.' );
}
$attributeFilter = $filters->attributeFilter( $class->attribute( 'identifier' ) );
$meta = XrowExtractColumns::attributeMeta( $classID );
$extras = XrowExtractColumns::extraAttributes();
$allowHash = XrowExtractColumns::allowPasswordHash();

if ( $options['list-columns'] )
{
    $cli->output( 'Attributes of ' . $class->attribute( 'identifier' ) . ' (' . $class->attribute( 'name' ) . '):' );
    foreach ( XrowExtractColumns::classColumns( $classID ) as $column )
    {
        $m = $meta[$column['id']];
        $flags = array_filter( array( $m['required'] ? 'required' : '', $m['searchable'] ? 'searchable' : '',
                                      $m['translatable'] ? '' : 'not translatable', $m['collector'] ? 'information collector' : '' ) );
        $cli->output( sprintf( '  %-32s %-26s %-22s %s%s', $column['id'], $m['datatype_name'] . ' (' . $m['datatype'] . ')',
                               $m['exportable'] ? '-> ' . $m['cell'] : 'EMPTY: no export handler', $column['name'], $flags ? '  [' . implode( ', ', $flags ) . ']' : '' ) );
    }
    $formats = XrowExtractCatalogue::formatColumns( $classID );
    if ( $formats )
    {
        $cli->output( 'Attribute formats (identifier:format):' );
        foreach ( $formats as $id => $column )
            $cli->output( sprintf( '  %-36s %s', $id, $column['name'] ) );
    }
    foreach ( XrowExtractCatalogue::groups() as $group => $label )
    {
        $cli->output( 'Special columns, ' . $label . ':' );
        foreach ( $extras as $id => $column )
        {
            if ( $column['group'] === $group )
                $cli->output( sprintf( '  %-36s %-30s %s', $id, '-> ' . $meta[$id]['cell'], $column['name'] ) );
        }
    }
    $cli->output( 'Column sets (--sets):' );
    foreach ( XrowExtractCatalogue::columnSets() as $id => $set )
        $cli->output( sprintf( '  %-12s %s', $id, $set[1] ) );
    $cli->output( 'Object fields a condition (--where) can also use, besides a class attribute:' );
    foreach ( XrowExtractFilters::objectFields() as $id => $field )
        $cli->output( sprintf( '  %-20s %s', $id, $field['name'] ) );
    $cli->output( 'Extended attribute filters (--extended-filter):' );
    foreach ( XrowExtractFilters::extendedFilters() as $id => $label )
        $cli->output( '  ' . $label );
    $script->shutdown( 0 );
}

// Columns: the class attributes, or the given list; then added ones; then names
$columns = array();
$byId = array();
foreach ( XrowExtractColumns::classColumns( $classID ) as $column )
    $byId[$column['id']] = $column;
foreach ( $extras as $id => $column )
    $byId[$id] = $column;
foreach ( XrowExtractCatalogue::formatColumns( $classID ) as $id => $column )
    $byId[$id] = $column;
$presetColumns = ( !$options['columns'] && isset( $presetDef['attributes'] ) && is_array( $presetDef['attributes'] ) && $presetDef['attributes'] ) ? $presetDef['attributes'] : false;
if ( $presetColumns )
{
    foreach ( $presetColumns as $presetColumn )
    {
        if ( !isset( $presetColumn['id'] ) || !isset( $byId[$presetColumn['id']] ) )
            continue; // a stale preset column (a renamed/removed attribute): skipped rather than failing the export
        $columns[] = array( 'id' => $presetColumn['id'], 'name' => isset( $presetColumn['name'] ) ? $presetColumn['name'] : $presetColumn['id'],
                            'exportname' => isset( $presetColumn['exportname'] ) && $presetColumn['exportname'] !== '' ? $presetColumn['exportname'] : $presetColumn['id'] );
    }
    if ( !$columns )
        $fail( 'The preset\'s own columns no longer exist on this class; pass --columns explicitly.' );
}
else
{
$wanted = $options['columns'] ? array_filter( array_map( 'trim', explode( ',', $options['columns'] ) ) )
                               : array_map( function ( $c ) { return $c['id']; }, XrowExtractColumns::classColumns( $classID ) );
if ( $options['sets'] )
{
    foreach ( array_filter( array_map( 'trim', explode( ',', $options['sets'] ) ) ) as $setID )
    {
        if ( !array_key_exists( $setID, XrowExtractCatalogue::columnSets() ) )
            $fail( "Unknown column set $setID (--sets). Sets: " . implode( ', ', array_keys( XrowExtractCatalogue::columnSets() ) ) . '.' );
        $wanted = array_merge( $wanted, XrowExtractCatalogue::setColumnIDs( $setID, $classID ) );
    }
}
if ( $options['add'] )
    $wanted = array_merge( $wanted, array_filter( array_map( 'trim', explode( ',', $options['add'] ) ) ) );
$wanted = array_values( array_unique( $wanted ) );
foreach ( $wanted as $id )
{
    if ( !isset( $byId[$id] ) )
        $fail( "Unknown column $id for class " . $class->attribute( 'identifier' ) . '. --list-columns shows them.' );
    $columns[] = $byId[$id];
}
}
if ( $options['names'] )
{
    foreach ( explode( ',', $options['names'] ) as $pair )
    {
        list( $id, $name ) = array_pad( array_map( 'trim', explode( '=', $pair, 2 ) ), 2, '' );
        foreach ( $columns as $i => $column )
        {
            if ( $column['id'] === $id && $name !== '' )
                $columns[$i]['exportname'] = $name;
        }
    }
}
if ( !$columns )
    $fail( 'No columns.' );
if ( count( $languages ) > 1 && !in_array( 'ezcontentobject.language', array_map( function ( $c ) { return $c['id']; }, $columns ), true ) )
    array_unshift( $columns, $extras['ezcontentobject.language'] );

// Format: an explicit option wins; else the preset's own, if it has one; else the usual default
$separators = array( 'comma' => ',', 'semicolon' => ';', 'tab' => "\t", '\t' => "\t", 'pipe' => '|' );
$separatorOption = $options['separator'] !== null && $options['separator'] !== false ? (string)$options['separator']
                  : ( isset( $presetDef['separator'] ) ? (string)$presetDef['separator'] : '' );
$separator = $separatorOption === '' ? ',' : $separatorOption;
$separator = isset( $separators[$separator] ) ? $separators[$separator] : $separator;
if ( strlen( $separator ) !== 1 || strpbrk( $separator, "\"\r\n" ) !== false )
    $fail( '--separator is one character (not a quote or line break), or comma, semicolon, tab, pipe.' );
$lines = array( 'win32' => "\r\n", 'crlf' => "\r\n", 'windows' => "\r\n", 'unix' => "\n", 'lf' => "\n", 'mac' => "\r", 'cr' => "\r" );
$lineKey = $options['line-endings'] ? strtolower( $options['line-endings'] )
         : ( isset( $presetDef['line_separator'] ) ? strtolower( $presetDef['line_separator'] ) : 'unix' );
if ( !isset( $lines[$lineKey] ) )
    $fail( '--line-endings is win32 (crlf), unix (lf) or mac (cr).' );
$newLine = $lines[$lineKey];
$outputFormat = $options['format'] ? $options['format'] : ( isset( $presetDef['output_format'] ) ? $presetDef['output_format'] : 'csv' );
if ( !XrowExtractWriter::isFormat( $outputFormat ) )
    $fail( "Unknown format $outputFormat (--format). Formats: " . implode( ', ', array_keys( XrowExtractWriter::formats() ) ) . '.' );
$unquoted = $options['unquoted'] || ( !$options['separator'] && isset( $presetDef['escape'] ) && !$presetDef['escape'] );
$parser = new ParserInterface( $separator, !$unquoted );

// Rows, in batches with the object cache cleared
$previewRows = $options['preview'] !== null && $options['preview'] !== false ? max( 1, (int)( $options['preview'] === true ? 10 : $options['preview'] ) ) : 0;
$sortBy = array( 'name', true );
if ( $scope === 'node' )
{
    $sort = $node->sortArray();
    $sortBy = $sort[0];
}
if ( $options['order'] && !in_array( $options['order'], array( 'asc', 'desc' ), true ) )
    $fail( '--order is asc or desc.' );
if ( $options['order2'] && !in_array( $options['order2'], array( 'asc', 'desc' ), true ) )
    $fail( '--order2 is asc or desc.' );
$sortOption = $options['sort'];
$orderOption = $options['order'];
$sort2Option = $options['sort2'];
$order2Option = $options['order2'];
if ( isset( $aliasValues['sort_by'] ) )
{
    $aliasSort = $aliasValues['sort_by'];
    $aliasSortPairs = ( isset( $aliasSort[0] ) && is_array( $aliasSort[0] ) ) ? $aliasSort : array( $aliasSort );
    $sortOption = isset( $aliasSortPairs[0][0] ) ? $aliasSortPairs[0][0] : '';
    $orderOption = ( isset( $aliasSortPairs[0][1] ) && $aliasSortPairs[0][1] ) ? 'asc' : 'desc';
    $sort2Option = isset( $aliasSortPairs[1][0] ) ? $aliasSortPairs[1][0] : '';
    $order2Option = ( isset( $aliasSortPairs[1][1] ) && $aliasSortPairs[1][1] ) ? 'asc' : 'desc';
}
if ( $sortOption && $sortOption !== 'tree' )
{
    if ( !array_key_exists( $sortOption, XrowExtractFilters::sortFields() ) && !array_key_exists( $sortOption, XrowExtractFilters::classFields( $classID ) ) )
        $fail( "--sort: $sortOption is not a sort field or a sortable attribute of " . $class->attribute( 'identifier' ) . '.' );
    $sortBy = XrowExtractFilters::sortParam( $sortOption, $orderOption !== 'desc', $classID, $class->attribute( 'identifier' ), $sortBy );
}
if ( $sort2Option )
{
    if ( !array_key_exists( $sort2Option, XrowExtractFilters::sortFields() ) && !array_key_exists( $sort2Option, XrowExtractFilters::classFields( $classID ) ) )
        $fail( "--sort2: $sort2Option is not a sort field or a sortable attribute of " . $class->attribute( 'identifier' ) . '.' );
    $sortBy = XrowExtractFilters::combineSort( $sortBy, XrowExtractFilters::sortParam( $sort2Option, $order2Option !== 'desc', $classID, $class->attribute( 'identifier' ), $sortBy ) );
}
$languageCounts = array();
foreach ( $languages as $locale )
    $languageCounts[$locale] = $countIn( $classID, $locale );
$total = array_sum( $languageCounts );
$wantRows = max( 0, $total - $offset );
if ( $limit )
    $wantRows = min( $wantRows, $limit );
if ( $previewRows )
    $wantRows = min( $wantRows, $previewRows );

$writer = new XrowExtractWriter( $previewRows ? 'csv' : $outputFormat, $columns, $separator, !$unquoted, $previewRows ? "\n" : $newLine,
                                 array( 'class' => $class->attribute( 'identifier' ), 'created' => date( 'c' ) ) );
$parser = $writer->parser();
$file = $options['output'];
if ( !$previewRows && !$file )
    $file = $scope === 'all' ? XrowExtractColumns::fileName( $class->attribute( 'identifier' ), '_all_export.' . $writer->extension() )
                              : XrowExtractColumns::fileName( $node->attribute( 'name' ), '_export.' . $writer->extension() );
$fh = null;
if ( !$previewRows )
{
    $fh = $file === '-' ? fopen( 'php://stdout', 'w' ) : @fopen( $file, 'w' );
    if ( !$fh )
        $fail( "Cannot write $file (--output)." );
}
$started = microtime( true );
$begin = $writer->begin();
$buffer = $previewRows ? $begin : null;
if ( $fh )
    fwrite( $fh, $begin );
$written = 0;
$skip = $offset;
$progressFile = $options['progress-file'] ? (string)$options['progress-file'] : false;
foreach ( $languages as $locale )
{
    if ( $written >= $wantRows )
        break;
    if ( $skip >= $languageCounts[$locale] )
    {
        $skip -= $languageCounts[$locale];
        continue;
    }
    XrowExtractColumns::$language = $locale;
    for ( $batchOffset = $skip; $written < $wantRows; $batchOffset += 100 )
    {
        $take = min( 100, $wantRows - $written );
        $result = eZContentFunctionCollection::fetchObjectTree( $fetchNode, $sortBy, true, $locale, $batchOffset, $take, $depth, $depthOperator,
                                                                $classID, $attributeFilter, XrowExtractTranslationFilter::chainedParams( $locale, $filters->values['extended_filter'], $filters->extendedParamsArray() ), 'include', array( $classID ), false, $mainOnly, true, false, true, false, true );
        $batch = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : array();
        foreach ( $batch as $treeNode )
        {
            $obj = $treeNode->attribute( 'object' );
            if ( !$obj instanceof eZContentObject || !$obj->canRead() )
                continue;
            $line = $writer->row( XrowExtractColumns::rowCells( $columns, $obj, $parser, $extras, $allowHash ) );
            if ( $fh )
                fwrite( $fh, $line );
            else
                $buffer .= $line;
            $written++;
        }
        eZContentObject::clearCache();
        if ( $progressFile )
            XrowExtractJob::writeProgress( $progressFile, $written, $wantRows, $locale );
        if ( count( $batch ) < $take )
            break;
    }
    $skip = 0;
}
XrowExtractColumns::$language = null;
if ( $fh )
    fwrite( $fh, $writer->end() );

if ( $previewRows )
{
    // A table in the terminal: the file read back as a spreadsheet would
    $in = fopen( 'php://temp', 'r+' );
    fwrite( $in, $buffer );
    rewind( $in );
    $table = array();
    while ( ( $row = fgetcsv( $in, 0, $separator, '"', '' ) ) !== false )
        $table[] = array_map( function ( $v ) { return mb_substr( preg_replace( '/\s+/', ' ', (string)$v ), 0, 24 ); }, $row );
    fclose( $in );
    $widths = array();
    foreach ( $table as $row )
        foreach ( $row as $i => $v )
            $widths[$i] = max( isset( $widths[$i] ) ? $widths[$i] : 0, mb_strlen( $v ) );
    foreach ( $table as $r => $row )
    {
        $cells = array();
        foreach ( $row as $i => $v )
            $cells[] = $v . str_repeat( ' ', $widths[$i] - mb_strlen( $v ) );
        $cli->output( ( $r ? sprintf( '%4d  ', $offset + $r ) : '   #  ' ) . implode( ' | ', $cells ) );
    }
    $cli->output( sprintf( '%d of %d rows, %d columns, %.1f s', $written, max( 0, $total - $offset ), count( $columns ), microtime( true ) - $started ) );
    $script->shutdown( 0 );
}

if ( $file !== '-' )
{
    fclose( $fh );
    $cli->output( sprintf( 'Wrote %s: %d rows, %d columns, %.1f KB, %.1f s (%s, class %s, read access of %s)', $file, $written, count( $columns ),
                           filesize( $file ) / 1024, microtime( true ) - $started,
                           ( $scope === 'all' ? 'whole site' : "below node $nodeID" ) . ', ' . implode( '+', $languages ), $class->attribute( 'identifier' ), $login ) );
}
$script->shutdown( 0 );
