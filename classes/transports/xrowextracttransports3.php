<?php

/**
 * S3 compatible object storage (AWS S3, MinIO, Wasabi, ...) with AWS Signature Version 4, written here
 * directly (no SDK, no composer): PUT Object with the payload's SHA-256, GET Object for a scheduled import,
 * and a HEAD Bucket for "Test connection". Path style (https://endpoint/bucket/key, the usual choice for
 * MinIO) or virtual-hosted style (https://bucket.endpoint/key). One PUT carries up to 5 GB.
 */
class XrowExtractTransportS3 extends XrowExtractTransport
{
    public static function fields()
    {
        return array(
            'endpoint' => array( 'Endpoint URL', 'text', 'https://s3.amazonaws.com' ),
            'region' => array( 'Region', 'text', 'us-east-1' ),
            'bucket' => array( 'Bucket', 'text', '' ),
            'prefix' => array( 'Key prefix (folder)', 'text', '' ),
            'access_key' => array( 'Access key ID', 'text', '' ),
            'path_style' => array( 'Path-style URLs (MinIO)', 'bool', '1' ),
        );
    }

    public static function secretFields()
    {
        return array( 'secret_key' => 'Secret access key' );
    }

    public static function unavailableReason()
    {
        return function_exists( 'curl_init' ) ? '' : 'the PHP curl extension is not available';
    }

    /** RFC 3986 encoding of one path segment, as SigV4 wants it. */
    public static function encodeSegment( $segment )
    {
        return str_replace( '%7E', '~', rawurlencode( $segment ) );
    }

    /** The canonical URI of a key: each segment encoded, slashes kept. */
    public static function canonicalURI( $path )
    {
        return implode( '/', array_map( array( __CLASS__, 'encodeSegment' ), explode( '/', $path ) ) );
    }

    /**
     * Signs one request. Returns array( 'authorization' => the Authorization header value, 'canonical' =>
     * the canonical request, 'string_to_sign' => ..., 'signature' => hex ). $headers: lower-case name =>
     * value, host and x-amz-date and x-amz-content-sha256 included. $query: already encoded key=value pairs
     * are not needed here beyond an empty string or a sorted canonical query.
     */
    public static function sign( $method, $canonicalURI, $canonicalQuery, array $headers, $payloadHash, $accessKey, $secretKey, $region, $service = 's3' )
    {
        ksort( $headers );
        $canonicalHeaders = '';
        foreach ( $headers as $name => $value )
            $canonicalHeaders .= strtolower( $name ) . ':' . trim( preg_replace( '/\s+/', ' ', (string)$value ) ) . "\n";
        $signedHeaders = implode( ';', array_map( 'strtolower', array_keys( $headers ) ) );
        $canonical = $method . "\n" . $canonicalURI . "\n" . $canonicalQuery . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
        $amzDate = $headers['x-amz-date'];
        $date = substr( $amzDate, 0, 8 );
        $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
        $key = hash_hmac( 'sha256', $date, 'AWS4' . $secretKey, true );
        $key = hash_hmac( 'sha256', $region, $key, true );
        $key = hash_hmac( 'sha256', $service, $key, true );
        $key = hash_hmac( 'sha256', 'aws4_request', $key, true );
        $signature = hash_hmac( 'sha256', $stringToSign, $key );
        return array(
            'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
            'canonical' => $canonical,
            'string_to_sign' => $stringToSign,
            'signature' => $signature,
        );
    }

