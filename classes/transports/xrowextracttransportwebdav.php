<?php

/**
 * WebDAV (Nextcloud, ownCloud, Apache mod_dav, a NAS): the folder is created with MKCOL where missing,
 * the file is PUT under a temporary name and MOVEd into place (Overwrite: T). Basic or digest auth.
 */
class XrowExtractTransportWebdav extends XrowExtractTransport
{
    public static function fields()
    {
        return array(
            'url' => array( 'Folder URL', 'text', '' ),
            'user' => array( 'User', 'text', '' ),
        );
    }

    public static function secretFields()
    {
        return array( 'password' => 'Password' );
    }

    public static function unavailableReason()
    {
        return function_exists( 'curl_init' ) ? '' : 'the PHP curl extension is not available';
    }

    protected function base()
    {
        $url = trim( (string)$this->config( 'url' ) );
        return preg_match( '#^https?://[^\s/]+#i', $url ) ? rtrim( $url, '/' ) : false;
    }

    protected function auth()
    {
        return $this->config( 'user' ) !== '' ? array( $this->config( 'user' ), $this->secret( 'password' ) ) : null;
    }

    /** A URL below the folder, every path segment encoded. */
    protected function urlFor( $relative )
    {
        $segments = array_map( 'rawurlencode', array_filter( explode( '/', (string)$relative ), 'strlen' ) );
        return $this->base() . ( $segments ? '/' . implode( '/', $segments ) : '' );
    }

    public function test()
    {
        if ( !$this->base() )
            return array( 'ok' => false, 'message' => 'The URL must start with http:// or https://.' );
        $result = self::curl( $this->base() . '/', array( 'method' => 'PROPFIND', 'auth' => $this->auth(), 'timeout' => 30,
                                                           'headers' => array( 'Depth: 0', 'Content-Type: application/xml' ),
                                                           'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>' ) );
        if ( !$result['ok'] )
            return array( 'ok' => false, 'message' => 'No connection: ' . $result['error'] );
        if ( $result['status'] === 207 || ( $result['status'] >= 200 && $result['status'] < 300 ) )
            return array( 'ok' => true, 'message' => 'WebDAV folder reachable (HTTP ' . $result['status'] . ').' );
        if ( $result['status'] === 404 )
        {
            $made = self::curl( $this->base() . '/', array( 'method' => 'MKCOL', 'auth' => $this->auth(), 'timeout' => 30 ) );
            if ( $made['ok'] && $made['status'] >= 200 && $made['status'] < 300 )
                return array( 'ok' => true, 'message' => 'The folder did not exist and was created (HTTP ' . $made['status'] . ').' );
        }
        return array( 'ok' => false, 'message' => 'HTTP ' . $result['status'] . ( $result['status'] === 401 ? ': the user or password is wrong' : '' ) );
    }

    public function upload( $localPath, $remoteName )
    {
        if ( !$this->base() )
            return array( 'ok' => false, 'message' => 'The URL must start with http:// or https://.' );
        $name = self::safeName( $remoteName );
        // The folder itself, if it is missing (405: exists already)
        self::curl( $this->base() . '/', array( 'method' => 'MKCOL', 'auth' => $this->auth(), 'timeout' => 30 ) );
        $partURL = $this->urlFor( '.' . $name . '.part' );
        $put = self::curl( $partURL, array( 'method' => 'PUT', 'upload_file' => $localPath, 'auth' => $this->auth() ) );
        if ( !$put['ok'] )
            return array( 'ok' => false, 'message' => 'No connection: ' . $put['error'] );
        if ( $put['status'] < 200 || $put['status'] >= 300 )
            return array( 'ok' => false, 'message' => 'PUT: HTTP ' . $put['status'] . ': ' . self::bodyExcerpt( $put['body'] ) );
        $target = $this->urlFor( $name );
        $move = self::curl( $partURL, array( 'method' => 'MOVE', 'auth' => $this->auth(), 'timeout' => 60,
                                              'headers' => array( 'Destination: ' . $target, 'Overwrite: T' ) ) );
        if ( !$move['ok'] || $move['status'] < 200 || $move['status'] >= 300 )
            return array( 'ok' => false, 'message' => 'MOVE: ' . ( $move['ok'] ? 'HTTP ' . $move['status'] : $move['error'] ) );
        return array( 'ok' => true, 'message' => 'Uploaded to ' . $target, 'location' => $target );
    }

    public function download( $remotePath, $localPath )
    {
        if ( !$this->base() || strpos( (string)$remotePath, '..' ) !== false )
            return array( 'ok' => false, 'message' => 'Not a path below the destination folder.' );
        $out = fopen( $localPath, 'wb' );
        $result = self::curl( $this->urlFor( $remotePath ), array( 'method' => 'GET', 'auth' => $this->auth(),
                                                                      'extra' => array( CURLOPT_RETURNTRANSFER => false, CURLOPT_FILE => $out ) ) );
        fclose( $out );
        if ( !$result['ok'] || $result['status'] < 200 || $result['status'] >= 300 )
            return array( 'ok' => false, 'message' => $result['ok'] ? 'HTTP ' . $result['status'] : 'No connection: ' . $result['error'] );
        return array( 'ok' => true, 'message' => 'Downloaded ' . $remotePath, 'location' => $this->urlFor( $remotePath ) );
    }
}

?>
