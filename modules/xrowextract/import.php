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
$login = eZUser::currentUser()->attribute( 'login' );

XrowExtractImport::cleanupOldUploads();
XrowExtractUpload::cleanupStale();
// Defence in depth only, since a "Try a sample" package is never registered in the repository for
// longer than the single request that needed it (see $PackageIsTransient below) - this sweep is what
// catches a package this class ever built that somehow still ended up left behind (a killed request
// that never reached its own cleanup, for instance), not the mechanism this relies on day to day.
XrowExtractPackage::cleanupOldSamplePackages();

$SESSION_KEY = 'XROWEXTRACT_IMPORT_FILE';

/** Forgets the session's current file, deleting it the way its source needs to. */
$forgetFile = function () use ( &$SESSION_KEY )
{
    if ( !isset( $_SESSION[$SESSION_KEY] ) )
        return;
    $current = $_SESSION[$SESSION_KEY];
    if ( isset( $current['source'] ) && $current['source'] === 'chunked' && !empty( $current['upload_id'] ) )
        XrowExtractUpload::delete( $current['upload_id'] );
    elseif ( isset( $current['path'] ) && is_file( $current['path'] ) )
    {
        @unlink( $current['path'] );
        if ( is_file( XrowExtractManifest::sidecarPath( $current['path'] ) ) )
            @unlink( XrowExtractManifest::sidecarPath( $current['path'] ) );
    }
    elseif ( !empty( $current['package_name'] ) )
        // A package (or standalone class/object XML wrapped as one) has no loose file on disk -
        // an unkept "Try a sample" one is removed from the repository right away; an uploaded or
        // installed one is left there (xrowextract/package, or package/list, can still reach it).
        XrowExtractPackage::forgetUnkeptSamplePackage( $current );
    unset( $_SESSION[$SESSION_KEY] );
};

/**
 * A zip of one data file and its typed column manifest ("Download with manifest (.zip)"): the data file
 * is unpacked next to the upload, the manifest as its sidecar, so the mapping below uses it exactly.
 * Returns array( path, name, error ); path false when it is not such a zip (the upload is used as it is).
 */
$unpackManifestZip = function ( $stored, $originalName )
{
    $unpacked = XrowExtractManifest::unpackZip( $stored, $stored . '.data' );
    if ( !$unpacked['ok'] )
        return array( 'path' => false, 'name' => $originalName, 'error' => $unpacked['error'] );
    return array( 'path' => $unpacked['path'], 'name' => $unpacked['name'], 'error' => '' );
};

// Resume a job (from the Jobs page): the same file and settings, a new job, --resume-from added
if ( $http->hasPostVariable( 'ResumeJobID' ) )
{
    $resumeID = (string)$http->postVariable( 'ResumeJobID' );
    $resumeRow = max( 1, (int)$http->postVariable( 'ResumeFromRow' ) );
    $sourceJob = XrowExtractJob::isValidID( $resumeID ) ? XrowExtractJob::load( $resumeID ) : null;
    if ( $sourceJob && XrowExtractJob::canSee( $sourceJob, $login, XrowExtractJob::allowAllJobs() ) && $sourceJob['type'] === 'import' && XrowExtractJob::available() )
    {
        $newArgs = array();
        foreach ( (array)$sourceJob['args'] as $arg )
        {
            if ( strpos( $arg, '--resume-from=' ) === 0 )
                continue; // replaced below
            $newArgs[] = $arg;
        }
        $newArgs[] = '--resume-from=' . $resumeRow;
        $newJobID = XrowExtractJob::create( array(
            'type' => 'import', 'owner' => $login,
            'what' => $sourceJob['what'] . " (resumed from row $resumeRow)",
            'format' => 'json', 'output_file' => 'report.json', 'args' => $newArgs,
        ) );
        XrowExtractJob::start( $newJobID );
        $http->setSessionVariable( 'eZExtractJobStarted', $newJobID );
    }
    return $module->redirectTo( 'xrowextract/jobs' );
}

// Start over: forget the uploaded file
if ( $http->hasPostVariable( 'NewImport' ) )
{
    $forgetFile();
    return $module->redirectTo( 'xrowextract/import' );
}

// "Open in Import" from the Package tab: a content package already in the repository becomes this page's
// file, exactly as an uploaded one (the same session shape the upload below leaves), then its review step
if ( $http->hasPostVariable( 'OpenRepositoryPackage' ) )
{
    $openName = $http->hasPostVariable( 'PackageName' ) ? (string)$http->postVariable( 'PackageName' ) : '';
    $openPackage = $openName !== '' ? eZPackage::fetch( $openName ) : false;
    $isContentPackage = false;
    if ( $openPackage instanceof eZPackage )
        foreach ( XrowExtractPackage::repositoryPackages() as $repositoryPackage )
            $isContentPackage = $isContentPackage || $repositoryPackage['name'] === $openName;
    if ( $isContentPackage )
    {
        $forgetFile();
        $_SESSION[$SESSION_KEY] = array( 'name' => $openName, 'format' => 'package', 'kind' => 'package', 'package_name' => $openName, 'source' => 'repository' );
    }
    return $module->redirectTo( 'xrowextract/import' );
}

