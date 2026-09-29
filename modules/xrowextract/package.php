<?php

/**
 * Content-package (.ezpkg) support: upload or pick a package already in the
 * repository, inspect it (a dry run: what it carries and what would happen,
 * nothing written), install it through the kernel package system, or build a
 * rich sample "content + class" package for a chosen class.
 *
 * The heavy lifting (parsing, matching, installing, sample generation) is in
 * XrowExtractPackage; this view is only the HTTP/session plumbing, the same
 * split xrowextract/import.php uses for XrowExtractImport.
 */

$module = $Params['Module'];
$http = eZHTTPTool::instance();
$tpl = eZTemplate::factory();

$SESSION_KEY = 'XROWEXTRACT_PACKAGE_NAME';

// ---------------------------------------------------------------- upload

$uploadError = '';
if ( $http->hasPostVariable( 'UploadPackage' ) )
{
    if ( eZHTTPFile::canFetch( 'PackageBinaryFile' ) )
    {
        $file = eZHTTPFile::fetch( 'PackageBinaryFile' );
        if ( $file )
        {
            $newPackageName = '';
            $imported = eZPackage::import( $file->attribute( 'filename' ), $newPackageName, true, false, false );
            if ( $imported instanceof eZPackage )
            {
                $_SESSION[$SESSION_KEY] = $imported->attribute( 'name' );
                return $module->redirectTo( 'xrowextract/package' );
            }
            elseif ( $imported === eZPackage::STATUS_ALREADY_EXISTS )
            {
                $uploadError = ezpI18n::tr( 'design/standard/extract', 'A package named %packagename already exists in the repository.', false, array( '%packagename' => $newPackageName ) );
            }
            elseif ( $imported === eZPackage::STATUS_INVALID_NAME )
            {
                $uploadError = ezpI18n::tr( 'design/standard/extract', 'The package name %packagename is invalid.', false, array( '%packagename' => $newPackageName ) );
            }
            else
            {
                $uploadError = ezpI18n::tr( 'design/standard/extract', 'The uploaded file is not a valid Exponential package (.ezpkg).' );
            }
        }
        else
        {
            $uploadError = ezpI18n::tr( 'design/standard/extract', 'The uploaded file could not be read.' );
        }
    }
    else
    {
        $uploadError = ezpI18n::tr( 'design/standard/extract', 'Choose a file first.' );
    }
}
$tpl->setVariable( 'UploadError', $uploadError );

// ---------------------------------------------------------------- pick the current package

if ( $http->hasPostVariable( 'ForgetPackage' ) )
{
    unset( $_SESSION[$SESSION_KEY] );
    return $module->redirectTo( 'xrowextract/package' );
}

$packageName = '';
if ( $http->hasPostVariable( 'PackageName' ) )
    $packageName = (string)$http->postVariable( 'PackageName' );
elseif ( isset( $Params['PackageName'] ) && $Params['PackageName'] )
    $packageName = (string)$Params['PackageName'];
elseif ( isset( $_SESSION[$SESSION_KEY] ) )
    $packageName = (string)$_SESSION[$SESSION_KEY];

$package = $packageName !== '' ? eZPackage::fetch( $packageName ) : false;
if ( $package instanceof eZPackage )
    $_SESSION[$SESSION_KEY] = $packageName;
else
    $packageName = '';

$tpl->setVariable( 'PackageName', $packageName );
$tpl->setVariable( 'Package', $package instanceof eZPackage ? $package : false );

$inspection = false;
if ( $package instanceof eZPackage )
    $inspection = XrowExtractPackage::inspect( $package );
$tpl->setVariable( 'Inspection', $inspection );

// ---------------------------------------------------------------- install options

$ParentNodeID = $http->hasPostVariable( 'ParentNodeID' ) ? (int)$http->postVariable( 'ParentNodeID' ) : 0;
if ( !$ParentNodeID )
{
    $publicContentINI = eZSiteAccess::getIni( eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ), 'content.ini' );
    $ParentNodeID = (int)$publicContentINI->variable( 'NodeSettings', 'RootNode' );
}
$tpl->setVariable( 'ParentNodeID', $ParentNodeID );
$parentNode = $ParentNodeID ? eZContentObjectTreeNode::fetch( $ParentNodeID ) : null;
$tpl->setVariable( 'ParentNode', ( $parentNode instanceof eZContentObjectTreeNode && $parentNode->canRead() )
    ? array( 'name' => $parentNode->attribute( 'name' ), 'path' => $parentNode->attribute( 'path_identification_string' ), 'node_id' => $ParentNodeID ) : false );

$availableSiteAccesses = eZINI::instance()->variable( 'SiteAccessSettings', 'RelatedSiteAccessList' );
$tpl->setVariable( 'AvailableSiteAccesses', $availableSiteAccesses );
$SiteAccess = $http->hasPostVariable( 'SiteAccess' ) ? (string)$http->postVariable( 'SiteAccess' ) : eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' );
if ( !in_array( $SiteAccess, $availableSiteAccesses, true ) && $availableSiteAccesses )
    $SiteAccess = $availableSiteAccesses[0];
$tpl->setVariable( 'SiteAccess', $SiteAccess );

