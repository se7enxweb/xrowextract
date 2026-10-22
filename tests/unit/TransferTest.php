<?php

use PHPUnit\Framework\TestCase;

/**
 * Export in Exponential's transfer runs (XrowExtractTransfer, XrowExtractTransferTask): the history columns of a job
 * and of an Export history row, the scope read from a job's arguments, the task's result for each job state, the
 * progress, the payload the kernel's runner accepts, and the branch on a kernel without transfer runs.
 */
class TransferTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';

    private static function job( array $values = array() ): array
    {
        return $values + array( 'id' => self::ID, 'type' => 'csv', 'state' => 'queued', 'owner' => 'anna', 'what' => 'Articles',
                                'format' => 'CSV', 'output_file' => 'articles_export.csv', 'args' => array( '--class=2', '--node=2' ) );
    }

    public function testCsvExportOfASubtree(): void
    {
        $info = XrowExtractTransfer::historyInfo( self::job() );
        $this->assertSame( 'export', $info['direction'] );
        $this->assertSame( 'export', $info['kind'] );
        $this->assertSame( 'subtree', $info['scope'] );
        $this->assertSame( '2', $info['scope_ref'] );
        $this->assertSame( 'csv', $info['format'] );
        $this->assertSame( self::ID, $info['tool_ref'] );
        $this->assertSame( 'xrowextract/jobs#job-' . self::ID, $info['detail_url'] );
        $this->assertSame( 'articles_export.csv', $info['file_ref'] );
        $this->assertSame( 'Articles', $info['title'] );
    }

    public function testCsvExportOfTheWholeSiteAndOfAClass(): void
    {
        $site = XrowExtractTransfer::historyInfo( self::job( array( 'args' => array( '--class=2', '--scope=all' ) ) ) );
        $this->assertSame( 'site', $site['scope'] );
        $this->assertArrayNotHasKey( 'scope_ref', $site );
        $class = XrowExtractTransfer::historyInfo( self::job( array( 'args' => array( '--preset=x', '--class=2' ) ) ), 'article' );
        $this->assertSame( 'class', $class['scope'] );
        $this->assertSame( 'article', $class['scope_ref'] );
    }

    public function testArchiveAndPackageExports(): void
    {
        $archive = XrowExtractTransfer::historyInfo( self::job( array( 'type' => 'archive', 'format' => 'tar.gz', 'output_file' => null,
                                                                       'args' => array( '--nodes=43,44', '--classes=1,2' ) ) ) );
        $this->assertSame( array( 'export', 'subtree', '43', 'tar.gz' ), array( $archive['direction'], $archive['scope'], $archive['scope_ref'], $archive['format'] ) );
        $this->assertArrayNotHasKey( 'file_ref', $archive );
        $object = XrowExtractTransfer::historyInfo( self::job( array( 'type' => 'package', 'format' => 'ezpkg', 'args' => array( '--export', '--node=60' ) ) ) );
        $this->assertSame( array( 'export', 'object', '60' ), array( $object['direction'], $object['scope'], $object['scope_ref'] ) );
        $subtree = XrowExtractTransfer::historyInfo( self::job( array( 'type' => 'package', 'args' => array( '--export', '--node=60', '--subtree' ) ) ) );
        $this->assertSame( 'subtree', $subtree['scope'] );
    }

    public function testImportsAndInstallsAreImports(): void
    {
        $import = XrowExtractTransfer::historyInfo( self::job( array( 'type' => 'import', 'format' => 'json', 'args' => array( '--file=x.csv', '--class=article' ) ) ) );
        $this->assertSame( array( 'import', 'import', 'class', 'article' ), array( $import['direction'], $import['kind'], $import['scope'], $import['scope_ref'] ) );
        $dry = XrowExtractTransfer::historyInfo( self::job( array( 'type' => 'import', 'args' => array( '--file=x.csv', '--dry-run' ) ) ) );
        $this->assertSame( 'dry_run', $dry['kind'] );
        $install = XrowExtractTransfer::historyInfo( self::job( array( 'type' => 'package', 'format' => 'json', 'output_file' => 'install-report.json',
                                                                       'args' => array( '--install=demo', '--parent=2' ) ) ) );
        $this->assertSame( array( 'import', 'import', 'subtree', '2', 'ezpkg' ),
                           array( $install['direction'], $install['kind'], $install['scope'], $install['scope_ref'], $install['format'] ) );
        $this->assertArrayNotHasKey( 'file_ref', $install );
        $this->assertSame( 'import', XrowExtractTransfer::historyInfo( self::job( array( 'type' => 'import_schedule', 'args' => array() ) ) )['direction'] );
    }

    public function testTheHistoryRowOfARunWithoutAJob(): void
    {
        $row = XrowExtractTransfer::historyRow( array( 'job_id' => '', 'kind' => 'csv', 'what' => 'Articles', 'output_format' => 'XLSX',
                                                       'run_state' => 'warning', 'row_count' => 12, 'byte_size' => 3456, 'file_name' => 'a.csv',
                                                       'started_at' => 100, 'ended_at' => 160, 'trigger_type' => 'manual', 'warnings' => array( 'w' ) ) );
        $this->assertSame( 'done', $row['state'] );
        $this->assertSame( 'export', $row['direction'] );
        $this->assertSame( 'xrowextract/history', $row['detail_url'] );
        $this->assertSame( array( 12, 3456, 100, 160, 'a.csv' ), array( $row['rows_done'], $row['bytes'], $row['started'], $row['finished'], $row['file_ref'] ) );
        $this->assertArrayNotHasKey( 'tool_ref', $row );
        $this->assertSame( array( 'w' ), $row['detail']['warnings'] );
        $this->assertSame( 'warning', $row['detail']['run_state'] );
    }

    public function testTheHistoryRowOfAFailedAndOfACancelledJob(): void
    {
        $data = array( 'job_id' => self::ID, 'kind' => 'csv', 'run_state' => 'failed', 'error_text' => 'boom' );
        $failed = XrowExtractTransfer::historyRow( $data, self::job( array( 'state' => 'failed' ) ) );
        $this->assertSame( array( 'failed', 'boom', self::ID ), array( $failed['state'], $failed['error'], $failed['tool_ref'] ) );
        $cancelled = XrowExtractTransfer::historyRow( $data, self::job( array( 'state' => 'failed', 'cancelled' => true ) ) );
        $this->assertSame( 'cancelled', $cancelled['state'] );
        $skipped = XrowExtractTransfer::historyRow( array( 'run_state' => 'skipped', 'error_text' => 'gone' ) + $data, self::job() );
        $this->assertSame( 'done', $skipped['state'] );
        $this->assertArrayNotHasKey( 'error', $skipped );
    }

    public function testTheHistoryRowOfAnInstall(): void
    {
        $row = XrowExtractTransfer::historyRow( array( 'job_id' => '', 'kind' => XrowExtractHistory::KIND_INSTALL, 'run_state' => 'failed',
                                                       'file_name' => 'demo', 'error_text' => 'x',
                                                       'delivery' => array( 'parent_node_id' => 43, 'errors' => array( 'x' ) ) ) );
        $this->assertSame( array( 'import', 'import', 'ezpkg', 'subtree', '43', 'failed' ),
                           array( $row['direction'], $row['kind'], $row['format'], $row['scope'], $row['scope_ref'], $row['state'] ) );
        $this->assertSame( array( 'x' ), $row['errors'] );
        $this->assertArrayNotHasKey( 'file_ref', $row );
    }

    public function testTheTaskResultOfEachJobState(): void
    {
        $this->assertNull( XrowExtractTransfer::resultOf( self::job( array( 'state' => 'queued' ) ) ) );
        $this->assertNull( XrowExtractTransfer::resultOf( self::job( array( 'state' => 'running' ) ) ) );
        $done = XrowExtractTransfer::resultOf( self::job( array( 'state' => 'done', 'rows' => 7, 'size' => 900, 'counts' => array( 'error' => 2 ) ) ) );
        $this->assertSame( array( 'state' => 'done', 'rows_done' => 7, 'rows_failed' => 2, 'bytes' => 900, 'format' => 'csv',
                                  'file_ref' => 'articles_export.csv' ), $done );
        $failed = XrowExtractTransfer::resultOf( self::job( array( 'state' => 'failed', 'error' => 'disk full' ) ) );
        $this->assertSame( array( 'failed', 'disk full' ), array( $failed['state'], $failed['error'] ) );
        $this->assertArrayNotHasKey( 'file_ref', $failed );
        $skipped = XrowExtractTransfer::resultOf( self::job( array( 'state' => 'skipped', 'error' => 'gone' ) ) );
        $this->assertSame( 'done', $skipped['state'] );
        $this->assertSame( 'skipped', $skipped['detail']['run_state'] );
    }

    public function testProgress(): void
    {
        $this->assertNull( XrowExtractTransfer::progressOf( null, null ) );
        $this->assertSame( array( 5, 10, 'article' ), XrowExtractTransfer::progressOf( array( 'done' => 5, 'total' => 10, 'phase' => 'article' ) ) );
        $this->assertSame( array( 3, null, '' ), XrowExtractTransfer::progressOf( array( 'done' => 3, 'total' => 0 ) ) );
        $this->assertSame( array( 40, 100, 'Installing' ), XrowExtractTransfer::progressOf( null, array( 'done' => 40, 'total' => 100, 'phase' => 'Installing' ) ) );
    }

    public function testTheJobPatchAndTheEngineCheck(): void
    {
        $patch = XrowExtractTransfer::jobPatch( array( 'engine' => 'expqueue', 'job_ref' => 'u-1', 'run_id' => '12' ) );
        $this->assertSame( array( 'transfer_engine' => 'expqueue', 'transfer_job_ref' => 'u-1', 'transfer_run_id' => 12 ), $patch );
        $this->assertTrue( XrowExtractTransfer::runsInEngine( self::job( $patch ) ) );
        $this->assertTrue( XrowExtractTransfer::runsInEngine( self::job( array( 'transfer_engine' => 'contentjob' ) ) ) );
        $this->assertFalse( XrowExtractTransfer::runsInEngine( self::job( array( 'transfer_engine' => 'process' ) ) ) );
        $this->assertFalse( XrowExtractTransfer::runsInEngine( self::job() ) );
        $this->assertSame( array( 'job' => self::ID ), XrowExtractTransfer::payload( self::ID ) );
    }

    public function testArgsAndDetailUrl(): void
    {
        $this->assertSame( array( 'class' => '2', 'subtree' => true, 'param' => 'a=b' ),
                           XrowExtractTransfer::args( array( 'args' => array( '--class=2', '--subtree', '--param=a=b', '--param=c=d', 'junk', 7 ) ) ) );
        $this->assertSame( 'xrowextract/history', XrowExtractTransfer::detailUrl( '../etc' ) );
        $this->assertSame( array( 'class', '' ), XrowExtractTransfer::scopeOf( 'csv', array( 'class' => '' ) ) );
        $this->assertSame( array( '', '' ), XrowExtractTransfer::scopeOf( 'import', array() ) );
    }

    public function testTheKernelBranch(): void
    {
        $hasKernel = class_exists( 'expKernelTransferRunner' ) && interface_exists( 'expKernelTransferTask' );
        // the task class is declared exactly when the kernel has the interface
        $this->assertSame( interface_exists( 'expKernelTransferTask' ), class_exists( 'XrowExtractTransferTask' ) );
        $this->assertSame( $hasKernel, XrowExtractTransfer::kernelHasRunner() );
        if ( !$hasKernel )
        {
            // an older kernel: start() launches the job as before, cancel() and mirror() do nothing
            $this->assertNull( XrowExtractTransfer::submit( self::ID, self::job() ) );
            $this->assertSame( 0, XrowExtractTransfer::mirror( array( 'job_id' => '' ) ) );
            return;
        }
        $this->assertSame( '', expKernelTransferRunner::payloadProblem( array(
            'tool' => XrowExtractTransfer::TOOL, 'task' => XrowExtractTransfer::TASK, 'params' => XrowExtractTransfer::payload( self::ID ), 'run_id' => 0 ) ) );
        $this->assertSame( array( '/usr/bin/php', '/x/job.php', '--run=' . self::ID, '--allow-root-user' ),
                           XrowExtractTransferTask::command( '/usr/bin/php', '/x/job.php', self::ID, true ) );
        $this->assertSame( array( '/usr/bin/php', '/x/job.php', '--run=' . self::ID ), XrowExtractTransferTask::command( '/usr/bin/php', '/x/job.php', self::ID, false ) );
        // every column the mapping writes passes the kernel's normalisation unchanged
        $row = XrowExtractTransfer::historyRow( array( 'job_id' => self::ID, 'kind' => 'csv', 'run_state' => 'done', 'row_count' => 3 ), self::job() );
        unset( $row['detail'] );
        $this->assertSame( $row, array_intersect_key( expKernelTransferRun::normalize( $row ), $row ) );
    }

    public function testTheSettingsDeclareTheQueueJobType(): void
    {
        $queue = eZINI::instance( 'expqueue.ini' );
        $this->assertSame( 'expKernelTransferQueueHandler', $queue->variable( 'JobType_xrowextract.run', 'Handler' ) );
        $this->assertSame( '1', $queue->variable( 'JobType_xrowextract.run', 'MaxAttempts' ) );
        $this->assertSame( 'bulk', $queue->variable( 'JobType_xrowextract.run', 'Class' ) );
        $services = eZINI::instance( 'kernelservices.ini' );
        $this->assertSame( 'xrowextract.run', $services->variable( 'Service_transfer.xrowextract', 'QueueJobType' ) );
        $this->assertSame( 'xrowextract/all_jobs', $services->variable( 'Service_transfer.xrowextract', 'HistoryAllPermission' ) );
    }
}
