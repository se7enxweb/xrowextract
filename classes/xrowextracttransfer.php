<?php

/**
 * Export's part of Exponential's transfer runs (6.0.15 and later): one engine and one run history for every import
 * and export tool of the installation.
 *
 *   - A background job (XrowExtractJob::start()) is handed to the kernel's runner (expKernelTransferRunner::submit()),
 *     which runs it in the job queue (expqueue, job type xrowextract.run) when it is active and a worker is alive,
 *     else as one of the kernel's content jobs, else here as before: "php bin/php/job.php --run=<id>", detached.
 *     The queue and the content jobs call XrowExtractTransferTask, which runs that same job.php as a child process
 *     and waits for it. job.json stays the job's state, so the Jobs page, the downloads, Re-import and History work
 *     the same whatever ran it.
 *   - Every run is also written to the kernel's run history (exp_transfer_run, tool transfer.xrowextract) wherever
 *     Export writes its own history row (XrowExtractHistory::record()): background jobs, scheduled runs, direct
 *     downloads and package installs alike.
 *
 * On an older kernel (no expKernelTransferRunner) nothing here does anything and Export runs as it always did.
 * The mapping functions are pure (no database, no request), for the unit tests.
 */
class XrowExtractTransfer
{
    /** The tool's id in the transfer registry */
    const TOOL = 'transfer.xrowextract';

    /** The task class the engines call */
    const TASK = 'XrowExtractTransferTask';

    /** The job keys this class writes into job.json */
    const ENGINE_KEY = 'transfer_engine';
    const JOB_REF_KEY = 'transfer_job_ref';
    const RUN_KEY = 'transfer_run_id';

    /** Whether the kernel has transfer runs (6.0.15 and later). */
    public static function kernelHasRunner(): bool
    {
        // the task class exists exactly when the kernel has the interface (xrowextracttransfertask.php)
        return class_exists( 'expKernelTransferRunner' ) && interface_exists( 'expKernelTransferTask' ) && class_exists( self::TASK );
    }

    /** Whether the kernel has the run history (6.0.15 and later). */
    public static function kernelHasHistory(): bool
    {
        return class_exists( 'expKernelTransferHistory' );
    }

    // ------------------------------------------------------------ submitting a job

    /**
     * Hands a queued job to the kernel's runner. Returns the engine that took it (expqueue, contentjob), or
     * 'process' when this process must start it the old way (the job then carries the history row's id, so
     * bin/php/job.php ends that row). Null on an older kernel or when the runner failed: start it the old way.
     * @param string $id
     * @param array<string, mixed> $job
     */
    public static function submit( $id, array $job ): ?string
    {
        if ( !self::kernelHasRunner() )
            return null;
        try
        {
            $options = array( 'title' => (string)$job['what'], 'history' => self::historyInfo( $job, self::classIdentifier( $job ) ) );
            $owner = !empty( $job['owner'] ) ? eZUser::fetchByName( (string)$job['owner'] ) : null;
            if ( $owner instanceof eZUser )
                $options['user'] = $owner;
            $run = expKernelTransferRunner::submit( self::TOOL, self::TASK, self::payload( $id ), $options );
        }
        catch ( Throwable $e )
        {
            eZDebug::writeWarning( 'Job ' . $id . ' not handed to the transfer runner: ' . $e->getMessage(), __METHOD__ );
            return null;
        }
        XrowExtractJob::update( $id, self::jobPatch( $run ) );
        return (string)$run['engine'];
    }

    /**
     * The task parameters of a job (pure): only its id; job.json holds the rest.
     * @param string $id
     * @return array{job: string}
     */
    public static function payload( $id ): array
    {
        return array( 'job' => (string)$id );
    }

    /**
     * What a submit result writes into job.json (pure).
     * @param array<string, mixed> $run engine, job_ref, run_id
     * @return array<string, mixed>
     */
    public static function jobPatch( array $run ): array
    {
        return array(
            self::ENGINE_KEY => isset( $run['engine'] ) ? (string)$run['engine'] : 'process',
            self::JOB_REF_KEY => isset( $run['job_ref'] ) ? (string)$run['job_ref'] : '',
            self::RUN_KEY => isset( $run['run_id'] ) ? (int)$run['run_id'] : 0,
        );
    }

    /**
     * Whether a job runs in one of the kernel's engines (its runner then starts it, not this process) (pure).
     * @param array<string, mixed> $job
     */
    public static function runsInEngine( array $job ): bool
    {
        return isset( $job[self::ENGINE_KEY] ) && in_array( $job[self::ENGINE_KEY], array( 'expqueue', 'contentjob' ), true );
    }

