<?php

if ( !function_exists( 'xrowExtractNodeName' ) ) {
/** The name of a node the current user may read, else ''. */
function xrowExtractNodeName( $nodeID )
{
    $node = $nodeID ? eZContentObjectTreeNode::fetch( (int)$nodeID ) : null;
    return ( $node instanceof eZContentObjectTreeNode && $node->canRead() ) ? $node->attribute( 'name' ) : '';
}

/**
 * The value of a special (non class attribute) column for one object. Only
 * the columns in $ExtraAttributes exist; each is computed here, nothing from
 * the request decides which class or method is called.
 */
function xrowExtractExtraValue( $key, eZContentObject $obj, $allowPasswordHash )
{
    if ( strpos( $key, 'ezuser.' ) === 0 )
    {
        $user = eZUser::fetch( $obj->attribute( 'id' ) );
        if ( !$user instanceof eZUser )
        {
            return '';
        }
        switch ( $key )
        {
            case 'ezuser.login':         return $user->attribute( 'login' );
            case 'ezuser.email':         return $user->attribute( 'email' );
            case 'ezuser.password_hash': return $allowPasswordHash ? $user->attribute( 'password_hash' ) : '';
            case 'ezuser.is_enabled':
                return $user->attribute( 'is_enabled' ) ? ezpI18n::tr( 'design/standard/extract', 'enabled' )
                                                        : ezpI18n::tr( 'design/standard/extract', 'disabled' );
        }
        return '';
    }

    $mainNode = $obj->attribute( 'main_node' );
    switch ( $key )
    {
        case 'ezcontentobject.published':
        case 'ezcontentobject.modified':
            $time = (int)$obj->attribute( substr( $key, 16 ) );
            return $time > 0 ? date( 'Y-m-d', $time ) : '';
        case 'ezcontentobject.url_alias':
            return $mainNode ? $mainNode->attribute( 'url_alias' ) : '';
        case 'ezcontentobject.full_url_alias':
            if ( !$mainNode )
            {
                return '';
            }
            // The public site's address (DefaultAccess), not the admin one this view runs in
            $siteINI = eZINI::instance();
            $defaultAccess = $siteINI->variable( 'SiteSettings', 'DefaultAccess' );
            $currentAccess = isset( $GLOBALS['eZCurrentAccess']['name'] ) ? $GLOBALS['eZCurrentAccess']['name'] : '';
            if ( $defaultAccess && $defaultAccess !== $currentAccess )
            {
                $siteINI = eZSiteAccess::getIni( $defaultAccess, 'site.ini' );
            }
            $siteURL = rtrim( preg_replace( '#^https?://#', '', $siteINI->variable( 'SiteSettings', 'SiteURL' ) ), '/' );
            $scheme = eZSys::isSSLNow() ? 'https://' : 'http://';
            return $scheme . $siteURL . '/' . $mainNode->attribute( 'url_alias' );
        case 'ezcontentobject.main_parent_name':
            return xrowExtractNodeName( $obj->attribute( 'main_parent_node_id' ) );
        case 'ezcontentobject.main_node_id':
            return $obj->attribute( 'main_node_id' );
        case 'ezcontentobject.main_parent_node_id':
            return $obj->attribute( 'main_parent_node_id' );
        case 'ezcontentobject.parent_nodes':
            $names = array();
            foreach ( (array)$obj->attribute( 'parent_nodes' ) as $nodeID )
            {
                $name = xrowExtractNodeName( $nodeID );
                if ( $name !== '' )
                    $names[] = $name;
            }
            return join( " ", $names );
    }
    return '';
}

/** A download file name from a node name: letters, digits, dot, dash and underscore only. */
function xrowExtractFileName( $name )
{
    $name = trim( preg_replace( '/[^A-Za-z0-9._-]+/', '_', (string)$name ), '._' );
    return ( $name === '' ? 'export' : substr( $name, 0, 80 ) ) . '_export.csv';
}
}

