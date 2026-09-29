<?php

/**
 * Imports a CSV or JSON file (as XrowExtractWriter writes it) back into
 * content objects: upload, choose the class and matching, map columns, a
 * dry run preview, then apply. Plain full-page post backs (no AJAX): every
 * step re-posts the whole form, the uploaded file itself stays on disk in
 * the private upload folder between steps.
 */

$module = $Params["Module"];
$http = eZHTTPTool::instance();
$tpl = eZTemplate::factory();

XrowExtractImport::cleanupOldUploads();

$SESSION_KEY = 'XROWEXTRACT_IMPORT_FILE';

// Start over: forget the uploaded file
if ( $http->hasPostVariable( 'NewImport' ) )
{
    if ( isset( $_SESSION[$SESSION_KEY]['path'] ) && is_file( $_SESSION[$SESSION_KEY]['path'] ) )
        @unlink( $_SESSION[$SESSION_KEY]['path'] );
    unset( $_SESSION[$SESSION_KEY] );
    return $module->redirectTo( 'xrowextract/import' );
}

// A new upload
$uploadError = '';
if ( $http->hasPostVariable( 'Upload' ) && isset( $_FILES['ImportFile'] ) && $_FILES['ImportFile']['error'] === UPLOAD_ERR_OK )
{
    $originalName = $_FILES['ImportFile']['name'];
    $stored = XrowExtractImport::storeUpload( $_FILES['ImportFile']['tmp_name'], $originalName );
    if ( $stored === false )
    {
        $uploadError = ezpI18n::tr( 'design/standard/extract', 'The uploaded file could not be stored.' );
    }
    else
    {
        if ( isset( $_SESSION[$SESSION_KEY]['path'] ) && is_file( $_SESSION[$SESSION_KEY]['path'] ) )
            @unlink( $_SESSION[$SESSION_KEY]['path'] );
        $format = XrowExtractImport::detectFormat( (string)file_get_contents( $stored ) );
        $separator = $format === 'csv' ? XrowExtractImport::detectSeparator( (string)file_get_contents( $stored ) ) : ',';
        $_SESSION[$SESSION_KEY] = array( 'path' => $stored, 'name' => $originalName, 'format' => $format, 'separator' => $separator );
        return $module->redirectTo( 'xrowextract/import' );
    }
}
elseif ( $http->hasPostVariable( 'Upload' ) )
{
    $uploadError = ezpI18n::tr( 'design/standard/extract', 'Choose a file first.' );
}

$hasFile = isset( $_SESSION[$SESSION_KEY]['path'] ) && is_file( $_SESSION[$SESSION_KEY]['path'] );
$tpl->setVariable( 'HasFile', $hasFile );
$tpl->setVariable( 'UploadError', $uploadError );

if ( $http->hasPostVariable( 'RemoveFile' ) && $hasFile )
{
    @unlink( $_SESSION[$SESSION_KEY]['path'] );
    unset( $_SESSION[$SESSION_KEY] );
    return $module->redirectTo( 'xrowextract/import' );
}

$parsed = array( 'header' => array(), 'rows' => array(), 'format' => 'csv', 'separator' => ',' );
if ( $hasFile )
{
    $format = $http->hasPostVariable( 'ImportFormat' ) ? (string)$http->postVariable( 'ImportFormat' ) : $_SESSION[$SESSION_KEY]['format'];
    $separators = array( 'comma' => ',', 'semicolon' => ';', 'tab' => "\t", 'pipe' => '|' );
    $separatorKey = $http->hasPostVariable( 'ImportSeparator' ) ? (string)$http->postVariable( 'ImportSeparator' ) : array_search( $_SESSION[$SESSION_KEY]['separator'], $separators, true );
    $separator = isset( $separators[$separatorKey] ) ? $separators[$separatorKey] : ',';
    $parsed = XrowExtractImport::parseFile( $_SESSION[$SESSION_KEY]['path'], $format === 'json' ? 'json' : 'csv', $separator );
    $tpl->setVariable( 'ImportFormat', $format );
    $tpl->setVariable( 'ImportSeparatorKey', $separatorKey ?: 'comma' );
    $tpl->setVariable( 'UploadedName', $_SESSION[$SESSION_KEY]['name'] );
}
$tpl->setVariable( 'ParseError', isset( $parsed['error'] ) ? $parsed['error'] : false );
$tpl->setVariable( 'FileHeader', $parsed['header'] );
$tpl->setVariable( 'FileRowCount', count( $parsed['rows'] ) );