// A new upload: the plain (no JavaScript) whole-file fallback, or a finished chunked upload
// adopted by its UploadID (XrowExtractUpload::path() only returns a path for the current user's
// own, complete upload). Either way, a content package (.ezpkg/.tar.gz) or a standalone
// content-class/content-object XML file is detected first and routed to the package repository;
// anything else is treated as a row file (CSV/XML/JSON), in that checking order.
$uploadError = '';
if ( $http->hasPostVariable( 'Upload' ) && $http->hasPostVariable( 'UploadID' ) && (string)$http->postVariable( 'UploadID' ) !== '' )
{
    $uploadID = (string)$http->postVariable( 'UploadID' );
    $stored = XrowExtractUpload::path( $uploadID );
    if ( $stored === false )
    {
        $uploadError = ezpI18n::tr( 'design/standard/extract', 'The upload could not be found; it may have expired. Choose the file again.' );
    }
    else
    {
        $originalName = $http->hasPostVariable( 'UploadName' ) ? (string)$http->postVariable( 'UploadName' ) : XrowExtractUpload::originalName( $uploadID );
        $forgetFile();
        $packageKind = XrowExtractPackage::detectUploadKind( $stored, $originalName );
        if ( $packageKind )
        {
            $wrapped = $packageKind === 'package'
                     ? XrowExtractPackage::importUploadedArchive( $stored )
                     : XrowExtractPackage::wrapStandaloneXML( $stored, $packageKind, $originalName );
            // The repository copy (a real eZPackage, or a transient one wrapping the single
            // class/object file) now carries everything; the chunked upload has done its job -
            // XrowExtractUpload::delete() removes its whole folder, not just this one file.
            XrowExtractUpload::delete( $uploadID );
            if ( !$wrapped['ok'] )
            {
                $uploadError = ezpI18n::tr( 'design/standard/extract', 'Could not read %name as a package: %reason', null,
                                            array( '%name' => $originalName, '%reason' => $wrapped['error'] ) );
            }
            else
            {
                $_SESSION[$SESSION_KEY] = array( 'name' => $originalName, 'format' => $packageKind, 'kind' => $packageKind,
                                                 'package_name' => $wrapped['package']->attribute( 'name' ) );
                return $module->redirectTo( 'xrowextract/import' );
            }
        }
        else
        {
            // A zip of a data file and its manifest: unpacked in the upload's own folder (removed with it)
            $zip = $unpackManifestZip( $stored, $originalName );
            if ( $zip['path'] )
            {
                $stored = $zip['path'];
                $originalName = $zip['name'];
            }
            if ( $zip['error'] !== '' )
            {
                XrowExtractUpload::delete( $uploadID );
                $uploadError = ezpI18n::tr( 'design/standard/extract', 'Could not read %name: %reason', null, array( '%name' => $originalName, '%reason' => $zip['error'] ) );
            }
            else
            {
                $format = XrowExtractImport::detectFormat( XrowExtractImport::sniff( $stored ) );
                $separator = $format === 'csv' ? XrowExtractImport::detectSeparator( XrowExtractImport::sniff( $stored ) ) : ',';
                $_SESSION[$SESSION_KEY] = array( 'path' => $stored, 'name' => $originalName, 'format' => $format, 'separator' => $separator,
                                                 'source' => 'chunked', 'upload_id' => $uploadID, 'size' => filesize( $stored ) );
                return $module->redirectTo( 'xrowextract/import' );
            }
        }
    }
}
elseif ( $http->hasPostVariable( 'Upload' ) && isset( $_FILES['ImportFile'] ) && $_FILES['ImportFile']['error'] === UPLOAD_ERR_OK )
{
    $originalName = $_FILES['ImportFile']['name'];
    list( $hasRoom, $roomMessage ) = XrowExtractUpload::hasRoomFor( (int)$_FILES['ImportFile']['size'] );
    if ( !$hasRoom )
    {
        $uploadError = $roomMessage;
    }
    else
    {
        $stored = XrowExtractImport::storeUpload( $_FILES['ImportFile']['tmp_name'], $originalName );
        if ( $stored === false )
        {
            $uploadError = ezpI18n::tr( 'design/standard/extract', 'The uploaded file could not be stored.' );
        }
        else
        {
            $forgetFile();
            $packageKind = XrowExtractPackage::detectUploadKind( $stored, $originalName );
            if ( $packageKind )
            {
                $wrapped = $packageKind === 'package'
                         ? XrowExtractPackage::importUploadedArchive( $stored )
                         : XrowExtractPackage::wrapStandaloneXML( $stored, $packageKind, $originalName );
                // The repository copy now carries everything; the raw upload has done its job.
                @unlink( $stored );
                if ( !$wrapped['ok'] )
                {
                    $uploadError = ezpI18n::tr( 'design/standard/extract', 'Could not read %name as a package: %reason', null,
                                                array( '%name' => $originalName, '%reason' => $wrapped['error'] ) );
                }
                else
                {
                    $_SESSION[$SESSION_KEY] = array( 'name' => $originalName, 'format' => $packageKind, 'kind' => $packageKind,
                                                     'package_name' => $wrapped['package']->attribute( 'name' ) );
                    return $module->redirectTo( 'xrowextract/import' );
                }
            }
            else
            {
                // A zip of a data file and its manifest: the data file replaces the upload, its manifest next to it
                $zip = $unpackManifestZip( $stored, $originalName );
                if ( $zip['path'] || $zip['error'] !== '' )
                    @unlink( $stored );
                if ( $zip['path'] )
                {
                    $stored = $zip['path'];
                    $originalName = $zip['name'];
                }
                if ( $zip['error'] !== '' )
                {
                    $uploadError = ezpI18n::tr( 'design/standard/extract', 'Could not read %name: %reason', null, array( '%name' => $originalName, '%reason' => $zip['error'] ) );
                }
                else
                {
                    $format = XrowExtractImport::detectFormat( XrowExtractImport::sniff( $stored ) );
                    $separator = $format === 'csv' ? XrowExtractImport::detectSeparator( XrowExtractImport::sniff( $stored ) ) : ',';
                    $_SESSION[$SESSION_KEY] = array( 'path' => $stored, 'name' => $originalName, 'format' => $format, 'separator' => $separator,
                                                     'source' => 'plain', 'size' => filesize( $stored ) );
                    return $module->redirectTo( 'xrowextract/import' );
                }
            }
        }
    }
}
elseif ( $http->hasPostVariable( 'Upload' ) )
{
    $uploadError = ezpI18n::tr( 'design/standard/extract', 'Choose a file first.' );
}

// A package (or standalone class/object XML) session is either an upload, persisted in the
// repository under 'package_name' (see the Upload block above), or a "Try a sample" one, which is
// never registered there at all - its 'path' names a private .ezpkg this session owns, imported into
// the repository transiently for whichever single request needs it (see $Package below) and removed
// again before that request ends, unless "Keep in the repository" says otherwise. Either shape sets
// 'kind', so that alone is enough to know this session is a package one.
$PackageMode = isset( $_SESSION[$SESSION_KEY]['kind'] ) && in_array( $_SESSION[$SESSION_KEY]['kind'], array( 'package', 'contentclass', 'contentobject' ), true );
$hasFile = ( isset( $_SESSION[$SESSION_KEY]['path'] ) && is_file( $_SESSION[$SESSION_KEY]['path'] ) ) || !empty( $_SESSION[$SESSION_KEY]['package_name'] );
$tpl->setVariable( 'HasFile', $hasFile );
$tpl->setVariable( 'UploadError', $uploadError );
$diskFree = XrowExtractUpload::freeDiskSpace();
$tpl->setVariable( 'UploadDiskFree', $diskFree !== null ? XrowExtractUpload::humanSize( $diskFree ) : false );
$tpl->setVariable( 'UploadedSize', $hasFile && isset( $_SESSION[$SESSION_KEY]['size'] ) ? XrowExtractUpload::humanSize( (int)$_SESSION[$SESSION_KEY]['size'] ) : false );
$uploadJsFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract-upload.js';
$tpl->setVariable( 'UploadScriptVersion', is_file( $uploadJsFile ) ? substr( md5_file( $uploadJsFile ), 0, 12 ) : '0' );

