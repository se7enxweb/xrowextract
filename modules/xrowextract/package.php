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
$RENAME_NOTICE_KEY = 'XROWEXTRACT_PACKAGE_RENAME_NOTICE';

// ---------------------------------------------------------------- upload
//
// Two ways in, same as the import page: a chunked upload (design/standard/javascript/
// xrowextract-upload.js) adopted by its UploadID - the default for anything above a small size,
// since one huge multipart POST is exactly what a slow-arriving body needs on Velocity, where the
// read loop that is still waiting for the rest of a large body is a live worker a size/idle check
// elsewhere can race - and the plain whole-file POST any browser (or a script) still falls back to
// without JavaScript. Either way the stored file goes through the same archive safety scan and
// import as before.

// A closure kept in a local variable, not a named function: this script runs again for every
// request a long-running Velocity worker serves in the same process, and a bare `function
// xrowExtractPackageFinishUpload(){}` declared at the top level here would fatal ("cannot
// redeclare") on the second one. $forgetFile above (xrowextract/import.php) sets the pattern.
$finishUpload = function ( $stored, $ownedByUpload, $uploadID ) use ( $module, $SESSION_KEY, $RENAME_NOTICE_KEY )
{
    $result = XrowExtractPackage::importUploadedArchive( $stored );
    if ( $ownedByUpload )
        XrowExtractUpload::delete( $uploadID );
    else
        @unlink( $stored );
    if ( $result['ok'] )
    {
        $_SESSION[$SESSION_KEY] = $result['package']->attribute( 'name' );
        if ( $result['renamed'] )
        {
            $_SESSION[$RENAME_NOTICE_KEY] = ezpI18n::tr( 'design/standard/extract',
                'The package’s own name ("%from") is not a valid identifier; it was imported as "%to".', false,
                array( '%from' => $result['renamed_from'], '%to' => $result['renamed_to'] ) );
        }
        return $module->redirectTo( 'xrowextract/package' );
    }
    return ezpI18n::tr( 'design/standard/extract', 'The package could not be read: %reason', false, array( '%reason' => $result['error'] ) );
};

$uploadError = '';
if ( $http->hasPostVariable( 'UploadPackage' ) && $http->hasPostVariable( 'UploadID' ) && (string)$http->postVariable( 'UploadID' ) !== '' )
{
    $uploadID = (string)$http->postVariable( 'UploadID' );
    $stored = XrowExtractUpload::path( $uploadID );
    if ( $stored === false )
    {
        $uploadError = ezpI18n::tr( 'design/standard/extract', 'The upload could not be found; it may have expired. Choose the file again.' );
    }
    else
    {
        $outcome = $finishUpload( $stored, true, $uploadID );
        if ( is_string( $outcome ) )
            $uploadError = $outcome;
        else
            return $outcome;
    }
}
elseif ( $http->hasPostVariable( 'UploadPackage' ) )
{
    if ( eZHTTPFile::canFetch( 'PackageBinaryFile' ) )
    {
        $file = eZHTTPFile::fetch( 'PackageBinaryFile' );
        list( $hasRoom, $roomMessage ) = $file ? XrowExtractUpload::hasRoomFor( (int)$file->attribute( 'filesize' ) ) : array( true, '' );
        if ( !$hasRoom )
        {
            $uploadError = $roomMessage;
        }
        else
        {
            // The upload is first copied into the private upload folder under a name with its own
            // extension, then goes through the same path as the import page: the archive safety scan
            // (no symlinks, hard links, special entries or paths leaving the folder) before anything is
            // unpacked, and every kernel/archive exception turned into a message. Passing the web
            // server's raw temporary file straight to eZPackage::import() skipped the scan, and on
            // Velocity the archive reader could not open that temporary file: an uncaught
            // ezcBaseFilePermissionException, a 500.
            $stored = $file ? XrowExtractImport::storeUpload( $file->attribute( 'filename' ), $file->attribute( 'original_filename' ) ) : false;
            if ( $stored )
            {
                $outcome = $finishUpload( $stored, false, null );
                if ( is_string( $outcome ) )
                    $uploadError = $outcome;
                else
                    return $outcome;
            }
            else
            {
                $uploadError = ezpI18n::tr( 'design/standard/extract', 'The uploaded file could not be read.' );
            }
        }
    }
    else
    {
        $uploadError = ezpI18n::tr( 'design/standard/extract', 'Choose a file first.' );
    }
}
$tpl->setVariable( 'UploadError', $uploadError );
$renameNotice = isset( $_SESSION[$RENAME_NOTICE_KEY] ) ? (string)$_SESSION[$RENAME_NOTICE_KEY] : '';
unset( $_SESSION[$RENAME_NOTICE_KEY] );
$tpl->setVariable( 'RenameNotice', $renameNotice );
$diskFree = XrowExtractUpload::freeDiskSpace();
$tpl->setVariable( 'UploadDiskFree', $diskFree !== null ? XrowExtractUpload::humanSize( $diskFree ) : false );
$uploadJsFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract-upload.js';
$tpl->setVariable( 'UploadScriptVersion', is_file( $uploadJsFile ) ? substr( md5_file( $uploadJsFile ), 0, 12 ) : '0' );

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

