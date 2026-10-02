<?php

/**
 * Content-package (.ezpkg) support: upload or pick a package already in the
 * repository, inspect it (a dry run: what it carries and what would happen,
 * nothing written), install it through the kernel package system, or build a
 * rich sample "content + class" package for a chosen class.
 *
 * The heavy lifting (parsing, matching, installing, sample generation) is in
 * XrowExtractPackage; this view is only the HTTP/session plumbing, the same
 * split xrowextract/import.php uses for XrowExtractImport.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/package.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\Package::main( __FILE__, get_defined_vars() );
