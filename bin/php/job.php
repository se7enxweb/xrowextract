#!/usr/bin/env php
<?php
/**
 * Runs, lists or cleans up background export jobs (xrowextract/csv and
 * xrowextract/archive, started from the "Run in the background" button).
 * A web request never waits on this: it launches
 *   php extension/xrowextract/bin/php/job.php --run=<id>
 * detached and returns at once; this script carries the export out and
 * updates the job's job.json as it goes.
 *
 * Usage (from the installation root; ./console ext:xrowextract:job runs it too):
 *   php extension/xrowextract/bin/php/job.php --run=3f9c...
 *   php extension/xrowextract/bin/php/job.php --list
 *   php extension/xrowextract/bin/php/job.php --clean
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/xrowextract/classes/runnable/commands/php_job.php (#207); this file is the entry point.
\Exponential\Command\Extension\Xrowextract\Job::main( __FILE__ );
