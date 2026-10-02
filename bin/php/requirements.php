#!/usr/bin/env php
<?php
/**
 * What xrowextract needs from PHP and the server, checked on this one (./console ext:xrowextract:requirements
 * runs it too): PASS or FAIL per requirement (WARN for one that is missing but not required: only the features
 * it names are unavailable), the features, and a summary line.
 *
 *   php extension/xrowextract/bin/php/requirements.php
 *   php extension/xrowextract/bin/php/requirements.php --feature=package,jobs   (exit 1 unless both are available)
 *   php extension/xrowextract/bin/php/requirements.php --strict                 (exit 1 when anything is missing)
 *   php extension/xrowextract/bin/php/requirements.php --json
 *
 * Exit code 0 when every required requirement is there (and, with --feature or --strict, what those ask for),
 * 1 otherwise, 2 for an unknown feature. Run it as the user the web server runs as: writable folders and
 * disabled functions depend on the user and on the PHP configuration (the command line one can differ from
 * the web server's).
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/xrowextract/classes/runnable/commands/php_requirements.php (#207); this file is the entry point.
\Exponential\Command\Extension\Xrowextract\Requirements::main( __FILE__ );