if ( $http->hasPostVariable( 'RemoveFile' ) && $hasFile )
{
    // forgetFile() covers every shape of session: a chunked upload (deletes it via
    // XrowExtractUpload), a private .ezpkg row/sample file on disk (unlinks it - a "Try a sample"
    // package's own repository copy is already gone by the time any RemoveFile can be clicked; see
    // $Package below), and an uploaded/kept package (only 'package_name' is set, no local file - left
    // in the repository, same as the "Forget" action on the Package page).
    $forgetFile();
    return $module->redirectTo( 'xrowextract/import' );
}

// The one package a $PackageMode session resolves to, however it is backed: an uploaded/kept one
// fetched by name from the repository (left as it was, not removed), or a "Try a sample" one
// imported transiently from its private 'path' - which this request removes again once it is done
// with it (at the very end of the script, see below), so it is never registered in the repository
// for longer than this one request takes. Computed once, up front, and reused for the meta line
// below and for the dry run/install further down - never fetched or imported twice in one request.
$Package = false;
$PackageIsTransient = false;
$PackageImportError = null;
if ( $PackageMode )
{
    if ( !empty( $_SESSION[$SESSION_KEY]['package_name'] ) )
    {
        $Package = eZPackage::fetch( $_SESSION[$SESSION_KEY]['package_name'] );
    }
    elseif ( !empty( $_SESSION[$SESSION_KEY]['path'] ) && is_file( $_SESSION[$SESSION_KEY]['path'] ) )
    {
        $PackageIsTransient = true;
        $transientImport = XrowExtractPackage::importUploadedArchive( $_SESSION[$SESSION_KEY]['path'] );
        if ( $transientImport['ok'] )
            $Package = $transientImport['package'];
        else
            $PackageImportError = $transientImport['error'];
    }
}

// A loaded package's review step: what it carries (read from its own XML, quick), how existing objects and
// classes are to be treated, and whether its dry run is small enough to show at once without being asked
$PkgObjectMode = $http->hasPostVariable( 'PkgObjectMode' ) && in_array( $http->postVariable( 'PkgObjectMode' ), array( XrowExtractPackage::OBJECT_SKIP, XrowExtractPackage::OBJECT_UPDATE, XrowExtractPackage::OBJECT_NEW ), true )
               ? $http->postVariable( 'PkgObjectMode' ) : XrowExtractPackage::OBJECT_UPDATE;
$PkgClassMode = $http->hasPostVariable( 'PkgClassMode' ) && in_array( $http->postVariable( 'PkgClassMode' ), array( XrowExtractPackage::CLASS_SKIP, XrowExtractPackage::CLASS_REPLACE, XrowExtractPackage::CLASS_NEW ), true )
              ? $http->postVariable( 'PkgClassMode' ) : XrowExtractPackage::CLASS_SKIP;
$tpl->setVariable( 'PkgObjectMode', $PkgObjectMode );
$tpl->setVariable( 'PkgClassMode', $PkgClassMode );
$PackageSummary = false;
$PackageAutoReview = false;
if ( $PackageMode && $Package instanceof eZPackage )
{
    $contents = XrowExtractPackage::packageContents( $Package );
    $PackageSummary = array(
        'name' => $Package->attribute( 'name' ),
        'summary' => (string)$Package->attribute( 'summary' ),
        'classes' => count( $contents['classes'] ),
        'objects' => count( $contents['objects'] ),
        'class_identifiers' => array_slice( array_map( function ( $c ) { return $c['identifier']; }, $contents['classes'] ), 0, 12 ),
        // The datatype check: every datatype the package uses that this site does not have
        'missing_datatypes' => $contents['missing_datatypes'],
    );
    // Up to 500 classes and objects the dry run takes a few seconds and is shown straight away; a larger
    // package waits for "Review the package" (it compares every item with the site)
    $PackageAutoReview = ( $PackageSummary['classes'] + $PackageSummary['objects'] ) <= 500;
}
$tpl->setVariable( 'PackageSummary', $PackageSummary );
$tpl->setVariable( 'PackageAutoReview', $PackageAutoReview );
$tpl->setVariable( 'PackageImportError', $PackageImportError );

