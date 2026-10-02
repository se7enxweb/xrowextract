<?php

/**
 * xrowextract/browse_file/<PackageName>/<ViewIndex>: streams one raw file out
 * of a package's own directory - an image the browser shows inline
 * (design:xrowextract/browse.tpl's <img>), or anything else offered as a
 * plain download. <ViewIndex> is the file's position in
 * XrowExtractPackage::allPackageFiles()'s own sorted list, not its path (see
 * browse.php's own comment for why); XrowExtractPackage::packageFilePath()
 * is the one gate that actually reads it off disk: no path traversal, no
 * absolute path, no following a symlink out of the package's own directory.
 *
 * IMPORTANT: eZExecution::cleanExit() ends the request by throwing under
 * Velocity, so the send-and-exit below must never sit inside a
 * try/catch(Exception) - that would swallow the exit and the page gets
 * appended to the file (see modules/xrowextract/job_download.php, the same
 * shape this view follows).
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/browse_file.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\BrowseFile::main( __FILE__, get_defined_vars() );
