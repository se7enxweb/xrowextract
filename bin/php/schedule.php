#!/usr/bin/env php
<?php
/**
 * Scheduled exports and imports from the command line (./console ext:xrowextract:schedule runs it too).
 *
 *   php extension/xrowextract/bin/php/schedule.php --list
 *   php extension/xrowextract/bin/php/schedule.php --show=3
 *   php extension/xrowextract/bin/php/schedule.php --run=3 [--mode=full|delta] [--wait]
 *   php extension/xrowextract/bin/php/schedule.php --run=3 --if-enabled        (what a generated crontab line runs)
 *   php extension/xrowextract/bin/php/schedule.php --enable=3 | --disable=3
 *   php extension/xrowextract/bin/php/schedule.php --cron [--wait]            (the cronjob part, by hand)
 *   php extension/xrowextract/bin/php/schedule.php --crontab                  (crontab lines for system cron)
 *   php extension/xrowextract/bin/php/schedule.php --create --name=Nightly --kind=preset --preset=site:hidden_content \
 *        --param=node=2 --frequency=daily --time=02:30 [--delta] [--destinations=1,2] [--owner=admin]
 *   php extension/xrowextract/bin/php/schedule.php --delete=3
 *   php extension/xrowextract/bin/php/schedule.php --next="30 2 * * 1-5"      (the next five run times of an expression)
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/xrowextract/classes/runnable/commands/php_schedule.php (#207); this file is the entry point.
\Exponential\Command\Extension\Xrowextract\Schedule::main( __FILE__ );
