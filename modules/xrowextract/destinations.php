<?php
/**
 * xrowextract/destinations: named, reusable delivery destinations (SFTP, FTP/FTPS, a local or NAS
 * folder, S3 compatible storage, WebDAV, HTTP POST). Policy xrowextract/destinations. Secrets are
 * write only: the form offers set, replace or clear, and never puts a stored value into the page.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/destinations.php (#207); this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\Destinations::main( __FILE__, get_defined_vars() );
