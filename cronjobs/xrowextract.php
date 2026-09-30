<?php
/**
 * The xrowextract cronjob part (runcronjobs.php xrowextract): starts every due schedule as a background
 * job through the same runner "Run in the background" uses (XrowExtractJob, bin/php/job.php), then
 * removes job folders and history rows past their retention.
 *
 *   php runcronjobs.php xrowextract
 *   crontab: *\/5 * * * * cd /path/to/exponential && php runcronjobs.php xrowextract >/dev/null 2>&1
 *
 * Manually, with the same result: php extension/xrowextract/bin/php/schedule.php --cron
 */

$log = function ( $line ) use ( $cli, $isQuiet )
{
    if ( !$isQuiet )
        $cli->output( 'xrowextract: ' . $line );
};
$stats = XrowExtractScheduler::runDue( time(), $log );
if ( !$isQuiet )
{
    $cli->output( sprintf( 'xrowextract: %d started, %d skipped, %d still running, %d job folder(s) and %d history row(s) cleaned',
                           $stats['started'], $stats['skipped'], $stats['busy'], $stats['cleaned_jobs'], $stats['cleaned_history'] ) );
}

?>
