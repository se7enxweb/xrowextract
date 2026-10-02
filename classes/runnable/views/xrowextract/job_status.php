<?php
/**
 * The code of extension/xrowextract/modules/xrowextract/job_status.php, moved into a class (#207 stage 1). The file extension/xrowextract/modules/xrowextract/job_status.php is one call to it.
 * Guide: doc/bc/6.0/cli_cronjob_view_abstractions.md
 */
/*
 * The original header of extension/xrowextract/modules/xrowextract/job_status.php:
 *
 *
 * xrowextract/job_status/<id>: JSON for the Jobs page's 2 second poll while
 * a job is queued or running: { id, state, rows, size, error, started,
 * ended, progress: { done, total, phase } | null }.
 *
 */

namespace Exponential\View\Extension\Xrowextract\Xrowextract
{

class JobStatus extends \Exponential\Runnable\ModuleView
{
    public function run( array $scope )
    {
        // the including function's variables ($Params, $Module, $cli, ...)
        foreach ( array_keys( $scope ) as $__name )
            if ( $__name !== 'this' && $__name !== 'scope' )
                ${$__name} = &$scope[$__name];
        unset( $__name );

        $login = \eZUser::currentUser()->attribute( 'login' );
        $allJobs = \XrowExtractJob::allowAllJobs();
        $id = isset( $Params['JobID'] ) ? (string)$Params['JobID'] : '';
        $job = \XrowExtractJob::isValidID( $id ) ? \XrowExtractJob::load( $id ) : null;

        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: application/json; charset=utf-8' );

        if ( !$job || !\XrowExtractJob::canSee( $job, $login, $allJobs ) )
        {
            header( 'HTTP/1.1 404 Not Found' );
            echo json_encode( array( 'error' => 'not_found' ) );
            \eZExecution::cleanExit();
        }

        $progress = \XrowExtractJob::readProgress( \XrowExtractJob::path( $id ) . '/' . \XrowExtractJob::PROGRESS_FILE );

        // A package install: its real progress, counted in the database against what the package carries
        // (the kernel's installer reports nothing while it works), plus the latest objects it wrote
        $installProgress = null;
        $watchFile = \XrowExtractJob::path( $id ) . '/' . \XrowExtractJob::INSTALL_WATCH_FILE;
        if ( $job['type'] === 'package' && is_file( $watchFile ) && in_array( $job['state'], array( 'running', 'done', 'failed' ), true ) )
        {
            $installProgress = \XrowExtractPackage::installProgress( $watchFile );
            if ( $installProgress && $job['state'] === 'running' )
            {
                $progress = array( 'done' => $installProgress['done'], 'total' => $installProgress['total'],
                                   'phase' => sprintf( '%d/%d classes, %d/%d objects', $installProgress['classes_done'], $installProgress['classes_total'],
                                                       $installProgress['objects_done'], $installProgress['objects_total'] ) );
            }
        }

        // The job's log from a byte offset on (the page asks for what it has not shown yet), at most 64 KB a time
        $logPath = \XrowExtractJob::path( $id ) . '/' . \XrowExtractJob::LOG_FILE;
        $logOffset = isset( $_GET['log_offset'] ) && ctype_digit( (string)$_GET['log_offset'] ) ? (int)$_GET['log_offset'] : 0;
        $logSize = is_file( $logPath ) ? (int)@filesize( $logPath ) : 0;
        $logText = '';
        if ( $logSize > $logOffset )
        {
            $fp = @fopen( $logPath, 'rb' );
            if ( $fp )
            {
                fseek( $fp, $logOffset );
                $logText = (string)fread( $fp, max( 1, min( 262144, $logSize - $logOffset ) ) );
                fclose( $fp );
                // Only whole lines (up to the last line break), so a line is never cleaned in two halves; the
                // rest comes with the next poll
                $lastBreak = max( (int)strrpos( $logText, "\n" ), (int)strrpos( $logText, "\r" ) );
                if ( $lastBreak > 0 && $job['state'] === 'running' )
                    $logText = substr( $logText, 0, $lastBreak + 1 );
            }
        }
        $logRead = strlen( $logText );
        // The log's progress timeline continues where the page left off (the phase and 10 % step it has shown)
        $logState = array(
            'phase' => isset( $_GET['log_phase'] ) ? mb_substr( (string)$_GET['log_phase'], 0, 120 ) : '',
            'step' => isset( $_GET['log_step'] ) && preg_match( '/^-?\d{1,3}$/', (string)$_GET['log_step'] ) ? (int)$_GET['log_step'] : -1,
        );
        $logClean = \XrowExtractJob::cleanLog( $logText, $logState );

        // The job's own progress bar (the kernel's installers print one: "40% (1736/4339) ... end @ 16:16") is the
        // most exact source while it runs; the database count and the progress file come after it
        if ( $job['state'] === 'running' && ( $fromLog = \XrowExtractJob::logProgress( $logPath ) ) )
            $progress = $fromLog;

        echo json_encode( array(
            'id' => $job['id'],
            'state' => $job['state'],
            'rows' => $job['rows'],
            'size' => $job['size'],
            'error' => $job['error'],
            'started' => $job['started'],
            'ended' => $job['ended'],
            'started_text' => $job['started'] ? \eZLocale::instance()->formatShortDateTime( (int)$job['started'] ) : '',
            'ended_text' => $job['ended'] ? \eZLocale::instance()->formatShortDateTime( (int)$job['ended'] ) : '',
            'has_file' => $job['state'] === 'done' && $job['output_file'] && is_file( \XrowExtractJob::path( $id ) . '/' . $job['output_file'] ),
            'progress' => $progress,
            'install' => $installProgress,
            'log' => array( 'text' => $logClean !== '' ? $logClean . "\n" : '', 'offset' => $logOffset + $logRead, 'size' => $logSize,
                            'phase' => $logState['phase'], 'step' => $logState['step'] ),
        ), JSON_INVALID_UTF8_SUBSTITUTE );
        \eZExecution::cleanExit();

        return $this->viewResult( isset( $Result ) ? $Result : null, null );
    }
}

}
