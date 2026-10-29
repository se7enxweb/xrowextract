<?php
/**
 * xrowextract/subtree_classes/<node id>: JSON for the content tree's Advanced > Export > "Subtree of class" list:
 * { node, classes: [ { id, identifier, name, count } ], more, total }, the classes with objects below a node the
 * user can read, counted with the user's read access. Read only.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */

// The code is in extension/xrowextract/classes/runnable/views/xrowextract/subtree_classes.php; this file is the entry point.
return \Exponential\View\Extension\Xrowextract\Xrowextract\SubtreeClasses::main( __FILE__, get_defined_vars() );