    /**
     * Cancels a job's engine job too (XrowExtractJob::cancel()); a job that had not started yet gets its history
     * row ended as cancelled here, since nothing else will end it.
     * @param array<string, mixed> $job the job as it was before the cancel
     */
    public static function cancel( array $job ): void
    {
        if ( self::runsInEngine( $job ) && class_exists( 'expKernelTransferRunner' ) )
            expKernelTransferRunner::cancel( (string)$job[self::ENGINE_KEY], (string)$job[self::JOB_REF_KEY] );
        // A running job ends its row itself (job.php's afterJob(), or the task); a queued one never will
        if ( $job['state'] === 'queued' && !empty( $job[self::RUN_KEY] ) && self::kernelHasHistory() )
            expKernelTransferHistory::finish( (int)$job[self::RUN_KEY], 'cancelled', array( 'error' => 'Cancelled before it started.' ) );
    }

    /**
     * bin/php/job.php took the job on: its history row is running.
     * @param array<string, mixed> $job
     */
    public static function markRunning( array $job ): void
    {
        if ( empty( $job[self::RUN_KEY] ) || !self::kernelHasHistory() )
            return;
        expKernelTransferHistory::update( (int)$job[self::RUN_KEY], array( 'state' => 'running', 'started' => time() ) );
    }

    // ------------------------------------------------------------ the history row

    /**
     * Writes one of Export's history rows (XrowExtractHistory::record()) into the kernel's run history too: the
     * row of the job (its run id in job.json, else the newest row of that job), or a new one for a run without a
     * job (a direct download, an install run from the command line, a skipped schedule run). Never throws.
     * @param array<string, mixed> $data the xrowextract_history row
     */
    public static function mirror( array $data ): int
    {
        if ( !self::kernelHasHistory() )
            return 0;
        try
        {
            $jobID = isset( $data['job_id'] ) ? (string)$data['job_id'] : '';
            $job = $jobID !== '' ? XrowExtractJob::load( $jobID ) : null;
            $info = self::historyRow( $data, $job );
            $owner = isset( $data['owner_login'] ) && $data['owner_login'] !== '' ? eZUser::fetchByName( (string)$data['owner_login'] ) : null;
            if ( $owner instanceof eZUser )
                $info['user_id'] = (int)$owner->attribute( 'contentobject_id' );
            if ( isset( $info['scope'] ) && $info['scope'] === 'class' && $job )
                $info['scope_ref'] = self::classIdentifier( $job );
            $runID = $job && !empty( $job[self::RUN_KEY] ) ? (int)$job[self::RUN_KEY] : 0;
            if ( !$runID && $jobID !== '' )
            {
                $row = expKernelTransferHistory::findByToolRef( self::TOOL, $jobID );
                $runID = $row ? (int)$row->attribute( 'id' ) : 0;
            }
            if ( $runID )
            {
                $state = $info['state'];
                unset( $info['state'] );
                expKernelTransferHistory::finish( $runID, $state, $info );
                return $runID;
            }
            return expKernelTransferHistory::record( self::TOOL, $info );
        }
        catch ( Throwable $e )
        {
            eZDebug::writeWarning( 'The run was not written to the transfer history: ' . $e->getMessage(), __METHOD__ );
            return 0;
        }
    }

