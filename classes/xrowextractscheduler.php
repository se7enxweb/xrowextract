<?php

/**
 * Runs schedules: finds the due ones (the cronjob part, cronjobs/xrowextract.php, or bin/php/schedule.php
 * --cron), turns each into an ordinary background job (XrowExtractJob, run by bin/php/job.php exactly as
 * "Run in the background" is), and, when a job ends (afterJob(), called by bin/php/job.php), records it in
 * the history, delivers its file to the schedule's destinations and sends the notifications.
 *
 * Every run - scheduled or not - gets a history row; only scheduled ones are delivered and notified.
 *
 * Degradation: what a schedule refers to and that no longer exists (a node, a class, an attribute of a
 * preset) is skipped with a WARNING; the export scripts run with --lenient for scheduled jobs and end
 * with exit code 3 ("skipped") only when nothing at all is left to export.
 */
class XrowExtractScheduler
{
    /** Checks a definition before it is saved. Returns a list of error messages. */
    public static function validateDefinition( $kind, array $def )
    {
        $errors = array();
        switch ( $kind )
        {
            case 'preset':
                if ( empty( $def['preset'] ) || !XrowExtractPreset::fetch( $def['preset'] ) )
                {
                    $errors[] = 'Choose a saved preset.';
                    break;
                }
                // A preset that leaves the class to the view (e.g. "Hidden content") needs one here
                $resolved = XrowExtractPreset::resolve( $def['preset'], 0, isset( $def['params'] ) && is_array( $def['params'] ) ? $def['params'] : array() );
                $filled = XrowExtractPreset::fillPlaceholders( $resolved['definition'], $resolved['placeholders'], isset( $def['params'] ) && is_array( $def['params'] ) ? $def['params'] : array() );
                if ( empty( $filled['definition']['class_identifier'] ) && empty( $filled['definition']['class_id'] ) && empty( $def['class'] ) )
                    $errors[] = 'This preset does not name a class: choose the class to export.';
                break;
            case 'archive':
                if ( empty( $def['nodes'] ) && empty( $def['set'] ) )
                    $errors[] = 'Choose a node set or node ids for the site archive.';
                if ( !empty( $def['format'] ) && !array_key_exists( $def['format'], XrowExtractArchive::formats() ) )
                    $errors[] = 'Unknown archive format.';
                if ( !empty( $def['files'] ) && !XrowExtractWriter::isFormat( $def['files'] ) )
                    $errors[] = 'Unknown file format.';
                break;
            case 'package':
                if ( empty( $def['node'] ) || (int)$def['node'] <= 0 )
                    $errors[] = 'Choose the node to export as a package.';
                break;
            case 'import':
                if ( ( isset( $def['source'] ) ? $def['source'] : 'local' ) === 'destination' )
                {
                    if ( empty( $def['destination_id'] ) || !XrowExtractDestination::fetch( $def['destination_id'] ) )
                        $errors[] = 'Choose the destination to read the file from.';
                    if ( empty( $def['remote_path'] ) )
                        $errors[] = 'Enter the path of the file at the destination.';
                }
                else
                {
                    $check = XrowExtractTransportLocal::checkPath( isset( $def['local_path'] ) ? $def['local_path'] : '', true );
                    if ( !$check['ok'] )
                        $errors[] = $check['message'];
                }
                break;
        }
        return $errors;
    }

    /** The configured default: files are kept as long as csv.ini [Jobs] RetentionDays says. */
    public static function fileRetentionDays( XrowExtractSchedule $schedule = null )
    {
        if ( $schedule )
        {
            $retention = $schedule->retentionArray();
            if ( !empty( $retention['files_days'] ) )
                return (int)$retention['files_days'];
        }
        return XrowExtractJob::retentionDays();
    }

    /** "YYYY-MM-DD_HHMMSS" plus the schedule name as a file name stem. */
    protected static function stem( XrowExtractSchedule $schedule )
    {
        return XrowExtractColumns::fileName( $schedule->attribute( 'name' ), '', 'schedule_' . (int)$schedule->attribute( 'id' ) ) . '_' . date( 'Y-m-d_His' );
    }

