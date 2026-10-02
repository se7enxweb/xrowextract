<?php

/**
 * xrowextract/compare/<PackageName>[/<OtherName>]: what differs.
 *   - With two packages (the second as a URL segment, or ?with=<name> from the picker): the classes and
 *     objects only in the first, only in the second, or in both with differences - matched by remote id,
 *     read from the packages' own XML (XrowExtractPackage::cachedComparison()).
 *   - With one: the package against this site - per class and object what installing it would create or
 *     change, down to the fields that differ, straight from the cached dry run the Package tab shows
 *     (XrowExtractPackage::cachedInspection(); no second inspect()).
 * Filters (what changes, class, a part of the name or remote id) and pages, all on the cached result;
 * links are query-only (href="?..."), so they keep the path and every filter.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/compare.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\Compare::main( __FILE__, get_defined_vars() );
