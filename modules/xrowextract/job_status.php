<?php
/**
 * xrowextract/job_status/<id>: JSON for the Jobs page's 2 second poll while
 * a job is queued or running: { id, state, rows, size, error, started,
 * ended, progress: { done, total, phase } | null }.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/job_status.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\JobStatus::main( __FILE__, get_defined_vars() );
