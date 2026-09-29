<?php
/**
 * xrowextract/jobs: background export jobs started from the "Run in the
 * background" button on the CSV and site archive views. Everyone sees their
 * own; a user with the policy xrowextract/all_jobs sees every job.
 */

$module = $Params['Module'];
$http = eZHTTPTool::instance();
$tpl = eZTemplate::factory();

$login = eZUser::currentUser()->attribute( 'login' );
$allJobs = XrowExtractJob::allowAllJobs();

if ( $http->hasPostVariable( 'DeleteJobID' ) )
{
    $id = (string)$http->postVariable( 'DeleteJobID' );
    $job = XrowExtractJob::isValidID( $id ) ? XrowExtractJob::load( $id ) : null;
    if ( $job && XrowExtractJob::canSee( $job, $login, $allJobs ) )
        XrowExtractJob::delete( $id );
    return $module->redirectTo( 'xrowextract/jobs' );
}

// The id of a job this same browser just started (set by csv.php / archive.php), shown once as a notice
$startedJobID = $http->hasSessionVariable( 'eZExtractJobStarted' ) ? $http->sessionVariable( 'eZExtractJobStarted' ) : false;
if ( $startedJobID )
    $http->removeSessionVariable( 'eZExtractJobStarted' );

// Who started a job: the login stored with it, resolved once per login to the
// user's name, initials, a colour that stays the same for that login, and the
// user's node in the admin (none when the account no longer exists)
$owners = array();
$ownerInfo = function ( $ownerLogin ) use ( &$owners )
{
    if ( isset( $owners[$ownerLogin] ) )
        return $owners[$ownerLogin];
    $name = $ownerLogin;
    $nodeID = false;
    $user = $ownerLogin !== '' ? eZUser::fetchByName( $ownerLogin ) : null;
    if ( $user instanceof eZUser )
    {
        $object = $user->attribute( 'contentobject' );
        if ( $object instanceof eZContentObject )
        {
            if ( trim( (string)$object->attribute( 'name' ) ) !== '' )
                $name = $object->attribute( 'name' );
            $nodeID = (int)$object->attribute( 'main_node_id' ) ?: false;
        }
    }
    $initials = '';
    foreach ( preg_split( '/[\s._@-]+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ) as $part )
    {
        $initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
        if ( mb_strlen( $initials ) >= 2 )
            break;
    }
    return $owners[$ownerLogin] = array(
        'login' => $ownerLogin,
        'name' => $name,
        'initials' => $initials !== '' ? $initials : '?',
        'hue' => hexdec( substr( md5( $ownerLogin ), 0, 4 ) ) % 360,
        'node_id' => $nodeID,
    );
};

// A duration as "8 s", "3 min 12 s" or "2 h 5 min" (the Jobs page's script formats live updates the same way)
$duration = function ( $seconds )
{
    if ( $seconds === null )
        return '';
    if ( $seconds < 60 )
        return $seconds . ' s';
    if ( $seconds < 3600 )
        return floor( $seconds / 60 ) . ' min' . ( $seconds % 60 ? ' ' . ( $seconds % 60 ) . ' s' : '' );
    return floor( $seconds / 3600 ) . ' h' . ( floor( $seconds % 3600 / 60 ) ? ' ' . floor( $seconds % 3600 / 60 ) . ' min' : '' );
};

$rows = array();
foreach ( XrowExtractJob::forViewer( $login, $allJobs ) as $job )
{
    $progress = XrowExtractJob::readProgress( XrowExtractJob::path( $job['id'] ) . '/' . XrowExtractJob::PROGRESS_FILE );
    // A package install: the classes and objects it has written so far, counted in the database
    $install = null;
    $watchFile = XrowExtractJob::path( $job['id'] ) . '/' . XrowExtractJob::INSTALL_WATCH_FILE;
    if ( $job['type'] === 'package' && is_file( $watchFile ) )
    {
        $install = XrowExtractPackage::installProgress( $watchFile );
        if ( $install && $job['state'] === 'running' )
            $progress = array( 'done' => $install['done'], 'total' => $install['total'],
                               'phase' => sprintf( '%d/%d classes, %d/%d objects', $install['classes_done'], $install['classes_total'], $install['objects_done'], $install['objects_total'] ) );
    }
    // The end of the job's log (what it printed: steps, warnings, errors), 64 KB at most; the page's poll
    // appends to it from this offset while the job runs
    $logPath = XrowExtractJob::path( $job['id'] ) . '/' . XrowExtractJob::LOG_FILE;
    $logSize = is_file( $logPath ) ? (int)@filesize( $logPath ) : 0;
    $logFrom = max( 0, $logSize - 65536 );
    $logText = $logSize ? (string)@file_get_contents( $logPath, false, null, $logFrom ) : '';
    $percent = 0;
    if ( is_array( $progress ) && !empty( $progress['total'] ) )
        $percent = max( 0, min( 100, (int)round( 100 * $progress['done'] / $progress['total'] ) ) );
    $rows[] = array(
        'id' => $job['id'],
        'type' => $job['type'],
        'what' => $job['what'],
        // A package job's file is the .ezpkg (export) or a JSON install report: name what the job is
        'format' => $job['type'] === 'package'
                    ? ( is_file( $watchFile ) || strpos( (string)$job['output_file'], 'install' ) !== false ? 'ezpkg · install' : 'ezpkg · export' )
                    : $job['format'],
        'install' => $install,
        'log_text' => $logText,
        'log_offset' => $logSize,
        'log_cut' => $logFrom > 0,
        'state' => $job['state'],
        'owner' => $job['owner'],
        'owner_user' => $ownerInfo( (string)$job['owner'] ),
        'mine' => $job['owner'] === $login,
        'wait_text' => $job['started'] ? $duration( max( 0, $job['started'] - $job['created'] ) ) : '',
        'run_text' => $job['started'] && $job['ended'] ? $duration( max( 0, $job['ended'] - $job['started'] ) ) : '',
        'created' => $job['created'],
        'started' => $job['started'],
        'ended' => $job['ended'],
        'rows' => $job['rows'],
        'size_kb' => $job['size'] !== null ? (int)ceil( $job['size'] / 1024 ) : null,
        'error' => $job['error'],
        'output_file' => $job['output_file'],
        'preset' => isset( $job['preset'] ) ? (string)$job['preset'] : '',
        'preset_name' => isset( $job['preset'] ) && $job['preset'] !== '' ? XrowExtractPreset::presetName( $job['preset'] ) : '',
        'progress' => $progress,
        'progress_percent' => $percent,
        'active' => $job['state'] === 'queued' || $job['state'] === 'running',
        'counts' => isset( $job['counts'] ) ? $job['counts'] : null,
        'has_errors_file' => !empty( $job['has_errors_file'] ),
    );
}

$counts = array( 'total' => count( $rows ), 'done' => 0, 'running' => 0, 'queued' => 0, 'failed' => 0 );
foreach ( $rows as $row )
{
    if ( isset( $counts[$row['state']] ) )
        $counts[$row['state']]++;
}

$tpl->setVariable( 'jobs', $rows );
$tpl->setVariable( 'job_counts', $counts );
$tpl->setVariable( 'all_jobs', $allJobs );
$tpl->setVariable( 'started_job_id', $startedJobID );
$tpl->setVariable( 'RunningJobsCount', XrowExtractJob::countRunning( $login, $allJobs ) );
$tpl->setVariable( 'retention_days', XrowExtractJob::retentionDays() );
$scriptFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract.js';
$tpl->setVariable( 'ScriptVersion', is_file( $scriptFile ) ? substr( md5_file( $scriptFile ), 0, 12 ) : '0' );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/jobs.tpl' );
$Result['path'] = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Jobs' ) ),
);
$Result['left_menu'] = 'design:xrowextract/menu_jobs.tpl';

?>
