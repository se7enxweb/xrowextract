<?php
/**
 * xrowextract/archive: the content of many classes below many nodes as one
 * archive, one CSV file per class. The selection (nodes, classes, format)
 * is kept in the session; every button also works without JavaScript.
 */

$module = $Params['Module'];
$http = eZHTTPTool::instance();
$tpl = eZTemplate::factory();
$contentINI = eZINI::instance( 'content.ini' );

$state = $http->hasSessionVariable( 'eZExtractArchive' ) ? $http->sessionVariable( 'eZExtractArchive' ) : null;
if ( !is_array( $state ) )
{
    $sets = XrowExtractArchive::nodeSets();
    $state = array( 'nodes' => $sets['content_media']['nodes'], 'excluded' => array(), 'format' => 'zip',
                    'separator' => ',', 'escape' => true, 'line' => 'win32' );
}
$nodeSets = XrowExtractArchive::nodeSets();
$formats = XrowExtractArchive::formats();
$lineSeparators = array( 'win32' => "\r\n", 'unix' => "\n", 'mac' => "\r" );
$error = false;

// Nodes: a ready-made set, one added or removed, all removed, or chosen in the browse page
if ( $http->hasPostVariable( 'UseNodeSet' ) && isset( $nodeSets[$http->postVariable( 'UseNodeSet' )] ) )
    $state['nodes'] = $nodeSets[$http->postVariable( 'UseNodeSet' )]['nodes'];
if ( $http->hasPostVariable( 'AddNodeID' ) && is_array( $http->postVariable( 'AddNodeID' ) ) )
{
    foreach ( array_keys( $http->postVariable( 'AddNodeID' ) ) as $nodeID )
        $state['nodes'][] = (int)$nodeID;
}
if ( $http->hasPostVariable( 'RemoveNodeID' ) && is_array( $http->postVariable( 'RemoveNodeID' ) ) )
{
    $remove = array_map( 'intval', array_keys( $http->postVariable( 'RemoveNodeID' ) ) );
    $state['nodes'] = array_values( array_diff( $state['nodes'], $remove ) );
}
if ( $http->hasPostVariable( 'ClearNodes' ) )
    $state['nodes'] = array();
if ( $http->hasPostVariable( 'SelectedNodeIDArray' ) && $http->hasPostVariable( 'BrowseActionName' )
     && $http->postVariable( 'BrowseActionName' ) === 'ExtractionArchiveNode' )
{
    foreach ( (array)$http->postVariable( 'SelectedNodeIDArray' ) as $nodeID )
        $state['nodes'][] = (int)$nodeID;
}
$state['nodes'] = array_values( array_unique( array_filter( array_map( 'intval', $state['nodes'] ) ) ) );

// Format
if ( $http->hasPostVariable( 'ArchiveFormat' ) && isset( $formats[$http->postVariable( 'ArchiveFormat' )] ) )
    $state['format'] = $http->postVariable( 'ArchiveFormat' );
if ( $http->hasPostVariable( 'Separator' ) )
{
    $separator = (string)$http->postVariable( 'Separator' );
    if ( $separator === '\t' )
        $separator = "\t";
    $state['separator'] = ( strlen( $separator ) === 1 && strpbrk( $separator, "\"\r\n" ) === false ) ? $separator : ',';
}
if ( $http->hasPostVariable( 'Escape' ) )
    $state['escape'] = (bool)$http->postVariable( 'Escape' );
if ( $http->hasPostVariable( 'LineSeparator' ) && isset( $lineSeparators[$http->postVariable( 'LineSeparator' )] ) )
    $state['line'] = $http->postVariable( 'LineSeparator' );
if ( !isset( $formats[$state['format']] ) || !$formats[$state['format']]['available'] )
    $state['format'] = 'zip';

// What the selection holds
$resolved = XrowExtractArchive::resolveNodes( $state['nodes'] );
$roots = XrowExtractArchive::exportRoots( $resolved );
$counts = XrowExtractArchive::classCounts( $roots );

// Classes: every class with objects is exported unless it was switched off; the form posts the ones kept
if ( $http->hasPostVariable( 'ClassSelection' ) )
{
    $kept = array_map( 'intval', (array)( $http->hasPostVariable( 'ClassIDs' ) ? $http->postVariable( 'ClassIDs' ) : array() ) );
    $shown = array_map( 'intval', explode( ',', (string)$http->postVariable( 'ClassSelection' ) ) );
    $state['excluded'] = array_values( array_unique( array_merge(
        array_diff( $state['excluded'], $shown ),       // switched off earlier and not on the form now
        array_diff( $shown, $kept ) ) ) );              // on the form and not ticked
}
if ( $http->hasPostVariable( 'SelectAllClasses' ) )
    $state['excluded'] = array();
if ( $http->hasPostVariable( 'SelectNoClasses' ) )
    $state['excluded'] = array_keys( $counts );
$state['excluded'] = array_map( 'intval', $state['excluded'] );

$classes = array();
$selectedClassIDs = array();
foreach ( eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, true, false, array( 'name' => 'asc' ) ) as $class )
{
    $id = (int)$class->attribute( 'id' );
    if ( !isset( $counts[$id] ) )
        continue;
    $included = !in_array( $id, $state['excluded'], true );
    if ( $included )
        $selectedClassIDs[] = $id;
    $classes[] = array( 'id' => $id, 'name' => $class->attribute( 'name' ), 'identifier' => $class->attribute( 'identifier' ),
                        'count' => $counts[$id], 'included' => $included,
                        'columns' => count( XrowExtractColumns::identityColumns() ) + count( $class->dataMap() ) );
}

$http->setSessionVariable( 'eZExtractArchive', $state );

