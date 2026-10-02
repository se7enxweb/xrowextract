<?php

/**
 * xrowextract/upload_chunk: the chunked upload endpoint XrowExtractUploadJS
 * talks to. Same policy function as xrowextract/import (see module.php), so
 * whoever may use the import view may upload to it; the eZ session and the
 * global form-token check (ezformtoken, X-CSRF-Token header for XHR) apply
 * exactly as they do to every other POST on this site. Always answers JSON.
 *
 * Actions (POST "Action"): start | chunk | status.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/upload_chunk.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\UploadChunk::main( __FILE__, get_defined_vars() );