    /** array( url, host, canonical uri ) for $key (relative to the prefix; '' for the bucket itself), or false. */
    public function target( $key )
    {
        $endpoint = rtrim( trim( (string)$this->config( 'endpoint', 'https://s3.amazonaws.com' ) ), '/' );
        $bucket = trim( (string)$this->config( 'bucket' ) );
        if ( !preg_match( '#^(https?)://([^/\s:]+)(:\d+)?$#i', $endpoint, $m ) || !preg_match( '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket ) )
            return false;
        $prefix = trim( (string)$this->config( 'prefix' ), '/' );
        $objectKey = $key === '' ? '' : ( $prefix !== '' ? $prefix . '/' : '' ) . ltrim( $key, '/' );
        if ( strpos( $objectKey, '..' ) !== false )
            return false;
        $pathStyle = (string)$this->config( 'path_style', '1' ) !== '0';
        $host = $pathStyle ? $m[2] . ( isset( $m[3] ) ? $m[3] : '' ) : $bucket . '.' . $m[2] . ( isset( $m[3] ) ? $m[3] : '' );
        $path = $pathStyle ? '/' . $bucket . ( $objectKey !== '' ? '/' . $objectKey : '' ) : '/' . $objectKey;
        $uri = self::canonicalURI( $path );
        return array( 'url' => strtolower( $m[1] ) . '://' . $host . $uri, 'host' => $host, 'uri' => $uri, 'key' => $objectKey );
    }

    /** The signed header lines for one request. */
    public function headersFor( $method, array $target, $payloadHash, array $extra = array(), $time = null )
    {
        $time = $time === null ? time() : $time;
        $headers = array_merge( array(
            'host' => $target['host'],
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => gmdate( 'Ymd\THis\Z', $time ),
        ), $extra );
        $signed = self::sign( $method, $target['uri'], '', $headers, $payloadHash, (string)$this->config( 'access_key' ),
                              $this->secret( 'secret_key' ), (string)$this->config( 'region', 'us-east-1' ) );
        $lines = array( 'Authorization: ' . $signed['authorization'] );
        foreach ( $headers as $name => $value )
        {
            if ( $name !== 'host' )
                $lines[] = $name . ': ' . $value;
        }
        return $lines;
    }

    protected function errorText( array $result )
    {
        if ( !$result['ok'] )
            return 'No connection: ' . $result['error'];
        $code = preg_match( '#<Code>([^<]+)</Code>#', $result['body'], $m ) ? $m[1] : '';
        $message = preg_match( '#<Message>([^<]+)</Message>#', $result['body'], $n ) ? $n[1] : '';
        return 'HTTP ' . $result['status'] . ( $code !== '' ? ' ' . $code : '' ) . ( $message !== '' ? ': ' . $message : '' );
    }

    public function test()
    {
        $target = $this->target( '' );
        if ( !$target )
            return array( 'ok' => false, 'message' => 'The endpoint or bucket name is not valid.' );
        $empty = hash( 'sha256', '' );
        $result = self::curl( $target['url'], array( 'method' => 'HEAD', 'timeout' => 30, 'headers' => $this->headersFor( 'HEAD', $target, $empty ) ) );
        if ( $result['ok'] && $result['status'] >= 200 && $result['status'] < 300 )
            return array( 'ok' => true, 'message' => 'The bucket ' . $this->config( 'bucket' ) . ' is reachable with these credentials.' );
        return array( 'ok' => false, 'message' => $result['ok'] && $result['status'] === 403 ? 'HTTP 403: the key has no access to the bucket, or the secret is wrong'
                                                    : ( $result['ok'] && $result['status'] === 404 ? 'HTTP 404: no such bucket' : $this->errorText( $result ) ) );
    }

    public function upload( $localPath, $remoteName )
    {
        $target = $this->target( self::safeName( $remoteName ) );
        if ( !$target )
            return array( 'ok' => false, 'message' => 'The endpoint or bucket name is not valid.' );
        if ( filesize( $localPath ) > 5 * 1024 * 1024 * 1024 )
            return array( 'ok' => false, 'message' => 'The file is larger than 5 GB, the limit of a single S3 PUT.' );
        $hash = hash_file( 'sha256', $localPath );
        $result = self::curl( $target['url'], array( 'method' => 'PUT', 'upload_file' => $localPath,
                                                     'headers' => $this->headersFor( 'PUT', $target, $hash, array( 'content-type' => 'application/octet-stream' ) ) ) );
        if ( $result['ok'] && $result['status'] >= 200 && $result['status'] < 300 )
            return array( 'ok' => true, 'message' => 'Stored as s3://' . $this->config( 'bucket' ) . '/' . $target['key'], 'location' => 's3://' . $this->config( 'bucket' ) . '/' . $target['key'] );
        return array( 'ok' => false, 'message' => $this->errorText( $result ) );
    }

    public function download( $remotePath, $localPath )
    {
        $target = $this->target( (string)$remotePath );
        if ( !$target || $target['key'] === '' )
            return array( 'ok' => false, 'message' => 'Not a key below the destination prefix.' );
        $out = fopen( $localPath, 'wb' );
        $result = self::curl( $target['url'], array( 'method' => 'GET', 'headers' => $this->headersFor( 'GET', $target, hash( 'sha256', '' ) ),
                                                     'extra' => array( CURLOPT_RETURNTRANSFER => false, CURLOPT_FILE => $out ) ) );
        fclose( $out );
        if ( $result['ok'] && $result['status'] >= 200 && $result['status'] < 300 )
            return array( 'ok' => true, 'message' => 'Downloaded s3://' . $this->config( 'bucket' ) . '/' . $target['key'], 'location' => $target['url'] );
        return array( 'ok' => false, 'message' => 'HTTP ' . $result['status'] );
    }
}

?>