// Browse for nodes to add
if ( $http->hasPostVariable( 'BrowseArchiveNode' ) )
{
    eZContentBrowse::browse( array( 'action_name' => 'ExtractionArchiveNode',
                                    'description_template' => 'design:xrowextract/browse_archive_node.tpl',
                                    'from_page' => '/xrowextract/archive' ), $module );
    return;
}

// Download
if ( $http->hasPostVariable( 'DownloadArchive' ) )
{
    if ( !$roots || !$selectedClassIDs )
    {
        $error = ezpI18n::tr( 'design/standard/extract', 'Choose at least one node and one class.' );
    }
    else
    {
        try
        {
            $result = XrowExtractArchive::build( $roots, $selectedClassIDs, $state['format'], $state['separator'], $state['escape'], $lineSeparators[$state['line']] );
            $types = array( 'zip' => 'application/zip', 'tar.gz' => 'application/gzip', 'tar.bz2' => 'application/x-bzip2',
                            'tar.xz' => 'application/x-xz', '7z' => 'application/x-7z-compressed', 'rar' => 'application/vnd.rar' );
            header( 'Cache-Control: private, no-store, max-age=0' );
            header( 'Pragma: no-cache' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Content-Type: ' . $types[$state['format']] );
            header( 'Content-Length: ' . filesize( $result['path'] ) );
            header( 'Content-Disposition: attachment; filename="' . $result['name'] . '"' );
            while ( @ob_end_clean() );
            readfile( $result['path'] );
            XrowExtractArchive::removeWork( $result['work'] );
            eZExecution::cleanExit();
        }
        catch ( Exception $e )
        {
            eZDebug::writeError( $e->getMessage(), 'xrowextract/archive' );
            $error = ezpI18n::tr( 'design/standard/extract', 'The archive could not be written: %reason', null, array( '%reason' => $e->getMessage() ) );
        }
    }
}

// Nodes to pick from: the roots of the installation and the first levels of the content and media trees
$suggestions = array();
$selectedIDs = array_flip( $state['nodes'] );
$addSuggestion = function ( $node, $level ) use ( &$suggestions, $selectedIDs )
{
    if ( !$node instanceof eZContentObjectTreeNode || !$node->canRead() || isset( $suggestions[$node->attribute( 'node_id' )] ) || count( $suggestions ) >= 150 )
        return;
    $suggestions[$node->attribute( 'node_id' )] = array(
        'node_id' => (int)$node->attribute( 'node_id' ), 'name' => $node->attribute( 'name' ), 'class_name' => $node->attribute( 'class_name' ),
        'class_identifier' => $node->attribute( 'class_identifier' ), 'path' => $node->attribute( 'path_identification_string' ),
        'level' => $level, 'count' => XrowExtractArchive::subtreeCount( $node ), 'selected' => isset( $selectedIDs[$node->attribute( 'node_id' )] ),
    );
};
foreach ( array( 'RootNode' => 2, 'MediaRootNode' => 1, 'UserRootNode' => 1 ) as $setting => $depth )
{
    $top = eZContentObjectTreeNode::fetch( (int)$contentINI->variable( 'NodeSettings', $setting ) );
    if ( !$top )
        continue;
    $addSuggestion( $top, 0 );
    foreach ( (array)$top->subTree( array( 'Depth' => 1, 'SortBy' => $top->sortArray(), 'Limit' => 60 ) ) as $child )
    {
        $addSuggestion( $child, 1 );
        if ( $depth > 1 && $child->attribute( 'children_count' ) > 0 )
        {
            foreach ( (array)$child->subTree( array( 'Depth' => 1, 'SortBy' => $child->sortArray(), 'Limit' => 30 ) ) as $grandChild )
                $addSuggestion( $grandChild, 2 );
        }
    }
}

// The selected nodes for the list
$nodes = array();
foreach ( $resolved as $item )
{
    $nodes[] = array(
        'node_id' => $item['id'],
        'node' => $item['node'],
        'covered_by' => $item['covered_by'] ? $item['covered_by']->attribute( 'name' ) : false,
        'count' => ( $item['node'] && !$item['covered_by'] ) ? XrowExtractArchive::subtreeCount( $item['node'] ) : 0,
    );
}
$activeSet = false;
foreach ( $nodeSets as $id => $set )
{
    $sorted = $set['nodes']; sort( $sorted );
    $current = $state['nodes']; sort( $current );
    if ( $sorted === $current )
        $activeSet = $id;
}

$totalRows = 0;
foreach ( $classes as $class )
    $totalRows += $class['included'] ? $class['count'] : 0;

$tpl->setVariable( 'state', $state );
$tpl->setVariable( 'separator_display', $state['separator'] === "\t" ? '\t' : $state['separator'] );
$tpl->setVariable( 'node_sets', $nodeSets );
$tpl->setVariable( 'active_set', $activeSet );
$tpl->setVariable( 'nodes', $nodes );
$tpl->setVariable( 'suggestions', array_values( $suggestions ) );
$tpl->setVariable( 'classes', $classes );
$tpl->setVariable( 'class_ids_shown', implode( ',', array_map( function ( $c ) { return $c['id']; }, $classes ) ) );
$tpl->setVariable( 'selected_class_count', count( $selectedClassIDs ) );
$tpl->setVariable( 'total_rows', $totalRows );
$tpl->setVariable( 'formats', $formats );
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'TabNotation', '\t' );
$scriptFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract.js';
$tpl->setVariable( 'ScriptVersion', is_file( $scriptFile ) ? substr( md5_file( $scriptFile ), 0, 12 ) : '0' );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/archive.tpl' );
$Result['path'] = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Site archive' ) ),
);
$Result['left_menu'] = 'design:xrowextract/menu_archive.tpl';

?>
