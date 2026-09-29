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
$exportScript = XrowExtractJob::scriptFor( $job['type'] );

// csv jobs know their exact output file name already; archive jobs get a directory and pick up
// whatever build() names the archive (the name carries a timestamp decided at run time)
$outputArg = $job['type'] === 'archive' ? $dir : $dir . '/' . $job['output_file'];

$argv = array_map( 'strval', (array)$job['args'] );
$argv[] = '--output=' . $outputArg;
$argv[] = '--progress-file=' . $progressPath;
$argv[] = '--user=' . $job['owner'];
// eZScript refuses to run as root without this flag; this process is root exactly when the child
// it is about to start would be too (Velocity, or a root command line)
if ( XrowExtractJob::runningAsRoot() )
    $argv[] = '--allow-root-user';

$commandParts = array( escapeshellarg( $phpCli ), escapeshellarg( $exportScript ) );
foreach ( $argv as $arg )
    $commandParts[] = escapeshellarg( $arg );
$command = implode( ' ', $commandParts );

$logHandle = @fopen( $logPath, 'a' );
if ( $logHandle )
{
    fwrite( $logHandle, '[' . date( 'c' ) . "] $command\n" );
    fclose( $logHandle );
    @chmod( $logPath, 0600 );
    XrowExtractJob::fixOwnership( $logPath );
}

$descriptors = array(
    0 => array( 'file', '/dev/null', 'r' ),
    1 => array( 'file', $logPath, 'a' ),
    2 => array( 'file', $logPath, 'a' ),
);
$process = @proc_open( $command, $descriptors, $pipes );
$exitCode = 1;
if ( is_resource( $process ) )
    $exitCode = proc_close( $process );

XrowExtractJob::fixOwnership( $logPath );

$log = is_file( $logPath ) ? (string)@file_get_contents( $logPath ) : '';
// eZCLI colours error() output with ANSI escapes even when not run at a terminal; strip them so the
// error text shown on the Jobs page is plain
$log = preg_replace( '/\x1b\[[0-9;]*m/', '', $log );
$job = XrowExtractJob::load( $id );
if ( !$job )
    $script->shutdown( $exitCode === 0 ? 0 : 1 );
$job['ended'] = time();

if ( $job['type'] === 'archive' )
{
    // The archive's own name (a timestamp decided inside build()): whatever new file build() put
    // in the job folder that is not one of ours
    $known = array( XrowExtractJob::JOB_FILE, XrowExtractJob::LOG_FILE, XrowExtractJob::PROGRESS_FILE );
    $found = null;
    foreach ( (array)@scandir( $dir ) as $entry )
    {
        if ( $entry === '.' || $entry === '..' || in_array( $entry, $known, true ) || substr( $entry, -4 ) === '.tmp' )
            continue;
        if ( is_file( $dir . '/' . $entry ) )
            $found = $entry;
    }
    if ( $found !== null )
        $job['output_file'] = $found;
}

$outputPath = $job['output_file'] ? $dir . '/' . $job['output_file'] : null;
// A package install can finish with some items rejected and still exit 1 (package.php's own 'ok'
// covers every item, continue-on-error is always on) - exactly the "a few bad rows" case
// bin/php/import.php already treats as a completed job with a report to read, not a failed one (see
// its own comment), so a package job's report is read whenever it exists, not only on exit 0.
if ( ( $exitCode === 0 || $job['type'] === 'package' ) && $outputPath && is_file( $outputPath ) )
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
XrowExtractJob::save( $id, $job );

$cli->output( sprintf( 'Job %s: %s%s', $id, $job['state'], $job['state'] === 'failed' ? ' (' . $job['error'] . ')' : '' ) );
$script->shutdown( $exitCode === 0 ? 0 : 1 );