    /**
     * The columns of a kernel history row for an Export history row (pure). $job: the job, when it still exists.
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $job
     * @return array<string, mixed>
     */
    public static function historyRow( array $data, ?array $job = null ): array
    {
        $kind = isset( $data['kind'] ) ? (string)$data['kind'] : '';
        $runState = isset( $data['run_state'] ) ? (string)$data['run_state'] : 'done';
        $jobID = isset( $data['job_id'] ) ? (string)$data['job_id'] : '';
        $cancelled = $job && !empty( $job['cancelled'] );
        $info = $job ? self::historyInfo( $job ) : array(
            'direction' => self::directionOfKind( $kind ),
            'kind' => self::directionOfKind( $kind ),
            'format' => isset( $data['output_format'] ) ? strtolower( (string)$data['output_format'] ) : '',
            'detail_url' => self::detailUrl( $jobID ),
        );
        if ( $kind === XrowExtractHistory::KIND_INSTALL )
        {
            $info['direction'] = 'import';
            $info['kind'] = $info['kind'] === 'dry_run' ? 'dry_run' : 'import';
            $info['format'] = 'ezpkg';
            $details = isset( $data['delivery'] ) && is_array( $data['delivery'] ) ? $data['delivery'] : array();
            if ( !empty( $details['parent_node_id'] ) )
            {
                $info['scope'] = 'subtree';
                $info['scope_ref'] = (string)(int)$details['parent_node_id'];
            }
        }
        $info['state'] = $cancelled ? 'cancelled' : ( $runState === 'failed' ? 'failed' : 'done' );
        $info['title'] = isset( $data['what'] ) ? (string)$data['what'] : '';
        if ( $jobID !== '' )
            $info['tool_ref'] = $jobID;
        foreach ( array( 'started_at' => 'started', 'ended_at' => 'finished', 'row_count' => 'rows_done', 'byte_size' => 'bytes' ) as $from => $to )
            if ( isset( $data[$from] ) && (int)$data[$from] > 0 )
                $info[$to] = (int)$data[$from];
        if ( !empty( $data['file_name'] ) && $kind !== XrowExtractHistory::KIND_INSTALL )
            $info['file_ref'] = basename( (string)$data['file_name'] );
        if ( $job && isset( $job['counts']['error'] ) && is_numeric( $job['counts']['error'] ) )
            $info['rows_failed'] = (int)$job['counts']['error'];
        if ( isset( $data['error_text'] ) && trim( (string)$data['error_text'] ) !== '' && $info['state'] !== 'done' )
            $info['error'] = mb_substr( trim( (string)$data['error_text'] ), 0, 1000 );
        $warnings = isset( $data['warnings'] ) && is_array( $data['warnings'] ) ? array_values( $data['warnings'] ) : array();
        if ( $kind === XrowExtractHistory::KIND_INSTALL && isset( $data['delivery']['errors'] ) )
            $info['errors'] = array_values( (array)$data['delivery']['errors'] );
        $detail = array_filter( array(
            'run_state' => $runState,
            'trigger' => isset( $data['trigger_type'] ) ? (string)$data['trigger_type'] : '',
            'schedule_id' => isset( $data['schedule_id'] ) ? (int)$data['schedule_id'] : 0,
            'run_mode' => isset( $data['run_mode'] ) ? (string)$data['run_mode'] : '',
            'preset' => isset( $data['preset_ref'] ) ? (string)$data['preset_ref'] : '',
            'delivery_state' => isset( $data['delivery_state'] ) ? (string)$data['delivery_state'] : '',
            'warnings' => array_slice( $warnings, 0, 20 ),
        ) );
        if ( $detail )
            $info['detail'] = $detail;
        return $info;
    }

    /**
     * The columns a job's history row starts with (pure): direction, kind, scope, scope_ref, format, tool_ref,
     * detail_url, file_ref when the name is known already. $classIdentifier: the class of a class export, when known.
     * @param array<string, mixed> $job
     * @param string $classIdentifier
     * @return array<string, mixed>
     */
    public static function historyInfo( array $job, $classIdentifier = '' ): array
    {
        $type = isset( $job['type'] ) ? (string)$job['type'] : 'csv';
        $args = self::args( $job );
        $install = $type === 'package' && isset( $args['install'] );
        $direction = in_array( $type, array( 'import', 'import_schedule' ), true ) || $install ? 'import' : 'export';
        $kind = $direction === 'import' && isset( $args['dry-run'] ) ? 'dry_run' : $direction;
        $info = array(
            'direction' => $direction,
            'kind' => $kind,
            'format' => $install ? 'ezpkg' : strtolower( isset( $job['format'] ) ? (string)$job['format'] : '' ),
            'tool_ref' => isset( $job['id'] ) ? (string)$job['id'] : '',
            'detail_url' => self::detailUrl( isset( $job['id'] ) ? (string)$job['id'] : '' ),
        );
        list( $scope, $ref ) = self::scopeOf( $type, $args );
        if ( $scope === 'class' && (string)$classIdentifier !== '' )
            $ref = (string)$classIdentifier;
        if ( $scope !== '' )
            $info['scope'] = $scope;
        if ( $ref !== '' )
            $info['scope_ref'] = $ref;
        if ( !$install && !empty( $job['output_file'] ) )
            $info['file_ref'] = basename( (string)$job['output_file'] );
        if ( isset( $job['what'] ) && $job['what'] !== '' )
            $info['title'] = (string)$job['what'];
        return $info;
    }

    /**
     * The scope of a job from its command line arguments (pure): array( scope, scope_ref ), scope one of site,
     * subtree, class, object or ''.
     * @param string $type
     * @param array<string, string|true> $args
     * @return array{0: string, 1: string}
     */
    public static function scopeOf( $type, array $args ): array
    {
        $first = function ( $value ) {
            $parts = array_filter( array_map( 'trim', explode( ',', (string)$value ) ), function ( $part ) { return $part !== ''; } );
            return $parts ? (string)reset( $parts ) : '';
        };
        if ( $type === 'package' && isset( $args['install'] ) )
            return isset( $args['parent'] ) ? array( 'subtree', $first( $args['parent'] ) ) : array( 'site', '' );
        if ( isset( $args['scope'] ) && $args['scope'] === 'all' )
            return array( 'site', '' );
        if ( $type === 'package' && isset( $args['node'] ) && !isset( $args['subtree'] ) )
            return array( 'object', $first( $args['node'] ) );
        if ( isset( $args['nodes'] ) )
            return array( 'subtree', $first( $args['nodes'] ) );
        if ( isset( $args['node'] ) )
            return array( 'subtree', $first( $args['node'] ) );
        if ( isset( $args['class'] ) )
            return array( 'class', $first( $args['class'] ) );
        if ( $type === 'archive' )
            return array( 'site', '' );
        return array( '', '' );
    }

