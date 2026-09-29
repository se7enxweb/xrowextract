<?php

/**
 * xrowextract/upload_chunk: the chunked upload endpoint XrowExtractUploadJS
 * talks to. Same policy function as xrowextract/import (see module.php), so
 * whoever may use the import view may upload to it; the eZ session and the
 * global form-token check (ezformtoken, X-CSRF-Token header for XHR) apply
 * exactly as they do to every other POST on this site. Always answers JSON.
 *
 * Actions (POST "Action"): start | chunk | status.
 */

header( 'Cache-Control: private, no-store, max-age=0' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Content-Type: application/json; charset=utf-8' );

$http = eZHTTPTool::instance();
$login = eZUser::currentUser()->attribute( 'login' );

$respond = function ( array $data, $httpStatus = null )
{
    if ( $httpStatus !== null )
        header( 'HTTP/1.1 ' . $httpStatus );
    while ( @ob_end_clean() );
    echo json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    eZExecution::cleanExit();
};

$action = $http->hasPostVariable( 'Action' ) ? (string)$http->postVariable( 'Action' ) : '';

XrowExtractUpload::cleanupStale();

if ( $action === 'start' )
{
    $name = $http->hasPostVariable( 'Name' ) ? (string)$http->postVariable( 'Name' ) : 'upload';
    $totalSize = $http->hasPostVariable( 'TotalSize' ) ? (int)$http->postVariable( 'TotalSize' ) : 0;
    if ( $totalSize <= 0 )
        $respond( array( 'ok' => false, 'error' => 'A file size is required.' ), '400 Bad Request' );

    list( $hasRoom, $message ) = XrowExtractUpload::hasRoomFor( $totalSize );
    if ( !$hasRoom )
        $respond( array( 'ok' => false, 'error' => $message ), '507 Insufficient Storage' );

    $id = XrowExtractUpload::create( $login, $name, $totalSize );
    $respond( array( 'ok' => true, 'upload_id' => $id, 'received' => 0 ) );
}

if ( $action === 'status' )
{
    $id = $http->hasPostVariable( 'UploadID' ) ? (string)$http->postVariable( 'UploadID' ) : '';
    $received = XrowExtractUpload::receivedBytes( $id, $login );
    if ( $received === null )
        $respond( array( 'ok' => false, 'error' => 'not_found' ), '404 Not Found' );
    $meta = XrowExtractUpload::loadMeta( $id );
    $respond( array( 'ok' => true, 'received' => $received, 'complete' => !empty( $meta['complete'] ) ) );
}

if ( $action === 'chunk' )
{
    $id = $http->hasPostVariable( 'UploadID' ) ? (string)$http->postVariable( 'UploadID' ) : '';
    $offset = $http->hasPostVariable( 'Offset' ) ? (int)$http->postVariable( 'Offset' ) : -1;
    if ( !XrowExtractUpload::isValidID( $id ) || $offset < 0 )
        $respond( array( 'ok' => false, 'error' => 'bad_request' ), '400 Bad Request' );
    if ( !isset( $_FILES['Chunk'] ) || $_FILES['Chunk']['error'] !== UPLOAD_ERR_OK )
        $respond( array( 'ok' => false, 'error' => 'no_chunk' ), '400 Bad Request' );

    $result = XrowExtractUpload::appendChunk( $id, $login, $offset, $_FILES['Chunk']['tmp_name'], (int)$_FILES['Chunk']['size'] );
    if ( !$result['ok'] )
    {
        $status = $result['error'] === 'not_found' ? '404 Not Found' : ( $result['error'] === 'too_large' ? '507 Insufficient Storage' : '409 Conflict' );
        $respond( $result, $status );
    }
    $respond( $result );
}

$respond( array( 'ok' => false, 'error' => 'unknown_action' ), '400 Bad Request' );

?>
