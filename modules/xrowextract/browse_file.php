<?php

/**
 * xrowextract/browse_file/<PackageName>/<ViewIndex>: streams one raw file out
 * of a package's own directory - an image the browser shows inline
 * (design:xrowextract/browse.tpl's <img>), or anything else offered as a
 * plain download. <ViewIndex> is the file's position in
 * XrowExtractPackage::allPackageFiles()'s own sorted list, not its path (see
 * browse.php's own comment for why); XrowExtractPackage::packageFilePath()
 * is the one gate that actually reads it off disk: no path traversal, no
 * absolute path, no following a symlink out of the package's own directory.
 *
 * IMPORTANT: eZExecution::cleanExit() ends the request by throwing under
 * Velocity, so the send-and-exit below must never sit inside a
 * try/catch(Exception) - that would swallow the exit and the page gets
 * appended to the file (see modules/xrowextract/job_download.php, the same
 * shape this view follows).
 */

$packageName = isset( $Params['PackageName'] ) ? (string)$Params['PackageName'] : '';
$viewIndex = isset( $Params['ViewIndex'] ) && ctype_digit( (string)$Params['ViewIndex'] ) ? (int)$Params['ViewIndex'] : -1;

$package = $packageName !== '' ? eZPackage::fetch( $packageName ) : false;
$fileRow = null;
if ( $package instanceof eZPackage && $viewIndex >= 0 )
{
    $allFiles = XrowExtractPackage::allPackageFiles( $package );
    $fileRow = isset( $allFiles[$viewIndex] ) ? $allFiles[$viewIndex] : null;
}
$realPath = $fileRow ? XrowExtractPackage::packageFilePath( $package, $fileRow['path'] ) : false;
if ( $realPath === false )
{
    header( 'HTTP/1.1 404 Not Found' );
    eZExecution::cleanExit();
}

$type = XrowExtractPackage::fileMimeType( $fileRow['path'] );

header( 'Cache-Control: private, no-store, max-age=0' );
header( 'Pragma: no-cache' );
header( 'X-Content-Type-Options: nosniff' );
// A package is uploaded content: an SVG in it may carry script. Inside the browser's <img> it never
// runs, but opened directly under the admin's own origin it would; the sandbox stops that.
header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox" );
header( 'Content-Type: ' . $type );
header( 'Content-Length: ' . filesize( $realPath ) );
// An image is shown inline (the browser page's own <img src>); anything else offered by name,
// including an .xml/.txt item - reaching this view for one of those means "Download", not "View"
// (the browser's own inline pretty-print/text reading goes through browse.php instead)
if ( $fileRow['kind'] !== 'image' )
    header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', '\\' ), '_', basename( $fileRow['path'] ) ) . '"' );

while ( @ob_end_clean() );

$fh = fopen( $realPath, 'rb' );
while ( $fh && !feof( $fh ) )
    echo fread( $fh, 1048576 );
if ( $fh )
    fclose( $fh );

eZExecution::cleanExit();