    /**
     * The XrowExtractJob data for one run of $schedule, or array( 'skip' => reason ) when nothing can run
     * (a deleted preset or node). $runMode: full or delta; $since: the delta start time.
     */
    public static function jobSpec( XrowExtractSchedule $schedule, $runMode, $since, $trigger )
    {
        $def = $schedule->definitionArray();
        $warnings = array();
        $common = array( '--lenient', '--schedule=' . (int)$schedule->attribute( 'id' ), '--run-mode=' . $runMode );
        if ( $runMode === 'delta' && $since )
            $common[] = '--changed-since=' . (int)$since;
        if ( !empty( $def['siteaccess'] ) && preg_match( '/^[A-Za-z0-9_-]+$/', $def['siteaccess'] ) )
            array_unshift( $common, '--siteaccess=' . $def['siteaccess'] );
        $spec = array(
            'owner' => $schedule->attribute( 'owner_login' ),
            'schedule_id' => (int)$schedule->attribute( 'id' ),
            'schedule_name' => $schedule->attribute( 'name' ),
            'run_mode' => $runMode,
            'since' => $since ? (int)$since : null,
            'trigger' => $trigger,
            'warnings' => array(),
        );
        switch ( $schedule->attribute( 'kind' ) )
        {
            case 'preset':
                $ref = isset( $def['preset'] ) ? (string)$def['preset'] : '';
                $preset = XrowExtractPreset::fetch( $ref );
                if ( !$preset )
                    return array( 'skip' => "The preset $ref no longer exists." );
                $params = isset( $def['params'] ) && is_array( $def['params'] ) ? $def['params'] : array();
                $resolved = XrowExtractPreset::resolve( $ref, 0, $params );
                $format = isset( $resolved['definition']['output_format'] ) && XrowExtractWriter::isFormat( $resolved['definition']['output_format'] )
                        ? $resolved['definition']['output_format'] : 'csv';
                $args = array_merge( $common, array( '--preset=' . $ref ) );
                if ( !empty( $def['class'] ) )
                    $args[] = '--class=' . $def['class'];
                foreach ( $params as $key => $value )
                {
                    if ( preg_match( '/^[A-Za-z0-9_]+$/', (string)$key ) )
                        $args[] = '--param=' . $key . '=' . str_replace( ',', ' ', (string)$value );
                }
                $formats = XrowExtractWriter::formats();
                return $spec + array(
                    'type' => 'csv', 'what' => 'Schedule "' . $schedule->attribute( 'name' ) . '": preset ' . $preset['name'],
                    'format' => $format, 'output_file' => self::stem( $schedule ) . '.' . $formats[$format]['extension'],
                    'args' => $args, 'preset' => $ref,
                );
            case 'archive':
                $args = $common;
                if ( !empty( $def['nodes'] ) )
                    $args[] = '--nodes=' . implode( ',', array_map( 'intval', (array)$def['nodes'] ) );
                else
                    $args[] = '--set=' . ( isset( $def['set'] ) ? $def['set'] : 'sites' );
                foreach ( array( 'classes' => 'classes', 'exclude_classes' => 'exclude-classes' ) as $key => $option )
                {
                    if ( !empty( $def[$key] ) )
                        $args[] = '--' . $option . '=' . implode( ',', array_map( 'trim', (array)$def[$key] ) );
                }
                if ( !empty( $def['languages'] ) && $def['languages'] !== 'all' )
                    $args[] = '--languages=' . implode( ',', (array)$def['languages'] );
                foreach ( array( 'columns', 'files', 'format', 'separator', 'line_endings' => 'line-endings', 'section', 'visibility' ) as $key => $option )
                {
                    if ( is_int( $key ) )
                        $key = $option;
                    if ( !empty( $def[$key] ) )
                        $args[] = '--' . $option . '=' . $def[$key];
                }
                if ( !empty( $def['plain_text'] ) )
                    $args[] = '--plain-text';
                $format = !empty( $def['format'] ) ? $def['format'] : 'zip';
                return $spec + array(
                    'type' => 'archive', 'what' => 'Schedule "' . $schedule->attribute( 'name' ) . '": ' . $schedule->summary(),
                    'format' => $format, 'output_file' => null, 'args' => $args,
                );
            case 'package':
                // package.php knows nothing of --lenient: what no longer resolves is settled here
                $nodeID = isset( $def['node'] ) ? (int)$def['node'] : 0;
                $node = $nodeID ? eZContentObjectTreeNode::fetch( $nodeID ) : null;
                if ( !$node instanceof eZContentObjectTreeNode )
                    return array( 'skip' => "Node $nodeID no longer exists." );
                $args = array( '--export', '--node=' . $nodeID );
                if ( !empty( $def['siteaccess'] ) && preg_match( '/^[A-Za-z0-9_-]+$/', $def['siteaccess'] ) )
                    array_unshift( $args, '--siteaccess=' . $def['siteaccess'] );
                if ( !empty( $def['subtree'] ) )
                    $args[] = '--subtree';
                if ( !empty( $def['class'] ) )
                {
                    $class = ctype_digit( (string)$def['class'] ) ? eZContentClass::fetch( (int)$def['class'] ) : eZContentClass::fetchByIdentifier( $def['class'] );
                    if ( !$class instanceof eZContentClass )
                        return array( 'skip' => "The class {$def['class']} no longer exists." );
                    $args[] = '--class=' . (int)$class->attribute( 'id' );
                }
                if ( $runMode === 'delta' )
                    $warnings[] = 'A package is always exported in full: delta runs are not available for packages.';
                return array_merge( $spec, array(
                    'type' => 'package', 'what' => 'Schedule "' . $schedule->attribute( 'name' ) . '": ' . $schedule->summary(),
                    'format' => 'ezpkg', 'output_file' => self::stem( $schedule ) . '.ezpkg', 'args' => $args,
                    'run_mode' => 'full', 'warnings' => $warnings,
                ) );
            case 'import':
                return array_merge( $spec, array(
                    'type' => 'import_schedule', 'what' => 'Schedule "' . $schedule->attribute( 'name' ) . '": ' . $schedule->summary(),
                    'format' => 'json', 'output_file' => 'report.json', 'args' => array(), 'import' => $def, 'run_mode' => 'full',
                ) );
        }
        return array( 'skip' => 'Unknown schedule kind ' . $schedule->attribute( 'kind' ) . '.' );
    }

