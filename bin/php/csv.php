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
    '[class:][node:][scope:][depth:][main-only][offset:][limit:][columns:][add:][sets:][names:][separator:][line-endings:][unquoted]' .
    '[languages:][format:][output:][preview;][list-classes][list-columns][user:]',
    '',
    array(
        'class'        => 'Class id or identifier (required to export)',
        'node'         => 'Node id to export below (default: export.ini StartNodeID or the user placement)',
        'scope'        => 'node (default): below --node; all: every object of the class in the whole site',
        'depth'        => 'tree (default): the whole subtree; list: direct children only',
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
        'output'       => 'File to write (default: <node name>_export.csv, or <class>_all_export.csv for --scope=all); - for stdout',
        'preview'      => 'Print the first rows (default 10) as a table instead of writing a file',
        'list-classes' => 'List the classes with how many objects each has in the selection',
        'list-columns' => 'List the attributes of --class with datatype and meta information, and the special columns',
        'user'         => 'Export with the read access of this login (default: admin)',
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

// Selection
$scope = $options['scope'] === 'all' ? 'all' : 'node';
if ( $options['scope'] && !in_array( $options['scope'], array( 'node', 'all' ), true ) )
    $fail( '--scope is node or all.' );
$nodeID = $options['node'] ? (int)$options['node']
        : (int)( $exportINI->variable( 'ExportSettings', 'StartNodeID' ) ?: $siteINI->variable( 'UserSettings', 'DefaultUserPlacement' ) );
$depth = $options['depth'] === 'list' ? 1 : false;
$depthOperator = $depth ? 'eq' : false;
$mainOnly = (bool)$options['main-only'];
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
$offset = max( 0, (int)$options['offset'] );
$limit = max( 0, (int)$options['limit'] );

// Languages: all, or the given locales, in the order of the content languages (site default first)
$contentLanguages = array_keys( XrowExtractColumns::contentLanguages() );
if ( !$options['languages'] || $options['languages'] === 'all' )
    $languages = $contentLanguages;
else
{
    $languages = array_values( array_filter( array_map( 'trim', explode( ',', $options['languages'] ) ) ) );
    foreach ( $languages as $locale )
        if ( !in_array( $locale, $contentLanguages, true ) )
            $fail( "Unknown language $locale (--languages). Languages: " . implode( ', ', $contentLanguages ) . '.' );
}
$countIn = function ( $classID, $locale = false ) use ( $fetchNode, $depth, $depthOperator, $mainOnly )
{
    $result = eZContentFunctionCollection::fetchObjectTreeCount( $fetchNode, $locale !== false, $locale, 'include', array( (int)$classID ),
                                                                 false, $depth, $depthOperator, true, false, $mainOnly,
                                                                 $locale !== false ? XrowExtractTranslationFilter::params( $locale ) : false, false );
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

// The class
if ( !$options['class'] )
    $fail( 'Missing --class (id or identifier). --list-classes shows them.' );
$class = ctype_digit( (string)$options['class'] ) ? eZContentClass::fetch( (int)$options['class'] ) : eZContentClass::fetchByIdentifier( $options['class'] );
if ( !$class instanceof eZContentClass )
    $fail( "No class {$options['class']} (--class)." );
$classID = (int)$class->attribute( 'id' );
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

// Format
$separators = array( 'comma' => ',', 'semicolon' => ';', 'tab' => "\t", '\t' => "\t", 'pipe' => '|' );
$separator = $options['separator'] === null || $options['separator'] === false ? ',' : (string)$options['separator'];
$separator = isset( $separators[$separator] ) ? $separators[$separator] : $separator;
if ( strlen( $separator ) !== 1 || strpbrk( $separator, "\"\r\n" ) !== false )
    $fail( '--separator is one character (not a quote or line break), or comma, semicolon, tab, pipe.' );
$lines = array( 'win32' => "\r\n", 'crlf' => "\r\n", 'windows' => "\r\n", 'unix' => "\n", 'lf' => "\n", 'mac' => "\r", 'cr' => "\r" );
$lineKey = $options['line-endings'] ? strtolower( $options['line-endings'] ) : 'unix';
if ( !isset( $lines[$lineKey] ) )
    $fail( '--line-endings is win32 (crlf), unix (lf) or mac (cr).' );
$newLine = $lines[$lineKey];
$outputFormat = $options['format'] ? $options['format'] : 'csv';
if ( !XrowExtractWriter::isFormat( $outputFormat ) )
    $fail( "Unknown format $outputFormat (--format). Formats: " . implode( ', ', array_keys( XrowExtractWriter::formats() ) ) . '.' );
$parser = new ParserInterface( $separator, !$options['unquoted'] );

// Rows, in batches with the object cache cleared
$previewRows = $options['preview'] !== null && $options['preview'] !== false ? max( 1, (int)( $options['preview'] === true ? 10 : $options['preview'] ) ) : 0;
$sortBy = array( 'name', true );
if ( $scope === 'node' )
{
    $sort = $node->sortArray();
    $sortBy = $sort[0];
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

$writer = new XrowExtractWriter( $previewRows ? 'csv' : $outputFormat, $columns, $separator, !$options['unquoted'], $previewRows ? "\n" : $newLine,
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
                                                                $classID, false, XrowExtractTranslationFilter::params( $locale ), 'include', array( $classID ), false, $mainOnly, true, false, true, false, true );
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