$parsed = array( 'header' => array(), 'rows' => array(), 'format' => 'csv', 'separator' => ',' );
if ( $hasFile && $PackageMode )
{
    $tpl->setVariable( 'ImportFormat', 'package' );
    $tpl->setVariable( 'UploadedName', $_SESSION[$SESSION_KEY]['name'] );
    $tpl->setVariable( 'IsSample', !empty( $_SESSION[$SESSION_KEY]['sample'] ) );
    $tpl->setVariable( 'SampleKinds', isset( $_SESSION[$SESSION_KEY]['kinds'] ) ? $_SESSION[$SESSION_KEY]['kinds'] : array() );
    // "N rows" in the meta line for a package: classes + objects it carries - a cheap parse-only
    // inspect(), the same one Preview will redo once the user clicks it for the full dry run.
    if ( $Package instanceof eZPackage )
    {
        $earlyInspection = XrowExtractPackage::inspect( $Package );
        $parsed['total_rows'] = count( $earlyInspection['classes'] ) + count( $earlyInspection['objects'] );
    }
}
elseif ( $hasFile )
{
    $format = $http->hasPostVariable( 'ImportFormat' ) ? (string)$http->postVariable( 'ImportFormat' ) : $_SESSION[$SESSION_KEY]['format'];
    $format = in_array( $format, array( 'csv', 'json', 'xml' ), true ) ? $format : 'csv';
    $separators = array( 'comma' => ',', 'semicolon' => ';', 'tab' => "\t", 'pipe' => '|' );
    $separatorKey = $http->hasPostVariable( 'ImportSeparator' ) ? (string)$http->postVariable( 'ImportSeparator' ) : array_search( $_SESSION[$SESSION_KEY]['separator'], $separators, true );
    $separator = isset( $separators[$separatorKey] ) ? $separators[$separatorKey] : ',';
    // A full streaming pass just to show the mapping/preview screen is cheap in memory but not in time
    // for a huge file (tens of seconds for hundreds of MB, on a single request thread) - too slow to
    // repeat on every postback while the user is only choosing a class or editing a mapping, so it is
    // cached in the session, keyed to this exact file (path + size + mtime never collide across a
    // RemoveFile/NewImport/re-upload) and to the format/separator that changes what streaming finds.
    //
    // Above the byte threshold, the exact row count is never computed inline at all: a byte size this
    // large already means the file will be queued as a background job regardless of its row count (see
    // NeedsQueue below), so only the header is read (fileHeader() is bounded - one CSV line, or an XML
    // pass that stops once </columns> is seen - never a full pass) and the mapping table works from
    // that alone; the job itself reports the exact total once it has actually streamed the file.
    $fileSizeForParse = filesize( $_SESSION[$SESSION_KEY]['path'] );
    $queueThresholdBytesForParse = (int)eZINI::instance( 'csv.ini' )->variable( 'Uploads', 'QueueThresholdMB' );
    $queueThresholdBytesForParse = ( $queueThresholdBytesForParse > 0 ? $queueThresholdBytesForParse : 20 ) * 1024 * 1024;
    if ( $format !== 'json' && $fileSizeForParse > $queueThresholdBytesForParse )
    {
        try
        {
            $info = XrowExtractImport::fileHeader( $_SESSION[$SESSION_KEY]['path'], $format, $separator );
            $parsed = array( 'header' => $info['header'], 'rows' => array(), 'total_rows' => null,
                            'columnIDs' => $info['columnIDs'], 'class' => $info['class'], 'format' => $format, 'separator' => $separator );
        }
        catch ( Exception $e )
        {
            $parsed = array( 'header' => array(), 'rows' => array(), 'total_rows' => null, 'format' => $format, 'separator' => $separator, 'error' => $e->getMessage() );
        }
    }
    else
    {
        $parseCacheKey = md5( $_SESSION[$SESSION_KEY]['path'] . '|' . filemtime( $_SESSION[$SESSION_KEY]['path'] ) . '|' . $fileSizeForParse . '|' . $format . '|' . $separator );
        if ( isset( $_SESSION[$SESSION_KEY]['parseCacheKey'] ) && $_SESSION[$SESSION_KEY]['parseCacheKey'] === $parseCacheKey && isset( $_SESSION[$SESSION_KEY]['parseCache'] ) )
        {
            $parsed = $_SESSION[$SESSION_KEY]['parseCache'];
        }
        else
        {
            $parsed = XrowExtractImport::parseFile( $_SESSION[$SESSION_KEY]['path'], $format, $separator );
            $_SESSION[$SESSION_KEY]['parseCacheKey'] = $parseCacheKey;
            $_SESSION[$SESSION_KEY]['parseCache'] = $parsed;
        }
    }
    $tpl->setVariable( 'ImportFormat', $format );
    $tpl->setVariable( 'ImportSeparatorKey', $separatorKey ?: 'comma' );
    $tpl->setVariable( 'UploadedName', $_SESSION[$SESSION_KEY]['name'] );
    $tpl->setVariable( 'IsSample', !empty( $_SESSION[$SESSION_KEY]['sample'] ) );
    $tpl->setVariable( 'SampleKinds', isset( $_SESSION[$SESSION_KEY]['kinds'] ) ? $_SESSION[$SESSION_KEY]['kinds'] : array() );
}
$tpl->setVariable( 'ParseError', isset( $parsed['error'] ) ? $parsed['error'] : false );
$tpl->setVariable( 'FileHeader', $parsed['header'] );
$tpl->setVariable( 'ImportManifest', isset( $parsed['manifest'] ) ? $parsed['manifest'] : null );
// A file large enough by bytes alone to be queued regardless never has its exact row count computed
// inline (see the note above the parseFile()/fileHeader() choice) - "total_rows" stays null for it.
$tpl->setVariable( 'FileRowCount', isset( $parsed['total_rows'] ) ? $parsed['total_rows'] : count( $parsed['rows'] ) );
$tpl->setVariable( 'FileRowCountKnown', array_key_exists( 'total_rows', $parsed ) ? $parsed['total_rows'] !== null : true );
$xmlColumnIDs = isset( $parsed['columnIDs'] ) ? $parsed['columnIDs'] : null;

// Class: a chosen fallback (a "class" column in the file still wins per row at run time)
$ClassChoices = array();
foreach ( eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, true, false, array( 'name' => 'asc' ) ) as $class )
{
    $count = (int)eZPersistentObject::count( eZContentObject::definition(), array( 'contentclass_id' => (int)$class->attribute( 'id' ) ) );
    $ClassChoices[] = array( 'id' => (int)$class->attribute( 'id' ), 'identifier' => $class->attribute( 'identifier' ), 'name' => $class->attribute( 'name' ), 'count' => $count );
}
$tpl->setVariable( 'ClassChoices', $ClassChoices );

// The same classes by class group, for "Try a sample"'s own choice: any class of the system, also one without objects
$countsByClassID = array();
foreach ( $ClassChoices as $choice )
    $countsByClassID[$choice['id']] = $choice;
$SampleClassGroups = array();
$groupedClassIDs = array();
foreach ( eZContentClassGroup::fetchList( false, true ) as $group )
{
    $members = array();
    foreach ( eZContentClassClassGroup::fetchClassList( eZContentClass::VERSION_STATUS_DEFINED, (int)$group->attribute( 'id' ), true ) as $class )
    {
        $id = (int)$class->attribute( 'id' );
        if ( isset( $countsByClassID[$id] ) )
        {
            $members[] = $countsByClassID[$id];
            $groupedClassIDs[$id] = true;
        }
    }
    if ( $members )
    {
        usort( $members, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        $SampleClassGroups[] = array( 'name' => $group->attribute( 'name' ), 'classes' => $members );
    }
}
$ungrouped = array();
foreach ( $ClassChoices as $choice )
{
    if ( !isset( $groupedClassIDs[$choice['id']] ) )
        $ungrouped[] = $choice;
}
if ( $ungrouped )
    $SampleClassGroups[] = array( 'name' => ezpI18n::tr( 'design/standard/extract', 'Other classes' ), 'classes' => $ungrouped );
$tpl->setVariable( 'SampleClassGroups', $SampleClassGroups );

// The class with the most objects, for "Try a sample" and the file format reference when none is chosen
$MostPopulousClassID = 0;
$mostPopulousCount = -1;
foreach ( $ClassChoices as $choice )
{
    if ( $choice['count'] > $mostPopulousCount )
    {
        $mostPopulousCount = $choice['count'];
        $MostPopulousClassID = $choice['id'];
    }
}

// The File card's "Class for these actions" select and "Class and matching"'s own Class select are
// kept in sync client-side (xrowextract.js), so either one drives every tile action (sample,
// template, class/object XML) and step 2's own matching alike - one class choice, not two. Whichever
// was actually posted wins; the File-card one takes priority so a visitor without JavaScript, who
// only touched that one, still gets what they chose reflected everywhere, not just there.
$ClassID = $http->hasPostVariable( 'SampleClassID' ) && (int)$http->postVariable( 'SampleClassID' )
         ? (int)$http->postVariable( 'SampleClassID' )
         : ( $http->hasPostVariable( 'ClassID' ) ? (int)$http->postVariable( 'ClassID' ) : 0 );
if ( !$ClassID && !empty( $_SESSION[$SESSION_KEY]['classID'] ) )
{
    // Right after "Try a sample"'s own redirect, no ClassID is posted yet; the class it was built for
    $ClassID = (int)$_SESSION[$SESSION_KEY]['classID'];
}
if ( !$ClassID && !empty( $parsed['class'] ) )
{
    // An XML file names its own class (the <export class="..."> attribute); use it as the default
    $fromFile = eZContentClass::fetchByIdentifier( $parsed['class'] );
    if ( $fromFile instanceof eZContentClass )
        $ClassID = (int)$fromFile->attribute( 'id' );
}
$tpl->setVariable( 'ClassID', $ClassID );
// Whether a "class + content"/"content only" package template can be built from this class's own
// existing content, or would fall back to temporary hidden scratch content (XrowExtractPackage::
// buildContentPackage()) - known ahead of the download itself (which always uses this same
// $ClassID, never the sample box's own class), so the page can say so beforehand.
$tpl->setVariable( 'ClassHasExistingContent', $ClassID && isset( $countsByClassID[$ClassID] ) ? ( $countsByClassID[$ClassID]['count'] > 0 ) : true );
$SampleClassID = $ClassID ?: $MostPopulousClassID;
$tpl->setVariable( 'SampleClassID', $SampleClassID );

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
{
    // New objects go below the public site's root by default (content, not the user placement)
    $publicContentINI = eZSiteAccess::getIni( eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ), 'content.ini' );
    $ParentNodeID = (int)$publicContentINI->variable( 'NodeSettings', 'RootNode' );
}
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
    $suggested = XrowExtractImport::suggestMapping( $parsed['header'], $ClassID, $xmlColumnIDs );
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
if ( ( $http->hasPostVariable( 'DownloadTemplate' ) || $http->hasPostVariable( 'DownloadClassXML' ) || $http->hasPostVariable( 'DownloadObjectXML' ) ) && $ClassID <= 0 )
    $tpl->setVariable( 'UploadError', ezpI18n::tr( 'design/standard/extract', 'Choose a class first' ) );

