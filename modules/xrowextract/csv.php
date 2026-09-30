<?php

if ( !function_exists( 'xrowExtractPreview' ) ) {
/**
 * Read an export back the way a spreadsheet does (same separator and
 * quoting) for the preview table: header, rows with a flag per cell, and
 * what the preview shows about the whole file.
 *
 * @param string $data the export (its first rows)
 * @param string $separator
 * @param mixed $escape
 * @param int|string $offset
 * @param int|string $total
 * @param string|null $file
 * @param float $seconds
 * @return array<string, mixed>
 */
function xrowExtractPreview( $data, $separator, $escape, $offset, $total, $file, $seconds ): array
{
    $fh = fopen( 'php://temp', 'r+' );
    if ( $fh === false )
        throw new RuntimeException( 'Cannot open a temporary stream to read the preview back.' );
    fwrite( $fh, $data );
    rewind( $fh );
    $header = fgetcsv( $fh, 0, $separator, '"', '' );
    $header = is_array( $header ) ? array_map( 'strval', $header ) : array();
    $columns = count( $header );
    $filled = array_fill( 0, $columns, 0 );
    $rows = array();
    $number = $offset;
    $mismatch = 0;
    $formulas = 0;
    while ( ( $values = fgetcsv( $fh, 0, $separator, '"', '' ) ) !== false )
    {
        if ( $values === array( null ) )
            continue;
        $cells = array();
        foreach ( $values as $i => $value )
        {
            $value = (string)$value;
            $formula = strlen( $value ) > 1 && $value[0] === "'" && XrowBaseHandler::looksLikeFormula( substr( $value, 1 ) );
            $formulas += $formula ? 1 : 0;
            if ( $value !== '' && isset( $filled[$i] ) )
                $filled[$i]++;
            $cells[] = array( 'text' => $value, 'empty' => $value === '', 'formula' => $formula,
                              'long' => mb_strlen( $value ) > 60 || strpos( $value, "\n" ) !== false );
        }
        $isMismatch = count( $cells ) !== $columns;
        $mismatch += $isMismatch ? 1 : 0;
        $rows[] = array( 'number' => ++$number, 'cells' => $cells, 'count' => count( $cells ), 'mismatch' => $isMismatch );
    }
    fclose( $fh );

    $shown = count( $rows );
    $headerCells = array();
    foreach ( $header as $i => $name )
    {
        // Spreadsheet column letters: A ... Z, AA ...
        $letters = '';
        for ( $n = $i + 1; $n > 0; $n = intdiv( $n - 1, 26 ) )
            $letters = chr( 65 + ( $n - 1 ) % 26 ) . $letters;
        $headerCells[] = array( 'name' => $name, 'letters' => $letters,
                                'fill' => $shown ? (int)round( 100 * $filled[$i] / $shown ) : 0 );
    }
    $total = max( (int)$total, $shown );
    $bytes = strlen( $data );
    return array(
        'header' => $headerCells,
        'columns' => $columns,
        'rows' => $rows,
        'shown' => $shown,
        'total' => $total,
        'mismatch' => $mismatch,
        'formulas' => $formulas,
        'file' => $file,
        'milliseconds' => (int)round( $seconds * 1000 ),
        'estimated_kb' => $shown ? (int)ceil( $bytes / $shown * $total / 1024 ) : (int)ceil( $bytes / 1024 ),
        'separator' => $separator === "\t" ? 'Tab' : $separator,
        'escape' => $escape ? 1 : 0,
    );
}

}

$csvINI = eZINI::instance( 'csv.ini' );
$allowPasswordHash = XrowExtractColumns::allowPasswordHash();

// The special columns (object id, user account, dates, locations ...)
$ExtraAttributes = XrowExtractColumns::extraAttributes( $allowPasswordHash );

// Start module definition
$module = $Params["Module"];

// Parse HTTP POST variables
$http = eZHTTPTool::instance();
// Access system variables
$sys = eZSys::instance();
// Init template behaviors
$tpl = eZTemplate::factory();
// Access ini variables
$ini = eZINI::instance();
$ini_bis = eZINI::instance( 'export.ini' );

// Object ids another view put in the session: only those the user may read
$preFilledIDs = array();
if ( isset( $_SESSION['EXTRACTCSV_OBJECTID_ARRAY'] ) && is_array( $_SESSION['EXTRACTCSV_OBJECTID_ARRAY'] ) )
{
    foreach ( $_SESSION['EXTRACTCSV_OBJECTID_ARRAY'] as $objectID )
    {
        if ( (int)$objectID > 0 )
            $preFilledIDs[] = (int)$objectID;
    }
}
$hasPreFilledData = count( $preFilledIDs ) > 0;

if ( $hasPreFilledData and $http->hasPostVariable( 'RemoveData' ) )
{
    unset( $_SESSION['EXTRACTCSV_OBJECTID_ARRAY'] );

    return $module->redirectTo( 'xrowextract/csv' );

}
$sessionConfig = $http->sessionVariable( 'eZExtractConfig' );
if ( !is_array( $sessionConfig ) || $http->hasPostVariable( 'ResetView' ) )
    $sessionConfig = array();
// Version 2: the node starts at the default siteaccess's root and the class with the most objects. A node,
// class or scope saved before (for example the old default, the user placement) is dropped once.
if ( !isset( $sessionConfig['Version'] ) || (int)$sessionConfig['Version'] < 2 )
{
    unset( $sessionConfig['Subtree'], $sessionConfig['Class_id'], $sessionConfig['Scope'] );
    $sessionConfig['Version'] = 2;
}
if ( $http->hasPostVariable( 'ResetView' ) )
{
    $http->setSessionVariable( 'eZExtractConfig', $sessionConfig );
    return $module->redirectTo( 'xrowextract/csv' );
}

// Named fetch (fetchalias.ini): apply one's Constant values into the view. Read early, so the node,
// class, sort, depth, limit/offset, main-locations and a condition below all see the override.
$FetchAliasChoices = XrowExtractFetchAlias::choices();
$FetchAliasApplyResult = false;
$FetchAliasFunction = '';
$fetchAliasName = '';
$fetchAliasSiteaccess = '';
if ( $http->hasPostVariable( 'FetchAliasChoice' ) )
{
    $choiceRaw = (string)$http->postVariable( 'FetchAliasChoice' );
    $pipePos = strpos( $choiceRaw, '|' );
    if ( $pipePos !== false )
    {
        $fetchAliasSiteaccess = substr( $choiceRaw, 0, $pipePos );
        $fetchAliasName = substr( $choiceRaw, $pipePos + 1 );
    }
}
// Free-form "key=value,key=value" for the alias's own Parameter[] entries (besides parent_node_id, which
// always takes the node currently chosen in the view); the same shape as the CLI's --alias-param.
$fetchAliasParamsRaw = (string)( $http->hasPostVariable( 'FetchAliasParams' ) ? $http->postVariable( 'FetchAliasParams' ) : '' );
$FetchAliasParamOverrides = array();
foreach ( explode( ',', $fetchAliasParamsRaw ) as $pair )
{
    $pair = trim( $pair );
    if ( $pair === '' || strpos( $pair, '=' ) === false )
        continue;
    list( $pKey, $pValue ) = array_map( 'trim', explode( '=', $pair, 2 ) );
    if ( $pKey !== '' )
        $FetchAliasParamOverrides[$pKey] = $pValue;
}
$FetchAliasFillable = array();
if ( $http->hasPostVariable( 'ApplyFetchAlias' ) && $fetchAliasName !== '' )
{
    $aliasDefinition = XrowExtractFetchAlias::find( $fetchAliasName, $fetchAliasSiteaccess );
    if ( $aliasDefinition )
    {
        $FetchAliasFunction = $aliasDefinition['function'];
        $FetchAliasFillable = XrowExtractFetchAlias::fillableParameters( $aliasDefinition );
        $currentNodeGuess = $http->hasPostVariable( 'Subtree' ) ? XrowExtractColumns::dbID( $http->postVariable( 'Subtree' ) )
                           : ( isset( $sessionConfig['Subtree'] ) ? (int)$sessionConfig['Subtree'] : 0 );
        $FetchAliasApplyResult = XrowExtractFetchAlias::apply( $aliasDefinition, $currentNodeGuess, $FetchAliasParamOverrides );
    }
    else
    {
        $FetchAliasApplyResult = array( 'applied' => array(), 'unknown' => array( "alias not found: $fetchAliasName" ), 'values' => array() );
    }
}
elseif ( $fetchAliasName !== '' )
{
    // Not applying yet: still tell the view which Parameter[] entries the chosen alias would take,
    // so the "Parameters" field can be filled in before "Apply" is clicked.
    $chosenAliasDefinition = XrowExtractFetchAlias::find( $fetchAliasName, $fetchAliasSiteaccess );
    if ( $chosenAliasDefinition )
        $FetchAliasFillable = XrowExtractFetchAlias::fillableParameters( $chosenAliasDefinition );
}
$FetchAliasValues = $FetchAliasApplyResult ? $FetchAliasApplyResult['values'] : array();

// Presets: a complete, named export definition. Delete / duplicate / rename act on the stored preset and
// redirect; load (and "run in the background") resolve it (its own Extends chain, its placeholders) into
// the same session the rest of the view already reads from, so a fresh GET picks it up exactly like a
// first visit would. Saving needs the state fully resolved, so it is handled at the end of the script.
$xePresetLogin = eZUser::currentUser()->attribute( 'login' );
$xePresetAllowAll = XrowExtractJob::allowAllJobs();
$PresetNotice = false;

