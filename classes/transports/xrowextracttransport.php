<?php

/**
 * One way of delivering a file to (or, for a scheduled import, fetching one from) a destination. A
 * transport gets the destination's plain config and its decrypted secrets, and never writes either
 * anywhere but the private temp folder of one call (removed again before it returns).
 *
 * Every method returns array( 'ok' => bool, 'message' => text, ... ). Nothing throws past it.
 */
abstract class XrowExtractTransport
{
    protected $config;
    protected $secrets;

    public function __construct( array $config, array $secrets )
    {
        $this->config = $config;
        $this->secrets = $secrets;
    }

    /** The config fields of this type: name => array( label, kind text|number|bool|select, default, options ). */
    public static function fields()
    {
        return array();
    }

    /** The secret fields of this type: name => label. Write only: never shown again once set. */
    public static function secretFields()
    {
        return array();
    }

    /** Can this server use the type at all (a PHP extension, a binary)? '' when yes, else why not. */
    public static function unavailableReason()
    {
        return '';
    }

    /** Checks the destination without writing a file (connect, log in, see the folder). */
    abstract public function test();

    /** Uploads $localPath as $remoteName into the destination's folder (via a temporary name, then renamed where the protocol allows). */
    abstract public function upload( $localPath, $remoteName );

    /** Downloads $remotePath (relative to the destination's folder) to $localPath, for a scheduled import. */
    public function download( $remotePath, $localPath )
    {
        return array( 'ok' => false, 'message' => 'This destination type cannot be read from.' );
    }

    protected function config( $name, $default = '' )
    {
        return isset( $this->config[$name] ) && $this->config[$name] !== '' ? $this->config[$name] : $default;
    }

    protected function secret( $name )
    {
        return isset( $this->secrets[$name] ) ? (string)$this->secrets[$name] : '';
    }

    /** A safe file name: no folder part, no control characters. */
    public static function safeName( $name )
    {
        $name = basename( str_replace( '\\', '/', (string)$name ) );
        $name = preg_replace( '/[\x00-\x1f\x7f"\'`]/', '', $name );
        return $name === '' || $name === '.' || $name === '..' ? 'export' : $name;
    }

    /** A remote folder path: forward slashes, no "..", no control characters or quotes. */
    public static function safeFolder( $path )
    {
        $path = str_replace( '\\', '/', trim( (string)$path ) );
        $path = preg_replace( '/[\x00-\x1f\x7f"\'`]/', '', $path );
        $parts = array();
        foreach ( explode( '/', $path ) as $part )
        {
            if ( $part === '' || $part === '.' )
                continue;
            if ( $part === '..' )
                return false;
            $parts[] = $part;
        }
        return ( strpos( $path, '/' ) === 0 ? '/' : '' ) . implode( '/', $parts );
    }

    /** A private temp folder for one call (0700, below the var directory, not the web root). */
    protected static function privateTempDir()
    {
        $base = eZSys::varDirectory() . '/xrowextract-tmp';
        if ( !is_dir( $base ) )
        {
            $umask = umask( 0077 );
            @mkdir( $base, 0700, true );
            umask( $umask );
            XrowExtractJob::fixOwnership( $base );
        }
        $dir = $base . '/' . bin2hex( random_bytes( 8 ) );
        $umask = umask( 0077 );
        @mkdir( $dir, 0700 );
        umask( $umask );
        return is_dir( $dir ) ? $dir : false;
    }

    protected static function removeDir( $dir )
    {
        if ( !$dir || !is_dir( $dir ) )
            return;
        foreach ( (array)@scandir( $dir ) as $entry )
        {
            if ( $entry !== '.' && $entry !== '..' && is_file( $dir . '/' . $entry ) )
                @unlink( $dir . '/' . $entry );
        }
        @rmdir( $dir );
    }

    /**
     * One HTTP(S)/FTP(S) request through the curl extension. $options: method, headers (array of lines),
     * body (string), upload_file (path, streamed), auth (array user, password), timeout, protocols
     * (CURLPROTO_* mask; default http and https), extra (more CURLOPT_* => value).
     * Returns array( 'ok' => transport-level success, 'status' => HTTP status or FTP code, 'body' => ...,
     * 'headers' => response header lines, 'error' => curl error ).
     */
    protected static function curl( $url, array $options = array() )
    {
        if ( !function_exists( 'curl_init' ) )
            return array( 'ok' => false, 'status' => 0, 'body' => '', 'headers' => array(), 'error' => 'the PHP curl extension is not available' );
        $ch = curl_init();
        $responseHeaders = array();
        $method = isset( $options['method'] ) ? $options['method'] : 'GET';
        $opts = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => isset( $options['timeout'] ) ? (int)$options['timeout'] : 600,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => isset( $options['protocols'] ) ? $options['protocols'] : ( CURLPROTO_HTTP | CURLPROTO_HTTPS ),
            CURLOPT_USERAGENT => 'xrowextract (Exponential)',
            CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$responseHeaders )
            {
                $trimmed = trim( $line );
                if ( $trimmed !== '' )
                    $responseHeaders[] = $trimmed;
                return strlen( $line );
            },
        );
        $handle = null;
        if ( !empty( $options['upload_file'] ) )
        {
            $handle = fopen( $options['upload_file'], 'rb' );
            $opts[CURLOPT_UPLOAD] = true;
            $opts[CURLOPT_INFILE] = $handle;
            $opts[CURLOPT_INFILESIZE] = filesize( $options['upload_file'] );
            if ( $method !== 'PUT' && strpos( $url, 'ftp' ) !== 0 )
                $opts[CURLOPT_CUSTOMREQUEST] = $method;
        }
        elseif ( isset( $options['body'] ) )
        {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            $opts[CURLOPT_POSTFIELDS] = $options['body'];
        }
        elseif ( $method === 'HEAD' )
        {
            $opts[CURLOPT_NOBODY] = true;
        }
        elseif ( $method !== 'GET' )
        {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
        }
        if ( !empty( $options['headers'] ) )
            $opts[CURLOPT_HTTPHEADER] = $options['headers'];
        if ( !empty( $options['auth'] ) )
        {
            $opts[CURLOPT_USERPWD] = $options['auth'][0] . ':' . $options['auth'][1];
            // Basic, sent with the first request: a streamed upload cannot be rewound for a second,
            // authenticated attempt (which negotiating digest would need)
            if ( strpos( $url, 'http' ) === 0 )
                $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }
        if ( !empty( $options['extra'] ) )
        {
            foreach ( $options['extra'] as $key => $value )
                $opts[$key] = $value;
        }
        curl_setopt_array( $ch, $opts );
        $body = curl_exec( $ch );
        $error = $body === false ? curl_error( $ch ) : '';
        $status = (int)curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        if ( PHP_VERSION_ID < 80000 )
            curl_close( $ch );
        if ( $handle )
            fclose( $handle );
        return array( 'ok' => $body !== false, 'status' => $status, 'body' => $body === false ? '' : (string)$body, 'headers' => $responseHeaders, 'error' => $error );
    }

    /** The first 300 characters of a response body, one line, for a result message. */
    protected static function bodyExcerpt( $body )
    {
        $text = trim( preg_replace( '/\s+/', ' ', strip_tags( (string)$body ) ) );
        return mb_substr( $text, 0, 300 );
    }
}

?>