// Class: a chosen fallback (a "class" column in the file still wins per row at run time)
$ClassChoices = array();
foreach ( eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, true, false, array( 'name' => 'asc' ) ) as $class )
{
    $count = (int)eZPersistentObject::count( eZContentObject::definition(), array( 'contentclass_id' => (int)$class->attribute( 'id' ) ) );
    $ClassChoices[] = array( 'id' => (int)$class->attribute( 'id' ), 'identifier' => $class->attribute( 'identifier' ), 'name' => $class->attribute( 'name' ), 'count' => $count );
}
$tpl->setVariable( 'ClassChoices', $ClassChoices );

$ClassID = $http->hasPostVariable( 'ClassID' ) ? (int)$http->postVariable( 'ClassID' ) : 0;
$tpl->setVariable( 'ClassID', $ClassID );

// Matching, language, parent
$MatchMode = $http->hasPostVariable( 'MatchMode' ) && in_array( $http->postVariable( 'MatchMode' ), array( 'remote_id', 'object_id', 'none' ), true )
           ? $http->postVariable( 'MatchMode' ) : 'remote_id';
$tpl->setVariable( 'MatchMode', $MatchMode );

$ContentLanguages = XrowExtractColumns::contentLanguages();
$tpl->setVariable( 'ContentLanguages', $ContentLanguages );
$defaultLocale = '';
foreach ( $ContentLanguages as $locale => $lang )
{
    if ( $lang['default'] )
        $defaultLocale = $locale;
}
$Language = $http->hasPostVariable( 'Language' ) ? (string)$http->postVariable( 'Language' ) : $defaultLocale;
$tpl->setVariable( 'Language', $Language );

if ( $http->hasPostVariable( 'ParentNodeID' ) )
    $ParentNodeID = (int)$http->postVariable( 'ParentNodeID' );
else
    $ParentNodeID = (int)eZINI::instance()->variable( 'UserSettings', 'DefaultUserPlacement' );
$tpl->setVariable( 'ParentNodeID', $ParentNodeID );
$parentNode = $ParentNodeID ? eZContentObjectTreeNode::fetch( $ParentNodeID ) : null;
$tpl->setVariable( 'ParentNode', ( $parentNode instanceof eZContentObjectTreeNode && $parentNode->canRead() )
    ? array( 'name' => $parentNode->attribute( 'name' ), 'path' => $parentNode->attribute( 'path_identification_string' ), 'node_id' => $ParentNodeID ) : false );

// Mapping: posted choices, else the automatic suggestion. The posted choices are only
// kept when they were posted for the class currently chosen (MappingClassID, the same
// "which class built this list" guard the csv view uses for its attribute list) - a
// class change (or the very first load) always starts from a fresh suggestion, since a
// mapping built for a different class' attributes means nothing here.
$Mapping = array();
if ( $parsed['header'] )
{
    $suggested = XrowExtractImport::suggestMapping( $parsed['header'], $ClassID );
    $keepPosted = $http->hasPostVariable( 'Mapping' ) && $http->hasPostVariable( 'MappingClassID' )
                && (int)$http->postVariable( 'MappingClassID' ) === $ClassID;
    $postedMapping = $keepPosted ? (array)$http->postVariable( 'Mapping' ) : null;
    foreach ( $suggested as $i => $suggestion )
    {
        $target = ( $postedMapping !== null && isset( $postedMapping[$i] ) ) ? (string)$postedMapping[$i] : $suggestion['target'];
        $Mapping[] = array( 'index' => $i, 'column' => $suggestion['column'], 'target' => $target, 'reason' => $suggestion['reason'] );
    }
}
$tpl->setVariable( 'Mapping', $Mapping );
$tpl->setVariable( 'MappingClassID', $ClassID );

