<?php
/**
 * The code of extension/xrowextract/cronjobs/xrowextract.php, moved into a class (#207 stage 1). The file extension/xrowextract/cronjobs/xrowextract.php is one call to it.
 * Guide: doc/bc/6.0/cli_cronjob_view_abstractions.md
 */
/*
 * The original header of extension/xrowextract/cronjobs/xrowextract.php:
 *
 *
 * The xrowextract cronjob part (runcronjobs.php xrowextract): starts every due schedule as a background
 * job through the same runner "Run in the background" uses (XrowExtractJob, bin/php/job.php), then
 * removes job folders and history rows past their retention.
 *
 *   php runcronjobs.php xrowextract
 *   crontab: *\/5 * * * * cd /path/to/exponential && php runcronjobs.php xrowextract >/dev/null 2>&1
 *
 * Manually, with the same result: php extension/xrowextract/bin/php/schedule.php --cron
 *
 */

namespace Exponential\Cronjob\Extension\Xrowextract
{

class Xrowextract extends \Exponential\Runnable\CronjobPart
{
    public function run( array $scope )
    {
        // the including function's variables ($Params, $Module, $cli, ...)
        foreach ( array_keys( $scope ) as $__name )
            if ( $__name !== 'this' && $__name !== 'scope' )
                ${$__name} = &$scope[$__name];
        unset( $__name );

        $log = function ( $line ) use ( $cli, $isQuiet )
        {
            if ( !$isQuiet )
                $cli->output( 'xrowextract: ' . $line );
        };
        $stats = \XrowExtractScheduler::runDue( time(), $log );
        if ( !$isQuiet )
        {
            $cli->output( sprintf( 'xrowextract: %d started, %d skipped, %d still running, %d job folder(s) and %d history row(s) cleaned',
                                   $stats['started'], $stats['skipped'], $stats['busy'], $stats['cleaned_jobs'], $stats['cleaned_history'] ) );
        }
    }
}

}
