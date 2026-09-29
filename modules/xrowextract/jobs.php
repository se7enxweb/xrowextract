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

$rows = array();
foreach ( XrowExtractJob::forViewer( $login, $allJobs ) as $job )
{
    $progress = XrowExtractJob::readProgress( XrowExtractJob::path( $job['id'] ) . '/' . XrowExtractJob::PROGRESS_FILE );
    $percent = 0;
    if ( is_array( $progress ) && !empty( $progress['total'] ) )
        $percent = max( 0, min( 100, (int)round( 100 * $progress['done'] / $progress['total'] ) ) );
    $rows[] = array(
        'id' => $job['id'],
        'type' => $job['type'],
        'what' => $job['what'],
        'format' => $job['format'],
        'state' => $job['state'],
        'owner' => $job['owner'],
        'mine' => $job['owner'] === $login,
        'created' => $job['created'],
        'started' => $job['started'],
        'ended' => $job['ended'],
        'rows' => $job['rows'],
        'size_kb' => $job['size'] !== null ? (int)ceil( $job['size'] / 1024 ) : null,
        'error' => $job['error'],
        'output_file' => $job['output_file'],
        'progress' => $progress,
        'progress_percent' => $percent,
        'active' => $job['state'] === 'queued' || $job['state'] === 'running',
    );
}

$tpl->setVariable( 'jobs', $rows );
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
