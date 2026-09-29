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
        @unlink( $current['path'] );
    unset( $_SESSION[$SESSION_KEY] );
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

// A new upload: the plain (no JavaScript) whole-file fallback, or a finished chunked upload adopted by
// its UploadID (XrowExtractUpload::path() only returns a path the current user's own upload, complete)
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
        $format = XrowExtractImport::detectFormat( XrowExtractImport::sniff( $stored ) );
        $separator = $format === 'csv' ? XrowExtractImport::detectSeparator( XrowExtractImport::sniff( $stored ) ) : ',';
        $_SESSION[$SESSION_KEY] = array( 'path' => $stored, 'name' => $originalName, 'format' => $format, 'separator' => $separator,
                                         'source' => 'chunked', 'upload_id' => $uploadID, 'size' => filesize( $stored ) );
        return $module->redirectTo( 'xrowextract/import' );
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
            $format = XrowExtractImport::detectFormat( XrowExtractImport::sniff( $stored ) );
            $separator = $format === 'csv' ? XrowExtractImport::detectSeparator( XrowExtractImport::sniff( $stored ) ) : ',';
            $_SESSION[$SESSION_KEY] = array( 'path' => $stored, 'name' => $originalName, 'format' => $format, 'separator' => $separator,
                                             'source' => 'plain', 'size' => filesize( $stored ) );
            return $module->redirectTo( 'xrowextract/import' );
        }
    }
}
elseif ( $http->hasPostVariable( 'Upload' ) )
{
    $uploadError = ezpI18n::tr( 'design/standard/extract', 'Choose a file first.' );
}

$hasFile = isset( $_SESSION[$SESSION_KEY]['path'] ) && is_file( $_SESSION[$SESSION_KEY]['path'] );
$tpl->setVariable( 'HasFile', $hasFile );
$tpl->setVariable( 'UploadError', $uploadError );
$diskFree = XrowExtractUpload::freeDiskSpace();
$tpl->setVariable( 'UploadDiskFree', $diskFree !== null ? XrowExtractUpload::humanSize( $diskFree ) : false );
$tpl->setVariable( 'UploadedSize', $hasFile && isset( $_SESSION[$SESSION_KEY]['size'] ) ? XrowExtractUpload::humanSize( (int)$_SESSION[$SESSION_KEY]['size'] ) : false );
$uploadJsFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract-upload.js';
$tpl->setVariable( 'UploadScriptVersion', is_file( $uploadJsFile ) ? substr( md5_file( $uploadJsFile ), 0, 12 ) : '0' );

if ( $http->hasPostVariable( 'RemoveFile' ) && $hasFile )
{
    $forgetFile();
    return $module->redirectTo( 'xrowextract/import' );
}

$parsed = array( 'header' => array(), 'rows' => array(), 'format' => 'csv', 'separator' => ',' );
if ( $hasFile )
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

$ClassID = $http->hasPostVariable( 'ClassID' ) ? (int)$http->postVariable( 'ClassID' ) : 0;
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
if ( $http->hasPostVariable( 'DownloadTemplate' ) && $ClassID <= 0 )
    $tpl->setVariable( 'UploadError', ezpI18n::tr( 'design/standard/extract', 'Choose a class first' ) );
// A template: an empty file with the columns of the Migration set for the chosen class (fill it, import it)
if ( $http->hasPostVariable( 'DownloadTemplate' ) && $ClassID > 0 && ( $templateClass = eZContentClass::fetch( $ClassID ) ) )
{
    $templateColumns = XrowExtractCatalogue::resolveColumns( XrowExtractCatalogue::setColumnIDs( 'migration', $ClassID ), $ClassID,
                                                             XrowExtractColumns::extraAttributes( false ) );
    $templateFormatIn = $http->hasPostVariable( 'TemplateFormat' ) ? (string)$http->postVariable( 'TemplateFormat' ) : 'xml';
    $templateFormat = in_array( $templateFormatIn, array( 'xml', 'json', 'csv' ), true ) ? $templateFormatIn : 'xml';
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

// Try a sample: a file built from the site's own content, loaded exactly as if it had been uploaded
if ( $http->hasPostVariable( 'TrySample' ) )
{
    $sampleFormatIn = (string)$http->postVariable( 'TrySample' );
    $sampleFormat = in_array( $sampleFormatIn, array( 'xml', 'json', 'csv' ), true ) ? $sampleFormatIn : 'xml';
    if ( !$SampleClassID )
    {
        $tpl->setVariable( 'UploadError', ezpI18n::tr( 'design/standard/extract', 'There is no class to sample from (the site has no classes with content, or none you may read).' ) );
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
                if ( isset( $_SESSION[$SESSION_KEY]['path'] ) && is_file( $_SESSION[$SESSION_KEY]['path'] ) )
                    @unlink( $_SESSION[$SESSION_KEY]['path'] );
                $_SESSION[$SESSION_KEY] = array( 'path' => $stored, 'name' => $sampleName, 'format' => $sample['format'], 'separator' => ',',
                                                 'sample' => true, 'kinds' => $sample['kinds'], 'classID' => $SampleClassID );
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
// again through streamRows() for the real work, never the preview array.
$QueueThresholdRows = (int)eZINI::instance( 'csv.ini' )->variable( 'Uploads', 'QueueThresholdRows' );
if ( $QueueThresholdRows <= 0 )
    $QueueThresholdRows = 2000;
$QueueThresholdBytes = (int)eZINI::instance( 'csv.ini' )->variable( 'Uploads', 'QueueThresholdMB' );
$QueueThresholdBytes = ( $QueueThresholdBytes > 0 ? $QueueThresholdBytes : 20 ) * 1024 * 1024;
$fileSizeForThreshold = $hasFile ? filesize( $_SESSION[$SESSION_KEY]['path'] ) : 0;
$NeedsQueue = $hasFile && ( $parsed['total_rows'] === null || $parsed['total_rows'] > $QueueThresholdRows || $fileSizeForThreshold > $QueueThresholdBytes );
$tpl->setVariable( 'NeedsQueue', $NeedsQueue );
$tpl->setVariable( 'QueueThresholdRows', $QueueThresholdRows );

$Preview = false;
$Applied = false;
$QueuedJobID = null;

if ( $hasFile && $NeedsQueue && ( $http->hasPostVariable( 'Preview' ) || $http->hasPostVariable( 'Apply' ) ) && XrowExtractJob::available() )
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
    $tpl->setVariable( 'Result', $result );
    $tpl->setVariable( 'Preview', !$apply );
    $tpl->setVariable( 'Applied', $apply );
    $tpl->setVariable( 'ApplyCount', $result['counts']['create'] + $result['counts']['update'] );
    if ( $apply )
    {
        // Done: the file has served its purpose
        $forgetFile();
    }
}

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
