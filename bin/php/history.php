#!/usr/bin/env php
<?php
/**
 * The export history from the command line (./console ext:xrowextract:history runs it too): every
 * background job, scheduled run and direct download, newest first, filtered and paged like the History page.
 *
 *   php extension/xrowextract/bin/php/history.php
 *   php extension/xrowextract/bin/php/history.php --state=failed --schedule=3 --from=2026-09-01 --limit=50 --offset=50
 *   php extension/xrowextract/bin/php/history.php --show=120 [--json]
 *   php extension/xrowextract/bin/php/history.php --clean                    (retention, as the cronjob part does it)
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/xrowextract/classes/runnable/commands/php_history.php (#207); this file is the entry point.
\Exponential\Command\Extension\Xrowextract\History::main( __FILE__ );
