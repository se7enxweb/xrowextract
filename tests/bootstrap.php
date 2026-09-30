<?php
/**
 * PHPUnit bootstrap for the xrowextract unit tests.
 *
 * Loads the Exponential kernel classes of the root named by EXPONENTIAL_ROOT
 * (default: the installation this checkout is installed in, two levels up) and
 * this checkout's own classes ahead of any installed copy. Nothing is booted:
 * no database, no siteaccess, no session. The tests are hermetic:
 *   - settings come from the root's settings/ and this checkout's settings/
 *     only, never from settings/override or a siteaccess;
 *   - the INI cache and every log file are off, so nothing is written into
 *     the installation;
 *   - whatever a test writes goes below this checkout's var/tests/.
 *
 * EXPONENTIAL_VENDOR_DIR may name a Composer vendor directory with the Zeta
 * Components for a root that has no vendor/ of its own.
 */

$checkout = dirname( __DIR__ );
$root = getenv( 'EXPONENTIAL_ROOT' );
if ( !is_string( $root ) || $root === '' )
{
    $root = dirname( $checkout, 2 );
}
$root = rtrim( $root, '/' );
if ( !is_file( $root . '/autoload.php' ) || !is_file( $root . '/autoload/ezp_kernel.php' ) )
{
    fwrite( STDERR, "xrowextract tests: no Exponential root at '$root'. Set EXPONENTIAL_ROOT.\n" );
    exit( 1 );
}

define( 'XROWEXTRACT_TEST_CHECKOUT', $checkout );
define( 'XROWEXTRACT_TEST_VAR', $checkout . '/var/tests' );
if ( !is_dir( XROWEXTRACT_TEST_VAR ) )
{
    mkdir( XROWEXTRACT_TEST_VAR, 0700, true );
}

// This checkout's classes first, so an installed copy of the extension (listed in the root's generated
// extension autoload map) never answers for them
$ownClasses = array();
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $checkout . '/classes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file )
{
    if ( substr( $file->getFilename(), -4 ) !== '.php' )
    {
        continue;
    }
    if ( preg_match_all( '/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m', (string)file_get_contents( $file->getPathname() ), $m ) )
    {
        foreach ( $m[1] as $class )
        {
            $ownClasses[strtolower( $class )] = $file->getPathname();
        }
    }
}
$ownClasses['xrowextractinfo'] = $checkout . '/ezinfo.php';
spl_autoload_register( static function ( $class ) use ( $ownClasses )
{
    $key = strtolower( $class );
    if ( isset( $ownClasses[$key] ) )
    {
        require_once $ownClasses[$key];
    }
}, true, true );

chdir( $root );
require_once $root . '/autoload.php';
$vendorDir = getenv( 'EXPONENTIAL_VENDOR_DIR' );
if ( is_string( $vendorDir ) && $vendorDir !== '' && is_file( $vendorDir . '/autoload.php' ) )
{
    require_once $vendorDir . '/autoload.php';
}

// No log file, not even for the levels the kernel always logs
$GLOBALS['eZDebugLogFileEnabled'] = false;
$GLOBALS['eZDebugAlwaysLog'] = array( eZDebug::LEVEL_NOTICE => false, eZDebug::LEVEL_WARNING => false, eZDebug::LEVEL_ERROR => false,
                                      eZDebug::LEVEL_DEBUG => false, eZDebug::LEVEL_STRICT => false );

// Settings: the root's settings/ plus this checkout's settings/, nothing from override or a siteaccess
eZINI::setIsCacheEnabled( false );
eZINI::instance()->setOverrideDirs( array(
    'sa-extension' => array(),
    'siteaccess' => array(),
    'extension' => array( 'xrowextract' => array( $checkout . '/settings', true ) ),
    'override' => array(),
) );
eZINI::resetAllInstances( false );

// The secrets key of the tests, never the installation's
eZINI::instance( 'xrowextract.ini' )->setVariable( 'Secrets', 'KeyFile', XROWEXTRACT_TEST_VAR . '/secrets.key' );
