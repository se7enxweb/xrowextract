<?php
/**
 * xrowextract/job_download/<id>: streams a finished background job's file.
 *
 * IMPORTANT: eZExecution::cleanExit() ends the request by throwing under
 * Velocity, so the send-and-exit below must never sit inside a
 * try/catch(Exception) -- that would swallow the exit and the page gets
 * appended to the file.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/job_download.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\JobDownload::main( __FILE__, get_defined_vars() );