    /** Whether the schedule's last job is still queued or running (the concurrency guard). */
    public static function isRunning( XrowExtractSchedule $schedule )
    {
        $jobID = (string)$schedule->attribute( 'last_job_id' );
        if ( $jobID === '' || !XrowExtractJob::isValidID( $jobID ) )
            return false;
        $job = XrowExtractJob::load( $jobID );
        if ( !$job || !in_array( $job['state'], array( 'queued', 'running' ), true ) )
            return false;
        // A job that has been "running" far longer than any export takes, with no live process: stale
        $pid = isset( $job['pid'] ) ? (int)$job['pid'] : 0;
        if ( $pid > 0 && function_exists( 'posix_kill' ) && !@posix_kill( $pid, 0 ) && time() - (int)$job['created'] > 600 )
            return false;
        return true;
    }

    /**
     * Starts one run. $mode: null (the schedule's own delta_mode), 'full' or 'delta'. $wait: run the job
     * in this process (the command line's --wait) instead of detached. Returns array( 'ok', 'job_id',
     * 'message', 'state' ).
     */
    public static function start( XrowExtractSchedule $schedule, $mode = null, $trigger = 'schedule', $wait = false )
    {
        $now = time();
        $mode = $mode === 'full' || $mode === 'delta' ? $mode : $schedule->attribute( 'delta_mode' );
        if ( self::isRunning( $schedule ) )
            return array( 'ok' => false, 'job_id' => $schedule->attribute( 'last_job_id' ), 'state' => 'running',
                          'message' => 'The previous run of this schedule is still going; not started twice.' );
        $since = null;
        $notes = array();
        if ( $mode === 'delta' )
        {
            $since = (int)$schedule->attribute( 'last_success' );
            if ( !$since )
            {
                $mode = 'full';
                $notes[] = 'No successful run yet: this first run exports everything (full).';
            }
        }
        $spec = self::jobSpec( $schedule, $mode, $since, $trigger );
        // Advance the schedule before the job even starts: a slow job never makes the next tick start it again
        $schedule->setAttribute( 'last_run', $now );
        $schedule->setAttribute( 'next_run', (int)XrowExtractCron::nextRun( $schedule->attribute( 'cron_expr' ), $now ) );
        if ( isset( $spec['skip'] ) )
        {
            $schedule->setAttribute( 'last_state', 'skipped' );
            $schedule->setAttribute( 'last_job_id', '' );
            $schedule->store();
            $run = array( 'schedule_id' => (int)$schedule->attribute( 'id' ), 'owner_login' => $schedule->attribute( 'owner_login' ),
                          'kind' => $schedule->attribute( 'kind' ), 'what' => 'Schedule "' . $schedule->attribute( 'name' ) . '"',
                          'trigger_type' => $trigger, 'run_mode' => $mode, 'started_at' => $now, 'ended_at' => $now, 'run_state' => 'skipped',
                          'warnings' => array_merge( $notes, array( $spec['skip'] ) ), 'error_text' => $spec['skip'],
                          'preset_ref' => isset( $schedule->definitionArray()['preset'] ) ? (string)$schedule->definitionArray()['preset'] : '' );
            XrowExtractHistory::record( $run );
            XrowExtractNotifier::notify( $schedule, $run );
            return array( 'ok' => false, 'job_id' => '', 'state' => 'skipped', 'message' => $spec['skip'] );
        }
        $spec['warnings'] = array_merge( $notes, isset( $spec['warnings'] ) ? $spec['warnings'] : array() );
        $jobID = XrowExtractJob::create( $spec );
        $schedule->setAttribute( 'last_job_id', $jobID );
        $schedule->setAttribute( 'last_state', 'queued' );
        $schedule->store();
        if ( $wait )
        {
            $code = self::runJobHere( $jobID );
            $job = XrowExtractJob::load( $jobID );
            return array( 'ok' => $job && in_array( $job['state'], array( 'done', 'skipped' ), true ), 'job_id' => $jobID,
                          'state' => $job ? $job['state'] : 'failed', 'message' => $job && $job['error'] ? $job['error'] : 'exit code ' . $code );
        }
        if ( !XrowExtractJob::start( $jobID ) )
        {
            XrowExtractJob::update( $jobID, array( 'state' => 'failed', 'ended' => time(), 'error' => 'Could not start the background process.' ) );
            self::afterJob( $jobID );
            return array( 'ok' => false, 'job_id' => $jobID, 'state' => 'failed', 'message' => 'Could not start the background process.' );
        }
        return array( 'ok' => true, 'job_id' => $jobID, 'state' => 'queued', 'message' => 'Started as job ' . $jobID . '.' );
    }

