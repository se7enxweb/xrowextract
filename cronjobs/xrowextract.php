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
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/cronjobs/xrowextract.php (#207); this file is the entry point.
return \Exponential\Cronjob\Extension\Xrowextract\Xrowextract::main( __FILE__, get_defined_vars() );