// "Download a template" is one button per format tile now (File card, pass 1 of the redesign),
// not a shared format select - DownloadTemplate's own posted value names the format, the same
// pattern the per-format "Try a sample" buttons already use.
$templateFormatIn = $http->hasPostVariable( 'DownloadTemplate' ) ? (string)$http->postVariable( 'DownloadTemplate' ) : 'xml';
// A content-package template: "package" is a fourth format here too, just like xml/json/csv - the
// class-only/content-only choice sits inside that tile and only matters when its own button is
// picked (XrowExtractPackage::buildContentPackage(), preferring the class's own existing content).
if ( $http->hasPostVariable( 'DownloadTemplate' ) && $ClassID > 0 && $templateFormatIn === 'package' && ( $templateClass = eZContentClass::fetch( $ClassID ) ) )
{
    $templateVariantIn = $http->hasPostVariable( 'TemplatePackageVariant' ) ? (string)$http->postVariable( 'TemplatePackageVariant' ) : 'both';
    $templateVariant = in_array( $templateVariantIn, array( 'both', 'class', 'content' ), true ) ? $templateVariantIn : 'both';
    $build = XrowExtractPackage::buildContentPackage( $ClassID, $templateVariant );
    if ( !$build['ok'] )
    {
        $tpl->setVariable( 'UploadError', ezpI18n::tr( 'design/standard/extract', 'The template package could not be built: %reason', null, array( '%reason' => implode( ' ', $build['errors'] ) ) ) );
    }
    else
    {
        XrowExtractImport::streamPackageDownload( $build['package'], $templateClass->attribute( 'identifier' ) . '_template_' . $templateVariant . '.ezpkg' );
    }
}
// A single content-class definition XML, or a single content-object XML, on their own - the Import
// page accepts those directly too (detectUploadKind()), no archive needed.
if ( $http->hasPostVariable( 'DownloadClassXML' ) && $ClassID > 0 && ( $classXMLClass = eZContentClass::fetch( $ClassID ) ) )
{
    $build = XrowExtractPackage::buildContentPackage( $ClassID, 'class' );
    $bytes = $build['ok'] ? XrowExtractPackage::singleItemXMLBytes( $build['package'], 'ezcontentclass' ) : false;
    if ( $bytes === false )
        $tpl->setVariable( 'UploadError', ezpI18n::tr( 'design/standard/extract', 'The class definition XML could not be built.' ) );
    else
        XrowExtractImport::streamXMLDownload( $bytes, $classXMLClass->attribute( 'identifier' ) . '_class.xml' );
}
if ( $http->hasPostVariable( 'DownloadObjectXML' ) && $ClassID > 0 && ( $objectXMLClass = eZContentClass::fetch( $ClassID ) ) )
{
    $build = XrowExtractPackage::buildContentPackage( $ClassID, 'content' );
    $bytes = ( $build['ok'] && $build['object_count'] > 0 ) ? XrowExtractPackage::singleItemXMLBytes( $build['package'], 'ezcontentobject' ) : false;
    if ( $bytes === false )
        $tpl->setVariable( 'UploadError', ezpI18n::tr( 'design/standard/extract', 'No content object XML could be built (the class may have no content on this site yet).' ) );
    else
        XrowExtractImport::streamXMLDownload( $bytes, $objectXMLClass->attribute( 'identifier' ) . '_objects.xml' );
}

