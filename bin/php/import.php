#!/usr/bin/env php
<?php
/**
 * Reads an XML, CSV or JSON file (as XrowExtractWriter writes it, any column
 * set, in particular "migration") back into content objects: the other
 * direction of bin/php/csv.php. A dry run (the default) never touches the
 * database. Streams the file (constant memory for XML and CSV, and for JSON
 * up to csv.ini [Uploads] JsonOneShotThresholdMB), so there is no file size
 * limit here beyond real disk space and time.
 *
 * Usage (from the installation root; ./console ext:xrowextract:import runs it too):
 *   php extension/xrowextract/bin/php/import.php --file=articles.xml --class=ng_article --parent=2
 *   php extension/xrowextract/bin/php/import.php --file=articles.xml --class=ng_article --parent=2 --apply
 *   php extension/xrowextract/bin/php/import.php --file=export.csv --match=object_id --language=ger-DE
 *   php extension/xrowextract/bin/php/import.php --file=export.csv --map=title=title,authors-ids=authors:ids
 *   php extension/xrowextract/bin/php/import.php --file=big.xml --class=ng_article --parent=2 --apply --background
 *   php extension/xrowextract/bin/php/import.php --file=big.xml --class=ng_article --parent=2 --apply --resume-from=40001
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/xrowextract/classes/runnable/commands/php_import.php (#207); this file is the entry point.
\Exponential\Command\Extension\Xrowextract\Import::main( __FILE__ );
