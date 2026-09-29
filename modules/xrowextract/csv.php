<?php

if ( !function_exists( 'xrowExtractPreview' ) ) {
/**
 * Read an export back the way a spreadsheet does (same separator and
 * quoting) for the preview table: header, rows with a flag per cell, and
 * what the preview shows about the whole file.
 */
function xrowExtractPreview( $data, $separator, $escape, $offset, $total, $file, $seconds )
{
    $fh = fopen( 'php://temp', 'r+' );
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
if ( !is_array( $sessionConfig ) )
    $sessionConfig = array();

// Set col & row separator: one character (\t is a tab), never a quote or a line break
$Separator = $http->hasPostVariable( 'Separator' ) ? (string)$http->postVariable( 'Separator' ) : ',';
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

$LineSeparator = $http->hasPostVariable( 'LineSeparator' ) ? $http->postVariable( 'LineSeparator' ) : $sys->osType();
if ( !is_string( $LineSeparator ) || !isset( $LineSeparatorArray[$LineSeparator] ) )
    $LineSeparator = 'unix';

$tpl->setVariable( 'Separator', $Separator === "\t" ? '\t' : $Separator );
$tpl->setVariable( 'LineSeparator', $LineSeparator );
$tpl->setVariable( 'LineSeparatorArray', $LineSeparatorArray );

// Set limit & offset
$Limit = max( 0, (int)( $http->hasPostVariable( 'Limit' ) ? $http->postVariable( 'Limit' ) : $ini_bis->variable( 'ExportSettings', 'Limit' ) ) );
$Offset = max( 0, (int)( $http->hasPostVariable( 'Offset' ) ? $http->postVariable( 'Offset' ) : $ini_bis->variable( 'ExportSettings', 'Offset' ) ) );

$tpl->setVariable( 'Limit', $Limit );
$tpl->setVariable( 'Offset', $Offset );

// What is the default subtree
if ( ! $http->hasPostVariable( 'Subtree' ) && isset( $sessionConfig['Subtree'] ) && (int)$sessionConfig['Subtree'] > 0 )
{
    $Subtree = (int)$sessionConfig['Subtree'];
}
elseif ( ! $http->hasPostVariable( 'Subtree' ) )
{
    $Subtree = ( $ini_bis->variable( 'ExportSettings', 'StartNodeID' ) == '' ) ? $ini->variable( 'UserSettings', 'DefaultUserPlacement' ) : $ini_bis->variable( 'ExportSettings', 'StartNodeID' );
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

$Mainnodeonly = ( $http->hasPostVariable( 'mainnodeonly' ) && $http->postVariable( 'mainnodeonly' ) ) ? '1' : '0';

$Escape = $http->hasPostVariable( 'Escape' ) ? (bool)$http->postVariable( 'Escape' ) : true;

if ( ! $hasPreFilledData )
{
    // What is the default class
    if ( ! $http->hasPostVariable( 'Class_id' ) )
    {
        $Class_id = ( $ini_bis->variable( 'ExportSettings', 'DefaultClassID' ) == '' ) ? $ini->variable( 'UserSettings', 'UserClassID' ) : $ini_bis->variable( 'ExportSettings', 'DefaultClassID' );
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
$Class_id = (int)$Class_id;
// The attribute formats of the class (identifier:format columns)
$FormatColumns = XrowExtractCatalogue::formatColumns( $Class_id );

if ( $http->hasPostVariable( 'SelectedNodeIDArray' ) )
{
    $nodes = (array)$http->postVariable( 'SelectedNodeIDArray' );
    if ( isset( $nodes[0] ) )
        $Subtree = $nodes[0];
}
$Subtree = (int)$Subtree;
$sessionConfig['Subtree'] = $Subtree;

// Scope: below the chosen node, or every object of the class in the whole site (read from the top of the
// tree, at main locations, so each object is one row). The chosen node stays for switching back.
// list: the node's direct children; tree: its whole subtree; all: the whole site. A form without the
// three-way choice (or "node") keeps the depth field it posted.
$scopeIn = $http->hasPostVariable( 'Scope' ) ? (string)$http->postVariable( 'Scope' )
                                             : ( isset( $sessionConfig['Scope'] ) ? (string)$sessionConfig['Scope'] : '' );
if ( $scopeIn === 'node' || $scopeIn === '' )
    $scopeIn = $type;
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
if ( $keepPosted && $http->hasPostVariable( 'AttributesClassID' ) && (int)$http->postVariable( 'AttributesClassID' ) !== $Class_id )
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
), false, $depth, $depthOperator, true, false, (bool)$FetchMainnodeonly, false, false );

$tpl->setVariable( 'max_count', isset( $list['result'] ) ? $list['result'] : 0 );

// Translations per language in this selection; a row is one object in one chosen language
$LanguageChoices = array();
$LanguageCounts = array();
foreach ( $ContentLanguages as $locale => $language )
{
    $languageCount = $fCollection->fetchObjectTreeCount( $FetchSubtree, true, $locale, 'include', array( $Class_id ),
                                                         false, $depth, $depthOperator, true, false, (bool)$FetchMainnodeonly, false, false );
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
                                                          false, $depth, $depthOperator, true, false, (bool)$FetchMainnodeonly, false, false );
        $count = isset( $classCount['result'] ) ? (int)$classCount['result'] : 0;
    }
    $ClassChoices[] = array( 'id' => (int)$class->attribute( 'id' ), 'name' => $class->attribute( 'name' ), 'count' => $count );
}
$tpl->setVariable( 'ClassChoices', $ClassChoices );

// The script's URL carries a hash of its content: a changed script is a new URL, never a stale cached copy
$scriptFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract.js';
$tpl->setVariable( 'ScriptVersion', is_file( $scriptFile ) ? substr( md5_file( $scriptFile ), 0, 12 ) : '0' );
$tpl->setVariable( 'ExportableDatatypes', (array)$csvINI->variable( 'General', 'ExportableDatatypes' ) );
// The exported datatypes by name, for the sidebar
$datatypeNames = array();
foreach ( array_unique( (array)$csvINI->variable( 'General', 'ExportableDatatypes' ) ) as $datatype )
    $datatypeNames[] = array( 'id' => $datatype, 'name' => XrowExtractColumns::datatypeName( $datatype ), 'cell' => (string)XrowExtractColumns::cellDescription( $datatype ) );
usort( $datatypeNames, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
$tpl->setVariable( 'ExportableDatatypeNames', $datatypeNames );

// Download and preview build the file the same way; the preview reads it back as a spreadsheet would
$isPreview = !$http->hasPostVariable( 'Download' ) && $http->hasPostVariable( 'Preview' );
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

if ( $http->hasPostVariable( 'Download' ) || $isPreview )
{
    $started = microtime( true );
    $parser = new ParserInterface( $Separator, $Escape );
    $newLine = $isPreview ? "\n" : $LineSeparatorArray[$LineSeparator]['value'];

    // More than one language: a language column in front tells the rows apart
    $ExportColumns = $Attributes;
    $hasLanguageColumn = false;
    foreach ( $Attributes as $item )
        $hasLanguageColumn = $hasLanguageColumn || $item['id'] === 'ezcontentobject.language';
    if ( count( $SelectedLanguages ) > 1 && !$hasLanguageColumn )
        array_unshift( $ExportColumns, $ExtraAttributes['ezcontentobject.language'] );
    $data = implode( $Separator, XrowExtractColumns::headerCells( $ExportColumns, $parser ) ) . $newLine;
    $file = 'export.csv';
    $exportTotal = 0;

    $maxRows = $isPreview ? ( $Limit ? min( $Limit, $PreviewRows ) : $PreviewRows ) : ( $Limit ? $Limit : PHP_INT_MAX );
    $written = 0;
    $writeRow = function ( eZContentObject $obj, $locale ) use ( &$data, &$written, $ExportColumns, $parser, $ExtraAttributes, $allowPasswordHash, $Separator, $newLine )
    {
        XrowExtractColumns::$language = $locale;
        $data .= implode( $Separator, XrowExtractColumns::rowCells( $ExportColumns, $obj, $parser, $ExtraAttributes, $allowPasswordHash ) ) . $newLine;
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
                $result = $fCollection->fetchObjectTree( $FetchSubtree, $sortBy, true, $locale, $batchOffset, $take, $depth, $depthOperator, $Class_id, false, false, 'include', array(
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

    if ( $isPreview )
    {
        $tpl->setVariable( 'PreviewColumns', $ExportColumns );
        $tpl->setVariable( 'preview', xrowExtractPreview( $data, $Separator, $Escape, $Offset, $exportTotal, $file, microtime( true ) - $started ) );
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
        header( 'Content-Type: text/csv; charset=' . $httpCharset );
        header( 'Content-Length: ' . strlen( $data ) );
        header( 'Content-Disposition: attachment; filename="' . $file . '"' );

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
