<?php
/**
 * xrowextract/jobs: background export jobs started from the "Run in the
 * background" button on the CSV and site archive views. Everyone sees their
 * own; a user with the policy xrowextract/all_jobs sees every job.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/jobs.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\Jobs::main( __FILE__, get_defined_vars() );
