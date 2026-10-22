<?php

/**
 * One background job of Export, run by one of the kernel's engines (Exponential 6.0.15 and later): the job queue
 * (expqueue, job type xrowextract.run) or the kernel's content jobs (XrowExtractTransfer).
 *
 * run() carries out the job exactly as before, with "php bin/php/job.php --run=<id>", but as a child process it waits
 * for instead of a detached one: job.php keeps eZScript, the export's memory and its own error handling out of the
 * long-lived queue worker, and it writes job.json, the log, the output file and Export's history as it always did.
 * While the child runs, run() reports the job's progress.json to the engine every second and watches for a cancel:
 * one asked for in the engine stops the job as the Jobs page's Cancel does (XrowExtractJob::cancel()); one asked for
 * on the Jobs page ends the run as cancelled.
 *
 * Declared only when the kernel has transfer runs; on an older kernel this file defines nothing.
 */
if ( interface_exists( 'expKernelTransferTask' ) )
{
    class XrowExtractTransferTask implements expKernelTransferTask
    {
        /** Seconds between two looks at the child */
        const POLL_SECONDS = 1;

        /**
         * @param array<string, mixed> $params
         * @param expKernelTransferContext $context
         * @return array<string, mixed>
         */
        public function run( array $params, expKernelTransferContext $context )
        {
            $id = isset( $params['job'] ) ? (string)$params['job'] : '';
            if ( !XrowExtractJob::isValidID( $id ) )
                throw new RuntimeException( 'Not a job id: ' . $id );
            $job = XrowExtractJob::load( $id );
            if ( !$job )
                throw new RuntimeException( 'The job ' . $id . ' no longer exists.' );
            if ( !empty( $job['cancelled'] ) )
                throw new expKernelTransferCancelledException( (string)$job['error'] );
            // Run again after a worker was killed: a job that ended is reported, one still running is waited for
            $result = XrowExtractTransfer::resultOf( $job );
            if ( $result !== null )
                return $result;
            if ( $context->isCancelled() )
            {
                XrowExtractJob::cancel( $id, 'the job queue' );
                throw new expKernelTransferCancelledException( 'The job was cancelled before it started.' );
            }

            $process = null;
            if ( $job['state'] === 'queued' )
                $process = $this->spawn( $id );
            elseif ( !$this->alive( isset( $job['pid'] ) ? (int)$job['pid'] : 0 ) )
                throw new RuntimeException( 'The job ' . $id . ' was left running by a process that has ended.' );

            $dir = XrowExtractJob::path( $id );
            while ( true )
            {
                $current = $process === null ? XrowExtractJob::load( $id ) : null;
                $running = $process !== null ? proc_get_status( $process )['running']
                                             : ( $current !== null && $current['state'] === 'running' && $this->alive( isset( $current['pid'] ) ? (int)$current['pid'] : 0 ) );
                $progress = XrowExtractTransfer::progressOf( XrowExtractJob::readProgress( $dir . '/' . XrowExtractJob::PROGRESS_FILE ),
                                                             XrowExtractJob::logProgress( $dir . '/' . XrowExtractJob::LOG_FILE ) );
                if ( $progress )
                    $context->progress( $progress[0], $progress[1], $progress[2] );
                else
                    $context->heartbeat();
                if ( !$running )
                    break;
                // A cancel in the engine stops the job as the Jobs page's Cancel does (once: job.json says it was).
                // Called through call_user_func(): the answer changes while the job runs, and a direct second call
                // would be taken by the static analysis as the same answer as the one before the start
                if ( call_user_func( array( $context, 'isCancelled' ) ) === true && empty( XrowExtractJob::load( $id )['cancelled'] ) )
                    XrowExtractJob::cancel( $id, 'the job queue' );
                sleep( self::POLL_SECONDS );
            }
            if ( $process !== null )
                proc_close( $process );

            $job = XrowExtractJob::load( $id );
            if ( !$job )
                throw new RuntimeException( 'The job ' . $id . ' was removed while it ran.' );
            if ( !empty( $job['cancelled'] ) )
                throw new expKernelTransferCancelledException( (string)$job['error'] );
            $result = XrowExtractTransfer::resultOf( $job );
            if ( $result === null )
            {
                // The child ended without a result in job.json (it could not even start): failed, as job.php's own
                // shutdown function would have marked it
                XrowExtractJob::markFailed( $id, 'The job runner ended without recording a result.' );
                XrowExtractScheduler::afterJob( $id );
                $job = XrowExtractJob::load( $id );
                $result = $job ? XrowExtractTransfer::resultOf( $job ) : null;
            }
            return $result !== null ? $result : array( 'state' => 'failed', 'error' => 'The job runner ended without recording a result.' );
        }

        /**
         * "php bin/php/job.php --run=<id>" as a child, its output appended to the job's log.
         * @param string $id
         * @return resource
         */
        protected function spawn( $id )
        {
            $php = XrowExtractJob::phpCliBinary();
            if ( !$php || !function_exists( 'proc_open' ) )
                throw new RuntimeException( 'No PHP command line binary to run the job with (csv.ini [Jobs] PhpCli).' );
            $argv = XrowExtractTransferTask::command( $php, XrowExtractJob::runnerScript(), $id, XrowExtractJob::runningAsRoot() );
            $log = XrowExtractJob::path( $id ) . '/' . XrowExtractJob::LOG_FILE;
            $process = @proc_open( $argv, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $log, 'a' ), 2 => array( 'file', $log, 'a' ) ),
                                   $pipes, eZSys::rootDir() );
            if ( !is_resource( $process ) )
                throw new RuntimeException( 'The job runner could not be started.' );
            XrowExtractJob::fixOwnership( $log );
            return $process;
        }

        /**
         * The child's argument list (pure).
         * @param string $php
         * @param string $runner
         * @param string $id
         * @param bool $asRoot
         * @return list<string>
         */
        public static function command( $php, $runner, $id, $asRoot ): array
        {
            $argv = array( (string)$php, (string)$runner, '--run=' . $id );
            if ( $asRoot )
                $argv[] = '--allow-root-user';
            return $argv;
        }

        /** @param int $pid */
        protected function alive( $pid ): bool
        {
            if ( $pid <= 1 )
                return false;
            // posix_kill() fails for a process of another system user (EPERM) that is still alive: /proc says so
            if ( function_exists( 'posix_kill' ) && @posix_kill( $pid, 0 ) )
                return true;
            return file_exists( '/proc/' . $pid );
        }
    }
}