    /**
     * A job's arguments as name => value (true for a flag) (pure).
     * @param array<string, mixed> $job
     * @return array<string, string|true>
     */
    public static function args( array $job ): array
    {
        $out = array();
        foreach ( isset( $job['args'] ) ? (array)$job['args'] : array() as $arg )
        {
            if ( !is_scalar( $arg ) || !preg_match( '/^--([a-z0-9-]+)(?:=(.*))?$/s', (string)$arg, $m ) )
                continue;
            if ( !isset( $out[$m[1]] ) )
                $out[$m[1]] = isset( $m[2] ) ? $m[2] : true;
        }
        return $out;
    }

    /**
     * The Jobs page of a job, or the History page for a run without one (pure).
     * @param string $jobID
     */
    public static function detailUrl( $jobID ): string
    {
        return XrowExtractJob::isValidID( $jobID ) ? 'xrowextract/jobs#job-' . $jobID : 'xrowextract/history';
    }

    /**
     * import or export for one of Export's history kinds (pure).
     * @param string $kind
     */
    public static function directionOfKind( $kind ): string
    {
        return in_array( (string)$kind, array( 'import', XrowExtractHistory::KIND_INSTALL ), true ) ? 'import' : 'export';
    }

    // ------------------------------------------------------------ the task's result

    /**
     * What the task returns for a job that has ended (pure): the history columns, state failed for a job that
     * failed. Null while the job has not ended.
     * @param array<string, mixed> $job
     * @return array<string, mixed>|null
     */
    public static function resultOf( array $job ): ?array
    {
        $state = isset( $job['state'] ) ? (string)$job['state'] : '';
        if ( !in_array( $state, array( 'done', 'skipped', 'failed' ), true ) )
            return null;
        $result = array( 'state' => $state === 'failed' ? 'failed' : 'done' );
        if ( isset( $job['rows'] ) && is_numeric( $job['rows'] ) )
            $result['rows_done'] = (int)$job['rows'];
        if ( isset( $job['counts']['error'] ) && is_numeric( $job['counts']['error'] ) )
            $result['rows_failed'] = (int)$job['counts']['error'];
        if ( isset( $job['size'] ) && is_numeric( $job['size'] ) )
            $result['bytes'] = (int)$job['size'];
        if ( !empty( $job['format'] ) )
            $result['format'] = strtolower( (string)$job['format'] );
        if ( !empty( $job['output_file'] ) && $state !== 'failed' )
            $result['file_ref'] = basename( (string)$job['output_file'] );
        if ( $state === 'failed' )
            $result['error'] = !empty( $job['error'] ) ? (string)$job['error'] : 'The job failed.';
        if ( $state === 'skipped' )
            $result['detail'] = array( 'run_state' => 'skipped', 'reason' => (string)$job['error'] );
        return $result;
    }

    /**
     * The progress of a running job (pure): array( done, total, message ) from its progress.json, else from the
     * progress bar its log shows, null when neither says anything yet.
     * @param array<string, mixed>|null $progress XrowExtractJob::readProgress()
     * @param array<string, mixed>|null $logProgress XrowExtractJob::logProgress()
     * @return array{0: int, 1: int|null, 2: string}|null
     */
    public static function progressOf( ?array $progress, ?array $logProgress = null ): ?array
    {
        foreach ( array( $progress, $logProgress ) as $source )
            if ( is_array( $source ) && isset( $source['done'] ) )
                return array( (int)$source['done'], isset( $source['total'] ) && (int)$source['total'] > 0 ? (int)$source['total'] : null,
                              isset( $source['phase'] ) ? (string)$source['phase'] : '' );
        return null;
    }

    /**
     * The class identifier of a class export (its --class= is the class id), '' when there is none.
     * @param array<string, mixed> $job
     */
    public static function classIdentifier( array $job ): string
    {
        $args = self::args( $job );
        if ( !isset( $args['class'] ) || $args['class'] === true )
            return '';
        $class = (string)$args['class'];
        if ( !ctype_digit( $class ) )
            return $class;
        try
        {
            $object = eZContentClass::fetch( (int)$class );
            return $object instanceof eZContentClass ? (string)$object->attribute( 'identifier' ) : $class;
        }
        catch ( Throwable $e )
        {
            return $class;
        }
    }
}
