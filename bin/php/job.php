#!/usr/bin/env php
<?php
/**
 * Runs, lists or cleans up background export jobs (xrowextract/csv and
 * xrowextract/archive, started from the "Run in the background" button).
 * A web request never waits on this: it launches
 *   php extension/xrowextract/bin/php/job.php --run=<id>
 * detached and returns at once; this script carries the export out and
 * updates the job's job.json as it goes.
 *
 * Usage (from the installation root; ./console ext:xrowextract:job runs it too):
 *   php extension/xrowextract/bin/php/job.php --run=3f9c...
 *   php extension/xrowextract/bin/php/job.php --list
 *   php extension/xrowextract/bin/php/job.php --clean
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array(
    'description'    => "Runs, lists or cleans up xrowextract background export jobs.",
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
) );
$script->startup();
$options = $script->getOptions(
    '[run:][list;][clean;]',
    '',
    array(
        'run'   => 'Carry out one queued job (its id, from the Jobs page or --list)',
        'list'  => 'List every job: id, type, state, owner, created, what',
        'clean' => 'Remove job folders older than csv.ini [Jobs] RetentionDays (default 7)',
    )
);
$script->initialize();

$fail = function ( $message ) use ( $cli, $script )
{
    $cli->error( $message );
    $script->shutdown( 1 );
};

if ( $options['clean'] )
{
    $removed = XrowExtractJob::clean();
    $cli->output( sprintf( 'Removed %d job folder(s) older than %d day(s).', $removed, XrowExtractJob::retentionDays() ) );
    $script->shutdown( 0 );
}

if ( $options['list'] )
{
    $jobs = XrowExtractJob::listAll();
    if ( !$jobs )
        $cli->output( 'No jobs.' );
    foreach ( $jobs as $job )
    {
        $cli->output( sprintf( '  %-32s %-8s %-8s %-12s %-20s %s', $job['id'], $job['type'], $job['state'], $job['owner'],
                               date( 'Y-m-d H:i:s', $job['created'] ), $job['what'] ) );
    }
    $script->shutdown( 0 );
}

if ( !$options['run'] )
    $fail( 'Nothing to do: --run=<id>, --list or --clean.' );

$id = (string)$options['run'];
if ( !XrowExtractJob::isValidID( $id ) )
    $fail( "Not a job id: $id." );
$job = XrowExtractJob::load( $id );
if ( !$job )
    $fail( "No job $id." );
if ( $job['state'] !== 'queued' )
    $fail( "Job $id is already {$job['state']}." );

$dir = XrowExtractJob::path( $id );
$logPath = $dir . '/' . XrowExtractJob::LOG_FILE;
$progressPath = $dir . '/' . XrowExtractJob::PROGRESS_FILE;

$job['state'] = 'running';
$job['started'] = time();
$job['pid'] = getmypid();
XrowExtractJob::save( $id, $job );

$phpCli = XrowExtractJob::phpCliBinary();
if ( !$phpCli )
    $fail( 'No PHP command line binary found (csv.ini [Jobs] PhpCli).' );

$logHandle = @fopen( $logPath, 'a' );
if ( $logHandle )
{
    fclose( $logHandle );
    @chmod( $logPath, 0600 );
    XrowExtractJob::fixOwnership( $logPath );
}

// One export/import script run as a child: an argument array for proc_open() (no shell), its output
// appended to job.log. --user and, when this process is root, --allow-root-user are added (eZScript
// refuses to run as root without it; this process is root exactly when the child would be too).
$runScript = function ( $type, array $args ) use ( $phpCli, $logPath, $job )
{
    $argv = array_merge( array( $phpCli, XrowExtractJob::scriptFor( $type ) ), array_map( 'strval', $args ) );
    $argv[] = '--user=' . $job['owner'];
    if ( XrowExtractJob::runningAsRoot() )
        $argv[] = '--allow-root-user';
    $logHandle = @fopen( $logPath, 'a' );
    if ( $logHandle )
    {
        fwrite( $logHandle, '[' . date( 'c' ) . '] ' . implode( ' ', array_map( 'escapeshellarg', $argv ) ) . "\n" );
        fclose( $logHandle );
    }
    $descriptors = array(
        0 => array( 'file', '/dev/null', 'r' ),
        1 => array( 'file', $logPath, 'a' ),
        2 => array( 'file', $logPath, 'a' ),
    );
    $process = @proc_open( $argv, $descriptors, $pipes, eZSys::rootDir() );
    $code = is_resource( $process ) ? proc_close( $process ) : 1;
    XrowExtractJob::fixOwnership( $logPath );
    return $code;
};

// What the log says: ANSI colours stripped (eZCLI colours error() output even when not run at a
// terminal), and every "WARNING: " line the scripts wrote with --lenient collected for the history
$readLog = function () use ( $logPath )
{
    $log = is_file( $logPath ) ? (string)@file_get_contents( $logPath ) : '';
    return preg_replace( '/\x1b\[[0-9;]*m/', '', $log );
};
$logWarnings = function ( $log )
{
    $found = array();
    if ( preg_match_all( '/^WARNING: (.+)$/m', $log, $m ) )
        $found = array_map( 'trim', $m[1] );
    return array_values( array_unique( $found ) );
};

if ( $job['type'] === 'import_schedule' )
{
    // A scheduled import: fetch, dry run, and apply only when the dry run found no errors
    $patch = XrowExtractScheduler::runScheduledImport( $id, $runScript );
    $job = XrowExtractJob::load( $id );
    $job = array_merge( $job, $patch );
    $job['ended'] = time();
    $job['warnings'] = array_values( array_unique( array_merge( isset( $patch['warnings'] ) ? $patch['warnings'] : array(), $logWarnings( $readLog() ) ) ) );
    if ( !empty( $job['output_file'] ) && is_file( $dir . '/' . $job['output_file'] ) )
        $job['size'] = filesize( $dir . '/' . $job['output_file'] );
    XrowExtractJob::save( $id, $job );
    XrowExtractScheduler::afterJob( $id );
    $job = XrowExtractJob::load( $id );
    $cli->output( sprintf( 'Job %s: %s%s', $id, $job['state'], $job['error'] ? ' (' . $job['error'] . ')' : '' ) );
    $script->shutdown( $job['state'] === 'failed' ? 1 : 0 );
}

// csv jobs know their exact output file name already; archive jobs get a directory and pick up
// whatever build() names the archive (the name carries a timestamp decided at run time)
$outputArg = $job['type'] === 'archive' ? $dir : $dir . '/' . $job['output_file'];
$args = array_map( 'strval', (array)$job['args'] );
$args[] = '--output=' . $outputArg;
$args[] = '--progress-file=' . $progressPath;
$exitCode = $runScript( $job['type'], $args );

$log = $readLog();
$job = XrowExtractJob::load( $id );
if ( !$job )
    $script->shutdown( $exitCode === 0 ? 0 : 1 );
$job['ended'] = time();
$job['warnings'] = array_values( array_unique( array_merge( isset( $job['warnings'] ) ? (array)$job['warnings'] : array(), $logWarnings( $log ) ) ) );

if ( $job['type'] === 'archive' )
{
    // The archive's own name (a timestamp decided inside build()): whatever new file build() put
    // in the job folder that is not one of ours (nor the archive's manifest next to it)
    $known = array( XrowExtractJob::JOB_FILE, XrowExtractJob::LOG_FILE, XrowExtractJob::PROGRESS_FILE );
    $found = null;
    foreach ( (array)@scandir( $dir ) as $entry )
    {
        if ( $entry === '.' || $entry === '..' || in_array( $entry, $known, true ) || substr( $entry, -4 ) === '.tmp'
             || substr( $entry, -strlen( XrowExtractManifest::SIDECAR_SUFFIX ) ) === XrowExtractManifest::SIDECAR_SUFFIX )
            continue;
        if ( is_file( $dir . '/' . $entry ) )
            $found = $entry;
    }
    if ( $found !== null )
        $job['output_file'] = $found;
}

$outputPath = $job['output_file'] ? $dir . '/' . $job['output_file'] : null;
if ( $outputPath && is_file( XrowExtractManifest::sidecarPath( $outputPath ) ) )
{
    XrowExtractJob::fixOwnership( XrowExtractManifest::sidecarPath( $outputPath ) );
    $job['has_manifest'] = true;
}
if ( $exitCode === 3 )
{
    // --lenient found nothing left to export (a deleted node, class or preset): skipped, not failed
    $job['state'] = 'skipped';
    $job['error'] = $job['warnings'] ? end( $job['warnings'] ) : 'Nothing left to export.';
}
// A package install can finish with some items rejected and still exit 1 (package.php's own 'ok'
// covers every item, continue-on-error is always on) - exactly the "a few bad rows" case
// bin/php/import.php already treats as a completed job with a report to read, not a failed one (see
// its own comment), so a package job's report is read whenever it exists, not only on exit 0.
elseif ( ( $exitCode === 0 || $job['type'] === 'package' ) && $outputPath && is_file( $outputPath ) )
{
    XrowExtractJob::fixOwnership( $outputPath );
    $job['state'] = 'done';
    $job['size'] = filesize( $outputPath );
    if ( $job['type'] === 'import' )
    {
        // The report bin/php/import.php just wrote is exact; the log line ("N row(s) (of ...)") is not
        // in the plain "N rows" shape the generic regex below expects
        $report = json_decode( (string)@file_get_contents( $outputPath ), true );
        if ( is_array( $report ) )
        {
            $job['rows'] = isset( $report['processed_rows'] ) ? (int)$report['processed_rows'] : null;
            $job['counts'] = isset( $report['counts'] ) ? $report['counts'] : null;
            if ( !empty( $report['errors_file'] ) && is_file( $dir . '/' . $report['errors_file'] ) )
            {
                XrowExtractJob::fixOwnership( $dir . '/' . $report['errors_file'] );
                $job['has_errors_file'] = true;
            }
        }
    }
    elseif ( $job['type'] === 'package' )
    {
        // bin/php/package.php --install writes install-report.json: 'counts' is the dry run's own
        // create/update/unchanged/class-missing split, taken just before installing (install()'s own
        // 'report' only ever lists what it touched as "created classes"/"created objects" - it looks
        // each one up again afterwards by remote id, with no notion of whether that particular one was
        // new or already there). The Jobs page reads both: counts for the summary line, 'report' for
        // the per-class/per-object links (a class id, or an object's node id when it has one).
        $report = json_decode( (string)@file_get_contents( $outputPath ), true );
        if ( is_array( $report ) )
        {
            $job['counts'] = isset( $report['counts'] ) ? $report['counts'] : null;
            $job['package_name'] = isset( $report['package_name'] ) ? $report['package_name'] : null;
            $job['created_classes'] = isset( $report['report']['created_classes'] ) ? $report['report']['created_classes'] : array();
            $job['created_objects'] = isset( $report['report']['created_objects'] ) ? $report['report']['created_objects'] : array();
            if ( !empty( $report['report']['errors'] ) )
                $job['install_errors'] = $report['report']['errors'];
            // A partial failure (install_errors set, report.ok false) is still shown as 'done': the
            // items that did install (counts, created_classes/created_objects) are real and worth
            // reading, exactly as a few bad import rows do not fail the whole import job.
        }
    }
    elseif ( preg_match( '/(\d+)\s+rows\b/', $log, $m ) )
    {
        $job['rows'] = (int)$m[1];
    }
}
else
{
    $job['state'] = 'failed';
    $lines = array_values( array_filter( array_map( 'trim', explode( "\n", $log ) ), function ( $line ) { return $line !== ''; } ) );
    $job['error'] = $lines ? end( $lines ) : ( 'The export failed (exit code ' . $exitCode . ').' );
}
// Cancelled from the Jobs page while it ran (XrowExtractJob::cancel()): keep that state and its message,
// do not replace it with the failure the stopped script leaves behind
$current = XrowExtractJob::load( $id );
if ( $current && !empty( $current['cancelled'] ) )
{
    $cli->output( sprintf( 'Job %s: cancelled', $id ) );
    XrowExtractScheduler::afterJob( $id ); // the history row (recorded as failed, with the cancel message)
    $script->shutdown( 1 );
}
XrowExtractJob::save( $id, $job );

// The history row; for a scheduled job also the delivery and the notifications
XrowExtractScheduler::afterJob( $id );

$cli->output( sprintf( 'Job %s: %s%s', $id, $job['state'], in_array( $job['state'], array( 'failed', 'skipped' ), true ) ? ' (' . $job['error'] . ')' : '' ) );
$script->shutdown( $exitCode === 0 || $exitCode === 3 ? 0 : 1 );