// The picker for every column's select: class attributes, their importable formats, special columns, ignore
$AttributeChoices = array();
$FormatChoices = array();
if ( $ClassID )
{
    foreach ( eZContentClassAttribute::fetchListByClassID( $ClassID, eZContentClass::VERSION_STATUS_DEFINED, true ) as $classAttribute )
    {
        $identifier = $classAttribute->attribute( 'identifier' );
        $datatype = $classAttribute->attribute( 'data_type_string' );
        $AttributeChoices[] = array(
            'id' => $identifier, 'value' => 'attr:' . $identifier, 'name' => $classAttribute->attribute( 'name' ), 'datatype' => $datatype,
            'importable' => in_array( $datatype, XrowExtractImport::baseImportableDatatypes(), true ),
            'reason' => XrowExtractImport::unsupportedReason( $datatype ),
        );
        foreach ( XrowExtractImport::importableFormats( $datatype ) as $format )
            $FormatChoices[] = array( 'id' => $identifier . ':' . $format, 'value' => 'attrfmt:' . $identifier . ':' . $format,
                                      'attribute' => $identifier, 'format' => $format, 'name' => $classAttribute->attribute( 'name' ) . ': ' . $format );
    }
}
$tpl->setVariable( 'AttributeChoices', $AttributeChoices );
$tpl->setVariable( 'FormatChoices', $FormatChoices );
$SpecialChoices = array();
$specialColumnIDs = array( 'ezcontentobject.id', 'ezcontentobject.remote_id', 'ezcontentobject.language', 'ezcontentobject.class_identifier',
                          'ezcontentobject.published', 'ezcontentobject.published_timestamp', 'ezcontentobject.modified', 'ezcontentobject.modified_timestamp',
                          'ezcontentobject.section', 'node.parent_remote_id', 'ezcontentobject.main_parent_node_id' );
$allExtras = XrowExtractColumns::extraAttributes( false );
foreach ( $specialColumnIDs as $id )
{
    if ( isset( $allExtras[$id] ) )
        $SpecialChoices[] = array( 'id' => $id, 'value' => 'special:' . $id, 'name' => $allExtras[$id]['name'] );
}
$tpl->setVariable( 'SpecialChoices', $SpecialChoices );

// Browse for a parent node (same pattern as the csv view's BrowseSubtree)
if ( $http->hasPostVariable( 'BrowseParent' ) )
{
    $return = eZContentBrowse::browse( array(
        'action_name' => 'ImportParentNode',
        'description_template' => 'design:xrowextract/browse_node.tpl',
        'from_page' => '/xrowextract/import',
        'persistent_data' => array( 'ParentNodeID' => $ParentNodeID, 'ClassID' => $ClassID, 'MatchMode' => $MatchMode, 'Language' => $Language, 'Mapping' => $http->hasPostVariable( 'Mapping' ) ? $http->postVariable( 'Mapping' ) : array() ),
    ), $module );
}
if ( $http->hasPostVariable( 'ImportParentNodeSelected' ) )
{
    $selected = (array)$http->postVariable( 'ImportParentNodeSelected' );
    if ( isset( $selected[0] ) )
        $ParentNodeID = (int)$selected[0];
    $tpl->setVariable( 'ParentNodeID', $ParentNodeID );
}

// Preview (dry run) and Apply run the same engine; Apply only after a preview was shown for these settings
$Preview = false;
$Applied = false;
if ( $hasFile && $parsed['rows'] && ( $http->hasPostVariable( 'Preview' ) || $http->hasPostVariable( 'Apply' ) ) )
{
    $mappingForRun = array();
    foreach ( $Mapping as $m )
        $mappingForRun[] = array( 'column' => $m['column'], 'target' => $m['target'] );
    $apply = $http->hasPostVariable( 'Apply' );
    $result = XrowExtractImport::run( array(
        'rows'         => $parsed['rows'],
        'mapping'      => $mappingForRun,
        'classID'      => $ClassID,
        'match'        => $MatchMode,
        'language'     => $Language,
        'parentNodeID' => $ParentNodeID,
        'apply'        => $apply,
    ) );
    $tpl->setVariable( 'Result', $result );
    $tpl->setVariable( 'Preview', !$apply );
    $tpl->setVariable( 'Applied', $apply );
    $tpl->setVariable( 'ApplyCount', $result['counts']['create'] + $result['counts']['update'] );
    if ( $apply )
    {
        // Done: the file has served its purpose
        @unlink( $_SESSION[$SESSION_KEY]['path'] );
        unset( $_SESSION[$SESSION_KEY] );
    }
}

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/import.tpl' );
$Result['path'] = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Import' ) ),
);
$Result['left_menu'] = 'design:xrowextract/menu.tpl';

?>
