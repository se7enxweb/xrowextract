<?php
/**
 * xrowextract/schedules: scheduled exports and imports. A schedule runs a saved preset, a site archive,
 * an Export as package or an import from a folder or destination, on a simple frequency or a cron
 * expression, in full or as a delta (only changes since the last successful run), and delivers the
 * file to its destinations. The cronjob part "xrowextract" starts the due ones (or system cron, with
 * the crontab lines shown here). Policy xrowextract/schedule; with xrowextract/all_jobs everyone's.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/schedules.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\Schedules::main( __FILE__, get_defined_vars() );
