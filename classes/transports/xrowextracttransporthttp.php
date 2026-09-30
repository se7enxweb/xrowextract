<?php

/**
 * HTTP POST of the file (a webhook upload): multipart/form-data with the file ("file" by default), its
 * manifest ("manifest", when there is one) and a JSON "meta" field. Optional secrets: a bearer token
 * (Authorization: Bearer ...) and an HMAC key: X-Xrowextract-Signature: sha256=<hex HMAC-SHA256 of
 * "<timestamp>.<sha256 of the file>"> with X-Xrowextract-Timestamp, so the receiver can check both the
 * sender and the file. A 2xx answer is a success.
 */
class XrowExtractTransportHttp extends XrowExtractTransport
{
    /** @return array<string, array<mixed>> */
    public static function fields(): array
    {
        return array(
            'url' => array( 'URL', 'text', '' ),
            'field' => array( 'File field name', 'text', 'file' ),
        );
    }

    /** @return array<string, string> */
    public static function secretFields(): array
    {
        return array( 'bearer_token' => 'Bearer token', 'hmac_key' => 'HMAC signing key' );
    }

    public static function unavailableReason(): string
    {
        return function_exists( 'curl_init' ) ? '' : 'the PHP curl extension is not available';
    }

    protected function url(): string|false
    {
        $url = trim( (string)$this->config( 'url' ) );
        return preg_match( '#^https?://[^\s/]+#i', $url ) ? $url : false;
    }

    /**
     * The request headers for a body/file with this sha256.
     *
     * @param string $sha256
     * @param int|string|null $timestamp
     * @return list<string>
     */
    public function signedHeaders( $sha256, $timestamp = null ): array
    {
        $timestamp = $timestamp === null ? time() : (int)$timestamp;
        $headers = array( 'X-Xrowextract-Timestamp: ' . $timestamp );
        if ( $this->secret( 'bearer_token' ) !== '' )
            $headers[] = 'Authorization: Bearer ' . $this->secret( 'bearer_token' );
        if ( $this->secret( 'hmac_key' ) !== '' )
            $headers[] = 'X-Xrowextract-Signature: sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $sha256, $this->secret( 'hmac_key' ) );
        return $headers;
    }

    public function test(): array
    {
        $url = $this->url();
        if ( !$url )
            return array( 'ok' => false, 'message' => 'The URL must start with http:// or https://.' );
        $body = json_encode( array( 'event' => 'xrowextract.test', 'time' => date( 'c' ) ) );
        $result = self::curl( $url, array( 'method' => 'POST', 'body' => $body, 'timeout' => 30,
                                           'headers' => array_merge( array( 'Content-Type: application/json' ), $this->signedHeaders( hash( 'sha256', $body ) ) ) ) );
        if ( !$result['ok'] )
            return array( 'ok' => false, 'message' => 'No connection: ' . $result['error'] );
        if ( $result['status'] < 200 || $result['status'] >= 300 )
            return array( 'ok' => false, 'message' => 'HTTP ' . $result['status'] . ': ' . self::bodyExcerpt( $result['body'] ) );
        return array( 'ok' => true, 'message' => 'HTTP ' . $result['status'] . ' from ' . parse_url( $url, PHP_URL_HOST ) );
    }

    /**
     * @param string $localPath
     * @param string $remoteName
     * @param string|null $manifestPath the manifest, sent along as a third part
     * @return array<string, mixed>
     */
    public function upload( $localPath, $remoteName, $manifestPath = null ): array
    {
        $url = $this->url();
        if ( !$url )
            return array( 'ok' => false, 'message' => 'The URL must start with http:// or https://.' );
        $sha = hash_file( 'sha256', $localPath );
        $name = self::safeName( $remoteName );
        $fields = array(
            preg_replace( '/[^A-Za-z0-9_-]/', '', (string)$this->config( 'field', 'file' ) ) ?: 'file' => new CURLFile( $localPath, 'application/octet-stream', $name ),
            'meta' => json_encode( array( 'name' => $name, 'bytes' => filesize( $localPath ), 'sha256' => $sha, 'sent' => date( 'c' ) ) ),
        );
        if ( $manifestPath && is_file( $manifestPath ) )
            $fields['manifest'] = new CURLFile( $manifestPath, 'application/json', $name . XrowExtractManifest::SIDECAR_SUFFIX );
        $result = self::curl( $url, array( 'method' => 'POST', 'body' => $fields, 'headers' => $this->signedHeaders( $sha ) ) );
        if ( !$result['ok'] )
            return array( 'ok' => false, 'message' => 'No connection: ' . $result['error'] );
        if ( $result['status'] < 200 || $result['status'] >= 300 )
            return array( 'ok' => false, 'message' => 'HTTP ' . $result['status'] . ': ' . self::bodyExcerpt( $result['body'] ) );
        return array( 'ok' => true, 'message' => 'HTTP ' . $result['status'] . ' from ' . parse_url( $url, PHP_URL_HOST ), 'location' => $url );
    }

    public function download( $remotePath, $localPath ): array
    {
        $url = $this->url();
        if ( !$url )
            return array( 'ok' => false, 'message' => 'The URL must start with http:// or https://.' );
        if ( (string)$remotePath !== '' )
            $url = preg_match( '#^https?://#i', $remotePath ) ? false : rtrim( $url, '/' ) . '/' . ltrim( $remotePath, '/' );
        if ( !$url )
            return array( 'ok' => false, 'message' => 'The path must be relative to the destination URL.' );
        $headers = $this->secret( 'bearer_token' ) !== '' ? array( 'Authorization: Bearer ' . $this->secret( 'bearer_token' ) ) : array();
        $out = fopen( $localPath, 'wb' );
        if ( !$out )
            return array( 'ok' => false, 'message' => 'Cannot write the local file ' . basename( (string)$localPath ) . '.' );
        $result = self::curl( $url, array( 'method' => 'GET', 'headers' => $headers, 'extra' => array( CURLOPT_RETURNTRANSFER => false, CURLOPT_FILE => $out ) ) );
        fclose( $out );
        if ( !$result['ok'] || $result['status'] < 200 || $result['status'] >= 300 )
            return array( 'ok' => false, 'message' => $result['ok'] ? 'HTTP ' . $result['status'] : 'No connection: ' . $result['error'] );
        return array( 'ok' => true, 'message' => 'Downloaded ' . $url, 'location' => $url );
    }
}

?>
