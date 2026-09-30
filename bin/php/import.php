#!/usr/bin/env php
<?php
/**
 * Reads an XML, CSV or JSON file (as XrowExtractWriter writes it, any column
 * set, in particular "migration") back into content objects: the other
 * direction of bin/php/csv.php. A dry run (the default) never touches the
 * database. Streams the file (constant memory for XML and CSV, and for JSON
 * up to csv.ini [Uploads] JsonOneShotThresholdMB), so there is no file size
 * limit here beyond real disk space and time.
 *
 * Usage (from the installation root; ./console ext:xrowextract:import runs it too):
 *   php extension/xrowextract/bin/php/import.php --file=articles.xml --class=ng_article --parent=2
 *   php extension/xrowextract/bin/php/import.php --file=articles.xml --class=ng_article --parent=2 --apply
 *   php extension/xrowextract/bin/php/import.php --file=export.csv --match=object_id --language=ger-DE
 *   php extension/xrowextract/bin/php/import.php --file=export.csv --map=title=title,authors-ids=authors:ids
 *   php extension/xrowextract/bin/php/import.php --file=big.xml --class=ng_article --parent=2 --apply --background
 *   php extension/xrowextract/bin/php/import.php --file=big.xml --class=ng_article --parent=2 --apply --resume-from=40001
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array(
    'description'    => "Imports an XML, CSV or JSON file (as exported by xrowextract) back into content objects. Dry run by default.",
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
) );
$script->startup();
$options = $script->getOptions(
    '[file:][class:][parent:][match:][language:][map:][apply][user:][report:][output:][progress-file:][resume-from:][background][what:][manifest:][no-manifest]',
    '',
    array(
        'file'          => 'XML, CSV or JSON file to read (required); - for stdin',
        'class'         => 'Class id or identifier, used for rows without a "class" column',
        'parent'        => 'Parent node id for new objects without a parent-remote-id/main-parent-node-id column',
        'match'         => 'remote_id (default, when the file has a remote-id column), object_id, or none (always create)',
        'language'      => 'Locale for rows without a language column (default: the site default language)',
        'map'           => 'Column overrides: file-column=target,... (target: an attribute identifier, identifier:format, ' .
                            'a special column id such as ezcontentobject.remote_id, or "ignore"); default: automatic',
        'apply'         => 'Write the changes (default: dry run only)',
        'user'          => 'Import with the permissions of this login (default: admin)',
        'report'        => 'Write the full per-row result as JSON to this file (also written next to any error rows file)',
        'output'        => 'Same as --report (the name a background job launches this with)',
        'progress-file' => 'Write done/total/counts here as the import runs (XrowExtractJob::writeProgress() format)',
        'resume-from'   => 'Row number to continue from (1-based): earlier rows are parsed and skipped, not processed again',
        'background'    => 'Queue this as a background job (XrowExtractJob, type import) and return at once instead of running now',
        'what'          => 'Free text describing this import, shown on the Jobs page (used with --background)',
        'manifest'      => 'A typed column manifest (JSON) for --file; default: <file>.manifest.json next to it, or the one embedded in an XML/JSON export. Its column ids map every column exactly',
        'no-manifest'   => 'Ignore any manifest and map the columns from their names, as for a file without one',
    )
);
$script->initialize();

$fail = function ( $message ) use ( $cli, $script )
{
    $cli->error( $message );
    $script->shutdown( 1 );
};

if ( !$options['file'] )
    $fail( 'Missing --file.' );

$login = $options['user'] ? $options['user'] : 'admin';
$user = eZUser::fetchByName( $login );
if ( !$user instanceof eZUser )
    $fail( "No user with login $login (--user)." );
$user->loginCurrent();

XrowExtractImport::cleanupOldUploads();
XrowExtractUpload::cleanupStale();

$path = $options['file'];
if ( $path === '-' )
{
    $tmp = XrowExtractImport::uploadDir() . '/cli_stdin_' . uniqid() . '.dat';
    file_put_contents( $tmp, stream_get_contents( STDIN ) );
    $path = $tmp;
}
elseif ( !is_file( $path ) )
{
    $fail( "No such file: $path (--file)." );
}

// The typed column manifest: an explicit --manifest, or none at all with --no-manifest; otherwise the
// sidecar next to the file or the one embedded in it is found by XrowExtractImport::fileHeader()
if ( $options['no-manifest'] )
    XrowExtractManifest::$disabled = true;
elseif ( $options['manifest'] )
{
    if ( !XrowExtractManifest::readFile( $options['manifest'] ) )
        $fail( "Not a manifest: {$options['manifest']} (--manifest)." );
    XrowExtractManifest::$explicitPath = $options['manifest'];
}
// A zip of one data file and its manifest (the One class view's "Download with manifest"): unpacked
// into the private upload folder, the manifest next to the data file as its sidecar
$unpacked = XrowExtractManifest::unpackZip( $path, XrowExtractImport::uploadDir() . '/cli_zip_' . bin2hex( random_bytes( 6 ) ) . '.dat' );
if ( $unpacked['ok'] )
{
    $cli->output( "Read {$unpacked['name']} from the zip " . basename( $path ) . '.' );
    $path = $unpacked['path'];
}
elseif ( $unpacked['error'] !== '' )
{
    $fail( 'Cannot read the zip ' . basename( $path ) . ': ' . $unpacked['error'] );
}

// --background: queue a job and return at once - bin/php/job.php runs this same script again with
// --run=<id>, which supplies --output/--progress-file/--user itself
if ( $options['background'] )
{
    if ( !XrowExtractJob::available() )
        $fail( 'Background jobs are not available on this server (no PHP CLI binary, or exec()/proc_open() disabled).' );
    // A very large file's dry run is queued too (the view offers "Preview in the background" first,
    // then "Import in the background" once that report has been read) - whichever this invocation was
    // asked for is what the queued run does.
    $args = array( '--file=' . $path );
    if ( $options['apply'] )
        $args[] = '--apply';
    foreach ( array( 'class', 'parent', 'match', 'language', 'map', 'resume-from', 'manifest' ) as $key )
    {
        if ( $options[$key] )
            $args[] = "--$key=" . $options[$key];
    }
    if ( $options['no-manifest'] )
        $args[] = '--no-manifest';
    $jobID = XrowExtractJob::create( array(
        'type' => 'import', 'owner' => $login,
        'what' => $options['what'] ?: ( 'Import: ' . basename( $path ) ),
        'format' => 'json', 'output_file' => 'report.json', 'args' => $args,
    ) );
    XrowExtractJob::start( $jobID );
    $cli->output( "Queued as job $jobID (xrowextract/jobs)." );
    $script->shutdown( 0 );
}

$format = null;
$separator = null;
try
{
    $parsed = XrowExtractImport::parseFile( $path );
}
catch ( Exception $e )
{
    $fail( $e->getMessage() );
}
if ( isset( $parsed['error'] ) )
    $fail( $parsed['error'] );
if ( !$parsed['header'] )
    $fail( 'No columns found in the file.' );
$format = $parsed['format'];
$separator = $parsed['separator'];
$manifestUsed = isset( $parsed['manifest'] ) ? $parsed['manifest'] : null;
if ( $manifestUsed )
{
    $cli->output( sprintf( 'Mapping from the manifest (%s): %d of %d column(s) exact%s%s.', $manifestUsed['source'],
                           count( $manifestUsed['matched'] ), count( $parsed['header'] ),
                           $manifestUsed['unknown'] ? ', not described: ' . implode( ', ', $manifestUsed['unknown'] ) : '',
                           $manifestUsed['checksum'] === 'ok' ? ', checksum ok' : ( $manifestUsed['checksum'] === 'mismatch' ? ', WARNING: the file changed since the export (checksum mismatch)' : '' ) ) );
}

// Class (a fallback; a "class" column - or an XML file's own <export class="..."> - still wins per row)
$classID = 0;
if ( $options['class'] )
{
    $class = ctype_digit( (string)$options['class'] ) ? eZContentClass::fetch( (int)$options['class'] ) : eZContentClass::fetchByIdentifier( $options['class'] );
    if ( !$class instanceof eZContentClass )
        $fail( "No class {$options['class']} (--class)." );
    $classID = (int)$class->attribute( 'id' );
}
elseif ( !empty( $parsed['class'] ) )
{
    $fromFile = eZContentClass::fetchByIdentifier( $parsed['class'] );
    if ( $fromFile instanceof eZContentClass )
        $classID = (int)$fromFile->attribute( 'id' );
}

// Mapping: automatic (using the file's own column ids for XML), then --map overrides by file column name
$mapping = XrowExtractImport::suggestMapping( $parsed['header'], $classID, isset( $parsed['columnIDs'] ) ? $parsed['columnIDs'] : null );
if ( $options['map'] )
{
    $overrides = array();
    foreach ( explode( ',', $options['map'] ) as $pair )
    {
        list( $col, $target ) = array_pad( array_map( 'trim', explode( '=', $pair, 2 ) ), 2, '' );
        if ( $col === '' )
            continue;
        if ( strpos( $target, ':' ) !== false && strpos( $target, 'special:' ) !== 0 && strpos( $target, 'attrfmt:' ) !== 0 && strpos( $target, 'attr:' ) !== 0 )
            $target = 'attrfmt:' . $target;
        elseif ( $target !== '' && $target !== 'ignore' && strpos( $target, ':' ) === false )
            $target = strpos( $target, '.' ) !== false ? 'special:' . $target : 'attr:' . $target;
        $overrides[$col] = $target;
    }
    foreach ( $mapping as $i => $map )
    {
        if ( isset( $overrides[$map['column']] ) )
            $mapping[$i]['target'] = $overrides[$map['column']];
    }
}

$defaultLanguage = $options['language'] ?: null;
if ( !$defaultLanguage )
{
    $languages = XrowExtractColumns::contentLanguages();
    foreach ( $languages as $locale => $lang )
    {
        if ( $lang['default'] )
            $defaultLanguage = $locale;
    }
}

$totalRows = XrowExtractImport::countRows( $path, $format, $separator );
$resumeFrom = max( 1, (int)( $options['resume-from'] ?: 1 ) );
$skipRows = $resumeFrom - 1;

$progressFile = $options['progress-file'];
$reportFile = $options['report'] ?: $options['output'];
$errorsFile = $reportFile ? preg_replace( '/\.[^.\/]+$/', '', $reportFile ) . '.errors.csv' : null;
$errorsHandle = null;
$errorsHeaderWritten = false;
$peakMemoryStart = memory_get_peak_usage( true );

$reportRows = array(); // only filled when there is no --report (small runs; kept for the on-screen summary)
$onRow = function ( $rowResult, $row ) use ( &$errorsHandle, &$errorsHeaderWritten, $errorsFile, $parsed )
{
    if ( $rowResult['action'] !== 'error' || !$errorsFile )
        return;
    if ( !$errorsHandle )
    {
        $errorsHandle = fopen( $errorsFile, 'w' );
        @chmod( $errorsFile, 0600 );
    }
    if ( !$errorsHandle )
        return;
    if ( !$errorsHeaderWritten )
    {
        fputcsv( $errorsHandle, array_merge( $parsed['header'], array( 'import-error' ) ) );
        $errorsHeaderWritten = true;
    }
    $line = array();
    foreach ( $parsed['header'] as $col )
        $line[] = isset( $row[$col] ) ? $row[$col] : '';
    $line[] = $rowResult['reason'];
    fputcsv( $errorsHandle, $line );
};

$onProgress = $progressFile ? function ( $done, $total, $counts ) use ( $progressFile )
{
    $phase = sprintf( 'create %d, update %d, unchanged %d, error %d', $counts['create'], $counts['update'], $counts['unchanged'], $counts['error'] );
    XrowExtractJob::writeProgress( $progressFile, $done, $total, $phase );
} : null;

$rows = XrowExtractImport::streamRows( $path, $format, $separator );
$result = XrowExtractImport::run( array(
    'rows'         => $rows,
    'mapping'      => $mapping,
    'classID'      => $classID,
    'match'        => $options['match'] ?: 'remote_id',
    'language'     => $defaultLanguage,
    'parentNodeID' => $options['parent'] ? (int)$options['parent'] : 0,
    'apply'        => (bool)$options['apply'],
    'skipRows'     => $skipRows,
    'startNumber'  => $resumeFrom,
    'totalRows'    => $totalRows,
    'onProgress'   => $onProgress,
    'progressEvery'=> 200,
    'collectRows'  => !$reportFile, // a report file is written incrementally (via onRow) instead of accumulated in memory
    'onRow'        => $onRow,
) );
if ( $errorsHandle )
    fclose( $errorsHandle );

$peakMemory = memory_get_peak_usage( true );

$cli->output( sprintf( '%s: %d row(s) (of %d total)%s, %s (%s), peak memory %s',
                       $options['apply'] ? 'Applied' : 'Dry run', $totalRows - $skipRows, $totalRows,
                       $skipRows ? " resumed from row $resumeFrom" : '', $options['file'], $format,
                       XrowExtractUpload::humanSize( $peakMemory ) ) );
foreach ( $result['counts'] as $action => $count )
    $cli->output( sprintf( '  %-10s %d', $action, $count ) );
if ( $result['ezoe'] === false )
    $cli->output( 'Note: the ezoe extension is not active; rich text columns were imported as plain paragraphs.' );

foreach ( $result['rows'] as $row )
{
    if ( $row['action'] === 'error' )
        $cli->output( sprintf( '  #%-5d ERROR  %s', $row['number'], $row['reason'] ) );
    elseif ( $row['action'] !== 'unchanged' && $row['changes'] )
    {
        $cli->output( sprintf( '  #%-5d %-8s object %s', $row['number'], strtoupper( $row['action'] ), $row['object_id'] ?: '(new)' ) );
        foreach ( $row['changes'] as $change )
            $cli->output( sprintf( '           %-24s %s -> %s', $change['field'], mb_substr( $change['old'], 0, 40 ), mb_substr( $change['new'], 0, 40 ) ) );
    }
}

if ( $errorsHeaderWritten )
    $cli->output( "Error rows written to $errorsFile (fix and re-import just that file)." );

if ( $reportFile )
{
    $report = array(
        'counts' => $result['counts'], 'total_rows' => $totalRows, 'processed_rows' => $totalRows - $skipRows,
        'resumed_from' => $skipRows ? $resumeFrom : null, 'ezoe' => $result['ezoe'],
        'peak_memory_bytes' => $peakMemory, 'errors_file' => $errorsHeaderWritten ? basename( $errorsFile ) : null,
        'applied' => (bool)$options['apply'], 'file' => basename( $options['file'] ), 'format' => $format,
        'manifest' => $manifestUsed ? array( 'source' => $manifestUsed['source'], 'exact_columns' => count( $manifestUsed['matched'] ),
                                             'not_described' => $manifestUsed['unknown'], 'checksum' => $manifestUsed['checksum'] ) : null,
        'mapping' => array_map( function ( $m ) { return array( 'column' => $m['column'], 'target' => $m['target'] ); }, $mapping ),
    );
    file_put_contents( $reportFile, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    @chmod( $reportFile, 0600 );
    $cli->output( 'Report written to ' . $reportFile );
}

// Exit 0 whenever the run itself completed, whether or not some rows had errors: a background job
// with a few bad rows is a completed job with a report to read, not a failed one (job.php would mark
// a non-zero exit "failed" and never look at report.json). --file/--class problems above still exit 1.
$script->shutdown( 0 );
