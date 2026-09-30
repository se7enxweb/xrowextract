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

if ( $http->hasPostVariable( 'CancelJobID' ) )
{
    $id = (string)$http->postVariable( 'CancelJobID' );
    $job = XrowExtractJob::isValidID( $id ) ? XrowExtractJob::load( $id ) : null;
    if ( $job && ( $job['owner'] === $login || $allJobs ) )
    {
        list( $cancelled, $message ) = XrowExtractJob::cancel( $id, $login );
        $http->setSessionVariable( 'eZExtractJobNotice', ezpI18n::tr( 'design/standard/extract', $message ) );
    }
    return $module->redirectTo( 'xrowextract/jobs' );
}

if ( $http->hasPostVariable( 'DeleteJobID' ) )
{
    $id = (string)$http->postVariable( 'DeleteJobID' );
    $job = XrowExtractJob::isValidID( $id ) ? XrowExtractJob::load( $id ) : null;
    if ( $job && XrowExtractJob::canSee( $job, $login, $allJobs ) )
        XrowExtractJob::delete( $id );
    return $module->redirectTo( 'xrowextract/jobs' );
}

// "Export these again": the objects a package install left on the site (a finished install job's own list,
// or, once the job is gone, the node ids its history row kept) as a new content package, a background
// export job of exactly those nodes - the same kind of job "Export as package" on the Site archive starts
if ( $http->hasPostVariable( 'ExportAgainJobID' ) || $http->hasPostVariable( 'ExportAgainHistoryID' ) )
{
    $againNodeIDs = array();
    $againPackage = '';
    if ( $http->hasPostVariable( 'ExportAgainJobID' ) )
    {
        $id = (string)$http->postVariable( 'ExportAgainJobID' );
        $job = XrowExtractJob::isValidID( $id ) ? XrowExtractJob::load( $id ) : null;
        if ( $job && XrowExtractJob::canSee( $job, $login, $allJobs ) && $job['type'] === 'package' )
        {
            foreach ( isset( $job['created_objects'] ) ? (array)$job['created_objects'] : array() as $object )
                if ( !empty( $object['node_id'] ) )
                    $againNodeIDs[] = (int)$object['node_id'];
            $againPackage = isset( $job['package_name'] ) ? (string)$job['package_name'] : '';
        }
    }
    else
    {
        $row = XrowExtractSchema::exists() ? eZPersistentObject::fetchObject( XrowExtractHistory::definition(), null, array( 'id' => (int)$http->postVariable( 'ExportAgainHistoryID' ) ) ) : null;
        if ( $row instanceof XrowExtractHistory && $row->attribute( 'kind' ) === XrowExtractHistory::KIND_INSTALL
             && ( $allJobs || $row->attribute( 'owner_login' ) === $login ) )
        {
            $details = $row->installDetails();
            $againNodeIDs = isset( $details['node_ids'] ) ? array_map( 'intval', (array)$details['node_ids'] ) : array();
            $againPackage = (string)$row->attribute( 'file_name' );
        }
    }
    // Only what still exists and this user may read; exporting needs the export policy (xrowextract/csv) too
    $readable = array();
    foreach ( array_unique( $againNodeIDs ) as $nodeID )
    {
        $node = eZContentObjectTreeNode::fetch( $nodeID );
        if ( $node instanceof eZContentObjectTreeNode && $node->canRead() )
            $readable[] = $nodeID;
    }
    $exportAccess = eZUser::currentUser()->hasAccessTo( 'xrowextract', 'csv' );
    if ( $exportAccess['accessWord'] === 'no' )
        $http->setSessionVariable( 'eZExtractJobNotice', ezpI18n::tr( 'design/standard/extract', 'You may not export content (policy xrowextract/csv).' ) );
    elseif ( !$readable )
        $http->setSessionVariable( 'eZExtractJobNotice', ezpI18n::tr( 'design/standard/extract', 'None of the objects this install left on the site exist any more, or you may not read them; nothing to export.' ) );
    elseif ( !XrowExtractJob::available() )
        $http->setSessionVariable( 'eZExtractJobNotice', ezpI18n::tr( 'design/standard/extract', 'Background exports are not available on this server (no PHP command line binary was found, or exec() is disabled).' ) );
    else
    {
        $args = array( '--export', '--nodes=' . implode( ',', $readable ) );
        if ( $againPackage !== '' )
            $args[] = '--name=' . XrowExtractPackage::validPackageName( $againPackage . '_again' );
        $againJobID = XrowExtractJob::create( array(
            'type' => 'package', 'owner' => $login,
            'what' => ezpI18n::tr( 'design/standard/extract', 'Export again: %count object(s) installed from %name', null,
                                   array( '%count' => count( $readable ), '%name' => $againPackage !== '' ? $againPackage : '?' ) ),
            'format' => 'ezpkg', 'output_file' => 'export.ezpkg', 'args' => $args,
        ) );
        if ( !XrowExtractJob::start( $againJobID ) )
        {
            XrowExtractJob::update( $againJobID, array(
                'state' => 'failed', 'ended' => time(),
                'error' => ezpI18n::tr( 'design/standard/extract', 'Could not start the background process.' ),
            ) );
        }
        $http->setSessionVariable( 'eZExtractJobStarted', $againJobID );
    }
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
    // The whole job log as a person reads it (XrowExtractJob::cleanLogFile(): steps, warnings, errors, and a
    // progress timeline of one line per phase and 10 %), read line by line; the page's poll appends to it from
    // this offset and timeline state while the job runs
    $logPath = XrowExtractJob::path( $job['id'] ) . '/' . XrowExtractJob::LOG_FILE;
    $cleaned = XrowExtractJob::cleanLogFile( $logPath );
    $logText = $cleaned['text'];
    $logSize = $cleaned['size'];
    $logFrom = $logSize > 8388608 ? 1 : 0;
    // The job's own progress bar in its log is the most exact progress while it runs
    if ( $job['state'] === 'running' && ( $fromLog = XrowExtractJob::logProgress( $logPath ) ) )
        $progress = $fromLog;
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
        'log_phase' => $cleaned['state']['phase'],
        'log_step' => (int)$cleaned['state']['step'],
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
        'has_manifest' => $job['state'] === 'done' && $job['output_file'] && is_file( XrowExtractJob::path( $job['id'] ) . '/' . $job['output_file'] . XrowExtractManifest::SIDECAR_SUFFIX ),
        'warnings' => isset( $job['warnings'] ) ? array_values( (array)$job['warnings'] ) : array(),
        'schedule_id' => isset( $job['schedule_id'] ) ? (int)$job['schedule_id'] : 0,
        'schedule_name' => isset( $job['schedule_name'] ) ? (string)$job['schedule_name'] : '',
        'run_mode' => isset( $job['run_mode'] ) ? (string)$job['run_mode'] : '',
        'delivery' => isset( $job['delivery'] ) && is_array( $job['delivery'] ) ? $job['delivery'] : array(),
        'delivery_state' => isset( $job['delivery_state'] ) ? (string)$job['delivery_state'] : '',
        // 'package' jobs only (bin/php/job.php): the dry run counts are already in 'counts' above;
        // these are install()'s own per-item results, for a link straight to what a package job
        // installed - a class's edit view, an object's node.
        'package_name' => isset( $job['package_name'] ) ? $job['package_name'] : null,
        'created_classes' => isset( $job['created_classes'] ) ? $job['created_classes'] : array(),
        'created_objects' => isset( $job['created_objects'] ) ? $job['created_objects'] : array(),
        'install_errors' => isset( $job['install_errors'] ) ? $job['install_errors'] : array(),
    );
    // A package install: what it installed, as counts, instead of "rows". A job that finished before its
    // runner recorded them (older jobs) gets them from its own install-report.json.
    $last = count( $rows ) - 1;
    if ( $job['type'] === 'package' && in_array( $job['state'], array( 'done', 'failed' ), true ) )
    {
        if ( !$rows[$last]['created_classes'] && !$rows[$last]['created_objects'] && $job['output_file']
             && is_file( $reportPath = XrowExtractJob::path( $job['id'] ) . '/' . $job['output_file'] ) )
        {
            $report = json_decode( (string)@file_get_contents( $reportPath ), true );
            if ( is_array( $report ) && isset( $report['report'] ) )
            {
                $rows[$last]['created_classes'] = isset( $report['report']['created_classes'] ) ? (array)$report['report']['created_classes'] : array();
                $rows[$last]['created_objects'] = isset( $report['report']['created_objects'] ) ? (array)$report['report']['created_objects'] : array();
                $rows[$last]['install_errors'] = isset( $report['report']['errors'] ) ? (array)$report['report']['errors'] : array();
                if ( !$rows[$last]['counts'] && isset( $report['counts'] ) )
                    $rows[$last]['counts'] = $report['counts'];
            }
        }
        $rows[$last]['installed_classes'] = count( $rows[$last]['created_classes'] );
        $rows[$last]['installed_objects'] = count( $rows[$last]['created_objects'] );
        $installedTotal = $rows[$last]['installed_classes'] + $rows[$last]['installed_objects'];
        // With the dry run's counts: how many were new and how many already existed, and what the job did with
        // those (its --object-mode/--class-mode) - a re-import with "skip" created nothing and left the rest alone
        $pc = $rows[$last]['counts'];
        if ( is_array( $pc ) && isset( $pc['objects_create'] ) )
        {
            $mode = function ( $name, $default ) use ( $job )
            {
                foreach ( (array)$job['args'] as $arg )
                    if ( strpos( $arg, '--' . $name . '=' ) === 0 )
                        return substr( $arg, strlen( $name ) + 3 );
                return $default;
            };
            $rows[$last]['install_summary'] = array(
                'created' => (int)$pc['classes_create'] + (int)$pc['objects_create'],
                'existing' => (int)$pc['classes_update'] + (int)$pc['objects_update'] + (int)$pc['objects_unchanged'],
                'class_missing' => (int)$pc['objects_class_missing'],
                'object_mode' => $mode( 'object-mode', 'update' ),
                'class_mode' => $mode( 'class-mode', 'skip' ),
            );
        }
        if ( $job['state'] === 'done' && $installedTotal )
        {
            $rows[$last]['progress'] = array( 'done' => $installedTotal, 'total' => $installedTotal, 'phase' => 'done' );
            $rows[$last]['progress_percent'] = 100;
        }
    }
}