$ObjectMode = $http->hasPostVariable( 'ObjectMode' ) && in_array( $http->postVariable( 'ObjectMode' ), array( XrowExtractPackage::OBJECT_SKIP, XrowExtractPackage::OBJECT_UPDATE, XrowExtractPackage::OBJECT_NEW ), true )
            ? $http->postVariable( 'ObjectMode' ) : XrowExtractPackage::OBJECT_UPDATE;
$tpl->setVariable( 'ObjectMode', $ObjectMode );
$ClassMode = $http->hasPostVariable( 'ClassMode' ) && in_array( $http->postVariable( 'ClassMode' ), array( XrowExtractPackage::CLASS_SKIP, XrowExtractPackage::CLASS_REPLACE, XrowExtractPackage::CLASS_NEW ), true )
           ? $http->postVariable( 'ClassMode' ) : XrowExtractPackage::CLASS_SKIP;
$tpl->setVariable( 'ClassMode', $ClassMode );

if ( $http->hasPostVariable( 'BrowseParent' ) )
{
    eZContentBrowse::browse( array(
        'action_name' => 'ImportParentNode',
        'description_template' => 'design:xrowextract/browse_node.tpl',
        'from_page' => '/xrowextract/package',
        'persistent_data' => array( 'PackageName' => $packageName, 'ParentNodeID' => $ParentNodeID, 'SiteAccess' => $SiteAccess, 'ObjectMode' => $ObjectMode, 'ClassMode' => $ClassMode ),
    ), $module );
}
if ( $http->hasPostVariable( 'ImportParentNodeSelected' ) )
{
    $selected = (array)$http->postVariable( 'ImportParentNodeSelected' );
    if ( isset( $selected[0] ) )
        $ParentNodeID = (int)$selected[0];
    $tpl->setVariable( 'ParentNodeID', $ParentNodeID );
    $parentNode = eZContentObjectTreeNode::fetch( $ParentNodeID );
    $tpl->setVariable( 'ParentNode', ( $parentNode instanceof eZContentObjectTreeNode && $parentNode->canRead() )
        ? array( 'name' => $parentNode->attribute( 'name' ), 'path' => $parentNode->attribute( 'path_identification_string' ), 'node_id' => $ParentNodeID ) : false );
}

// ---------------------------------------------------------------- install

$installReport = false;
if ( $http->hasPostVariable( 'Install' ) && $package instanceof eZPackage )
{
    $installReport = XrowExtractPackage::install( $package, $ParentNodeID, $SiteAccess, $ObjectMode, $ClassMode );
    if ( $installReport['ok'] )
    {
        eZContentObject::clearCache();
        $inspection = XrowExtractPackage::inspect( $package );
        $tpl->setVariable( 'Inspection', $inspection );
    }
}
$tpl->setVariable( 'InstallReport', $installReport );

// ---------------------------------------------------------------- repository list

$tpl->setVariable( 'RepositoryPackages', XrowExtractPackage::repositoryPackages() );

// ---------------------------------------------------------------- template builder

$ClassChoices = array();
foreach ( eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, true, false, array( 'name' => 'asc' ) ) as $class )
{
    $count = (int)eZPersistentObject::count( eZContentObject::definition(), array( 'contentclass_id' => (int)$class->attribute( 'id' ) ) );
    $ClassChoices[] = array( 'id' => (int)$class->attribute( 'id' ), 'identifier' => $class->attribute( 'identifier' ), 'name' => $class->attribute( 'name' ), 'count' => $count );
}
$tpl->setVariable( 'ClassChoices', $ClassChoices );

if ( $http->hasPostVariable( 'TemplateClassID' ) )
    $TemplateClassID = (int)$http->postVariable( 'TemplateClassID' );
elseif ( isset( $_GET['ClassID'] ) )
    $TemplateClassID = (int)$_GET['ClassID'];
else
    $TemplateClassID = 0;
if ( !$TemplateClassID && $ClassChoices )
{
    // Fall back to the class with the most objects: the "best" default when none was picked.
    $best = $ClassChoices[0];
    foreach ( $ClassChoices as $choice )
        if ( $choice['count'] > $best['count'] )
            $best = $choice;
    $TemplateClassID = $best['id'];
}
$tpl->setVariable( 'TemplateClassID', $TemplateClassID );

$TemplateVariant = $http->hasPostVariable( 'TemplateVariant' ) && in_array( $http->postVariable( 'TemplateVariant' ), array( 'class', 'content', 'both' ), true )
                 ? $http->postVariable( 'TemplateVariant' ) : 'both';
$tpl->setVariable( 'TemplateVariant', $TemplateVariant );
$tpl->setVariable( 'TemplateVariants', XrowExtractPackage::templateVariants() );

$templateError = '';
$templateBuilt = false;
if ( $http->hasPostVariable( 'BuildTemplate' ) && $TemplateClassID )
{
    $build = XrowExtractPackage::buildTemplatePackage( $TemplateClassID, $TemplateVariant );
    if ( $build['ok'] )
    {
        $_SESSION[$SESSION_KEY] = $build['package']->attribute( 'name' );
        return $module->redirectTo( 'xrowextract/package' );
    }
    $templateError = implode( ' ', $build['errors'] );
}
$tpl->setVariable( 'TemplateError', $templateError );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/package.tpl' );
$Result['path'] = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Package' ) ),
);
$Result['left_menu'] = 'design:xrowextract/menu_package.tpl';

?>