$csvINI = eZINI::instance( 'csv.ini' );
$allowPasswordHash = $csvINI->hasVariable( 'General', 'AllowPasswordHashExport' )
                     && $csvINI->variable( 'General', 'AllowPasswordHashExport' ) === 'enabled';

// Array of extra node attributes
$ExtraAttributes = array(
    'ezuser.login' => array(
        'id' => 'ezuser.login' ,
        'exportname' => 'login' ,
        'name' => 'Login'
    ) ,
    'ezuser.email' => array(
        'id' => 'ezuser.email' ,
        'exportname' => 'email' ,
        'name' => 'E-Mail'
    ) ,
    'ezuser.password_hash' => array(
        'id' => 'ezuser.password_hash' ,
        'exportname' => 'password' ,
        'name' => 'Password'
    ) ,
    'ezuser.is_enabled' => array(
        'id' => 'ezuser.is_enabled' ,
        'exportname' => 'user_status' ,
        'name' => 'User Status'
    ) ,
    'ezcontentobject.published' => array(
        'id' => 'ezcontentobject.published' ,
        'exportname' => 'published' ,
        'name' => 'Content Object Published Time'
    ) ,
    'ezcontentobject.modified' => array(
        'id' => 'ezcontentobject.modified' ,
        'exportname' => 'modified' ,
        'name' => 'Content Object Modified Time'
    ) ,
    'ezcontentobject.url_alias' => array(
        'id' => 'ezcontentobject.url_alias' ,
        'exportname' => 'url_alias' ,
        'name' => 'URL Alias'
    ) ,
    'ezcontentobject.full_url_alias' => array(
        'id' => 'ezcontentobject.full_url_alias' ,
        'exportname' => 'full_url_alias' ,
        'name' => 'Absolute URL Alias'
    ) ,
    'ezcontentobject.main_parent_name' => array(
        'id' => 'ezcontentobject.main_parent_name' ,
        'exportname' => 'parent_name' ,
        'name' => 'Content Object Main Parent Name'
    ) ,
    'ezcontentobject.main_node_id' => array(
        'id' => 'ezcontentobject.main_node_id' ,
        'exportname' => 'main_node_id' ,
        'name' => 'Main Node ID'
    ) ,
    'ezcontentobject.main_parent_node_id' => array(
        'id' => 'ezcontentobject.main_parent_node_id' ,
        'exportname' => 'main_parent_node_id' ,
        'name' => 'Main Parent Node ID'
    ) ,
    'ezcontentobject.parent_nodes' => array(
        'id' => 'ezcontentobject.parent_nodes' ,
        'exportname' => 'parent_nodes' ,
        'name' => 'Content Object Parent Names'
    )
);
if ( !$allowPasswordHash )
{
    unset( $ExtraAttributes['ezuser.password_hash'] );
}

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

// Set col & row separator: one or a few characters, never a quote or a line break
$Separator = $http->hasPostVariable( 'Separator' ) ? (string)$http->postVariable( 'Separator' ) : ',';
if ( $Separator === '\t' )
    $Separator = "\t";
if ( $Separator === '' || strlen( $Separator ) > 4 || strpbrk( $Separator, "\"\r\n" ) !== false )
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
if ( ! $http->hasPostVariable( 'Subtree' ) )
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

if ( $http->hasPostVariable( 'SelectedNodeIDArray' ) )
{
    $nodes = (array)$http->postVariable( 'SelectedNodeIDArray' );
    if ( isset( $nodes[0] ) )
        $Subtree = $nodes[0];
}
$Subtree = (int)$Subtree;

