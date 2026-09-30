<?php

/**
 * FTP and FTPS through the curl extension: plain FTP (unencrypted: user, password and file travel in
 * clear text - the Destinations page says so), explicit FTPS (AUTH TLS on port 21, required for the
 * whole session) or implicit FTPS (port 990). The server certificate is verified. Passive mode; the file
 * is stored under a temporary name and renamed (RNFR/RNTO) once complete; missing folders are created.
 */
class XrowExtractTransportFtp extends XrowExtractTransport
{
    /** @return array<string, array<mixed>> */
    public static function fields(): array
    {
        return array(
            'host' => array( 'Host', 'text', '' ),
            'port' => array( 'Port', 'number', '' ),
            'user' => array( 'User', 'text', '' ),
            'path' => array( 'Folder', 'text', '/' ),
            'security' => array( 'Encryption', 'select', 'explicit', array( 'explicit' => 'FTPS, explicit (AUTH TLS)', 'implicit' => 'FTPS, implicit (port 990)', 'none' => 'None: plain FTP, unencrypted' ) ),
        );
    }

    /** @return array<string, string> */
    public static function secretFields(): array
    {
        return array( 'password' => 'Password' );
    }

    public static function unavailableReason(): string
    {
        if ( !function_exists( 'curl_init' ) )
            return 'the PHP curl extension is not available';
        $version = curl_version();
        return in_array( 'ftp', $version['protocols'], true ) ? '' : 'the curl library has no FTP support';
    }

    protected function security(): string
    {
        $value = $this->config( 'security', 'explicit' );
        return in_array( $value, array( 'none', 'explicit', 'implicit' ), true ) ? $value : 'explicit';
    }

    /** The folder URL (ends in /) or false. */
    protected function folderURL(): string|false
    {
        $host = trim( (string)$this->config( 'host' ) );
        if ( !preg_match( '/^[A-Za-z0-9.-]+$|^\[[0-9a-fA-F:]+\]$/', $host ) )
            return false;
        $folder = self::safeFolder( $this->config( 'path', '/' ) );
        if ( $folder === false )
            return false;
        $scheme = $this->security() === 'implicit' ? 'ftps' : 'ftp';
        $port = (int)$this->config( 'port' ) ?: ( $this->security() === 'implicit' ? 990 : 21 );
        $segments = array_map( 'rawurlencode', array_filter( explode( '/', $folder ), static function ( $part ) { return $part !== ''; } ) );
        return $scheme . '://' . $host . ':' . $port . '/' . ( $segments ? implode( '/', $segments ) . '/' : '' );
    }

    /**
     * @param array<int, mixed> $extra more CURLOPT_* => value
     * @return array<string, mixed>
     */
    protected function options( array $extra = array() ): array
    {
        $curlExtra = array( CURLOPT_FTP_USE_EPSV => true, CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY );
        if ( $this->security() !== 'none' )
            $curlExtra[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
        return array(
            'protocols' => CURLPROTO_FTP | CURLPROTO_FTPS,
            'auth' => array( $this->config( 'user', 'anonymous' ), $this->secret( 'password' ) ),
            'extra' => $extra + $curlExtra,
        );
    }

    public function test(): array
    {
        $url = $this->folderURL();
        if ( !$url )
            return array( 'ok' => false, 'message' => 'The host or folder is not valid.' );
        $result = self::curl( $url, $this->options( array( CURLOPT_DIRLISTONLY => true, CURLOPT_TIMEOUT => 30 ) ) );
        if ( !$result['ok'] )
            return array( 'ok' => false, 'message' => 'FTP: ' . $result['error'] );
        return array( 'ok' => true, 'message' => 'Logged in and listed the folder (' . count( array_filter( explode( "\n", trim( $result['body'] ) ), static function ( $part ) { return $part !== ''; } ) ) . ' entries)'
                                              . ( $this->security() === 'none' ? '; the connection is NOT encrypted' : '; TLS' ) . '.' );
    }

    public function upload( $localPath, $remoteName ): array
    {
        $url = $this->folderURL();
        if ( !$url )
            return array( 'ok' => false, 'message' => 'The host or folder is not valid.' );
        $name = self::safeName( $remoteName );
        $part = '.' . $name . '.part';
        $options = $this->options( array( CURLOPT_POSTQUOTE => array( 'RNFR ' . $part, 'RNTO ' . $name ) ) );
        $options['upload_file'] = $localPath;
        $options['method'] = 'PUT';
        $result = self::curl( $url . rawurlencode( $part ), $options );
        if ( !$result['ok'] )
            return array( 'ok' => false, 'message' => 'FTP: ' . $result['error'] );
        return array( 'ok' => true, 'message' => 'Uploaded to ' . preg_replace( '#^(ftps?://)#', '$1', $url ) . $name, 'location' => $url . $name );
    }

    public function download( $remotePath, $localPath ): array
    {
        $url = $this->folderURL();
        if ( !$url || strpos( (string)$remotePath, '..' ) !== false )
            return array( 'ok' => false, 'message' => 'Not a path below the destination folder.' );
        $segments = array_map( 'rawurlencode', array_filter( explode( '/', (string)$remotePath ), static function ( $part ) { return $part !== ''; } ) );
        $out = fopen( $localPath, 'wb' );
        if ( !$out )
            return array( 'ok' => false, 'message' => 'Cannot write the local file ' . basename( (string)$localPath ) . '.' );
        $result = self::curl( $url . implode( '/', $segments ), $this->options( array( CURLOPT_RETURNTRANSFER => false, CURLOPT_FILE => $out ) ) );
        fclose( $out );
        if ( !$result['ok'] )
            return array( 'ok' => false, 'message' => 'FTP: ' . $result['error'] );
        return array( 'ok' => true, 'message' => 'Downloaded ' . $remotePath, 'location' => $url . implode( '/', $segments ) );
    }
}

?>
