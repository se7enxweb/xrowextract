#!/usr/bin/env php
<?php
/**
 * One class as a CSV file, from the command line: everything the
 * xrowextract/csv view does (node or whole site, depth, main locations,
 * offset/limit, columns and their names, separator, line endings, quoting,
 * a preview), with the same code.
 *
 * Usage (from the installation root; ./console ext:xrowextract:csv runs it too):
 *   php extension/xrowextract/bin/php/csv.php --class=ng_article --node=2 --output=articles.csv
 *   php extension/xrowextract/bin/php/csv.php --class=image --scope=all --separator=';' --line-endings=win32
 *   php extension/xrowextract/bin/php/csv.php --class=4 --node=5 --columns=first_name,last_name,ezuser.email
 *   php extension/xrowextract/bin/php/csv.php --class=ng_article --node=2 --preview=10
 *   php extension/xrowextract/bin/php/csv.php --list-classes --node=2
 *   php extension/xrowextract/bin/php/csv.php --list-columns --class=ng_article
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/xrowextract/classes/runnable/commands/php_csv.php (#207); this file is the entry point.
\Exponential\Command\Extension\Xrowextract\Csv::main( __FILE__ );