    /** bin/php/job.php --run=<id> in the foreground (the CLI's --wait). Returns its exit code. */
    public static function runJobHere( $jobID )
    {
        $php = XrowExtractJob::phpCliBinary();
        if ( !$php )
            return 127;
        $argv = array( $php, XrowExtractJob::runnerScript(), '--run=' . $jobID );
        if ( XrowExtractJob::runningAsRoot() )
            $argv[] = '--allow-root-user';
        $log = XrowExtractJob::path( $jobID ) . '/' . XrowExtractJob::LOG_FILE;
        $process = @proc_open( $argv, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $log, 'a' ), 2 => array( 'file', $log, 'a' ) ), $pipes, eZSys::rootDir() );
        return is_resource( $process ) ? proc_close( $process ) : 127;
    }

    /**
     * The cronjob part: every due schedule started, then the retention clean-up. $log: a callable getting
     * one line per action. Returns array( 'started' => n, 'skipped' => n, 'busy' => n, 'cleaned_jobs' => n,
     * 'cleaned_history' => n ).
     */
    public static function runDue( $now = null, $log = null, $wait = false )
    {
        $now = $now === null ? time() : $now;
        $say = is_callable( $log ) ? $log : function () {};
        $stats = array( 'started' => 0, 'skipped' => 0, 'busy' => 0, 'cleaned_jobs' => 0, 'cleaned_history' => 0 );
        foreach ( XrowExtractSchedule::fetchDue( $now ) as $schedule )
        {
            $result = self::start( $schedule, null, 'schedule', $wait );
            $label = '#' . $schedule->attribute( 'id' ) . ' ' . $schedule->attribute( 'name' );
            if ( $result['ok'] || $result['state'] === 'failed' && $result['job_id'] )
            {
                $stats['started']++;
                $say( "started $label: job {$result['job_id']} ({$result['state']})" );
            }
            elseif ( $result['state'] === 'running' )
            {
                $stats['busy']++;
                $say( "not started $label: {$result['message']}" );
            }
            else
            {
                $stats['skipped']++;
                $say( "skipped $label: {$result['message']}" );
            }
        }
        $stats['cleaned_jobs'] = self::cleanJobs( $now );
        $stats['cleaned_history'] = XrowExtractHistory::clean( $now );
        if ( $stats['cleaned_jobs'] || $stats['cleaned_history'] )
            $say( "cleaned {$stats['cleaned_jobs']} job folder(s), {$stats['cleaned_history']} history row(s)" );
        return $stats;
    }

    /** Job folders past their retention: a schedule's own files_days, else csv.ini [Jobs] RetentionDays. */
    public static function cleanJobs( $now = null )
    {
        $now = $now === null ? time() : $now;
        $schedules = array();
        foreach ( XrowExtractSchedule::fetchList() as $schedule )
            $schedules[(int)$schedule->attribute( 'id' )] = $schedule;
        $removed = 0;
        foreach ( XrowExtractJob::listAll() as $job )
        {
            if ( in_array( $job['state'], array( 'queued', 'running' ), true ) )
                continue;
            $scheduleID = isset( $job['schedule_id'] ) ? (int)$job['schedule_id'] : 0;
            $days = self::fileRetentionDays( isset( $schedules[$scheduleID] ) ? $schedules[$scheduleID] : null );
            $when = !empty( $job['ended'] ) ? $job['ended'] : $job['created'];
            if ( $when < $now - $days * 86400 )
            {
                XrowExtractJob::delete( $job['id'] );
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * A scheduled import (job type import_schedule), called by bin/php/job.php: the file is fetched
     * (a local path below [Destinations] LocalPathRoots[], or a destination), a dry run reports what would
     * change, and only when it found no errors is the import applied. Both reports stay in the job
     * folder (dry-run.json, report.json) and in job.json. Returns the job patch.
     */
    public static function runScheduledImport( $jobID, $runScript )
    {
        $job = XrowExtractJob::load( $jobID );
        $dir = XrowExtractJob::path( $jobID );
        $def = isset( $job['import'] ) ? $job['import'] : array();
        $warnings = isset( $job['warnings'] ) ? (array)$job['warnings'] : array();
        $source = isset( $def['source'] ) ? $def['source'] : 'local';
        $remote = $source === 'destination' ? (string)$def['remote_path'] : (string)$def['local_path'];
        $extension = preg_match( '/\.(csv|json|xml|zip)$/i', $remote, $m ) ? strtolower( $m[1] ) : 'dat';
        $local = $dir . '/source.' . $extension;
        if ( $source === 'destination' )
        {
            $destination = XrowExtractDestination::fetch( isset( $def['destination_id'] ) ? $def['destination_id'] : 0 );
            if ( !$destination )
                return array( 'state' => 'skipped', 'error' => 'The destination to read from no longer exists.', 'warnings' => $warnings );
            $fetched = $destination->transport()->download( $remote, $local );
            if ( $fetched['ok'] )
            {
                // Its manifest, when the other side has one next to it (no error when it does not)
                $destination->transport()->download( $remote . XrowExtractManifest::SIDECAR_SUFFIX, $local . XrowExtractManifest::SIDECAR_SUFFIX );
                if ( is_file( $local . XrowExtractManifest::SIDECAR_SUFFIX ) && !XrowExtractManifest::readFile( $local . XrowExtractManifest::SIDECAR_SUFFIX ) )
                    @unlink( $local . XrowExtractManifest::SIDECAR_SUFFIX );
            }
        }
        else
        {
            $check = XrowExtractTransportLocal::checkPath( $remote, true );
            $fetched = $check['ok'] ? array( 'ok' => @copy( $check['path'], $local ), 'message' => 'copied ' . $check['path'] ) : $check;
            if ( $fetched['ok'] && is_file( $check['path'] . XrowExtractManifest::SIDECAR_SUFFIX ) )
                @copy( $check['path'] . XrowExtractManifest::SIDECAR_SUFFIX, $local . XrowExtractManifest::SIDECAR_SUFFIX );
        }
        if ( !$fetched['ok'] || !is_file( $local ) || filesize( $local ) === 0 )
        {
            $message = 'The file to import could not be fetched: ' . $fetched['message'];
            // The source is gone (a deleted file) rather than broken: nothing to import this time
            return array( 'state' => $source === 'local' && strpos( $fetched['message'], 'does not exist' ) !== false ? 'skipped' : 'failed',
                          'error' => $message, 'warnings' => array_merge( $warnings, array( $message ) ) );
        }
        @chmod( $local, 0600 );
        XrowExtractJob::fixOwnership( $local );
        $args = array( '--file=' . $local );
        foreach ( array( 'class', 'parent', 'match', 'language' ) as $key )
        {
            if ( !empty( $def[$key] ) )
                $args[] = '--' . $key . '=' . $def[$key];
        }
        if ( !empty( $def['class'] ) )
        {
            $class = ctype_digit( (string)$def['class'] ) ? eZContentClass::fetch( (int)$def['class'] ) : eZContentClass::fetchByIdentifier( $def['class'] );
            if ( !$class instanceof eZContentClass )
            {
                $warnings[] = "The class {$def['class']} no longer exists; the class is taken from the file itself.";
                $args = array_values( array_filter( $args, function ( $a ) { return strpos( $a, '--class=' ) !== 0; } ) );
            }
        }
        if ( !empty( $def['parent'] ) && !eZContentObjectTreeNode::fetch( (int)$def['parent'] ) )
        {
            $warnings[] = "The parent node {$def['parent']} no longer exists; new objects need a parent column in the file.";
            $args = array_values( array_filter( $args, function ( $a ) { return strpos( $a, '--parent=' ) !== 0; } ) );
        }
        // 1. the dry run
        $dryArgs = array_merge( $args, array( '--report=' . $dir . '/dry-run.json' ) );
        $dryCode = call_user_func( $runScript, 'import', $dryArgs );
        $dry = json_decode( (string)@file_get_contents( $dir . '/dry-run.json' ), true );
        if ( $dryCode !== 0 || !is_array( $dry ) )
            return array( 'state' => 'failed', 'error' => 'The dry run did not finish (exit code ' . $dryCode . ').', 'warnings' => $warnings, 'output_file' => is_file( $dir . '/dry-run.json' ) ? 'dry-run.json' : null );
        XrowExtractJob::fixOwnership( $dir . '/dry-run.json' );
        $patch = array( 'dry_run' => array( 'counts' => $dry['counts'], 'rows' => $dry['processed_rows'], 'manifest' => isset( $dry['manifest'] ) ? $dry['manifest'] : null ),
                        'warnings' => $warnings );
        if ( !empty( $dry['counts']['error'] ) )
        {
            return $patch + array( 'state' => 'failed', 'output_file' => 'dry-run.json', 'rows' => (int)$dry['processed_rows'], 'counts' => $dry['counts'],
                                   'error' => 'The dry run found ' . (int)$dry['counts']['error'] . ' row(s) with errors: nothing was applied.' );
        }
        // 2. the import itself
        $applyCode = call_user_func( $runScript, 'import', array_merge( $args, array( '--apply', '--report=' . $dir . '/report.json' ) ) );
        $report = json_decode( (string)@file_get_contents( $dir . '/report.json' ), true );
        if ( $applyCode !== 0 || !is_array( $report ) )
            return $patch + array( 'state' => 'failed', 'output_file' => 'dry-run.json', 'error' => 'The import did not finish (exit code ' . $applyCode . ').' );
        XrowExtractJob::fixOwnership( $dir . '/report.json' );
        return $patch + array( 'state' => 'done', 'output_file' => 'report.json', 'rows' => (int)$report['processed_rows'], 'counts' => $report['counts'],
                               'applied' => true );
    }

    /**
     * After a job ended (bin/php/job.php): its history row; for a scheduled job, the delivery to every
     * destination of the schedule (retried with backoff), the schedule's own state, and the notifications.
     */
    public static function afterJob( $jobID )
    {
        $job = XrowExtractJob::load( $jobID );
        if ( !$job )
            return null;
        $dir = XrowExtractJob::path( $jobID );
        $file = !empty( $job['output_file'] ) && is_file( $dir . '/' . $job['output_file'] ) ? $dir . '/' . $job['output_file'] : null;
        $manifest = $file && is_file( XrowExtractManifest::sidecarPath( $file ) ) ? XrowExtractManifest::sidecarPath( $file ) : null;
        $warnings = isset( $job['warnings'] ) ? array_values( (array)$job['warnings'] ) : array();
        $state = $job['state'];
        $runState = $state === 'done' ? ( $warnings ? 'warning' : 'done' ) : ( $state === 'skipped' ? 'skipped' : 'failed' );
        $scheduleID = isset( $job['schedule_id'] ) ? (int)$job['schedule_id'] : 0;
        $schedule = $scheduleID ? XrowExtractSchedule::fetch( $scheduleID ) : null;
        $run = array(
            'job_id' => $jobID,
            'schedule_id' => $scheduleID,
            'preset_ref' => isset( $job['preset'] ) ? (string)$job['preset'] : '',
            'owner_login' => (string)$job['owner'],
            'kind' => $job['type'] === 'import_schedule' ? 'import' : (string)$job['type'],
            'what' => (string)$job['what'],
            'trigger_type' => isset( $job['trigger'] ) ? $job['trigger'] : 'manual',
            'run_mode' => isset( $job['run_mode'] ) ? $job['run_mode'] : 'full',
            'output_format' => (string)$job['format'],
            'started_at' => (int)( $job['started'] ?: $job['created'] ),
            'ended_at' => (int)( $job['ended'] ?: time() ),
            'run_state' => $runState,
            'row_count' => (int)$job['rows'],
            'byte_size' => $file ? (int)filesize( $file ) : 0,
            'checksum' => $file ? hash_file( 'sha256', $file ) : '',
            'file_name' => $file ? basename( $file ) : '',
            'warnings' => $warnings,
            'error_text' => (string)$job['error'],
            'delivery' => array(),
            'delivery_state' => '',
        );
        XrowExtractHistory::record( $run );
        if ( !$schedule )
            return $run;

        // Delivery: a finished file to every destination of the schedule
        $ids = $schedule->destinationIDList();
        if ( $ids && $file && in_array( $runState, array( 'done', 'warning' ), true ) && $job['type'] !== 'import_schedule' )
        {
            $names = array();
            $okCount = 0;
            foreach ( $ids as $id )
            {
                $destination = XrowExtractDestination::fetch( $id );
                if ( !$destination )
                {
                    $run['warnings'][] = "Destination #$id no longer exists; skipped.";
                    continue;
                }
                $names[] = $destination->attribute( 'name' );
                XrowExtractJob::update( $jobID, array( 'delivering' => $destination->attribute( 'name' ) ) );
                $result = $destination->deliver( $file, $manifest );
                $run['delivery'][] = $result;
                if ( $result['ok'] )
                    $okCount++;
            }
            $run['destinations'] = implode( ', ', $names );
            $run['delivery_state'] = !$run['delivery'] ? '' : ( $okCount === count( $run['delivery'] ) ? 'ok' : ( $okCount ? 'partial' : 'failed' ) );
            if ( $run['warnings'] && $run['run_state'] === 'done' )
                $run['run_state'] = 'warning';
            XrowExtractJob::update( $jobID, array( 'delivery' => $run['delivery'], 'delivery_state' => $run['delivery_state'], 'delivering' => null, 'warnings' => $run['warnings'] ) );
            XrowExtractHistory::record( $run );
        }

        // The schedule's own state; a delta run starts from the last successful run's start
        $schedule->setAttribute( 'last_state', $run['delivery_state'] === 'failed' ? 'delivery_failed' : $runState );
        if ( in_array( $runState, array( 'done', 'warning' ), true ) && $run['delivery_state'] !== 'failed' )
            $schedule->setAttribute( 'last_success', $run['started_at'] );
        $schedule->store();
        $sent = XrowExtractNotifier::notify( $schedule, $run );
        XrowExtractJob::update( $jobID, array( 'notified' => $sent ) );
        return $run;
    }

    /** Whether the current user may manage schedules (policy xrowextract/schedule). */
    public static function canManage()
    {
        $access = eZUser::currentUser()->hasAccessTo( 'xrowextract', 'schedule' );
        return $access['accessWord'] !== 'no';
    }

    /** The crontab line for the cronjob part (run every few minutes). */
    public static function cronjobPartLine( $every = 5 )
    {
        $php = XrowExtractJob::phpCliBinary() ?: 'php';
        return '*/' . (int)$every . ' * * * * cd ' . escapeshellarg( eZSys::rootDir() ) . ' && ' . escapeshellarg( $php ) . ' runcronjobs.php xrowextract >/dev/null 2>&1';
    }
}

?>