/** What a resolved, filled preset definition set, as removable chips (key + label) for "Currently loaded". */
$buildPresetChips = function ( array $presetDef, $presetNodeID, $presetClassID )
{
    $chips = array();
    if ( $presetNodeID )
    {
        $chipNode = eZContentObjectTreeNode::fetch( $presetNodeID );
        $chips[] = array( 'key' => 'node', 'label' => 'Node: ' . ( $chipNode ? $chipNode->attribute( 'name' ) : $presetNodeID ) );
    }
    if ( $presetClassID )
    {
        $chipClass = eZContentClass::fetch( $presetClassID );
        $chips[] = array( 'key' => 'class', 'label' => 'Class: ' . ( $chipClass ? $chipClass->attribute( 'name' ) : $presetClassID ) );
    }
    if ( !empty( $presetDef['attributes'] ) && is_array( $presetDef['attributes'] ) )
        $chips[] = array( 'key' => 'columns', 'label' => count( $presetDef['attributes'] ) . ' ' . ( count( $presetDef['attributes'] ) === 1 ? 'column' : 'columns' ) );
    if ( !empty( $presetDef['languages'] ) && is_array( $presetDef['languages'] ) )
        $chips[] = array( 'key' => 'languages', 'label' => 'Languages: ' . implode( ', ', $presetDef['languages'] ) );
    $sortLabel = '';
    if ( isset( $presetDef['sort_field'] ) && $presetDef['sort_field'] !== 'tree' )
        $sortLabel = $presetDef['sort_field'];
    if ( !empty( $presetDef['sort_field2'] ) )
        $sortLabel = ( $sortLabel !== '' ? $sortLabel . ', ' : '' ) . $presetDef['sort_field2'];
    if ( $sortLabel !== '' )
        $chips[] = array( 'key' => 'sort', 'label' => 'Sort: ' . $sortLabel );
    if ( isset( $presetDef['output_format'] ) || isset( $presetDef['separator'] ) || isset( $presetDef['line_separator'] ) || isset( $presetDef['escape'] ) )
        $chips[] = array( 'key' => 'output', 'label' => 'Output: ' . strtoupper( isset( $presetDef['output_format'] ) ? $presetDef['output_format'] : 'csv' ) );
    $f = isset( $presetDef['filters'] ) && is_array( $presetDef['filters'] ) ? $presetDef['filters'] : array();
    if ( isset( $f['depth_mode'] ) && $f['depth_mode'] !== 'any' )
        $chips[] = array( 'key' => 'depth', 'label' => 'Depth: ' . $f['depth_mode'] . ' ' . ( isset( $f['depth_value'] ) ? $f['depth_value'] : '' ) );
    if ( !empty( $f['extended_filter'] ) )
        $chips[] = array( 'key' => 'extended_filter', 'label' => 'Extended filter: ' . $f['extended_filter'] );
    if ( isset( $f['date_mode'] ) && $f['date_mode'] !== 'any' )
        $chips[] = array( 'key' => 'date', 'label' => 'Date: ' . $f['date_mode'] );
    if ( !empty( $f['section'] ) )
        $chips[] = array( 'key' => 'section', 'label' => 'Section' );
    if ( !empty( $f['state'] ) )
        $chips[] = array( 'key' => 'state', 'label' => 'State' );
    if ( isset( $f['visibility'] ) && $f['visibility'] !== 'any' )
        $chips[] = array( 'key' => 'visibility', 'label' => 'Visibility: ' . $f['visibility'] );
    if ( !empty( $f['name'] ) )
        $chips[] = array( 'key' => 'name', 'label' => 'Name contains "' . $f['name'] . '"' );
    if ( !empty( $f['conditions'] ) && is_array( $f['conditions'] ) )
    {
        foreach ( $f['conditions'] as $i => $c )
        {
            if ( !empty( $c['field'] ) )
                $chips[] = array( 'key' => 'condition:' . $i, 'label' => trim( $c['field'] . ' ' . ( !empty( $c['negate'] ) ? 'not ' : '' ) . $c['op'] . ' ' . $c['value'] ) );
        }
    }
    return $chips;
};
if ( $http->hasPostVariable( 'DeletePreset' ) && $http->hasPostVariable( 'PresetActionRef' ) )
{
    $presetToDelete = XrowExtractPreset::fetch( (string)$http->postVariable( 'PresetActionRef' ) );
    if ( $presetToDelete && !$presetToDelete['site'] && XrowExtractPreset::canEdit( $presetToDelete, $xePresetLogin, $xePresetAllowAll ) )
        XrowExtractPreset::deleteUser( $presetToDelete['id'] );
    return $module->redirectTo( 'xrowextract/csv' );
}
if ( $http->hasPostVariable( 'DuplicatePreset' ) && $http->hasPostVariable( 'PresetActionRef' ) )
{
    $presetToDuplicate = XrowExtractPreset::fetch( (string)$http->postVariable( 'PresetActionRef' ) );
    if ( $presetToDuplicate )
    {
        XrowExtractPreset::saveUser( $xePresetLogin, array(
            'name' => $presetToDuplicate['name'] . ' (copy)', 'description' => $presetToDuplicate['description'],
            'view' => $presetToDuplicate['view'], 'shared' => false, 'extends' => $presetToDuplicate['extends'],
            'placeholders' => $presetToDuplicate['placeholders'], 'definition' => $presetToDuplicate['definition'],
        ) );
    }
    return $module->redirectTo( 'xrowextract/csv' );
}
if ( $http->hasPostVariable( 'RenamePreset' ) && $http->hasPostVariable( 'PresetActionRef' ) && $http->hasPostVariable( 'PresetNewName' ) )
{
    $presetToRename = XrowExtractPreset::fetch( (string)$http->postVariable( 'PresetActionRef' ) );
    $newName = trim( (string)$http->postVariable( 'PresetNewName' ) );
    if ( $presetToRename && !$presetToRename['site'] && $newName !== '' && XrowExtractPreset::canEdit( $presetToRename, $xePresetLogin, $xePresetAllowAll ) )
    {
        $presetToRename['name'] = $newName;
        XrowExtractPreset::saveUser( $presetToRename['owner_login'], $presetToRename, $presetToRename['id'] );
    }
    return $module->redirectTo( 'xrowextract/csv' );
}
if ( ( $http->hasPostVariable( 'LoadPreset' ) || $http->hasPostVariable( 'RunPresetInBackground' ) || $http->hasPostVariable( 'RunPresetNow' ) ) && $http->hasPostVariable( 'PresetRef' ) )
{
    $presetRefToLoad = (string)$http->postVariable( 'PresetRef' );
    $presetNodeGuess = $http->hasPostVariable( 'Subtree' ) ? XrowExtractColumns::dbID( $http->postVariable( 'Subtree' ) )
                      : ( isset( $sessionConfig['Subtree'] ) ? (int)$sessionConfig['Subtree'] : 0 );
    // Labelled placeholder inputs (one group of inputs per preset, PresetPlaceholder[<ref>][<name>]; only
    // the loaded preset's own group is read) plus the free-text "Advanced" field, which wins if both set
    // the same name.
    $presetParamOverrides = array();
    if ( $http->hasPostVariable( 'PresetPlaceholder' ) )
    {
        $allPlaceholderGroups = (array)$http->postVariable( 'PresetPlaceholder' );
        if ( isset( $allPlaceholderGroups[$presetRefToLoad] ) && is_array( $allPlaceholderGroups[$presetRefToLoad] ) )
        {
            foreach ( $allPlaceholderGroups[$presetRefToLoad] as $ppKey => $ppValue )
            {
                if ( trim( (string)$ppValue ) !== '' )
                    $presetParamOverrides[(string)$ppKey] = trim( (string)$ppValue );
            }
        }
    }
    $presetParamsRaw = (string)( $http->hasPostVariable( 'PresetParams' ) ? $http->postVariable( 'PresetParams' ) : '' );
    foreach ( explode( ',', $presetParamsRaw ) as $pair )
    {
        $pair = trim( $pair );
        if ( $pair === '' || strpos( $pair, '=' ) === false )
            continue;
        list( $ppKey, $ppValue ) = array_map( 'trim', explode( '=', $pair, 2 ) );
        if ( $ppKey !== '' )
            $presetParamOverrides[$ppKey] = $ppValue;
    }
    $resolvedPreset = XrowExtractPreset::resolve( $presetRefToLoad, $presetNodeGuess, $presetParamOverrides );
    if ( $resolvedPreset['error'] !== '' )
    {
        $http->setSessionVariable( 'eZExtractPresetError', $resolvedPreset['error'] );
        return $module->redirectTo( 'xrowextract/csv' );
    }
    $filledPreset = XrowExtractPreset::fillPlaceholders( $resolvedPreset['definition'], $resolvedPreset['placeholders'], $presetParamOverrides );
    $presetDef = $filledPreset['definition'];

    // The node, preferably by remote_id (survives a reinstall's renumbering); the class, preferably by identifier
    $presetNodeID = 0;
    if ( !empty( $presetDef['subtree_remote_id'] ) )
    {
        $presetRemoteNode = eZContentObjectTreeNode::fetchByRemoteID( $presetDef['subtree_remote_id'] );
        if ( $presetRemoteNode instanceof eZContentObjectTreeNode )
            $presetNodeID = (int)$presetRemoteNode->attribute( 'node_id' );
    }
    if ( !$presetNodeID && isset( $presetDef['subtree'] ) && ctype_digit( (string)$presetDef['subtree'] ) )
        $presetNodeID = (int)$presetDef['subtree'];
    $presetClassID = 0;
    if ( !empty( $presetDef['class_identifier'] ) )
    {
        $presetClass = eZContentClass::fetchByIdentifier( $presetDef['class_identifier'] );
        if ( $presetClass instanceof eZContentClass )
            $presetClassID = (int)$presetClass->attribute( 'id' );
    }
    if ( !$presetClassID && isset( $presetDef['class_id'] ) )
        $presetClassID = (int)$presetDef['class_id'];

    $presetSessionConfig = array(
        'Version' => 2,
        'Subtree' => $presetNodeID ?: ( isset( $sessionConfig['Subtree'] ) ? $sessionConfig['Subtree'] : 2 ),
        'Class_id' => $presetClassID,
        'Scope' => isset( $presetDef['scope'] ) && in_array( $presetDef['scope'], array( 'list', 'tree', 'all' ), true ) ? $presetDef['scope'] : 'tree',
        'Filters' => ( new XrowExtractFilters( isset( $presetDef['filters'] ) && is_array( $presetDef['filters'] ) ? $presetDef['filters'] : array() ) )->values,
        'SortField' => isset( $presetDef['sort_field'] ) ? $presetDef['sort_field'] : 'tree',
        'SortAscending' => !isset( $presetDef['sort_ascending'] ) || (bool)$presetDef['sort_ascending'],
        'SortField2' => isset( $presetDef['sort_field2'] ) ? $presetDef['sort_field2'] : '',
        'SortAscending2' => !isset( $presetDef['sort_ascending2'] ) || (bool)$presetDef['sort_ascending2'],
        'Attributes' => array( $presetClassID => isset( $presetDef['attributes'] ) && is_array( $presetDef['attributes'] ) ? $presetDef['attributes'] : array() ),
        'OutputFormat' => isset( $presetDef['output_format'] ) ? $presetDef['output_format'] : 'csv',
        'Separator' => isset( $presetDef['separator'] ) ? $presetDef['separator'] : ',',
        'LineSeparator' => isset( $presetDef['line_separator'] ) ? $presetDef['line_separator'] : 'unix',
        'Escape' => !isset( $presetDef['escape'] ) || (bool)$presetDef['escape'],
        'Limit' => isset( $presetDef['limit'] ) ? max( 0, (int)$presetDef['limit'] ) : 0,
        'Offset' => isset( $presetDef['offset'] ) ? max( 0, (int)$presetDef['offset'] ) : 0,
        'Mainnodeonly' => isset( $presetDef['mainnodeonly'] ) && (string)$presetDef['mainnodeonly'] === '1' ? '1' : '0',
    );
    // Languages: only set when the preset actually names some, so one that does not (most site presets
    // will not) falls through to the existing "all languages" default rather than forcing an empty pick
    if ( isset( $presetDef['languages'] ) && is_array( $presetDef['languages'] ) && $presetDef['languages'] )
        $presetSessionConfig['Languages'] = array_values( $presetDef['languages'] );
    $http->setSessionVariable( 'eZExtractConfig', $presetSessionConfig );
    $http->setSessionVariable( 'eZExtractLoadedPreset', $presetRefToLoad );
    $http->setSessionVariable( 'eZExtractPresetFields', $buildPresetChips( $presetDef, $presetSessionConfig['Subtree'], $presetClassID ) );
    if ( $filledPreset['unresolved'] )
        $http->setSessionVariable( 'eZExtractPresetUnresolved', $filledPreset['unresolved'] );
    if ( $http->hasPostVariable( 'RunPresetInBackground' ) )
        $http->setSessionVariable( 'eZExtractRunPresetAfterLoad', $presetRefToLoad );
    if ( $http->hasPostVariable( 'RunPresetNow' ) )
        $http->setSessionVariable( 'eZExtractDownloadAfterLoad', $presetRefToLoad );
    return $module->redirectTo( 'xrowextract/csv' );
}
// Clearing: one chip ("ClearPresetField", the chip's key), or all of them / the whole loaded preset
// ("UnloadPreset" / "ClearAllPresetFields") — each field a preset set is put back to the export's own
// default, the session it already reads from updated directly (this runs before that session is read
// below, so the same request already reflects the change).
if ( $http->hasPostVariable( 'ClearPresetField' ) || $http->hasPostVariable( 'UnloadPreset' ) || $http->hasPostVariable( 'ClearAllPresetFields' ) )
{
    $clearAllPresetFields = $http->hasPostVariable( 'UnloadPreset' ) || $http->hasPostVariable( 'ClearAllPresetFields' );
    $singlePresetFieldKey = (string)( $http->hasPostVariable( 'ClearPresetField' ) ? $http->postVariable( 'ClearPresetField' ) : '' );
    $currentPresetChips = $http->hasSessionVariable( 'eZExtractPresetFields' ) ? (array)$http->sessionVariable( 'eZExtractPresetFields' ) : array();
    $presetFieldKeysToClear = $clearAllPresetFields ? array_map( function ( $c ) { return $c['key']; }, $currentPresetChips ) : array( $singlePresetFieldKey );
    $liveExtractConfig = $http->hasSessionVariable( 'eZExtractConfig' ) ? (array)$http->sessionVariable( 'eZExtractConfig' ) : array();
    $liveExtractFilters = isset( $liveExtractConfig['Filters'] ) && is_array( $liveExtractConfig['Filters'] ) ? $liveExtractConfig['Filters'] : array();
    foreach ( $presetFieldKeysToClear as $presetFieldKey )
    {
        if ( $presetFieldKey === 'node' )
            unset( $liveExtractConfig['Subtree'] );
        elseif ( $presetFieldKey === 'class' )
            unset( $liveExtractConfig['Class_id'] );
        elseif ( $presetFieldKey === 'columns' )
        {
            if ( isset( $liveExtractConfig['Class_id'] ) )
                unset( $liveExtractConfig['Attributes'][(int)$liveExtractConfig['Class_id']] );
        }
        elseif ( $presetFieldKey === 'languages' )
            unset( $liveExtractConfig['Languages'] );
        elseif ( $presetFieldKey === 'sort' )
        {
            $liveExtractConfig['SortField'] = 'tree'; $liveExtractConfig['SortAscending'] = true;
            $liveExtractConfig['SortField2'] = ''; $liveExtractConfig['SortAscending2'] = true;
        }
        elseif ( $presetFieldKey === 'output' )
        {
            $liveExtractConfig['OutputFormat'] = 'csv'; $liveExtractConfig['Separator'] = ',';
            $liveExtractConfig['LineSeparator'] = 'unix'; $liveExtractConfig['Escape'] = true;
        }
        elseif ( $presetFieldKey === 'depth' )
        {
            $liveExtractFilters['depth_mode'] = 'any'; $liveExtractFilters['depth_value'] = 0;
        }
        elseif ( $presetFieldKey === 'extended_filter' )
        {
            $liveExtractFilters['extended_filter'] = ''; $liveExtractFilters['extended_params'] = '';
        }
        elseif ( $presetFieldKey === 'date' )
        {
            $liveExtractFilters['date_mode'] = 'any'; $liveExtractFilters['date_from'] = ''; $liveExtractFilters['date_to'] = '';
        }
        elseif ( $presetFieldKey === 'section' )
            $liveExtractFilters['section'] = 0;
        elseif ( $presetFieldKey === 'state' )
            $liveExtractFilters['state'] = 0;
        elseif ( $presetFieldKey === 'visibility' )
            $liveExtractFilters['visibility'] = 'any';
        elseif ( $presetFieldKey === 'name' )
            $liveExtractFilters['name'] = '';
        elseif ( strpos( $presetFieldKey, 'condition:' ) === 0 )
        {
            $clearConditionIndex = (int)substr( $presetFieldKey, strlen( 'condition:' ) );
            if ( isset( $liveExtractFilters['conditions'][$clearConditionIndex] ) )
                array_splice( $liveExtractFilters['conditions'], $clearConditionIndex, 1 );
        }
    }
    $liveExtractConfig['Filters'] = ( new XrowExtractFilters( $liveExtractFilters ) )->values;
    $http->setSessionVariable( 'eZExtractConfig', $liveExtractConfig );
    if ( $clearAllPresetFields )
    {
        $http->removeSessionVariable( 'eZExtractLoadedPreset' );
        $http->removeSessionVariable( 'eZExtractPresetFields' );
    }
    else
    {
        // Condition chips are index-keyed ("condition:0", "condition:1", ...); removing one re-indexes the
        // conditions that follow it (array_splice above), so their chips are rebuilt from the live filters
        // rather than kept from the stale snapshot, or a later chip's × would remove the wrong row.
        $remainingPresetChips = array_values( array_filter( $currentPresetChips, function ( $c ) use ( $singlePresetFieldKey ) {
            return $c['key'] !== $singlePresetFieldKey && strpos( $c['key'], 'condition:' ) !== 0;
        } ) );
        foreach ( $liveExtractConfig['Filters']['conditions'] as $conditionIndex => $condition )
        {
            if ( $condition['field'] !== '' )
                $remainingPresetChips[] = array( 'key' => 'condition:' . $conditionIndex, 'label' => trim( $condition['field'] . ' ' . ( !empty( $condition['negate'] ) ? 'not ' : '' ) . $condition['op'] . ' ' . $condition['value'] ) );
        }
        if ( $remainingPresetChips )
            $http->setSessionVariable( 'eZExtractPresetFields', $remainingPresetChips );
        else
        {
            $http->removeSessionVariable( 'eZExtractPresetFields' );
            $http->removeSessionVariable( 'eZExtractLoadedPreset' );
        }
    }
    return $module->redirectTo( 'xrowextract/csv' );
}
if ( $http->hasSessionVariable( 'eZExtractPresetError' ) )
{
    $PresetNotice = array( 'error' => true, 'text' => (string)$http->sessionVariable( 'eZExtractPresetError' ) );
    $http->removeSessionVariable( 'eZExtractPresetError' );
}
$LoadedPresetRef = $http->hasSessionVariable( 'eZExtractLoadedPreset' ) ? (string)$http->sessionVariable( 'eZExtractLoadedPreset' ) : '';
$LoadedPresetFields = $http->hasSessionVariable( 'eZExtractPresetFields' ) ? (array)$http->sessionVariable( 'eZExtractPresetFields' ) : array();
$LoadedPresetUnresolved = array();
if ( $http->hasSessionVariable( 'eZExtractPresetUnresolved' ) )
{
    $LoadedPresetUnresolved = (array)$http->sessionVariable( 'eZExtractPresetUnresolved' );
    $http->removeSessionVariable( 'eZExtractPresetUnresolved' );
}
// A "run in the background" / "run now" requested when the preset was loaded (the redirect above),
// consumed once the state it needs (Filters, Attributes, Languages ...) is fully resolved, further down.
$AutoRunPresetInBackground = false;
if ( $http->hasSessionVariable( 'eZExtractRunPresetAfterLoad' ) )
{
    $AutoRunPresetInBackground = (string)$http->sessionVariable( 'eZExtractRunPresetAfterLoad' );
    $http->removeSessionVariable( 'eZExtractRunPresetAfterLoad' );
}
$AutoDownloadAfterLoad = false;
if ( $http->hasSessionVariable( 'eZExtractDownloadAfterLoad' ) )
{
    $AutoDownloadAfterLoad = (string)$http->sessionVariable( 'eZExtractDownloadAfterLoad' );
    $http->removeSessionVariable( 'eZExtractDownloadAfterLoad' );
}

