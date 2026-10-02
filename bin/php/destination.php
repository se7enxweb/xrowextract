#!/usr/bin/env php
<?php
/**
 * Delivery destinations from the command line (./console ext:xrowextract:destination runs it too).
 * Secret values are never printed; they are given through an environment variable or a file, never
 * on the command line itself (which the process list and the shell history would show).
 *
 *   php extension/xrowextract/bin/php/destination.php --list [--json]
 *   php extension/xrowextract/bin/php/destination.php --test=2
 *   php extension/xrowextract/bin/php/destination.php --scan-host-key=2            (SFTP: show the server's host keys)
 *   php extension/xrowextract/bin/php/destination.php --trust-host-key=2 --fingerprint=SHA256:...
 *   php extension/xrowextract/bin/php/destination.php --create --name=Partner --type=sftp \
 *        --config=host=sftp.example.com --config=user=exp --config=path=/incoming --config=auth=key \
 *        --secret-file=private_key=/root/.ssh/partner_ed25519
 *   php extension/xrowextract/bin/php/destination.php --update=2 --secret-env=password=PARTNER_PASSWORD
 *   php extension/xrowextract/bin/php/destination.php --update=2 --clear-secret=password
 *   php extension/xrowextract/bin/php/destination.php --send=2 --file=var/export.csv  (deliver a file by hand)
 *   php extension/xrowextract/bin/php/destination.php --delete=2
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/xrowextract/classes/runnable/commands/php_destination.php (#207); this file is the entry point.
\Exponential\Command\Extension\Xrowextract\Destination::main( __FILE__ );