// If we don't remove, add or download then or we load all attributes or we start empty
if ( $http->hasPostVariable( 'Remove' ) || $http->hasPostVariable( 'AddAttribute' ) || $http->hasPostVariable( 'Download' ) )
{
    $Attributes = $http->hasPostVariable( 'Attributes' ) ? $http->postVariable( 'Attributes' ) : array();
}
else
{
    if ( isset( $sessionConfig['Attributes'][$Class_id] ) && is_array( $sessionConfig['Attributes'][$Class_id] ) )
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
    if ( strpos( $item['id'], '.' ) !== false )
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
$http->setSessionVariable( 'eZExtractConfig', $sessionConfig );
// Put above vars in tpl
$tpl->setVariable( 'Type', $type );
$tpl->setVariable( 'Subtree', $Subtree );
$tpl->setVariable( 'Class_id', $Class_id );
$tpl->setVariable( 'Attributes', $Attributes );
$tpl->setVariable( 'ExtraAttributes', $ExtraAttributes );
$tpl->setVariable( 'Mainnodeonly', $Mainnodeonly );
$tpl->setVariable( 'has_prefilledata', $hasPreFilledData );
$tpl->setVariable( 'Escape', $Escape ? 1 : 0 );

// The same selection as the export: the user's read access, depth and main nodes
$fCollection = new eZContentFunctionCollection();
$list = $fCollection->fetchObjectTreeCount( $Subtree, false, false, 'include', array(
    $Class_id
), false, $depth, $depthOperator, true, false, (bool)$Mainnodeonly, false, false );

$tpl->setVariable( 'max_count', isset( $list['result'] ) ? $list['result'] : 0 );

// Handle download action
if ( $http->hasPostVariable( 'Download' ) )
{
    $parser = new ParserInterface( $Separator, $Escape );
    $newLine = $LineSeparatorArray[$LineSeparator]['value'];

    $cells = array();
    foreach ( $Attributes as $item )
    {
        $cells[] = $parser->escape( str_replace( '_', '-', $item['exportname'] ) );
    }
    $data = implode( $Separator, $cells ) . $newLine;
    $file = 'export.csv';

    if ( $hasPreFilledData )
    {
        $list = $preFilledIDs;
    }
    else
    {
        $node = eZContentObjectTreeNode::fetch( $Subtree );
        if ( !( $node instanceof eZContentObjectTreeNode ) || !$node->canRead() )
        {
            return $module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
        }
        $file = xrowExtractFileName( $node->attribute( 'name' ) );

        $sortBy = $node->sortArray();
        $sortBy = $sortBy[0];

        // Limitation false: the user's content/read policies apply
        $list2 = $fCollection->fetchObjectTree( $Subtree, $sortBy, false, false, $Offset, $Limit ? $Limit : false, $depth, $depthOperator, $Class_id, false, false, 'include', array(
            $Class_id
        ), false, (bool)$Mainnodeonly, true, false, true, false, true );

        $list = isset( $list2['result'] ) && is_array( $list2['result'] ) ? $list2['result'] : array();
    }

    foreach ( $list as $item )
    {
        $obj = is_object( $item ) ? $item->attribute( 'object' ) : eZContentObject::fetch( (int)$item );
        if ( ! ( $obj instanceof eZContentObject ) || ! $obj->canRead() )
            continue;

        $datamap = $obj->attribute( 'data_map' );

        $cells = array();
        foreach ( $Attributes as $dataelement )
        {
            if ( isset( $ExtraAttributes[$dataelement['id']] ) )
            {
                $cells[] = $parser->escape( xrowExtractExtraValue( $dataelement['id'], $obj, $allowPasswordHash ) );
            }
            elseif ( isset( $datamap[$dataelement['id']] ) && is_object( $datamap[$dataelement['id']] ) )
            {
                $cells[] = $parser->exportValue( $datamap[$dataelement['id']] );
            }
            else
            {
                $cells[] = $parser->escape( '' );
            }
        }
        $data .= implode( $Separator, $cells ) . $newLine;

        // A large export: keep the object cache from growing with every row
        eZContentObject::clearCache( array( $obj->attribute( 'id' ) ) );
    }

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