// Set col & row separator: one character (\t is a tab), never a quote or a line break. A loaded preset
// (session) is the fallback once POST has nothing, so its output settings stick after the redirect.
$Separator = $http->hasPostVariable( 'Separator' ) ? (string)$http->postVariable( 'Separator' )
           : ( isset( $sessionConfig['Separator'] ) ? (string)$sessionConfig['Separator'] : ',' );
if ( $Separator === '\t' )
    $Separator = "\t";
if ( strlen( $Separator ) !== 1 || strpbrk( $Separator, "\"\r\n" ) !== false )
    $Separator = ',';

$LineSeparatorArray = array(
    'win32' => array(
        'id' => 'win32' ,
        'value' => "\r\n" ,
        'name' => 'Windows'
    ) ,
    'unix' => array(
        'id' => 'unix' ,
        'value' => "\n" ,
        'name' => 'Unix'
    ) ,
    'mac' => array(
        'id' => 'mac' ,
        'value' => "\r" ,
        'name' => 'Mac'
    )
);

$LineSeparator = $http->hasPostVariable( 'LineSeparator' ) ? $http->postVariable( 'LineSeparator' )
                : ( isset( $sessionConfig['LineSeparator'] ) ? $sessionConfig['LineSeparator'] : $sys->osType() );
if ( !is_string( $LineSeparator ) || !isset( $LineSeparatorArray[$LineSeparator] ) )
    $LineSeparator = 'unix';

$tpl->setVariable( 'Separator', $Separator === "\t" ? '\t' : $Separator );
$tpl->setVariable( 'LineSeparator', $LineSeparator );
$tpl->setVariable( 'LineSeparatorArray', $LineSeparatorArray );

// Set limit & offset
$Limit = isset( $FetchAliasValues['limit'] ) ? $FetchAliasValues['limit']
       : max( 0, (int)( $http->hasPostVariable( 'Limit' ) ? $http->postVariable( 'Limit' )
                       : ( isset( $sessionConfig['Limit'] ) ? $sessionConfig['Limit'] : $ini_bis->variable( 'ExportSettings', 'Limit' ) ) ) );
$Offset = isset( $FetchAliasValues['offset'] ) ? $FetchAliasValues['offset']
        : max( 0, (int)( $http->hasPostVariable( 'Offset' ) ? $http->postVariable( 'Offset' )
                        : ( isset( $sessionConfig['Offset'] ) ? $sessionConfig['Offset'] : $ini_bis->variable( 'ExportSettings', 'Offset' ) ) ) );

$tpl->setVariable( 'Limit', $Limit );
$tpl->setVariable( 'Offset', $Offset );

// What is the default subtree
if ( isset( $FetchAliasValues['parent_node_id'] ) && $FetchAliasValues['parent_node_id'] > 0 )
{
    $Subtree = (int)$FetchAliasValues['parent_node_id'];
}
elseif ( ! $http->hasPostVariable( 'Subtree' ) && isset( $sessionConfig['Subtree'] ) && (int)$sessionConfig['Subtree'] > 0 )
{
    $Subtree = (int)$sessionConfig['Subtree'];
}
elseif ( ! $http->hasPostVariable( 'Subtree' ) )
{
    // export.ini StartNodeID, else the root node of the default (public) siteaccess, else the content structure
    $Subtree = (int)$ini_bis->variable( 'ExportSettings', 'StartNodeID' );
    if ( $Subtree <= 0 )
    {
        $publicContentINI = eZSiteAccess::getIni( $ini->variable( 'SiteSettings', 'DefaultAccess' ), 'content.ini' );
        $Subtree = (int)$publicContentINI->variable( 'NodeSettings', 'RootNode' );
    }
    if ( $Subtree <= 0 )
        $Subtree = 2;
}
else
{
    $Subtree = $http->postVariable( 'Subtree' );
}
// What is the default fetch type
$type = ( $http->hasPostVariable( 'type' ) && $http->postVariable( 'type' ) === 'list' ) ? 'list' : 'tree';

// A list is the children only: depth 1
$depth = $type == 'list' ? 1 : false;
$depthOperator = $type == 'list' ? 'eq' : false;

$Mainnodeonly = isset( $FetchAliasValues['main_node_only'] ) ? ( $FetchAliasValues['main_node_only'] ? '1' : '0' )
              : ( $http->hasPostVariable( 'mainnodeonly' ) ? ( $http->postVariable( 'mainnodeonly' ) ? '1' : '0' )
                : ( isset( $sessionConfig['Mainnodeonly'] ) ? $sessionConfig['Mainnodeonly'] : '0' ) );

$Escape = $http->hasPostVariable( 'Escape' ) ? (bool)$http->postVariable( 'Escape' )
        : ( isset( $sessionConfig['Escape'] ) ? (bool)$sessionConfig['Escape'] : true );

// Output format: CSV, JSON or XML, remembered
if ( $http->hasPostVariable( 'OutputFormat' ) && XrowExtractWriter::isFormat( $http->postVariable( 'OutputFormat' ) ) )
    $OutputFormat = $http->postVariable( 'OutputFormat' );
else
    $OutputFormat = isset( $sessionConfig['OutputFormat'] ) && XrowExtractWriter::isFormat( $sessionConfig['OutputFormat'] ) ? $sessionConfig['OutputFormat'] : 'csv';
$sessionConfig['OutputFormat'] = $OutputFormat;
$tpl->setVariable( 'OutputFormat', $OutputFormat );
$tpl->setVariable( 'OutputFormats', array_values( XrowExtractWriter::formats() ) );

if ( ! $hasPreFilledData )
{
    // What is the default class
    // The class chosen before, else export.ini DefaultClassID, else (0) the class with the most objects
    // in the selection, found once the node and scope are known
    if ( ! $http->hasPostVariable( 'Class_id' ) )
    {
        if ( isset( $sessionConfig['Class_id'] ) && (int)$sessionConfig['Class_id'] > 0 )
            $Class_id = (int)$sessionConfig['Class_id'];
        else
            $Class_id = (int)$ini_bis->variable( 'ExportSettings', 'DefaultClassID' );
    }
    else
    {
        $Class_id = $http->postVariable( 'Class_id' );
    }
}
else
{
    $obj = eZContentObject::fetch( $preFilledIDs[0] );
    $Class_id = $obj ? $obj->attribute( 'contentclass_id' ) : 0;
}
$Class_id = XrowExtractColumns::dbID( $Class_id ); // 0 (none) for anything that is not an id
if ( !$hasPreFilledData && isset( $FetchAliasValues['class_id'] ) && $FetchAliasValues['class_id'] > 0 )
{
    $Class_id = (int)$FetchAliasValues['class_id'];
}
elseif ( !$hasPreFilledData && isset( $FetchAliasValues['class_filter_array'] ) && $FetchAliasValues['class_filter_array'] )
{
    $aliasFirstClass = reset( $FetchAliasValues['class_filter_array'] );
    $aliasResolvedClass = ctype_digit( (string)$aliasFirstClass ) ? eZContentClass::fetch( (int)$aliasFirstClass ) : eZContentClass::fetchByIdentifier( $aliasFirstClass );
    if ( $aliasResolvedClass )
        $Class_id = (int)$aliasResolvedClass->attribute( 'id' );
}
$pickBestClass = !$hasPreFilledData && $Class_id <= 0;
// The attribute formats of the class (identifier:format columns)
$FormatColumns = XrowExtractCatalogue::formatColumns( $Class_id );

if ( $http->hasPostVariable( 'SelectedNodeIDArray' ) )
{
    $nodes = (array)$http->postVariable( 'SelectedNodeIDArray' );
    if ( isset( $nodes[0] ) )
        $Subtree = $nodes[0];
}
$Subtree = XrowExtractColumns::dbID( $Subtree ); // 0 (no node) for anything that is not an id
$sessionConfig['Subtree'] = $Subtree;

