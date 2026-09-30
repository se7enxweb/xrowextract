<?php
/**
 * PHPStan bootstrap: makes the Exponential kernel and library classes known to
 * the analysis without booting the kernel.
 *
 * The Exponential root is taken from the EXPONENTIAL_ROOT environment variable.
 * Without it, the installation this extension is installed in is used
 * (extension/xrowextract lives two levels below the root). CI fetches
 * se7enxweb/exponential and points EXPONENTIAL_ROOT at that checkout.
 *
 * Only the kernel autoload map (autoload/ezp_kernel.php) is registered, never
 * the generated extension map: that one also lists this extension's own
 * classes, from the installed copy, which would shadow the code under analysis.
 * The Zeta Components are registered from a Composer classmap (see below).
 */

$root = getenv( 'EXPONENTIAL_ROOT' );
if ( !is_string( $root ) || $root === '' )
{
    $root = dirname( __DIR__, 4 );
}
$root = rtrim( $root, '/' );
if ( !is_file( $root . '/autoload/ezp_kernel.php' ) )
{
    fwrite( STDERR, "PHPStan bootstrap: no Exponential root at '$root' (autoload/ezp_kernel.php missing). Set EXPONENTIAL_ROOT.\n" );
    exit( 1 );
}

$kernelClasses = require $root . '/autoload/ezp_kernel.php';
spl_autoload_register( static function ( $class ) use ( $root, $kernelClasses )
{
    if ( isset( $kernelClasses[$class] ) && is_file( $root . '/' . $kernelClasses[$class] ) )
    {
        require_once $root . '/' . $kernelClasses[$class];
    }
} );

// The Zeta Components (ezc*) come from a Composer classmap: the root's vendor/,
// or the vendor directory named by EXPONENTIAL_VENDOR_DIR for a checkout that
// has none. Only the ezc* entries are registered, never vendor/autoload.php:
// that would load the root's own nikic/php-parser into PHPStan's process, and
// PHPStan then parses closures with the wrong parser version.
foreach ( array( $root . '/vendor', (string)getenv( 'EXPONENTIAL_VENDOR_DIR' ) ) as $vendorDir )
{
    $classMapFile = $vendorDir . '/composer/autoload_classmap.php';
    if ( $vendorDir === '' || !is_file( $classMapFile ) )
    {
        continue;
    }
    $zetaClasses = array();
    foreach ( require $classMapFile as $class => $file )
    {
        if ( strncmp( $class, 'ezc', 3 ) === 0 )
        {
            $zetaClasses[$class] = $file;
        }
    }
    spl_autoload_register( static function ( $class ) use ( $zetaClasses )
    {
        if ( isset( $zetaClasses[$class] ) )
        {
            require_once $zetaClasses[$class];
        }
    } );
}

// Constants the kernel defines while it boots (index.php, autoload.php,
// config.php); the analysis never boots it, so they are declared here.
if ( !defined( 'EXP_ROOT_DIR' ) )
{
    define( 'EXP_ROOT_DIR', $root );
}
