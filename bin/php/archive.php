#!/usr/bin/env php
<?php
/**
 * The site archive from the command line: everything the xrowextract/archive
 * view does (node sets or single nodes, classes, archive format, separator,
 * line endings, quoting), with the same code. One CSV per class, a
 * manifest.json and a README.txt, packed as zip, tar.gz, tar.bz2, tar.xz,
 * 7z or rar.
 *
 * Usage (from the installation root; ./console ext:xrowextract:archive runs it too):
 *   php extension/xrowextract/bin/php/archive.php                                  (content and media, all classes, zip)
 *   php extension/xrowextract/bin/php/archive.php --set=everything --format=tar.xz --output=var/backups/
 *   php extension/xrowextract/bin/php/archive.php --nodes=2,43 --exclude-classes=image,file
 *   php extension/xrowextract/bin/php/archive.php --nodes=89 --classes=ng_article,ng_blog_post --separator=semicolon
 *   php extension/xrowextract/bin/php/archive.php --dry-run --set=content
 *   php extension/xrowextract/bin/php/archive.php --list-sets | --list-formats | --list-classes --set=content_media
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/xrowextract/classes/runnable/commands/php_archive.php (#207); this file is the entry point.
\Exponential\Command\Extension\Xrowextract\Archive::main( __FILE__ );