// Scope: below the chosen node, or every object of the class in the whole site (read from the top of the
// tree, at main locations, so each object is one row). The chosen node stays for switching back.
// list: the node's direct children; tree: its whole subtree; all: the whole site. A form without the
// three-way choice (or "node") keeps the depth field it posted.
$scopeIn = $http->hasPostVariable( 'Scope' ) ? (string)$http->postVariable( 'Scope' )
                                             : ( isset( $sessionConfig['Scope'] ) ? (string)$sessionConfig['Scope'] : '' );
if ( $scopeIn === 'node' || $scopeIn === '' )
    $scopeIn = $type;
if ( $FetchAliasApplyResult !== false && $FetchAliasFunction !== '' )
    $scopeIn = in_array( $FetchAliasFunction, array( 'list', 'list_count' ), true ) ? 'list' : 'tree';
$Scope = in_array( $scopeIn, array( 'list', 'tree', 'all' ), true ) ? $scopeIn : 'tree';
if ( $Scope !== 'all' )
{
    $type = $Scope;
    $depth = $type == 'list' ? 1 : false;
    $depthOperator = $type == 'list' ? 'eq' : false;
}
$sessionConfig['Scope'] = $Scope;
$FetchSubtree = $Subtree;
$FetchMainnodeonly = $Mainnodeonly;
if ( $Scope === 'all' )
{
    $FetchSubtree = 1;
    $depth = false;
    $depthOperator = false;
    $FetchMainnodeonly = '1';
}
$tpl->setVariable( 'Scope', $Scope );

// Filters: from the form (FilterSelection marks it), cleared, or as saved
if ( $http->hasPostVariable( 'ClearFilters' ) )
{
    $Filters = new XrowExtractFilters();
}
elseif ( $http->hasPostVariable( 'FilterSelection' ) )
{
    $filterInput = (array)( $http->hasPostVariable( 'Filter' ) ? $http->postVariable( 'Filter' ) : array() );
    $filterInput['conditions'] = array_values( (array)( isset( $filterInput['conditions'] ) ? $filterInput['conditions'] : array() ) );
    // Add / remove a condition row (several rows, joined with and/or)
    if ( $http->hasPostVariable( 'AddCondition' ) )
        $filterInput['conditions'][] = array( 'field' => '', 'op' => 'contains', 'value' => '', 'value2' => '' );
    if ( $http->hasPostVariable( 'RemoveCondition' ) && is_array( $http->postVariable( 'RemoveCondition' ) ) )
    {
        $removeConditionKeys = array_keys( $http->postVariable( 'RemoveCondition' ) );
        $removeConditionIndex = (int)$removeConditionKeys[0];
        if ( isset( $filterInput['conditions'][$removeConditionIndex] ) )
            array_splice( $filterInput['conditions'], $removeConditionIndex, 1 );
    }
    $Filters = new XrowExtractFilters( $filterInput );
}
else
{
    $Filters = new XrowExtractFilters( isset( $sessionConfig['Filters'] ) && is_array( $sessionConfig['Filters'] ) ? $sessionConfig['Filters'] : array() );
}
if ( $FetchAliasApplyResult !== false )
{
    // A named fetch just applied: fold its depth and condition into the filters, and remember the choice
    $aliasFilterValues = $Filters->values;
    if ( isset( $FetchAliasValues['depth'] ) )
    {
        $aliasDepthModeMap = array( 'eq' => 'exact', 'le' => 'atmost', 'ge' => 'atleast' );
        if ( isset( $aliasDepthModeMap[$FetchAliasValues['depth_operator']] ) )
        {
            $aliasFilterValues['depth_mode'] = $aliasDepthModeMap[$FetchAliasValues['depth_operator']];
            $aliasFilterValues['depth_value'] = max( 0, $FetchAliasValues['depth'] );
        }
    }
    if ( isset( $FetchAliasValues['condition'] ) )
    {
        $aliasFilterValues['conditions'] = array( array_merge( array( 'value2' => '' ), $FetchAliasValues['condition'] ) );
        $aliasFilterValues['where_attribute'] = ''; // the alias's own condition replaces the legacy single one
    }
    $aliasFilterValues['fetch_alias'] = $fetchAliasName;
    $aliasFilterValues['fetch_alias_siteaccess'] = $fetchAliasSiteaccess;
    $Filters = new XrowExtractFilters( $aliasFilterValues );
}
$sessionConfig['Filters'] = $Filters->values;
// An exact / at most / at least depth below the node, the tree scope only (list is already depth 1 = eq,
// all has no depth); a named fetch's own depth (folded into $Filters above) is applied the same way.
if ( $Scope === 'tree' )
    list( $depth, $depthOperator ) = $Filters->depthParams( $depth, $depthOperator );

// Sort: the node's own order by default, or a field / attribute, ascending or descending; a second field
// breaks ties in the first. A named fetch's own sort_by overrides both.
$SortField = $http->hasPostVariable( 'SortField' ) ? (string)$http->postVariable( 'SortField' ) : ( isset( $sessionConfig['SortField'] ) ? $sessionConfig['SortField'] : 'tree' );
if ( !preg_match( '/^[A-Za-z0-9_]+$/', $SortField ) )
    $SortField = 'tree';
$SortAscending = $http->hasPostVariable( 'SortOrder' ) ? $http->postVariable( 'SortOrder' ) !== 'desc' : ( isset( $sessionConfig['SortAscending'] ) ? (bool)$sessionConfig['SortAscending'] : true );
$SortField2 = $http->hasPostVariable( 'SortField2' ) ? (string)$http->postVariable( 'SortField2' ) : ( isset( $sessionConfig['SortField2'] ) ? $sessionConfig['SortField2'] : '' );
if ( $SortField2 !== '' && !preg_match( '/^[A-Za-z0-9_]+$/', $SortField2 ) )
    $SortField2 = '';
$SortAscending2 = $http->hasPostVariable( 'SortOrder2' ) ? $http->postVariable( 'SortOrder2' ) !== 'desc' : ( isset( $sessionConfig['SortAscending2'] ) ? (bool)$sessionConfig['SortAscending2'] : true );
if ( $FetchAliasApplyResult !== false && isset( $FetchAliasValues['sort_by'] ) )
{
    $aliasSort = $FetchAliasValues['sort_by'];
    $aliasSortPairs = ( isset( $aliasSort[0] ) && is_array( $aliasSort[0] ) ) ? $aliasSort : array( $aliasSort );
    $SortField = isset( $aliasSortPairs[0][0] ) ? $aliasSortPairs[0][0] : 'tree';
    $SortAscending = isset( $aliasSortPairs[0][1] ) ? (bool)$aliasSortPairs[0][1] : true;
    $SortField2 = isset( $aliasSortPairs[1][0] ) ? $aliasSortPairs[1][0] : '';
    $SortAscending2 = isset( $aliasSortPairs[1][1] ) ? (bool)$aliasSortPairs[1][1] : true;
}
$sessionConfig['SortField'] = $SortField;
$sessionConfig['SortAscending'] = $SortAscending;
$sessionConfig['SortField2'] = $SortField2;
$sessionConfig['SortAscending2'] = $SortAscending2;
// The parts that work for every class (dates of the object, section, state, visibility, name)
$GenericAttributeFilter = $Filters->attributeFilter( false, null );

// No class chosen and none configured: the one with the most objects in this selection
if ( $pickBestClass )
{
    $exportClassFilter = array_filter( (array)$ini_bis->variable( 'ExportSettings', 'ExportClasses' ) );
    $bestCount = -1;
    foreach ( eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, true, false, array( 'name' => 'asc' ) ) as $candidate )
    {
        if ( $exportClassFilter && !in_array( $candidate->attribute( 'id' ), $exportClassFilter ) && !in_array( $candidate->attribute( 'identifier' ), $exportClassFilter ) )
            continue;
        $candidateCount = eZContentFunctionCollection::fetchObjectTreeCount( $FetchSubtree, false, false, 'include', array( $candidate->attribute( 'id' ) ),
                                                                             $GenericAttributeFilter, $depth, $depthOperator, true, false, (bool)$FetchMainnodeonly, false, false );
        $candidateCount = isset( $candidateCount['result'] ) ? (int)$candidateCount['result'] : 0;
        if ( $candidateCount > $bestCount )
        {
            $bestCount = $candidateCount;
            $Class_id = (int)$candidate->attribute( 'id' );
        }
    }
    if ( $Class_id <= 0 )
        $Class_id = (int)$ini->variable( 'UserSettings', 'UserClassID' );
    $FormatColumns = XrowExtractCatalogue::formatColumns( $Class_id );
}
$sessionConfig['Class_id'] = $Class_id;
// The full filter for this class: also the date attributes and the condition on an attribute
$filterClass = eZContentClass::fetch( $Class_id );
$LastExport = (int)eZPreferences::value( XrowExtractFilters::lastExportPreference( $Class_id ) );
$AttributeFilter = $Filters->attributeFilter( $filterClass ? $filterClass->attribute( 'identifier' ) : false, $LastExport );

// "Fetch parameters": the same values as a literal fetch('content','tree', hash(...)) call, to copy into a
// template. Sort is shown symbolically here (the node's own order is not resolved without fetching it).
$resolvedSortLiteral = null;
if ( $SortField !== 'tree' )
{
    $resolvedSortLiteral = XrowExtractFilters::sortParam( $SortField, $SortAscending, $Class_id, $filterClass ? $filterClass->attribute( 'identifier' ) : false, array( $SortField, $SortAscending ) );
    if ( $SortField2 !== '' )
        $resolvedSortLiteral = XrowExtractFilters::combineSort( $resolvedSortLiteral, XrowExtractFilters::sortParam( $SortField2, $SortAscending2, $Class_id, $filterClass ? $filterClass->attribute( 'identifier' ) : false, $resolvedSortLiteral ) );
}
$resolvedExtendedFilter = false;
if ( $Filters->values['extended_filter'] !== '' )
    $resolvedExtendedFilter = XrowExtractTranslationFilter::chainedParams( '<locale>', $Filters->values['extended_filter'], $Filters->extendedParamsArray() );
$tpl->setVariable( 'ResolvedFetchParams', XrowExtractFilters::fetchLiteral( $FetchSubtree, $Class_id, $depth, $depthOperator, $FetchMainnodeonly, $AttributeFilter, $resolvedExtendedFilter, $resolvedSortLiteral ) );

// Languages: every content language by default; the form posts the ticked ones (LanguageSelection marks it)
$ContentLanguages = XrowExtractColumns::contentLanguages();
$allLocales = array_keys( $ContentLanguages );
if ( $http->hasPostVariable( 'SelectAllLanguages' ) )
    $SelectedLanguages = $allLocales;
elseif ( $http->hasPostVariable( 'SelectNoLanguages' ) )
    $SelectedLanguages = array();
elseif ( $http->hasPostVariable( 'LanguageSelection' ) )
    $SelectedLanguages = array_values( array_intersect( $allLocales, (array)( $http->hasPostVariable( 'Languages' ) ? $http->postVariable( 'Languages' ) : array() ) ) );
elseif ( isset( $sessionConfig['Languages'] ) && is_array( $sessionConfig['Languages'] ) )
    $SelectedLanguages = array_values( array_intersect( $allLocales, $sessionConfig['Languages'] ) );
else
    $SelectedLanguages = $allLocales;
$sessionConfig['Languages'] = $SelectedLanguages;

// The posted column list is kept by every action on the same class (the form holds the columns of
// AttributesClassID); a class change, or a first visit, starts from the saved or preselected list
$columnActions = array( 'Remove', 'RemoveAttribute', 'RemoveAllAttributes', 'ResetAttributes', 'MoveAttributeUp', 'MoveAttributeDown',
                        'AddAttribute', 'AddAllAttributes', 'AddColumnSet', 'Download', 'Preview', 'BrowseSubtree', 'Update' );
$keepPosted = false;
foreach ( $columnActions as $action )
{
    $keepPosted = $keepPosted || $http->hasPostVariable( $action );
}
if ( $keepPosted && $http->hasPostVariable( 'AttributesClassID' ) && XrowExtractColumns::dbID( $http->postVariable( 'AttributesClassID' ) ) !== $Class_id )
    $keepPosted = false;
if ( $keepPosted && !$http->hasPostVariable( 'AttributesClassID' ) && $http->hasPostVariable( 'Update' ) )
    $keepPosted = false;
if ( $keepPosted )
{
    $Attributes = $http->hasPostVariable( 'Attributes' ) ? $http->postVariable( 'Attributes' ) : array();
}
else
{
    // An empty saved list means "start from the class", so a class always comes with its attributes
    if ( isset( $sessionConfig['Attributes'][$Class_id] ) && is_array( $sessionConfig['Attributes'][$Class_id] ) && count( $sessionConfig['Attributes'][$Class_id] ) > 0 )
    {
        $Attributes = $sessionConfig['Attributes'][$Class_id];
    }
    else
        if ( $ini_bis->variable( 'ExportSettings', 'PreselectAttributes' ) == 'false' )
        {
            $Attributes = array();
        }
        else
        {
            $Attributes = array();
            $contentAttributeList = eZContentClassAttribute::fetchListByClassID( $Class_id, eZContentClass::VERSION_STATUS_DEFINED, true );

            foreach ( $contentAttributeList as $classattribute )
            {
                $Attributes[] = array(
                    'id' => $classattribute->attribute( 'identifier' ) ,
                    'name' => $classattribute->attribute( 'name' ) ,
                    'exportname' => $classattribute->attribute( 'identifier' )
                );
            }
        }
}

