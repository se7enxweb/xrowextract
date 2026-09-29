<?php
/**
 * xrowextract/job_status/<id>: JSON for the Jobs page's 2 second poll while
 * a job is queued or running: { id, state, rows, size, error, started,
 * ended, progress: { done, total, phase } | null }.
 */

$login = eZUser::currentUser()->attribute( 'login' );
$allJobs = XrowExtractJob::allowAllJobs();
$id = isset( $Params['JobID'] ) ? (string)$Params['JobID'] : '';
$job = XrowExtractJob::isValidID( $id ) ? XrowExtractJob::load( $id ) : null;

header( 'Cache-Control: private, no-store, max-age=0' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Content-Type: application/json; charset=utf-8' );

if ( !$job || !XrowExtractJob::canSee( $job, $login, $allJobs ) )
{
    header( 'HTTP/1.1 404 Not Found' );
    echo json_encode( array( 'error' => 'not_found' ) );
    eZExecution::cleanExit();
}

$progress = XrowExtractJob::readProgress( XrowExtractJob::path( $id ) . '/' . XrowExtractJob::PROGRESS_FILE );
echo json_encode( array(
    'id' => $job['id'],
    'state' => $job['state'],
    'rows' => $job['rows'],
    'size' => $job['size'],
    'error' => $job['error'],
    'started' => $job['started'],
    'ended' => $job['ended'],
    'has_file' => $job['state'] === 'done' && $job['output_file'] && is_file( XrowExtractJob::path( $id ) . '/' . $job['output_file'] ),
    'progress' => $progress,
) );
eZExecution::cleanExit();