// A row-format template: an empty file with the columns of the Migration set for the chosen class
if ( $http->hasPostVariable( 'DownloadTemplate' ) && $ClassID > 0 && in_array( $templateFormatIn, array( 'xml', 'json', 'csv' ), true ) && ( $templateClass = eZContentClass::fetch( $ClassID ) ) )
{
    $templateColumns = XrowExtractCatalogue::resolveColumns( XrowExtractCatalogue::setColumnIDs( 'migration', $ClassID ), $ClassID,
                                                             XrowExtractColumns::extraAttributes( false ) );
    $templateFormat = $templateFormatIn;
    $templateWriter = new XrowExtractWriter( $templateFormat, $templateColumns, ',', true, "\r\n", array( 'class' => $templateClass->attribute( 'identifier' ) ) );
    $templateData = $templateWriter->begin() . $templateWriter->end();
    header( 'Cache-Control: private, no-store, max-age=0' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Content-Type: ' . $templateWriter->contentType( 'utf-8' ) );
    header( 'Content-Length: ' . strlen( $templateData ) );
    header( 'Content-Disposition: attachment; filename="' . XrowExtractColumns::fileName( $templateClass->attribute( 'identifier' ), '_import_template.' . $templateWriter->extension() ) . '"' );
    while ( @ob_end_clean() );
    echo $templateData;
    eZExecution::cleanExit();
}

// Try a sample: a file built from the site's own content, loaded exactly as if it had been uploaded.
// "package" is a fourth format here, just like xml/json/csv (the owner's own words): the sample is a
// real content package built read-only from up to 3 existing objects of the class
// (XrowExtractPackage::buildSamplePackage()), altered on the copy so its dry run shows every outcome.
if ( $http->hasPostVariable( 'TrySample' ) )
{
    $sampleFormatIn = (string)$http->postVariable( 'TrySample' );
    // The sample box's own class choice: any class of the system
    if ( $http->hasPostVariable( 'SampleClassID' ) && isset( $countsByClassID[(int)$http->postVariable( 'SampleClassID' )] ) )
        $SampleClassID = (int)$http->postVariable( 'SampleClassID' );
    $sampleFormat = in_array( $sampleFormatIn, array( 'xml', 'json', 'csv', 'package' ), true ) ? $sampleFormatIn : 'xml';
    if ( !$SampleClassID )
    {
        $tpl->setVariable( 'UploadError', ezpI18n::tr( 'design/standard/extract', 'There is no class to sample from (the site has no classes with content, or none you may read).' ) );
    }
    elseif ( $sampleFormat === 'package' )
    {
        $sample = XrowExtractPackage::buildSamplePackage( $SampleClassID );
        if ( !$sample['ok'] )
        {
            $tpl->setVariable( 'UploadError', ezpI18n::tr( 'design/standard/extract', 'The sample package could not be built: %reason', null, array( '%reason' => implode( ' ', $sample['errors'] ) ) ) );
        }
        else
        {
            $forgetFile();
            $_SESSION[$SESSION_KEY] = array( 'path' => $sample['file'], 'name' => basename( $sample['file'] ), 'format' => 'package', 'kind' => 'package',
                                             'sample' => true, 'kinds' => array( 'create', 'update', 'unchanged', 'class_missing' ), 'classID' => $SampleClassID );
            return $module->redirectTo( 'xrowextract/import' );
        }
    }
    else
    {
        $sample = XrowExtractImport::buildSample( $SampleClassID, $ParentNodeID, $Language, $sampleFormat );
        if ( empty( $sample['ok'] ) )
        {
            $tpl->setVariable( 'UploadError', ezpI18n::tr( 'design/standard/extract', 'The sample could not be built: %reason', null, array( '%reason' => isset( $sample['error'] ) ? $sample['error'] : '?' ) ) );
        }
        else
        {
            $sampleName = XrowExtractColumns::fileName( $sample['classIdentifier'], '_sample.' . $sample['format'], 'sample' );
            $stored = XrowExtractImport::storeGenerated( $sample['text'], $sampleName );
            if ( $stored === false )
            {
                $tpl->setVariable( 'UploadError', ezpI18n::tr( 'design/standard/extract', 'The sample could not be stored.' ) );
            }
            else
            {
                $forgetFile();
                $_SESSION[$SESSION_KEY] = array( 'path' => $stored, 'name' => $sampleName, 'format' => $sample['format'], 'separator' => ',',
                                                 'sample' => true, 'kinds' => $sample['kinds'], 'classID' => $SampleClassID, 'source' => 'plain' );
                return $module->redirectTo( 'xrowextract/import' );
            }
        }
    }
}

// The file format reference: a small card of real examples for $SampleClassID (or a static fallback),
// and a downloadable example file in every format
$ReferenceClass = $SampleClassID ? eZContentClass::fetch( $SampleClassID ) : null;
if ( $http->hasPostVariable( 'DownloadExample' ) && $ReferenceClass instanceof eZContentClass )
{
    $exampleFormatIn = (string)$http->postVariable( 'DownloadExample' );
    $exampleFormat = in_array( $exampleFormatIn, array( 'xml', 'json', 'csv' ), true ) ? $exampleFormatIn : 'xml';
    $example = XrowExtractImport::referenceExampleRows( $SampleClassID, $exampleFormat, 2 );
    if ( !empty( $example['ok'] ) && $example['rowCount'] > 0 )
    {
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'X-Content-Type-Options: nosniff' );
        $exampleWriter = new XrowExtractWriter( $exampleFormat, array(), ',', true );
        header( 'Content-Type: ' . $exampleWriter->contentType( 'utf-8' ) );
        header( 'Content-Length: ' . strlen( $example['text'] ) );
        header( 'Content-Disposition: attachment; filename="' . $example['filename'] . '"' );
        while ( @ob_end_clean() );
        echo $example['text'];
        eZExecution::cleanExit();
    }
}
$tpl->setVariable( 'ReferenceClass', $ReferenceClass ? array( 'id' => $SampleClassID, 'identifier' => $ReferenceClass->attribute( 'identifier' ), 'name' => $ReferenceClass->attribute( 'name' ) ) : false );
$ReferenceExamples = array();
if ( $ReferenceClass instanceof eZContentClass )
{
    foreach ( array( 'xml', 'json', 'csv' ) as $refFormat )
        $ReferenceExamples[$refFormat] = XrowExtractImport::referenceExampleRows( $SampleClassID, $refFormat, 2 );
}
$tpl->setVariable( 'ReferenceExamples', $ReferenceExamples );
$tpl->setVariable( 'DatatypeExamples', $ReferenceClass instanceof eZContentClass ? XrowExtractImport::datatypeExamples( $SampleClassID ) : array() );
$referenceSpecialColumns = array();
foreach ( XrowExtractColumns::extraAttributes( false ) as $id => $column )
    $referenceSpecialColumns[] = array( 'id' => $id, 'exportname' => str_replace( '_', '-', $column['exportname'] ), 'name' => $column['name'] );
$tpl->setVariable( 'ReferenceSpecialColumns', $referenceSpecialColumns );
$referenceUnimportable = array();
foreach ( array( 'ezenhancedobjectrelation', 'ezenhancedselection', 'ezenum', 'ezcountry', 'ezmatrix', 'ezprice', 'ezuser', 'eztime', 'hmregexpline' ) as $datatype )
    $referenceUnimportable[] = array( 'id' => $datatype, 'name' => XrowExtractColumns::datatypeName( $datatype ), 'reason' => XrowExtractImport::unsupportedReason( $datatype ) );
$tpl->setVariable( 'ReferenceUnimportable', $referenceUnimportable );

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

// Above the threshold, both the dry run and the apply run as a background job instead (the web
// request only queues it and returns); below it, they run right here exactly as before - "rows" only
// ever held a bounded preview since parseFile() started streaming, so both paths now read the file
// again through streamRows() for the real work, never the preview array. A package (or standalone
// class/object XML) is never a row file, so it is never queued this way - see the package block below.
$QueueThresholdRows = (int)eZINI::instance( 'csv.ini' )->variable( 'Uploads', 'QueueThresholdRows' );
if ( $QueueThresholdRows <= 0 )
    $QueueThresholdRows = 2000;
$QueueThresholdBytes = (int)eZINI::instance( 'csv.ini' )->variable( 'Uploads', 'QueueThresholdMB' );
$QueueThresholdBytes = ( $QueueThresholdBytes > 0 ? $QueueThresholdBytes : 20 ) * 1024 * 1024;
$fileSizeForThreshold = ( $hasFile && !$PackageMode && isset( $_SESSION[$SESSION_KEY]['path'] ) ) ? filesize( $_SESSION[$SESSION_KEY]['path'] ) : 0;
$NeedsQueue = $hasFile && !$PackageMode && ( $parsed['total_rows'] === null || $parsed['total_rows'] > $QueueThresholdRows || $fileSizeForThreshold > $QueueThresholdBytes );
$tpl->setVariable( 'NeedsQueue', $NeedsQueue );
$tpl->setVariable( 'QueueThresholdRows', $QueueThresholdRows );

