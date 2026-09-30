<?php
/**
 * The requirements check (XrowExtractRequirements) of this checkout, for bin/check.sh (part "req") where no
 * installation with this checkout in it is at hand: the kernel classes come from EXPONENTIAL_ROOT as in the
 * unit tests (tests/bootstrap.php, nothing booted, nothing written into the installation). The folders
 * checked are that root's. With an installation, bin/php/requirements.php checks the real thing.
 *
 * Usage: EXPONENTIAL_ROOT=... php tests/tools/check_requirements.php [feature,...]
 * Prints the report (PASS/FAIL/WARN per requirement, the features, a summary); exit code 1 when a required
 * requirement is missing, or one of the features named is not available.
 */

if ( PHP_SAPI !== 'cli' )
{
    exit( 1 );
}

require dirname( __DIR__ ) . '/bootstrap.php';

$report = XrowExtractRequirements::check();
$exitCode = $report['ok'] ? 0 : 1;
foreach ( XrowExtractRequirements::reportLines( $report ) as $line )
{
    echo $line, "\n";
}
foreach ( array_filter( array_map( 'trim', explode( ',', $argv[1] ?? '' ) ) ) as $feature )
{
    $available = isset( $report['features'][$feature] ) && $report['features'][$feature]['available'];
    echo ( $available ? 'PASS' : 'FAIL' ), ' feature ', $feature, "\n";
    if ( !$available )
    {
        $exitCode = 1;
    }
}
exit( $exitCode );
