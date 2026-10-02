#!/usr/bin/env php
<?php
/**
 * Content packages (.ezpkg) from the command line: everything the
 * xrowextract/package view does (list, inspect, install, build a sample
 * template) plus a plain node/subtree export, with the same code
 * (XrowExtractPackage) so behaviour cannot drift from the web view.
 *
 * Usage (from the installation root; ./console ext:xrowextract:package runs it too):
 *   php extension/xrowextract/bin/php/package.php --list
 *   php extension/xrowextract/bin/php/package.php --inspect=my_package
 *   php extension/xrowextract/bin/php/package.php --install=my_package --parent=2 --dry-run
 *   php extension/xrowextract/bin/php/package.php --install=my_package --parent=2 --site-access=site --object-mode=update --class-mode=skip
 *   php extension/xrowextract/bin/php/package.php --export --node=130 --file=var/tmp/ng_news_130.ezpkg
 *   php extension/xrowextract/bin/php/package.php --export --node=2 --subtree --class=ng_article --file=var/tmp/articles.ezpkg
 *   php extension/xrowextract/bin/php/package.php --template --class=ng_article --variant=both --file=var/tmp/ng_article_template.ezpkg
 *   php extension/xrowextract/bin/php/package.php --export --node=2 --class=ng_article --since=30d --section=standard --file=var/tmp/recent.ezpkg
 *   php extension/xrowextract/bin/php/package.php --export --preset=site:news_last_30_days --file=var/tmp/news.ezpkg
 *   php extension/xrowextract/bin/php/package.php --compare=package_a --with=package_b
 *   php extension/xrowextract/bin/php/package.php --compare=package_a
 *
 * --export with any of the filter options of ext:xrowextract:csv (--since, --before, --date, --section,
 * --state, --visibility, --where, --sort, --depth, --languages, --extended-filter, --fetch-alias, ...) or
 * with --preset runs exactly that export (bin/php/csv.php --format=ezpkg): one class, every filter the
 * same as there. Only the name filter is spelled --name-contains here (--name is the package's name).
 *
 * --output writes a JSON report alongside the normal text output, for
 * --inspect and --install: how xrowextract/jobs.php (type "package") runs a
 * large package inspect/install in the background and reports it, through
 * bin/php/job.php the same way a csv/archive job does. --progress-file gets
 * a couple of coarse phase updates (eZPackage::install() has no natural
 * per-item hook to report finer progress from without patching the kernel).
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/xrowextract/classes/runnable/commands/php_package.php (#207); this file is the entry point.
\Exponential\Command\Extension\Xrowextract\Package::main( __FILE__ );