$counts = array( 'total' => count( $rows ), 'done' => 0, 'running' => 0, 'queued' => 0, 'failed' => 0 );
foreach ( $rows as $row )
{
    if ( isset( $counts[$row['state']] ) )
        $counts[$row['state']]++;
    elseif ( $row['state'] === 'skipped' )
        $counts['failed']++;
}
// Failed scheduled runs this user has not acknowledged yet (the red badge on the tab): a notice here
$scheduleAlerts = XrowExtractFunctionCollection::fetchScheduleAlerts();
$tpl->setVariable( 'schedule_alerts', $scheduleAlerts['result'] );
$tpl->setVariable( 'can_view_history', XrowExtractHistory::canView() );

// The install history (package installs, kept after their job folders expire): every package, or one
// (?package=<name>, the Package tab's "All N installs" link), 20 a page (?install_offset=)
$installFilterPackage = isset( $_GET['package'] ) && preg_match( '/^[A-Za-z0-9_.-]{1,200}$/', (string)$_GET['package'] ) ? (string)$_GET['package'] : '';
$installOffset = isset( $_GET['install_offset'] ) && ctype_digit( (string)$_GET['install_offset'] ) ? (int)$_GET['install_offset'] : 0;
$installPageSize = 20;
$installs = array();
$installTotal = 0;
if ( XrowExtractSchema::exists() )
{
    $installTotal = XrowExtractHistory::countInstalls( $login, $allJobs, $installFilterPackage );
    if ( $installOffset >= $installTotal )
        $installOffset = $installTotal > 0 ? (int)( floor( ( $installTotal - 1 ) / $installPageSize ) * $installPageSize ) : 0;
    foreach ( XrowExtractHistory::fetchInstalls( $login, $allJobs, $installFilterPackage, $installOffset, $installPageSize ) as $historyRow )
        $installs[] = $historyRow->installRow( $login );
}
$installPackages = array();
foreach ( XrowExtractPackage::repositoryPackages() as $repositoryPackage )
    $installPackages[] = $repositoryPackage['name'];
