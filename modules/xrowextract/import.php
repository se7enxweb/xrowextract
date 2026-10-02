<?php

/**
 * Imports a CSV or JSON file (as XrowExtractWriter writes it) back into
 * content objects: upload, choose the class and matching, map columns, a
 * dry run preview, then apply. Plain full-page post backs (no AJAX): every
 * step re-posts the whole form, the uploaded file itself stays on disk in
 * the private upload folder between steps.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/import.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\Import::main( __FILE__, get_defined_vars() );