// ---------------------------------------------------------------- install options
// (ParentNodeID is resolved before the first inspect() call below, so the dry run can describe
// where a new top-level object would land under the parent currently chosen on the page)

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

$inspection = false;
if ( $package instanceof eZPackage )
    $inspection = XrowExtractPackage::inspect( $package, $ParentNodeID );
$tpl->setVariable( 'Inspection', $inspection );

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
if ( $http->hasPostVariable( 'Install' ) && $package instanceof eZPackage && XrowExtractJob::available() )
{
    // Installing can take minutes (every class and object through the kernel's package handlers), so it
    // runs as a background job (bin/php/package.php --install, job type "package") and the page goes
    // straight to the Jobs view, which follows its progress and keeps its report - the request is not held
    // for the length of the install.
    $installArgs = array(
        '--install=' . $package->attribute( 'name' ),
        '--parent=' . (int)$ParentNodeID,
        '--site-access=' . $SiteAccess,
        '--object-mode=' . $ObjectMode,
        '--class-mode=' . $ClassMode,
    );
    $parentForName = eZContentObjectTreeNode::fetch( (int)$ParentNodeID );
    $installJobID = XrowExtractJob::create( array(
        'type' => 'package', 'owner' => eZUser::currentUser()->attribute( 'login' ),
        'what' => ezpI18n::tr( 'design/standard/extract', 'Install package %name below %parent', false,
                               array( '%name' => $package->attribute( 'name' ),
                                      '%parent' => $parentForName instanceof eZContentObjectTreeNode ? $parentForName->attribute( 'name' ) : ( 'node ' . (int)$ParentNodeID ) ) ),
        'format' => 'json', 'output_file' => 'install-report.json', 'args' => $installArgs,
    ) );
    if ( !XrowExtractJob::start( $installJobID ) )
    {
        XrowExtractJob::update( $installJobID, array(
            'state' => 'failed', 'ended' => time(),
            'error' => ezpI18n::tr( 'design/standard/extract', 'Could not start the background process.' ),
        ) );
    }
    $http->setSessionVariable( 'eZExtractJobStarted', $installJobID );
    return $module->redirectTo( 'xrowextract/jobs' );
}
elseif ( $http->hasPostVariable( 'Install' ) && $package instanceof eZPackage )
{
    // No background jobs on this installation (XrowExtractJob::available() is false): install in the request
    $installReport = XrowExtractPackage::install( $package, $ParentNodeID, $SiteAccess, $ObjectMode, $ClassMode );
    if ( $installReport['ok'] )
    {
        eZContentObject::clearCache();
        $inspection = XrowExtractPackage::inspect( $package, $ParentNodeID );
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
// Where the sample objects will be created while a build runs (export.ini [PackageTemplate]
// ScratchNodeID, default content.ini [NodeSettings] MediaRootNode) - never the public front page.
$tpl->setVariable( 'ScratchLocation', XrowExtractPackage::scratchLocationInfo() );

$templateError = '';
$templateBuilt = false;
if ( $http->hasPostVariable( 'BuildTemplate' ) && $TemplateClassID )
{
    // The same builder as the Import page: from the class's own existing objects (read-only), falling back to
    // temporary sample objects only for a class with none (the old builder always created them, and a made-up
    // tag or a relation to a sample that had failed then stopped every build)
    $build = XrowExtractPackage::buildContentPackage( $TemplateClassID, $TemplateVariant );
    if ( $build['ok'] )
    {
        $_SESSION[$SESSION_KEY] = $build['package']->attribute( 'name' );
        return $module->redirectTo( 'xrowextract/package' );
    }
    $templateError = implode( ' ', $build['errors'] );
}
$tpl->setVariable( 'TemplateError', $templateError );

$scriptFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract.js';
$tpl->setVariable( 'ScriptVersion', is_file( $scriptFile ) ? substr( md5_file( $scriptFile ), 0, 12 ) : '0' );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/package.tpl' );
$Result['path'] = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Package' ) ),
);
$Result['left_menu'] = 'design:xrowextract/menu_package.tpl';

?>