// A content package, or a standalone class/object XML wrapped as one (see the Upload block): its
// dry run and its "Apply" both go through the same block as XML/CSV/JSON rows below, reusing the
// Parent field above for its top-level objects. Existing-object/existing-class handling uses the
// same safe defaults the Package page starts from (update existing objects by remote id, skip an
// existing class) - the Package tab is still where those are changed for a one-off install.
// $Package/$PackageIsTransient were already resolved above, for the meta line.
$KeepThisPackage = false;
if ( $Package instanceof eZPackage && $PackageIsTransient && $http->hasPostVariable( 'KeepSamplePackage' ) )
{
    // "Keep in the repository": the transient import stays (skipped below, at the end of the
    // script), and the session graduates from a private-file sample to an ordinary persisted
    // package - the same shape an upload already uses, reachable from the Package tab from now on.
    $KeepThisPackage = true;
    // The package keeps its xrowextract_sample_ name (nothing here renames it), so the marker is
    // still what tells cleanupOldSamplePackages() - a defence-in-depth sweep, not the primary
    // mechanism any more - to leave it alone forever, the same as before this request's package
    // was ever transient.
    XrowExtractPackage::keepSamplePackage( $Package );
    $keptPath = isset( $_SESSION[$SESSION_KEY]['path'] ) ? $_SESSION[$SESSION_KEY]['path'] : null;
    unset( $_SESSION[$SESSION_KEY]['sample'], $_SESSION[$SESSION_KEY]['path'] );
    $_SESSION[$SESSION_KEY]['package_name'] = $Package->attribute( 'name' );
    if ( $keptPath && is_file( $keptPath ) )
        @unlink( $keptPath );
    // IsSample (set earlier, for the meta line, before this Keep click was processed) still says
    // "sample" for this one response otherwise - the repository state is already correct by now,
    // this only keeps the page's own wording from lagging a request behind it.
    $tpl->setVariable( 'IsSample', false );
}
$tpl->setVariable( 'PackageMode', $PackageMode );
$tpl->setVariable( 'PackageKind', $PackageMode ? $_SESSION[$SESSION_KEY]['kind'] : false );
$tpl->setVariable( 'PackageName', $Package instanceof eZPackage ? $Package->attribute( 'name' ) : '' );
$tpl->setVariable( 'IsPackageSample', $PackageMode && !empty( $_SESSION[$SESSION_KEY]['sample'] ) );
$tpl->setVariable( 'PackageImportError', $PackageImportError );

// Preview (dry run) and Apply run the same engine; Apply only after a preview was shown for these settings
$Preview = false;
$Applied = false;
$QueuedJobID = null;

/** Publishes a row-shaped $result (XrowExtractImport::run()'s own shape, or
 * XrowExtractPackage::inspectionToResultRows()'s) to the template: ResultClasses,
 * Result, Preview/Applied, ApplyCount - the same for a row import and a package. */