$tpl->setVariable( 'installs', $installs );
$tpl->setVariable( 'install_history', array(
    'package' => $installFilterPackage, 'packages' => $installPackages, 'total' => $installTotal,
    'from' => $installTotal ? $installOffset + 1 : 0, 'to' => min( $installTotal, $installOffset + $installPageSize ),
    'prev' => $installOffset > 0 ? max( 0, $installOffset - $installPageSize ) : -1,
    'next' => $installOffset + $installPageSize < $installTotal ? $installOffset + $installPageSize : -1,
    'query' => $installFilterPackage !== '' ? '&package=' . rawurlencode( $installFilterPackage ) : '',
) );
$tpl->setVariable( 'JobsAvailable', XrowExtractJob::available() );

$tpl->setVariable( 'jobs', $rows );
$tpl->setVariable( 'job_counts', $counts );
$tpl->setVariable( 'all_jobs', $allJobs );
$tpl->setVariable( 'started_job_id', $startedJobID );
$jobNotice = $http->hasSessionVariable( 'eZExtractJobNotice' ) ? $http->sessionVariable( 'eZExtractJobNotice' ) : false;
if ( $jobNotice )
    $http->removeSessionVariable( 'eZExtractJobNotice' );
$tpl->setVariable( 'job_notice', $jobNotice );

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