// The columns before any add action, to tell what an add brought in
$columnIDsBeforeAdd = array();
foreach ( (array)$Attributes as $item )
{
    if ( is_array( $item ) && isset( $item['id'] ) )
        $columnIDsBeforeAdd[$item['id']] = true;
}

// Add attribute action that modify previous array
if ( $http->hasPostVariable( 'AddAttribute' ) )
{
    $addID = (string)$http->postVariable( 'AddAttributeID' );

    if ( ctype_digit( $addID ) )
    {
        $attribute = eZContentClassAttribute::fetch( (int)$addID );
        if ( $attribute instanceof eZContentClassAttribute )
        {
            $Attributes[] = array(
                'id' => $attribute->attribute( 'identifier' ) ,
                'name' => $attribute->attribute( 'name' ) ,
                'exportname' => $attribute->attribute( 'identifier' )
            );
        }
    }
    elseif ( isset( $ExtraAttributes[$addID] ) )
    {
        $Attributes[] = $ExtraAttributes[$addID];
    }
    elseif ( isset( $FormatColumns[$addID] ) )
    {
        $Attributes[] = $FormatColumns[$addID];
    }
}

// A column set: its columns that are not in the list yet, in the set's order
if ( $http->hasPostVariable( 'AddColumnSet' ) )
{
    $present = array();
    foreach ( (array)$Attributes as $item )
    {
        if ( is_array( $item ) && isset( $item['id'] ) )
            $present[$item['id']] = true;
    }
    $classColumnsByID = array();
    foreach ( XrowExtractColumns::classColumns( $Class_id ) as $column )
        $classColumnsByID[$column['id']] = $column;
    foreach ( XrowExtractCatalogue::setColumnIDs( (string)$http->postVariable( 'AddColumnSet' ), $Class_id ) as $id )
    {
        if ( isset( $present[$id] ) )
            continue;
        if ( isset( $classColumnsByID[$id] ) )
            $Attributes[] = $classColumnsByID[$id];
        elseif ( isset( $FormatColumns[$id] ) )
            $Attributes[] = $FormatColumns[$id];
        elseif ( isset( $ExtraAttributes[$id] ) )
            $Attributes[] = $ExtraAttributes[$id];
        $present[$id] = true;
    }
}

// Remove all columns, or start again from every class attribute
if ( $http->hasPostVariable( 'RemoveAllAttributes' ) || $http->hasPostVariable( 'ResetAttributes' ) )
{
    $Attributes = array();
}

// Add every class attribute that is not a column yet, in class order
if ( $http->hasPostVariable( 'AddAllAttributes' ) || $http->hasPostVariable( 'ResetAttributes' ) )
{
    $present = array();
    foreach ( (array)$Attributes as $item )
    {
        if ( is_array( $item ) && isset( $item['id'] ) )
            $present[$item['id']] = true;
    }
    foreach ( eZContentClassAttribute::fetchListByClassID( $Class_id, eZContentClass::VERSION_STATUS_DEFINED, true ) as $classattribute )
    {
        if ( !isset( $present[$classattribute->attribute( 'identifier' )] ) )
        {
            $Attributes[] = array(
                'id' => $classattribute->attribute( 'identifier' ) ,
                'name' => $classattribute->attribute( 'name' ) ,
                'exportname' => $classattribute->attribute( 'identifier' )
            );
        }
    }
}

// One column: remove it, or move it one place (the buttons are named Action[<position>])
$Attributes = array_values( (array)$Attributes );
foreach ( array( 'RemoveAttribute', 'MoveAttributeUp', 'MoveAttributeDown' ) as $action )
{
    if ( !$http->hasPostVariable( $action ) || !is_array( $http->postVariable( $action ) ) )
        continue;
    $keys = array_keys( $http->postVariable( $action ) );
    $index = (int)$keys[0];
    if ( !isset( $Attributes[$index] ) )
        continue;
    if ( $action === 'RemoveAttribute' )
    {
        array_splice( $Attributes, $index, 1 );
    }
    else
    {
        $other = $action === 'MoveAttributeUp' ? $index - 1 : $index + 1;
        if ( isset( $Attributes[$other] ) )
        {
            $moved = $Attributes[$index];
            $Attributes[$index] = $Attributes[$other];
            $Attributes[$other] = $moved;
        }
    }
}

// Remove action that modify previous array
if ( $http->hasPostVariable( 'Remove' ) && $http->hasPostVariable( 'RemoveIDArray' ) )
{
    $Removes = array_map( 'intval', (array)$http->postVariable( 'RemoveIDArray' ) );
    $AttributesClean = array();
    foreach ( array_values( (array)$Attributes ) as $i => $item )
    {
        if ( ! in_array( $i, $Removes, true ) )
            $AttributesClean[] = $item;
    }
    $Attributes = $AttributesClean;
}

// Every column is a class attribute identifier or one of the special columns; anything else is dropped
$AttributesClean = array();
foreach ( (array)$Attributes as $item )
{
    if ( !is_array( $item ) || !isset( $item['id'] ) || !is_string( $item['id'] ) )
        continue;
    if ( strpos( $item['id'], ':' ) !== false )
    {
        if ( !isset( $FormatColumns[$item['id']] ) )
            continue;
    }
    elseif ( strpos( $item['id'], '.' ) !== false )
    {
        if ( !isset( $ExtraAttributes[$item['id']] ) )
            continue;
    }
    elseif ( !preg_match( '/^[A-Za-z0-9_]+$/', $item['id'] ) )
        continue;
    $AttributesClean[] = array(
        'id' => $item['id'],
        'name' => isset( $item['name'] ) ? (string)$item['name'] : $item['id'],
        'exportname' => ( isset( $item['exportname'] ) && trim( (string)$item['exportname'] ) !== '' ) ? trim( (string)$item['exportname'] ) : $item['id']
    );
}
$Attributes = $AttributesClean;

$sessionConfig['Attributes'][$Class_id] = $Attributes;

// What an add action brought in, for the notice and the marked rows
$AddedColumnIDs = array();
$addedNames = array();
$addAction = $http->hasPostVariable( 'AddAttribute' ) || $http->hasPostVariable( 'AddAllAttributes' ) || $http->hasPostVariable( 'AddColumnSet' );
if ( $addAction )
{
    foreach ( $Attributes as $item )
    {
        if ( !isset( $columnIDsBeforeAdd[$item['id']] ) )
        {
            $AddedColumnIDs[] = $item['id'];
            $addedNames[] = $item['name'];
        }
    }
}
$tpl->setVariable( 'AddedColumnIDs', $AddedColumnIDs );
$tpl->setVariable( 'ColumnNotice', $addAction ? array( 'count' => count( $AddedColumnIDs ),
                                                       'names' => implode( ', ', array_slice( $addedNames, 0, 12 ) ) . ( count( $addedNames ) > 12 ? ' …' : '' ) ) : false );
$http->setSessionVariable( 'eZExtractConfig', $sessionConfig );
// Put above vars in tpl
$tpl->setVariable( 'Type', $type );
$tpl->setVariable( 'Subtree', $Subtree );
$tpl->setVariable( 'Class_id', $Class_id );
$tpl->setVariable( 'Attributes', $Attributes );
// What the view shows beside each column: datatype, flags, what the cell holds
$tpl->setVariable( 'AttributeMeta', XrowExtractColumns::attributeMeta( $Class_id ) );
$chosenClass = eZContentClass::fetch( $Class_id );
$tpl->setVariable( 'ChosenClass', $chosenClass ? array( 'identifier' => $chosenClass->attribute( 'identifier' ),
                                                        'name' => $chosenClass->attribute( 'name' ),
                                                        'attributes' => count( $chosenClass->dataMap() ) ) : false );
$tpl->setVariable( 'ExtraAttributes', $ExtraAttributes );
// The Filters card
$tpl->setVariable( 'Filters', $Filters->values );
$tpl->setVariable( 'FilterCount', $Filters->activeCount() );
$tpl->setVariable( 'FilterDateModes', XrowExtractFilters::dateModes() );
$tpl->setVariable( 'FilterOperators', XrowExtractFilters::operators() );
$tpl->setVariable( 'FilterFields', array_values( XrowExtractFilters::classFields( $Class_id ) ) );
$tpl->setVariable( 'FilterSections', eZSection::fetchList() );
$filterStates = array();
foreach ( eZContentObjectStateGroup::fetchByOffset( 50, 0 ) as $stateGroup )
{
    if ( $stateGroup->attribute( 'is_internal' ) && $stateGroup->attribute( 'identifier' ) !== 'ez_lock' )
        continue;
    foreach ( $stateGroup->attribute( 'states' ) as $state )
        $filterStates[] = array( 'id' => (int)$state->attribute( 'id' ), 'name' => $stateGroup->attribute( 'current_translation' )->attribute( 'name' ) . ': ' . $state->attribute( 'current_translation' )->attribute( 'name' ) );
}
$tpl->setVariable( 'FilterStates', $filterStates );
$tpl->setVariable( 'LastExport', $LastExport );
$tpl->setVariable( 'SortField', $SortField );
$tpl->setVariable( 'SortAscending', $SortAscending );
$tpl->setVariable( 'SortField2', $SortField2 );
$tpl->setVariable( 'SortAscending2', $SortAscending2 );
$tpl->setVariable( 'SortFields', XrowExtractFilters::sortFields() );
// Condition rows: several, joined with and/or, on a class attribute or an object field
$tpl->setVariable( 'FilterConditionOperators', XrowExtractFilters::conditionOperators() );
$tpl->setVariable( 'FilterTwoValueOperators', XrowExtractFilters::twoValueOperators() );
$tpl->setVariable( 'FilterListOperators', XrowExtractFilters::listOperators() );
$tpl->setVariable( 'FilterNoValueOperators', XrowExtractFilters::noValueOperators() );
$filterObjectFields = array();
foreach ( XrowExtractFilters::objectFields() as $id => $field )
    $filterObjectFields[] = array_merge( array( 'identifier' => $id ), $field );
$tpl->setVariable( 'FilterObjectFields', $filterObjectFields );
$tpl->setVariable( 'FilterConditionsJoinAffectsEverything', $Filters->conditionsJoinAffectsEverything() );
// An exact / at most / at least depth below the node
$tpl->setVariable( 'FilterDepthModes', XrowExtractFilters::depthModes() );
// An extended attribute filter chained with the language filter
$tpl->setVariable( 'ExtendedFilters', XrowExtractFilters::extendedFilters() );
// Named fetches (fetchalias.ini): this siteaccess's, and the default siteaccess's
$tpl->setVariable( 'FetchAliasChoices', $FetchAliasChoices );
$tpl->setVariable( 'FetchAliasApplied', $FetchAliasApplyResult ? $FetchAliasApplyResult['applied'] : array() );
$tpl->setVariable( 'FetchAliasUnknown', $FetchAliasApplyResult ? $FetchAliasApplyResult['unknown'] : array() );
$tpl->setVariable( 'FetchAliasFillable', $FetchAliasFillable );
$tpl->setVariable( 'FetchAliasParamsRaw', $fetchAliasParamsRaw );
// The picker: special columns by group, attribute formats, column sets
$ExtraGroups = array();
foreach ( XrowExtractCatalogue::groups() as $group => $label )
    $ExtraGroups[$group] = array( 'label' => $label, 'columns' => array() );
foreach ( $ExtraAttributes as $id => $column )
    $ExtraGroups[$column['group']]['columns'][] = $column;
$tpl->setVariable( 'ExtraGroups', $ExtraGroups );
$tpl->setVariable( 'FormatColumns', array_values( $FormatColumns ) );
$ColumnSets = array();
foreach ( XrowExtractCatalogue::columnSets() as $id => $set )
    $ColumnSets[] = array( 'id' => $id, 'name' => $set[0], 'description' => $set[1] );
$tpl->setVariable( 'ColumnSets', $ColumnSets );
$tpl->setVariable( 'Mainnodeonly', $Mainnodeonly );
$tpl->setVariable( 'has_prefilledata', $hasPreFilledData );
$tpl->setVariable( 'Escape', $Escape ? 1 : 0 );

// The same selection as the export: the user's read access, depth and main nodes
$fCollection = new eZContentFunctionCollection();
$list = $fCollection->fetchObjectTreeCount( $FetchSubtree, false, false, 'include', array(
    $Class_id
), $AttributeFilter, $depth, $depthOperator, true, false, (bool)$FetchMainnodeonly, false, false );

