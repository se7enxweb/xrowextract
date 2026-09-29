<?php
/**
 * xrowextract/job_download/<id>: streams a finished background job's file.
 *
 * IMPORTANT: eZExecution::cleanExit() ends the request by throwing under
 * Velocity, so the send-and-exit below must never sit inside a
 * try/catch(Exception) -- that would swallow the exit and the page gets
 * appended to the file.
 */

$login = eZUser::currentUser()->attribute( 'login' );
$allJobs = XrowExtractJob::allowAllJobs();
$id = isset( $Params['JobID'] ) ? (string)$Params['JobID'] : '';
$job = XrowExtractJob::isValidID( $id ) ? XrowExtractJob::load( $id ) : null;

if ( !$job || !XrowExtractJob::canSee( $job, $login, $allJobs ) || $job['state'] !== 'done' || !$job['output_file'] )
{
    header( 'HTTP/1.1 404 Not Found' );
    eZExecution::cleanExit();
}

// The main output file, or (an import job only) its error-rows file: a re-importable file of exactly
// the rows that errored, so fixing and re-uploading just that file is the whole recovery step
$what = isset( $Params['What'] ) ? (string)$Params['What'] : '';
$outputName = $job['output_file'];
if ( $what === 'errors' && $job['type'] === 'import' )
{
    $errorsName = preg_replace( '/\.[^.]+$/', '', $outputName ) . '.errors.csv';
    if ( is_file( XrowExtractJob::path( $id ) . '/' . $errorsName ) )
        $outputName = $errorsName;
    else
    {
        header( 'HTTP/1.1 404 Not Found' );
        eZExecution::cleanExit();
    }
}

$path = XrowExtractJob::path( $id ) . '/' . $outputName;
if ( !is_file( $path ) )
{
    header( 'HTTP/1.1 404 Not Found' );
    eZExecution::cleanExit();
}

$types = array(
    'csv' => 'text/csv', 'json' => 'application/json', 'xml' => 'application/xml',
    'zip' => 'application/zip', 'tar.gz' => 'application/gzip', 'tar.bz2' => 'application/x-bzip2',
    'tar.xz' => 'application/x-xz', '7z' => 'application/x-7z-compressed', 'rar' => 'application/vnd.rar',
    // A content package (.ezpkg) is the same gzip-compressed tar a .tar.gz is, only the extension differs
    'ezpkg' => 'application/gzip',
);
$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
if ( preg_match( '/\.(tar\.(gz|bz2|xz))$/i', $path, $m ) )
    $ext = strtolower( $m[1] );
$type = isset( $types[$ext] ) ? $types[$ext] : 'application/octet-stream';

header( 'Cache-Control: private, no-store, max-age=0' );
header( 'Pragma: no-cache' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Content-Type: ' . $type );
header( 'Content-Length: ' . filesize( $path ) );
header( 'Content-Disposition: attachment; filename="' . basename( $outputName ) . '"' );

while ( @ob_end_clean() );

$fh = fopen( $path, 'rb' );
while ( $fh && !feof( $fh ) )
    echo fread( $fh, 1048576 );
if ( $fh )
    fclose( $fh );

eZExecution::cleanExit();
