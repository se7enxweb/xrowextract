<?php
/**
 * xrowextract/history: every export run (background jobs started by hand, from the command line or a
 * schedule, and direct downloads), newest first, filtered (GET: state, kind, schedule, trigger,
 * delivery, from, to, text, owner) and paged (offset). Policy xrowextract/history; your own runs, or
 * everyone's with xrowextract/all_jobs. "Mark as seen" clears the failure badge of the Jobs tab.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/history.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\History::main( __FILE__, get_defined_vars() );