$tpl->setVariable( 'max_count', isset( $list['result'] ) ? $list['result'] : 0 );

// Translations per language in this selection; a row is one object in one chosen language
$LanguageChoices = array();
$LanguageCounts = array();
foreach ( $ContentLanguages as $locale => $language )
{
    $languageCount = $fCollection->fetchObjectTreeCount( $FetchSubtree, true, $locale, 'include', array( $Class_id ),
                                                         $AttributeFilter, $depth, $depthOperator, true, false, (bool)$FetchMainnodeonly,
                                                         XrowExtractTranslationFilter::chainedParams( $locale, $Filters->values['extended_filter'], $Filters->extendedParamsArray() ), false );
    $LanguageCounts[$locale] = isset( $languageCount['result'] ) ? (int)$languageCount['result'] : 0;
    $LanguageChoices[] = array_merge( $language, array( 'count' => $LanguageCounts[$locale],
                                                        'selected' => in_array( $locale, $SelectedLanguages, true ) ) );
}
$tpl->setVariable( 'LanguageChoices', $LanguageChoices );
$tpl->setVariable( 'SelectedLanguageCount', count( $SelectedLanguages ) );
$translationRows = 0;
foreach ( $SelectedLanguages as $locale )
    $translationRows += $LanguageCounts[$locale];

// How many rows the file will hold with this limit and offset
$exportRows = $hasPreFilledData ? count( $preFilledIDs ) : max( 0, $translationRows - $Offset );
if ( $Limit && !$hasPreFilledData )
    $exportRows = min( $exportRows, $Limit );
$tpl->setVariable( 'export_rows', $exportRows );
$tpl->setVariable( 'prefilled_count', count( $preFilledIDs ) );
$tpl->setVariable( 'TabNotation', '\t' );

// The classes to choose from, each with how many objects it has in this selection (node, depth,
// main locations, the user's read access), so the list itself shows where the content is
$exportClasses = array_filter( (array)$ini_bis->variable( 'ExportSettings', 'ExportClasses' ) );
$ClassChoices = array();
foreach ( eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, true, false, array( 'name' => 'asc' ) ) as $class )
{
    if ( $exportClasses && !in_array( $class->attribute( 'id' ), $exportClasses ) && !in_array( $class->attribute( 'identifier' ), $exportClasses ) )
        continue;
    $count = null;
    if ( !$hasPreFilledData )
    {
        $classCount = $fCollection->fetchObjectTreeCount( $FetchSubtree, false, false, 'include', array( $class->attribute( 'id' ) ),
                                                          $GenericAttributeFilter, $depth, $depthOperator, true, false, (bool)$FetchMainnodeonly, false, false );
        $count = isset( $classCount['result'] ) ? (int)$classCount['result'] : 0;
    }
    $ClassChoices[] = array( 'id' => (int)$class->attribute( 'id' ), 'name' => $class->attribute( 'name' ), 'count' => $count );
}
$tpl->setVariable( 'ClassChoices', $ClassChoices );

// The script's URL carries a hash of its content: a changed script is a new URL, never a stale cached copy
$scriptFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract.js';
$scriptHash = is_file( $scriptFile ) ? md5_file( $scriptFile ) : false;
$tpl->setVariable( 'ScriptVersion', $scriptHash !== false ? substr( $scriptHash, 0, 12 ) : '0' );
$tpl->setVariable( 'ExportableDatatypes', (array)$csvINI->variable( 'General', 'ExportableDatatypes' ) );
// The exported datatypes by name, for the sidebar
$datatypeNames = array();
foreach ( array_unique( (array)$csvINI->variable( 'General', 'ExportableDatatypes' ) ) as $datatype )
    $datatypeNames[] = array( 'id' => $datatype, 'name' => XrowExtractColumns::datatypeName( $datatype ), 'cell' => (string)XrowExtractColumns::cellDescription( $datatype ) );
