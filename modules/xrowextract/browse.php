<?php

/**
 * xrowextract/browse/<PackageName>/<Offset>/<ViewIndex>: the package contents
 * browser (#26). Every file a package carries, paginated (no limit on the
 * list itself - only how much of it one page shows), each one viewable: an
 * .xml item pretty-printed, an image shown inline, a small text file shown
 * as text, anything else only offered as a download (xrowextract/browse_file).
 *
 * <Offset> and <ViewIndex> are both plain integers, like every other
 * xrowextract URL param (JobID aside, everything here is a name or a
 * number) - never the file's own path. A relative path can carry slashes of
 * its own ("ezcontentobject/abc123.xml"), which a bare URL segment cannot
 * hold safely without an encoding step this codebase does not otherwise use
 * anywhere (every other link here is concat(...)|ezurl over plain names and
 * ids). <ViewIndex> is instead the file's position in allPackageFiles()'s
 * own sorted list - deterministic and stable for as long as the package's
 * files do not change, which for an installed or repository package is for
 * as long as it exists. xrowextract/browse_file uses the same scheme.
 *
 * Reachable from three places, all through the same data
 * (XrowExtractPackage::allPackageFiles()) and the same row markup
 * (design:xrowextract/package_files_rows.tpl):
 *   - this page itself, the full paginated browser;
 *   - a short preview embedded on the Import page and the Package tab
 *     (design:xrowextract/package_files_preview.tpl), "Browse all N files"
 *     linking here for the rest;
 *   - a link added to the kernel's own package/view/full/<name>
 *     (extension/xrowextract/design/standard/override/templates/package/view/full.tpl).
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/browse.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\Browse::main( __FILE__, get_defined_vars() );