$publishResult = function ( array $result, $apply ) use ( $tpl, $ContentLanguages, $Language )
{
    $resultClasses = array();
    foreach ( $result['rows'] as $row )
    {
        if ( empty( $row['class_id'] ) )
            continue;
        $id = (int)$row['class_id'];
        if ( !isset( $resultClasses[$id] ) )
            $resultClasses[$id] = array( 'id' => $id, 'identifier' => $row['class_identifier'], 'name' => $row['class_name'], 'rows' => 0,
                                         'create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0 );
        $resultClasses[$id]['rows']++;
        if ( isset( $resultClasses[$id][$row['action']] ) )
            $resultClasses[$id][$row['action']]++;
    }
    $tpl->setVariable( 'ResultClasses', array_values( $resultClasses ) );
    $tpl->setVariable( 'ResultLanguageName', isset( $ContentLanguages[$Language]['name'] ) ? $ContentLanguages[$Language]['name'] : (string)$Language );
    $tpl->setVariable( 'Result', $result );
    $tpl->setVariable( 'Preview', !$apply );
    $tpl->setVariable( 'Applied', $apply );
    $tpl->setVariable( 'ApplyCount', $result['counts']['create'] + $result['counts']['update'] );
};

if ( $hasFile && $PackageMode && $http->hasPostVariable( 'Apply' ) && $Package instanceof eZPackage && XrowExtractJob::available() )
{
    // Installing can take minutes (every class and object through the kernel's package handlers),
    // exactly as for the Package tab's own "Install this package" (modules/xrowextract/package.php),
    // so it runs the same way: a background job (bin/php/package.php --install, job type "package")
    // and the page goes straight to the Jobs view instead of holding the request.
    //
    // A package here can be transient ($PackageIsTransient: a "Try a sample"/"Start from a template"
    // one, imported into the repository a few lines up only for this one request's own use, and
    // normally removed again at the very end of this script). The job needs it to still be there
    // when it runs, maybe minutes from now, so that end-of-script removal must not happen - returning
    // here, before that code, is what skips it (the row-import queue branch above relies on the same
    // thing). --remove-after tells the job itself to remove it once installing is done, so the
    // repository still ends up exactly as clean as an immediate, in-request install would have left
    // it. An already-registered package (uploaded, or explicitly kept) is not touched either way.
    $installArgs = array(
        '--install=' . $Package->attribute( 'name' ),
        '--parent=' . (int)$ParentNodeID,
        '--site-access=' . eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ),
        '--object-mode=' . $PkgObjectMode,
        '--class-mode=' . $PkgClassMode,
    );
    if ( $PackageIsTransient )
        $installArgs[] = '--remove-after';
    $installJobID = XrowExtractJob::create( array(
        'type' => 'package', 'owner' => $login,
        'what' => ezpI18n::tr( 'design/standard/extract', 'Install package %name', false, array( '%name' => $Package->attribute( 'name' ) ) ),
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
elseif ( $hasFile && $PackageMode && ( $http->hasPostVariable( 'Preview' ) || $http->hasPostVariable( 'Apply' )
                                       || ( $PackageAutoReview && !$http->hasPostVariable( 'BrowseParent' ) ) ) )
{
    // A content package's dry run/apply: the same Preview/Apply buttons and the same row-shaped
    // display as XML/CSV/JSON ("just like json, csv, xml"), through inspectionToResultRows(). Apply
    // installs it for real (XrowExtractPackage::install(), the site's default siteaccess, updating an
    // existing object by remote id and skipping an existing class - the Package tab's own install
    // form is still where those two are changed for a one-off). Reached either when no background
    // jobs are available on this installation, or for Preview, which is always quick enough to run
    // in the request.
    $apply = $http->hasPostVariable( 'Apply' );
    $forceFreshInspection = false;
    if ( $apply && $Package instanceof eZPackage )
    {
        $installStartedAt = time();
        $installReport = XrowExtractPackage::install( $Package, $ParentNodeID, eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ),
                                                       $PkgObjectMode, $PkgClassMode );
        // In the request (no background jobs here): the install history row the job's runner writes otherwise
        XrowExtractHistory::recordInstall( array(
            'owner_login' => $login, 'trigger_type' => 'manual', 'started_at' => $installStartedAt, 'ended_at' => time(),
            'package' => $Package->attribute( 'name' ), 'parent_node_id' => (int)$ParentNodeID,
            'site_access' => eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ),
            'object_mode' => $PkgObjectMode, 'class_mode' => $PkgClassMode,
            'counts' => isset( $earlyInspection['counts'] ) ? $earlyInspection['counts'] : array(),
            'report' => $installReport,
            'missing_datatypes' => isset( $earlyInspection['missing_datatypes'] ) ? $earlyInspection['missing_datatypes'] : array(),
        ) );
        if ( $installReport['ok'] )
        {
            eZContentObject::clearCache();
            // What "already exists" means just changed; a cached dry run from before the install
            // would still say "create" for what this just installed.
            XrowExtractPackage::forgetInspections( $Package->attribute( 'name' ) );
            $forceFreshInspection = true;
        }
        $tpl->setVariable( 'PackageInstallErrors', $installReport['errors'] );
    }
    // Cached (#26): see XrowExtractPackage::cachedInspection()'s own comment - a large package makes
    // a fresh inspect() too slow to redo on every reload of what is otherwise only ever a read of
    // its own last result.
    $inspection = $Package instanceof eZPackage
                ? XrowExtractPackage::cachedInspection( $Package, $ParentNodeID, $forceFreshInspection )
                : array( 'classes' => array(), 'objects' => array() );
    $result = XrowExtractPackage::inspectionToResultRows( $inspection );
    $publishResult( $result, $apply );
    // The package contents browser (#26): a short preview of the package's own files here
    // (design:xrowextract/package_files_preview.tpl), "Browse all N files" linking to the full
    // paginated xrowextract/browse/<name> for the rest - the same include the Package tab uses.
    // Skipped once $forgetFile() below has already cleared $Package for an applied, non-kept
    // transient package: nothing left to browse.
    if ( $Package instanceof eZPackage )
    {
        $filesPreview = XrowExtractPackage::packageFilesPreview( $Package );
        $tpl->setVariable( 'Files', $filesPreview['files'] );
        $tpl->setVariable( 'FilesTotal', $filesPreview['total'] );
        $tpl->setVariable( 'FilesOffset', 0 );
        $tpl->setVariable( 'ViewedFile', false );
    }
    if ( $apply )
        $forgetFile();
}
elseif ( $hasFile && $NeedsQueue && ( $http->hasPostVariable( 'Preview' ) || $http->hasPostVariable( 'Apply' ) ) && XrowExtractJob::available() )
{
    $apply = $http->hasPostVariable( 'Apply' );
    $args = array( '--file=' . $_SESSION[$SESSION_KEY]['path'] );
    if ( $apply )
        $args[] = '--apply';
    if ( $ClassID )
        $args[] = '--class=' . $ClassID;
    if ( $ParentNodeID )
        $args[] = '--parent=' . $ParentNodeID;
    $args[] = '--match=' . $MatchMode;
    if ( $Language )
        $args[] = '--language=' . $Language;
    $mapOverrides = array();
    foreach ( $Mapping as $m )
    {
        if ( $m['target'] !== 'ignore' )
            $mapOverrides[] = $m['column'] . '=' . $m['target'];
    }
    if ( $mapOverrides )
        $args[] = '--map=' . implode( ',', $mapOverrides );
    $QueuedJobID = XrowExtractJob::create( array(
        'type' => 'import', 'owner' => $login,
        'what' => ( $apply ? 'Import' : 'Preview' ) . ': ' . $_SESSION[$SESSION_KEY]['name'],
        'format' => 'json', 'output_file' => 'report.json', 'args' => $args,
    ) );
    XrowExtractJob::start( $QueuedJobID );
    $http->setSessionVariable( 'eZExtractJobStarted', $QueuedJobID );
    return $module->redirectTo( 'xrowextract/jobs' );
}
elseif ( $hasFile && !$NeedsQueue && ( $http->hasPostVariable( 'Preview' ) || $http->hasPostVariable( 'Apply' ) ) )
{
    $mappingForRun = array();
    foreach ( $Mapping as $m )
        $mappingForRun[] = array( 'column' => $m['column'], 'target' => $m['target'] );
    $apply = $http->hasPostVariable( 'Apply' );
    $streamPath = $_SESSION[$SESSION_KEY]['path'];
    $streamFormat = $parsed['format'];
    $streamSeparator = $parsed['separator'];
    $result = XrowExtractImport::run( array(
        'rows'         => XrowExtractImport::streamRows( $streamPath, $streamFormat, $streamSeparator ),
        'mapping'      => $mappingForRun,
        'classID'      => $ClassID,
        'match'        => $MatchMode,
        'language'     => $Language,
        'parentNodeID' => $ParentNodeID,
        'apply'        => $apply,
        'totalRows'    => $parsed['total_rows'],
    ) );
    $publishResult( $result, $apply );
    if ( $apply )
    {
        // Done: the file has served its purpose
        $forgetFile();
    }
}

// A "Try a sample" package (imported transiently, above, for whatever this one request needed it
// for) is removed from the repository again right here - unless "Keep in the repository" was just
// pressed. This runs on every request that resolved one, not only Preview/Apply: even the plain
// page load that only reads the meta line imports and then removes it again. Nothing about a sample
// is ever registered in the repository for longer than the single request that touched it.
if ( $PackageIsTransient && !$KeepThisPackage && $Package instanceof eZPackage )
{
    $stillThere = eZPackage::fetch( $Package->attribute( 'name' ) );
    if ( $stillThere instanceof eZPackage )
        $stillThere->remove();
}

$scriptFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract.js';
$tpl->setVariable( 'ScriptVersion', is_file( $scriptFile ) ? substr( md5_file( $scriptFile ), 0, 12 ) : '0' );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/import.tpl' );
$Result['path'] = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Import' ) ),
);
$importableNames = array();
foreach ( XrowExtractImport::baseImportableDatatypes() as $datatype )
    $importableNames[] = array( 'id' => $datatype, 'name' => XrowExtractColumns::datatypeName( $datatype ) );
usort( $importableNames, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
$tpl->setVariable( 'ImportableDatatypes', $importableNames );
$Result['left_menu'] = 'design:xrowextract/menu_import.tpl';

?>