usort( $datatypeNames, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
$tpl->setVariable( 'ExportableDatatypeNames', $datatypeNames );

// Download and preview build the file the same way; the preview reads it back as a spreadsheet would
// "Download with manifest" (a zip of the file and its typed column manifest) and "Manifest only" build
// the same file as Download; they only send something else at the end
$downloadWithManifest = $http->hasPostVariable( 'DownloadWithManifest' ) && class_exists( 'ZipArchive' );
$downloadManifestOnly = $http->hasPostVariable( 'DownloadManifest' );
$isPreview = !$http->hasPostVariable( 'Download' ) && !$downloadWithManifest && !$downloadManifestOnly && $http->hasPostVariable( 'Preview' );
$previewRowChoices = array( 10, 25, 50, 100 );
$PreviewRows = (int)eZPreferences::value( 'admin_xrowextract_preview_rows' );
if ( $http->hasPostVariable( 'PreviewRows' ) && in_array( (int)$http->postVariable( 'PreviewRows' ), $previewRowChoices, true ) )
{
    $PreviewRows = (int)$http->postVariable( 'PreviewRows' );
    eZPreferences::setValue( 'admin_xrowextract_preview_rows', $PreviewRows );
}
if ( !in_array( $PreviewRows, $previewRowChoices, true ) )
    $PreviewRows = 25;
$tpl->setVariable( 'PreviewRows', $PreviewRows );
$tpl->setVariable( 'PreviewRowChoices', $previewRowChoices );

// Velocity ends a request after 30s, too short for a large export: "Run in the background" builds
// the same command line as bin/php/csv.php from this view's already-validated state (never from
// raw request strings) and starts it detached, so the export keeps going after the page returns.
$tpl->setVariable( 'BackgroundAvailable', XrowExtractJob::available() );
$tpl->setVariable( 'RunningJobsCount', XrowExtractJob::countRunning( eZUser::currentUser()->attribute( 'login' ), XrowExtractJob::allowAllJobs() ) );

// Save the fully resolved view state as a preset (everything above is settled by now: the node, the
// class, the columns, the languages, every filter, the sort and the output settings)
if ( $http->hasPostVariable( 'SavePreset' ) && !$hasPreFilledData )
{
    $presetName = trim( (string)( $http->hasPostVariable( 'PresetSaveName' ) ? $http->postVariable( 'PresetSaveName' ) : '' ) );
    if ( $presetName === '' )
    {
        $PresetNotice = array( 'error' => true, 'text' => ezpI18n::tr( 'design/standard/extract', 'Name the preset first.' ) );
    }
    else
    {
        $saveNode = eZContentObjectTreeNode::fetch( $Subtree );
        $saveDefinition = array(
            'scope' => $Scope,
            'subtree' => $Subtree,
            'subtree_remote_id' => $saveNode ? $saveNode->attribute( 'remote_id' ) : '',
            'class_id' => $Class_id,
            'class_identifier' => $chosenClass ? $chosenClass->attribute( 'identifier' ) : '',
            'mainnodeonly' => $Mainnodeonly,
            'limit' => $Limit,
            'offset' => $Offset,
            'languages' => $SelectedLanguages,
            'attributes' => $Attributes,
            'filters' => $Filters->values,
            'sort_field' => $SortField,
            'sort_ascending' => $SortAscending,
            'sort_field2' => $SortField2,
            'sort_ascending2' => $SortAscending2,
            'output_format' => $OutputFormat,
            'separator' => $Separator,
            'line_separator' => $LineSeparator,
            'escape' => $Escape,
        );
        $savePresetRef = (string)( $http->hasPostVariable( 'PresetSaveRef' ) ? $http->postVariable( 'PresetSaveRef' ) : '' );
        $saveExistingID = false;
        if ( strpos( $savePresetRef, 'user:' ) === 0 )
        {
            $existingForSave = XrowExtractPreset::fetch( $savePresetRef );
            if ( $existingForSave && XrowExtractPreset::canEdit( $existingForSave, $xePresetLogin, $xePresetAllowAll ) )
                $saveExistingID = $existingForSave['id'];
        }
        $savedID = XrowExtractPreset::saveUser( $xePresetLogin, array(
            'name' => $presetName,
            'description' => (string)( $http->hasPostVariable( 'PresetSaveDescription' ) ? $http->postVariable( 'PresetSaveDescription' ) : '' ),
            'view' => 'csv',
            'shared' => $http->hasPostVariable( 'PresetSaveShared' ) && $http->postVariable( 'PresetSaveShared' ),
            'extends' => '',
            'placeholders' => array(),
            'definition' => $saveDefinition,
        ), $saveExistingID );
        $LoadedPresetRef = 'user:' . $savedID;
        $http->setSessionVariable( 'eZExtractLoadedPreset', $LoadedPresetRef );
        $http->setSessionVariable( 'eZExtractPresetFields', $buildPresetChips( $saveDefinition, $Subtree, $Class_id ) );
        $LoadedPresetFields = $buildPresetChips( $saveDefinition, $Subtree, $Class_id );
        $PresetNotice = array( 'error' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Preset "%name" saved.', null, array( '%name' => $presetName ) ) );
    }
}
$tpl->setVariable( 'PresetNotice', $PresetNotice );
$tpl->setVariable( 'LoadedPresetRef', $LoadedPresetRef );
$tpl->setVariable( 'LoadedPresetFields', $LoadedPresetFields );
$tpl->setVariable( 'LoadedPresetUnresolved', $LoadedPresetUnresolved );
$UserPresets = array();
foreach ( XrowExtractPreset::fetchUserList() as $preset )
{
    if ( $xePresetAllowAll || $preset['owner_login'] === $xePresetLogin || $preset['shared'] )
        $UserPresets[] = array_merge( $preset, array(
            'owner_user' => XrowExtractJob::ownerInfo( $preset['owner_login'] ),
            'mine' => $preset['owner_login'] === $xePresetLogin,
            'can_edit' => XrowExtractPreset::canEdit( $preset, $xePresetLogin, $xePresetAllowAll ),
            'ini_block' => XrowExtractPreset::toIniBlock( $preset ),
            'summary' => XrowExtractPreset::summaryLine( $preset ),
            'placeholder_list' => array_values( array_map( function ( $name, $spec ) { return array( 'name' => $name, 'default' => isset( $spec['default'] ) ? $spec['default'] : '' ); }, array_keys( $preset['placeholders'] ), $preset['placeholders'] ) ),
        ) );
}
$SitePresets = array();
foreach ( XrowExtractPreset::fetchSiteList() as $preset )
    $SitePresets[] = array_merge( $preset, array(
        'ini_block' => XrowExtractPreset::toIniBlock( $preset ),
        'summary' => XrowExtractPreset::summaryLine( $preset ),
        'placeholder_list' => array_values( array_map( function ( $name, $spec ) { return array( 'name' => $name, 'default' => isset( $spec['default'] ) ? $spec['default'] : '' ); }, array_keys( $preset['placeholders'] ), $preset['placeholders'] ) ),
    ) );
$tpl->setVariable( 'UserPresets', $UserPresets );
$tpl->setVariable( 'SitePresets', $SitePresets );
// Site presets grouped by Audience (Site/Editors/Developers/Partners/Users/Maintenance, in that fixed
// order, anything else after) — the Presets card shows one collapsible section per group instead of one
// long flat list, now that the catalogue ships 40+ of them.
$xeAudienceLabels = array(
    'Site' => ezpI18n::tr( 'design/standard/extract', 'Site' ),
    'Editors' => ezpI18n::tr( 'design/standard/extract', 'Editors' ),
    'Developers' => ezpI18n::tr( 'design/standard/extract', 'Developers' ),
    'Partners' => ezpI18n::tr( 'design/standard/extract', 'Partners' ),
    'Users' => ezpI18n::tr( 'design/standard/extract', 'Users' ),
    'Maintenance' => ezpI18n::tr( 'design/standard/extract', 'Maintenance' ),
);
$SitePresetsByAudience = array();
foreach ( XrowExtractPreset::audienceOrder() as $xeAudienceKey )
    $SitePresetsByAudience[$xeAudienceKey] = array( 'key' => $xeAudienceKey,
        'label' => isset( $xeAudienceLabels[$xeAudienceKey] ) ? $xeAudienceLabels[$xeAudienceKey] : $xeAudienceKey, 'presets' => array() );
foreach ( $SitePresets as $sitePreset )
{
    $xeAudienceKey = $sitePreset['audience'];
    if ( !isset( $SitePresetsByAudience[$xeAudienceKey] ) )
        $SitePresetsByAudience[$xeAudienceKey] = array( 'key' => $xeAudienceKey,
            'label' => isset( $xeAudienceLabels[$xeAudienceKey] ) ? $xeAudienceLabels[$xeAudienceKey] : $xeAudienceKey, 'presets' => array() );
    $SitePresetsByAudience[$xeAudienceKey]['presets'][] = $sitePreset;
}
// Empty groups are dropped rather than shown as a section with nothing in it
$SitePresetsByAudience = array_values( array_filter( $SitePresetsByAudience, function ( $group ) { return count( $group['presets'] ) > 0; } ) );
$tpl->setVariable( 'SitePresetsByAudience', $SitePresetsByAudience );

if ( $http->hasPostVariable( 'RunInBackground' ) || $AutoRunPresetInBackground )
{
    $backgroundError = false;
    if ( $hasPreFilledData )
        $backgroundError = ezpI18n::tr( 'design/standard/extract', 'A pre filled selection cannot run in the background; download it directly.' );
    elseif ( !XrowExtractJob::available() )
        $backgroundError = ezpI18n::tr( 'design/standard/extract', 'Background exports are not available on this server (no PHP command line binary was found, or exec() is disabled).' );
    elseif ( count( $Attributes ) === 0 )
        $backgroundError = ezpI18n::tr( 'design/standard/extract', 'Add at least one column first.' );
    elseif ( count( $SelectedLanguages ) === 0 )
        $backgroundError = ezpI18n::tr( 'design/standard/extract', 'Choose at least one language first.' );
    else
    {
        // The current siteaccess, so a URL alias in the export (below a multi-site root, several
        // siteaccesses can see the same node under a different path) comes out exactly as this
        // Download would have written it
        $currentAccess = eZSiteAccess::current();
        $jobArgs = array();
        if ( $currentAccess && !empty( $currentAccess['name'] ) )
            $jobArgs[] = '--siteaccess=' . $currentAccess['name'];
        $jobArgs[] = '--class=' . $Class_id;
        if ( $Scope === 'all' )
        {
            $jobArgs[] = '--scope=all';
        }
        else
        {
            $jobArgs[] = '--node=' . $Subtree;
            if ( $type === 'list' )
                $jobArgs[] = '--depth=list';
        }
        if ( $FetchMainnodeonly === '1' )
            $jobArgs[] = '--main-only';
        if ( $Offset > 0 )
            $jobArgs[] = '--offset=' . $Offset;
        if ( $Limit > 0 )
            $jobArgs[] = '--limit=' . $Limit;

        $columnIDs = array();
        $names = array();
        foreach ( $Attributes as $item )
        {
            $columnIDs[] = $item['id'];
            if ( $item['exportname'] !== $item['id'] )
                // A comma or = in the name would break --names=id=name,id=name; kept readable instead of lost
                $names[] = $item['id'] . '=' . str_replace( array( ',', '=' ), ' ', $item['exportname'] );
        }
        $jobArgs[] = '--columns=' . implode( ',', $columnIDs );
        if ( $names )
            $jobArgs[] = '--names=' . implode( ',', $names );
        $jobArgs[] = '--separator=' . $Separator;
        $jobArgs[] = '--line-endings=' . $LineSeparator;
        if ( !$Escape )
            $jobArgs[] = '--unquoted';
        $jobArgs[] = '--languages=' . implode( ',', $SelectedLanguages );
        $jobArgs[] = '--format=' . $OutputFormat;
        foreach ( $Filters->cliArgs( $LastExport, true ) as $filterArg )
            $jobArgs[] = $filterArg;
        if ( $SortField !== 'tree' )
        {
            $jobArgs[] = '--sort=' . $SortField;
            $jobArgs[] = '--order=' . ( $SortAscending ? 'asc' : 'desc' );
        }
        if ( $SortField2 !== '' )
        {
            $jobArgs[] = '--sort2=' . $SortField2;
            $jobArgs[] = '--order2=' . ( $SortAscending2 ? 'asc' : 'desc' );
        }
        $jobExtension = XrowExtractWriter::formats()[$OutputFormat]['extension'];

        $className = $chosenClass ? $chosenClass->attribute( 'name' ) : ( 'class ' . $Class_id );
        if ( $Scope === 'all' )
        {
            $outputName = XrowExtractColumns::fileName( $chosenClass ? $chosenClass->attribute( 'identifier' ) : 'class_' . $Class_id, '_all_export.' . $jobExtension );
            $what = $className . ' — ' . ezpI18n::tr( 'design/standard/extract', 'whole site' );
        }
        else
        {
            $jobNode = eZContentObjectTreeNode::fetch( $Subtree );
            $outputName = XrowExtractColumns::fileName( $jobNode ? $jobNode->attribute( 'name' ) : ( 'node_' . $Subtree ), '_export.' . $jobExtension );
            $what = $className . ' — ' . ( $jobNode ? $jobNode->attribute( 'name' ) : ( 'node ' . $Subtree ) );
        }

        $jobID = XrowExtractJob::create( array(
            'type' => 'csv',
            'owner' => eZUser::currentUser()->attribute( 'login' ),
            'what' => $what,
            'format' => $OutputFormat,
            'output_file' => $outputName,
            'args' => $jobArgs,
            'preset' => $AutoRunPresetInBackground ?: ( $LoadedPresetRef !== '' ? $LoadedPresetRef : '' ),
        ) );
        if ( !XrowExtractJob::start( $jobID ) )
        {
            XrowExtractJob::update( $jobID, array(
                'state' => 'failed', 'ended' => time(),
                'error' => ezpI18n::tr( 'design/standard/extract', 'Could not start the background process.' ),
            ) );
        }
        $http->setSessionVariable( 'eZExtractJobStarted', $jobID );
        return $module->redirectTo( 'xrowextract/jobs' );
    }
    $tpl->setVariable( 'BackgroundError', $backgroundError );
}

// Export as package (.ezpkg): the class chosen here, below the node/subtree (or the whole site)
// chosen here, as a real content package - always a background job (xrowextract/jobs, type
// "package") since a class-wide export can be as large as the class itself; bin/php/package.php
// --export does the actual work, the same code path ext:xrowextract:package/xrowextract/package use.
if ( $http->hasPostVariable( 'ExportAsPackage' ) )
{
    if ( !XrowExtractJob::available() )
    {
        $tpl->setVariable( 'BackgroundError', ezpI18n::tr( 'design/standard/extract', 'Background exports are not available on this server (no PHP command line binary was found, or exec() is disabled).' ) );
    }
    else
    {
        $exportArgs = array( '--export' );
        if ( $Scope === 'all' )
        {
            $exportContentINI = eZSiteAccess::getIni( eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ), 'content.ini' );
            $rootNodeID = (int)$exportContentINI->variable( 'NodeSettings', 'RootNode' );
            $exportArgs[] = '--node=' . $rootNodeID;
            $exportArgs[] = '--subtree';
        }
        else
        {
            // "list" (single level) is exported the same as the full subtree for a package - a
            // package has no notion of "one level only", unlike a row export's own depth option
            $exportArgs[] = '--node=' . $Subtree;
            $exportArgs[] = '--subtree';
        }
        if ( $Class_id )
            $exportArgs[] = '--class=' . $Class_id;

        $className = $chosenClass ? $chosenClass->attribute( 'name' ) : ( 'class ' . $Class_id );
        $exportWhat = $className . ' — ' . ( $Scope === 'all' ? ezpI18n::tr( 'design/standard/extract', 'whole site' ) : ( ( $exportNode = eZContentObjectTreeNode::fetch( $Subtree ) ) ? $exportNode->attribute( 'name' ) : ( 'node ' . $Subtree ) ) );
        $exportJobID = XrowExtractJob::create( array(
            'type' => 'package', 'owner' => eZUser::currentUser()->attribute( 'login' ),
            'what' => 'Export as package: ' . $exportWhat,
            'format' => 'ezpkg', 'output_file' => 'export.ezpkg', 'args' => $exportArgs,
        ) );
        if ( !XrowExtractJob::start( $exportJobID ) )
        {
            XrowExtractJob::update( $exportJobID, array(
                'state' => 'failed', 'ended' => time(),
                'error' => ezpI18n::tr( 'design/standard/extract', 'Could not start the background process.' ),
            ) );
        }
        $http->setSessionVariable( 'eZExtractJobStarted', $exportJobID );
        return $module->redirectTo( 'xrowextract/jobs' );
    }
}

// Download, format Content package (.ezpkg): the same filtered node id collection and
// XrowExtractPackage::exportNodeIDsIntoPackage() call as --format=ezpkg on the command line (see
// bin/php/csv.php) - a preview click still shows CSV rows (harmless; a package has no row preview), only
// a real Download/auto-download takes this branch. "Run in the background" needs no branch of its own:
// its job args already include --format=ezpkg (built below, format-agnostic), and bin/php/csv.php does
// the rest.
if ( $OutputFormat === 'ezpkg' && !$isPreview && ( $http->hasPostVariable( 'Download' ) || $AutoDownloadAfterLoad ) )
{
    $packageNodeIDs = array();
    $packageWant = $Limit ? $Limit : PHP_INT_MAX;
    foreach ( $SelectedLanguages as $locale )
    {
        if ( count( $packageNodeIDs ) >= $packageWant )
            break;
        for ( $batchOffset = 0; count( $packageNodeIDs ) < $packageWant; $batchOffset += 500 )
        {
            $take = min( 500, $packageWant - count( $packageNodeIDs ) );
            $result = eZContentFunctionCollection::fetchObjectTree( $FetchSubtree, array( 'name', true ), true, $locale, $batchOffset, $take,
                $depth, $depthOperator, $Class_id, $AttributeFilter,
                XrowExtractTranslationFilter::chainedParams( $locale, $Filters->values['extended_filter'], $Filters->extendedParamsArray() ),
                'include', array( $Class_id ), false, (bool)$FetchMainnodeonly, true, false, true, false, true );
            $batch = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : array();
            foreach ( $batch as $treeNode )
                $packageNodeIDs[(int)$treeNode->attribute( 'node_id' )] = true;
            if ( count( $batch ) < $take )
                break;
        }
    }
    $packageNodeIDs = array_keys( $packageNodeIDs );
    if ( !$packageNodeIDs )
    {
        $DownloadNotice = array( 'error' => true, 'text' => ezpI18n::tr( 'design/standard/extract', 'Nothing matches this selection (node/class/filters); nothing to export as a package.' ) );
    }
    else
    {
        $packageIdentifier = $chosenClass ? $chosenClass->attribute( 'identifier' ) : ( 'class_' . $Class_id );
        $packageName = XrowExtractPackage::validPackageName( 'xrowextract_export_' . $packageIdentifier . '_' . ( $Scope === 'all' ? 'all' : $Subtree ) );
        $packageSummary = 'Exported ' . count( $packageNodeIDs ) . ' ' . $packageIdentifier . ' object(s), matching the selection below node ' . $FetchSubtree . '.';
        $package = eZPackage::create( $packageName, array( 'summary' => $packageSummary, 'vendor' => 'xrowextract' ) );
        XrowExtractPackage::attachAboutDocument( $package, 'Exported by the "Content package (.ezpkg)" file format on xrowextract/csv, ' . $packageSummary );
        XrowExtractPackage::exportNodeIDsIntoPackage( $package, $packageNodeIDs, $SelectedLanguages, true );
        $package->setAttribute( 'is_active', true );
        $package->store();
        if ( $Scope === 'all' )
        {
            $packageFileBase = $packageIdentifier;
        }
        else
        {
            $chosenNode = eZContentObjectTreeNode::fetch( $Subtree );
            $packageFileBase = $chosenNode instanceof eZContentObjectTreeNode ? $chosenNode->attribute( 'name' ) : ( 'node_' . $Subtree );
        }
        $packageFile = XrowExtractColumns::fileName( $packageFileBase, $Scope === 'all' ? '_all_export.ezpkg' : '_export.ezpkg' );
        $exportPath = XrowExtractPackage::exportToPrivateFile( $package, $packageIdentifier );
        $package->remove();
        if ( $exportPath === false )
        {
            $DownloadNotice = array( 'error' => true, 'text' => ezpI18n::tr( 'design/standard/extract', 'Could not write the package file.' ) );
        }
        else
        {
            header( 'Cache-Control: private, no-store, max-age=0' );
            header( 'Pragma: no-cache' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Content-Type: application/gzip' );
            header( 'Content-Length: ' . filesize( $exportPath ) );
            header( 'Content-Disposition: attachment; filename="' . $packageFile . '"' );
            while ( @ob_end_clean() );
            readfile( $exportPath );
            @unlink( $exportPath );
            eZExecution::cleanExit();
        }
    }
}
// Shown next to the download buttons: the page's other notices were handed to the template further up
$tpl->setVariable( 'DownloadNotice', isset( $DownloadNotice ) ? $DownloadNotice : false );

// Format ezpkg already had its own branch above for a real download/auto-download (returned via
// cleanExit() on success, or fell through with $DownloadNotice set on error) - never reaches
// XrowExtractWriter, which has no row-writing logic of its own for a package. A column manifest does not apply
// to a package either, so the two manifest downloads are left out for it as well.
if ( ( $http->hasPostVariable( 'Download' ) || $downloadWithManifest || $downloadManifestOnly || $isPreview || $AutoDownloadAfterLoad )
     && !( $OutputFormat === 'ezpkg' && !$isPreview ) )
{
    $started = microtime( true );
    $newLine = $isPreview ? "\n" : $LineSeparatorArray[$LineSeparator]['value'];

    // More than one language: a language column in front tells the rows apart
    $ExportColumns = $Attributes;
    $hasLanguageColumn = false;
    foreach ( $Attributes as $item )
        $hasLanguageColumn = $hasLanguageColumn || $item['id'] === 'ezcontentobject.language';
    if ( count( $SelectedLanguages ) > 1 && !$hasLanguageColumn )
        array_unshift( $ExportColumns, $ExtraAttributes['ezcontentobject.language'] );
    // The preview reads CSV back into its table; JSON and XML get a sample of the first rows next to it
    $writerMeta = array( 'class' => ( $metaClass = eZContentClass::fetch( $Class_id ) ) ? $metaClass->attribute( 'identifier' ) : '', 'created' => date( 'c' ) );
    // The typed column manifest: embedded in an XML/JSON download, and the second file of "with manifest"
    $downloadManifest = null;
    if ( !$isPreview )
    {
        $downloadManifest = XrowExtractManifest::build( array(
            'type' => 'csv', 'format' => $OutputFormat, 'separator' => $Separator, 'quoted' => (bool)$Escape, 'line_endings' => $newLine,
            'languages' => $SelectedLanguages, 'columns' => $ExportColumns, 'class_id' => (int)$Class_id, 'allow_password_hash' => $allowPasswordHash,
            'filters' => $Filters->values, 'preset' => $LoadedPresetRef !== '' ? $LoadedPresetRef : null, 'run_mode' => 'full',
            'selection' => array( 'scope' => $Scope, 'node_id' => $Scope === 'all' ? null : (int)$Subtree, 'offset' => (int)$Offset, 'limit' => (int)$Limit,
                                  'main_only' => $FetchMainnodeonly === '1', 'user' => eZUser::currentUser()->attribute( 'login' ) ),
        ) );
        $writerMeta['manifest'] = $downloadManifest;
    }
    $writer = new XrowExtractWriter( $isPreview ? 'csv' : $OutputFormat, $ExportColumns, $Separator, $Escape, $newLine, $writerMeta );
    $parser = $writer->parser();
    $sampleWriter = ( $isPreview && $OutputFormat !== 'csv' ) ? new XrowExtractWriter( $OutputFormat, $ExportColumns, $Separator, $Escape, "\n", $writerMeta ) : null;
    $sample = '';
    $sampleWriterExtension = $sampleWriter ? $sampleWriter->extension() : 'csv';
    $data = $writer->begin();
    $file = 'export.csv';
    $exportTotal = 0;

    $maxRows = $isPreview ? ( $Limit ? min( $Limit, $PreviewRows ) : $PreviewRows ) : ( $Limit ? $Limit : PHP_INT_MAX );
    $written = 0;
    $writeRow = function ( eZContentObject $obj, $locale ) use ( &$data, &$written, &$sample, $writer, $sampleWriter, $ExportColumns, $parser, $ExtraAttributes, $allowPasswordHash )
    {
        XrowExtractColumns::$language = $locale;
        $data .= $writer->row( XrowExtractColumns::rowCells( $ExportColumns, $obj, $parser, $ExtraAttributes, $allowPasswordHash ) );
        if ( $sampleWriter && $written < 3 )
            $sample .= $sampleWriter->row( XrowExtractColumns::rowCells( $ExportColumns, $obj, $sampleWriter->parser(), $ExtraAttributes, $allowPasswordHash ) );
        $written++;
    };

    if ( $hasPreFilledData )
    {
        // Objects handed over by another view: each in every chosen language it has
        $exportTotal = 0;
        foreach ( $preFilledIDs as $objectID )
        {
            $obj = eZContentObject::fetch( $objectID );
            if ( !$obj instanceof eZContentObject || !$obj->canRead() )
                continue;
            foreach ( $SelectedLanguages as $locale )
            {
                if ( !in_array( $locale, $obj->availableLanguages(), true ) )
                    continue;
                $exportTotal++;
                if ( $written < $maxRows )
                    $writeRow( $obj, $locale );
            }
            eZContentObject::clearCache( array( $objectID ) );
        }
    }
    else
    {
        if ( $Scope === 'all' )
        {
            // Every object of the class the user may read, by name; the file is named after the class
            $exportClass = eZContentClass::fetch( $Class_id );
            $file = XrowExtractColumns::fileName( $exportClass ? $exportClass->attribute( 'identifier' ) : 'class_' . $Class_id, '_all_export.csv' );
            $sortBy = array( 'name', true );
        }
        else
        {
            $node = eZContentObjectTreeNode::fetch( $Subtree );
            if ( !( $node instanceof eZContentObjectTreeNode ) || !$node->canRead() )
            {
                return $module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
            }
            $file = XrowExtractColumns::fileName( $node->attribute( 'name' ) );

            $sortBy = $node->sortArray();
            $sortBy = $sortBy[0];
        }
        if ( $SortField !== 'tree' )
        {
            $sortClass = eZContentClass::fetch( $Class_id );
            $sortBy = XrowExtractFilters::sortParam( $SortField, $SortAscending, $Class_id, $sortClass ? $sortClass->attribute( 'identifier' ) : false, $sortBy );
        }
        if ( $SortField2 !== '' )
        {
            if ( !isset( $sortClass ) )
                $sortClass = eZContentClass::fetch( $Class_id );
            $sortBy = XrowExtractFilters::combineSort( $sortBy, XrowExtractFilters::sortParam( $SortField2, $SortAscending2, $Class_id, $sortClass ? $sortClass->attribute( 'identifier' ) : false, $sortBy ) );
        }
        $exportTotal = $Limit ? min( $Limit, max( 0, $translationRows - $Offset ) ) : max( 0, $translationRows - $Offset );

        // The chosen languages one after the other; skip and take count over all of them.
        // Limitation false: the user's content/read policies apply.
        $skip = $Offset;
        foreach ( $SelectedLanguages as $locale )
        {
            if ( $written >= $maxRows )
                break;
            if ( $skip >= $LanguageCounts[$locale] )
            {
                $skip -= $LanguageCounts[$locale];
                continue;
            }
            for ( $batchOffset = $skip; $written < $maxRows; $batchOffset += 100 )
            {
                $take = min( 100, $maxRows - $written );
                $result = $fCollection->fetchObjectTree( $FetchSubtree, $sortBy, true, $locale, $batchOffset, $take, $depth, $depthOperator, $Class_id, $AttributeFilter, XrowExtractTranslationFilter::chainedParams( $locale, $Filters->values['extended_filter'], $Filters->extendedParamsArray() ), 'include', array(
                    $Class_id
                ), false, (bool)$FetchMainnodeonly, true, false, true, false, true );
                $batch = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : array();
                foreach ( $batch as $treeNode )
                {
                    $obj = $treeNode->attribute( 'object' );
                    if ( $obj instanceof eZContentObject && $obj->canRead() )
                        $writeRow( $obj, $locale );
                }
                // A large export: keep the object cache from growing with every row
                eZContentObject::clearCache();
                if ( count( $batch ) < $take )
                    break;
            }
            $skip = 0;
        }
    }
    XrowExtractColumns::$language = null;
    $data .= $writer->end();

    if ( $isPreview )
    {
        $tpl->setVariable( 'preview_sample', $sampleWriter ? $sampleWriter->begin() . $sample . $sampleWriter->end() : '' );
        $tpl->setVariable( 'preview_sample_format', $sampleWriter ? strtoupper( $OutputFormat ) : '' );
        $tpl->setVariable( 'PreviewColumns', $ExportColumns );
        $tpl->setVariable( 'preview', xrowExtractPreview( $data, $Separator, $Escape, $Offset, $exportTotal, preg_replace( '/\.csv$/', '.' . $sampleWriterExtension, $file ) ?? $file, microtime( true ) - $started ) );
        if ( $http->hasPostVariable( 'PreviewOnly' ) )
        {
            // The preview panel alone, for the view's script
            header( 'Cache-Control: private, no-store, max-age=0' );
            header( 'Content-Type: text/html; charset=' . eZTextCodec::httpCharset() );
            header( 'X-Content-Type-Options: nosniff' );
            $tpl->setVariable( 'Attributes', $Attributes );
            $tpl->setVariable( 'PreviewColumns', $ExportColumns );
            $html = $tpl->fetch( 'design:xrowextract/csv_preview.tpl' );
            while ( @ob_end_clean() );
            echo $html;
            eZExecution::cleanExit();
        }
    }
    else
    {
        $httpCharset = eZTextCodec::httpCharset();
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'X-Content-Type-Options: nosniff' );
        $file = preg_replace( '/\.csv$/', '.' . $writer->extension(), $file ) ?? $file;
        // The manifest, finished: rows, size and checksum of exactly the bytes sent
        $finishedManifest = XrowExtractManifest::finish( $downloadManifest, null, $written );
        $finishedManifest['file'] = array( 'name' => $file, 'bytes' => strlen( $data ), 'sha256' => hash( 'sha256', $data ) );
        $manifestJSON = XrowExtractManifest::encode( $finishedManifest );
        // A zip that cannot be written (no room in the cache folder) sends the file alone, never an empty download
        $zipped = $downloadWithManifest && !$downloadManifestOnly ? XrowExtractManifest::zipWithManifest( $file, $data, $manifestJSON ) : false;
        if ( $downloadWithManifest && !$downloadManifestOnly && $zipped === false )
        {
            eZDebug::writeError( 'The zip of ' . $file . ' and its manifest could not be written; the file is sent alone.', 'xrowextract' );
            $downloadWithManifest = false;
        }
        // Every download is a row of the export history (no file is kept for it)
        XrowExtractHistory::record( array(
            'owner_login' => eZUser::currentUser()->attribute( 'login' ), 'kind' => 'csv', 'trigger_type' => 'download', 'run_mode' => 'full',
            'what' => ( $metaClass ? $metaClass->attribute( 'name' ) : 'class ' . $Class_id ) . ' — ' . ( $Scope === 'all' ? ezpI18n::tr( 'design/standard/extract', 'whole site' ) : ( ( $historyNode = eZContentObjectTreeNode::fetch( $Subtree ) ) ? $historyNode->attribute( 'name' ) : 'node ' . $Subtree ) ),
            'preset_ref' => $LoadedPresetRef, 'output_format' => $OutputFormat,
            'started_at' => (int)$started, 'ended_at' => time(), 'run_state' => 'done', 'row_count' => $written,
            'byte_size' => strlen( $data ), 'checksum' => $finishedManifest['file']['sha256'],
            'file_name' => $downloadManifestOnly ? $file . XrowExtractManifest::SIDECAR_SUFFIX : ( $downloadWithManifest ? $file . '.zip' : $file ),
        ) );
        if ( $downloadManifestOnly )
        {
            $data = $manifestJSON;
            $file .= XrowExtractManifest::SIDECAR_SUFFIX;
            header( 'Content-Type: application/json; charset=utf-8' );
        }
        elseif ( $downloadWithManifest && $zipped !== false )
        {
            $data = $zipped;
            $file .= '.zip';
            header( 'Content-Type: application/zip' );
        }
        else
        {
            header( 'Content-Type: ' . $writer->contentType( $httpCharset ) );
        }
        header( 'Content-Length: ' . strlen( $data ) );
        header( 'Content-Disposition: attachment; filename="' . $file . '"' );

        // "Changed since my last export" starts from here next time (not for the manifest alone: no rows went out)
        if ( !$downloadManifestOnly )
            eZPreferences::setValue( XrowExtractFilters::lastExportPreference( $Class_id ), time() );
        while ( @ob_end_clean() );

        echo $data;
        eZExecution::cleanExit();
    }
}

if ( $http->hasPostVariable( 'BrowseSubtree' ) )
{
    $return = eZContentBrowse::browse( array(
        'action_name' => 'ExtractionSubtree' ,
        'description_template' => 'design:xrowextract/browse_node.tpl' ,
        'from_page' => '/xrowextract/csv' ,
        'persistent_data' => array(
            'Subtree' => $Subtree ,
            'Class_id' => $Class_id ,
            'Attributes' => $Attributes ,
            'LineSeparator' => $LineSeparator ,
            'Separator' => $Separator
        )
    ), $module );
}

$Result = array();
$Result['content'] = $tpl->fetch( "design:xrowextract/csv.tpl" );
$Result['path'] = array(
    array(
        'url' => false ,
        'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' )
    ) ,
    array(
        'url' => false ,
        'text' => ezpI18n::tr( 'design/standard/xrowextract', 'CSV' )
    )
);

$Result['left_menu'] = 'design:xrowextract/menu.tpl';

?>
