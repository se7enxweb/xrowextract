<?php

/**
 * A named, reusable delivery destination (table xrowextract_destination). Plain settings live in
 * config (JSON); credentials in secret, encrypted with XrowExtractSecrets and never shown again once
 * set - the Destinations page can only set, replace or clear a secret. Managing destinations needs the
 * policy xrowextract/destinations; a schedule owner without it can pick a destination by name only.
 */
class XrowExtractDestination extends eZPersistentObject
{
    public function __construct( $row = array() )
    {
        parent::__construct( $row );
    }

    public static function definition()
    {
        $int = function ( $name, $default = 0 ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => $default, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'name' => $str( 'Name' ),
                'dest_type' => $str( 'Type' ),
                'owner_login' => $str( 'OwnerLogin' ),
                'config' => $str( 'Config', '{}' ),
                'secret' => $str( 'Secret' ),
                'last_test' => $int( 'LastTest' ),
                'last_test_result' => $str( 'LastTestResult' ),
                'created' => $int( 'Created' ),
                'modified' => $int( 'Modified' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'function_attributes' => array(
                'config_array' => 'configArray',
                'secret_names' => 'secretNames',
                'type_name' => 'typeName',
                'last_test_array' => 'lastTestArray',
                'summary' => 'summary',
                'is_unencrypted' => 'isUnencrypted',
            ),
            'class_name' => 'XrowExtractDestination',
            'sort' => array( 'name' => 'asc' ),
            'name' => 'xrowextract_destination',
        );
    }

    /** type => array( name, transport class ). */
    public static function types()
    {
        return array(
            'sftp'   => array( 'SFTP', 'XrowExtractTransportSftp' ),
            'ftp'    => array( 'FTP / FTPS', 'XrowExtractTransportFtp' ),
            'local'  => array( 'Local or NAS folder', 'XrowExtractTransportLocal' ),
            's3'     => array( 'S3 compatible (AWS, MinIO, Wasabi)', 'XrowExtractTransportS3' ),
            'webdav' => array( 'WebDAV', 'XrowExtractTransportWebdav' ),
            'http'   => array( 'HTTP POST (webhook upload)', 'XrowExtractTransportHttp' ),
        );
    }

    public static function transportClass( $type )
    {
        $types = self::types();
        return isset( $types[$type] ) ? $types[$type][1] : false;
    }

    public function configArray()
    {
        $data = json_decode( (string)$this->attribute( 'config' ), true );
        return is_array( $data ) ? $data : array();
    }

    /** The names of the secrets that are set (never the values). */
    public function secretNames()
    {
        return XrowExtractSecrets::names( $this->attribute( 'secret' ) );
    }

    public function typeName()
    {
        $types = self::types();
        return isset( $types[$this->attribute( 'dest_type' )] ) ? $types[$this->attribute( 'dest_type' )][0] : $this->attribute( 'dest_type' );
    }

    public function lastTestArray()
    {
        $data = json_decode( (string)$this->attribute( 'last_test_result' ), true );
        return is_array( $data ) ? $data : null;
    }

    /** Plain FTP: user, password and file in clear text. */
    public function isUnencrypted()
    {
        $config = $this->configArray();
        if ( $this->attribute( 'dest_type' ) === 'ftp' )
            return isset( $config['security'] ) && $config['security'] === 'none';
        foreach ( array( 'url', 'endpoint' ) as $key )
        {
            if ( isset( $config[$key] ) && stripos( $config[$key], 'http://' ) === 0 )
                return true;
        }
        return false;
    }

    /** Where it delivers, in one line (no secret in it). */
    public function summary()
    {
        $c = $this->configArray();
        switch ( $this->attribute( 'dest_type' ) )
        {
            case 'sftp': return 'sftp://' . ( isset( $c['user'] ) ? $c['user'] . '@' : '' ) . ( isset( $c['host'] ) ? $c['host'] : '' ) . ( !empty( $c['port'] ) ? ':' . (int)$c['port'] : '' ) . '/' . ltrim( isset( $c['path'] ) ? $c['path'] : '', '/' );
            case 'ftp': return ( isset( $c['security'] ) && $c['security'] === 'implicit' ? 'ftps' : 'ftp' ) . '://' . ( isset( $c['user'] ) ? $c['user'] . '@' : '' ) . ( isset( $c['host'] ) ? $c['host'] : '' ) . '/' . ltrim( isset( $c['path'] ) ? $c['path'] : '', '/' );
            case 'local': return isset( $c['path'] ) ? $c['path'] : '';
            case 's3': return 's3://' . ( isset( $c['bucket'] ) ? $c['bucket'] : '' ) . '/' . ( isset( $c['prefix'] ) ? ltrim( $c['prefix'], '/' ) : '' ) . ' (' . ( isset( $c['endpoint'] ) ? parse_url( $c['endpoint'], PHP_URL_HOST ) : '' ) . ')';
            case 'webdav':
            case 'http': return isset( $c['url'] ) ? preg_replace( '#//[^/@]+@#', '//', $c['url'] ) : '';
        }
        return '';
    }

    /** @return XrowExtractDestination|null */
    public static function fetch( $id )
    {
        $id = XrowExtractColumns::dbID( $id ); // never an id the database cannot even compare
        if ( !$id )
            return null;
        if ( !XrowExtractSchema::exists() )
            return null;
        $object = eZPersistentObject::fetchObject( self::definition(), null, array( 'id' => $id ) );
        return $object instanceof self ? $object : null;
    }

    public static function fetchList()
    {
        if ( !XrowExtractSchema::exists() )
            return array();
        $list = eZPersistentObject::fetchObjectList( self::definition(), null, null, array( 'name' => 'asc' ) );
        return is_array( $list ) ? $list : array();
    }

    /** id => name, for pickers (no config, no secrets). */
    public static function nameList()
    {
        $names = array();
        foreach ( self::fetchList() as $destination )
            $names[(int)$destination->attribute( 'id' )] = $destination->attribute( 'name' );
        return $names;
    }

    /**
     * Creates or changes a destination. $values: name, type, config (array), secrets (name => new value;
     * '' keeps the stored one), clear_secrets (names to remove). Returns array( 'destination', 'errors' ).
     */
    public static function saveFrom( array $values, $ownerLogin, ?XrowExtractDestination $destination = null )
    {
        $errors = array();
        $name = mb_substr( trim( (string)( isset( $values['name'] ) ? $values['name'] : '' ) ), 0, 150 );
        if ( $name === '' )
            $errors[] = 'A destination needs a name.';
        $type = $destination ? $destination->attribute( 'dest_type' ) : ( isset( $values['type'] ) ? (string)$values['type'] : '' );
        $class = self::transportClass( $type );
        if ( !$class )
            return array( 'destination' => $destination, 'errors' => array_merge( $errors, array( 'Choose the kind of destination.' ) ) );
        $config = array();
        $given = isset( $values['config'] ) && is_array( $values['config'] ) ? $values['config'] : array();
        foreach ( call_user_func( array( $class, 'fields' ) ) as $field => $spec )
        {
            $value = isset( $given[$field] ) ? trim( (string)$given[$field] ) : '';
            if ( $spec[1] === 'bool' )
                $value = !empty( $given[$field] ) ? '1' : '0';
            elseif ( $spec[1] === 'number' )
                $value = $value === '' ? '' : (string)max( 0, (int)$value );
            elseif ( $spec[1] === 'select' && !array_key_exists( $value, $spec[3] ) )
                $value = $spec[2];
            $config[$field] = mb_substr( $value, 0, 1000 );
        }
        // An SFTP destination keeps the host keys the admin trusted (unless host or port changed)
        if ( $type === 'sftp' && $destination )
        {
            $old = $destination->configArray();
            if ( isset( $old['host_keys'] ) && ( !isset( $old['host'] ) || $old['host'] === $config['host'] ) && ( !isset( $old['port'] ) || (string)$old['port'] === (string)$config['port'] ) )
                $config['host_keys'] = $old['host_keys'];
        }
        if ( $type === 'local' && $config['path'] !== '' )
        {
            $check = XrowExtractTransportLocal::checkPath( $config['path'] );
            if ( !$check['ok'] )
                $errors[] = $check['message'];
        }
        if ( $errors )
            return array( 'destination' => $destination, 'errors' => $errors );

        // Secrets: a new value replaces, an empty field keeps, "clear" removes; never read back to the form
        $secretFields = call_user_func( array( $class, 'secretFields' ) );
        try
        {
            $secrets = $destination ? XrowExtractSecrets::decrypt( $destination->attribute( 'secret' ) ) : array();
        }
        catch ( Throwable $e )
        {
            $secrets = array(); // unreadable with the current key: whatever is entered now replaces it
        }
        $newSecrets = isset( $values['secrets'] ) && is_array( $values['secrets'] ) ? $values['secrets'] : array();
        $clear = isset( $values['clear_secrets'] ) ? (array)$values['clear_secrets'] : array();
        foreach ( $secretFields as $field => $label )
        {
            if ( in_array( $field, $clear, true ) )
                unset( $secrets[$field] );
            if ( isset( $newSecrets[$field] ) && (string)$newSecrets[$field] !== '' )
                $secrets[$field] = (string)$newSecrets[$field];
        }
        $secrets = array_intersect_key( $secrets, $secretFields );
        try
        {
            $encrypted = XrowExtractSecrets::encrypt( $secrets );
        }
        catch ( Throwable $e )
        {
            return array( 'destination' => $destination, 'errors' => array( $e->getMessage() ) );
        }
        if ( !XrowExtractSchema::ensure() )
            return array( 'destination' => $destination, 'errors' => array( 'The destination table could not be created (see the debug log).' ) );
        $now = time();
        if ( !$destination )
            $destination = new XrowExtractDestination( array( 'dest_type' => $type, 'owner_login' => (string)$ownerLogin, 'created' => $now ) );
        $destination->setAttribute( 'name', $name );
        $destination->setAttribute( 'config', json_encode( $config ) );
        $destination->setAttribute( 'secret', $encrypted );
        $destination->setAttribute( 'modified', $now );
        $destination->store();
        return array( 'destination' => $destination, 'errors' => array() );
    }

    /** Trusts SFTP host key lines (from a scan the admin looked at). */
    public function trustHostKeys( array $lines )
    {
        $config = $this->configArray();
        $config['host_keys'] = implode( "\n", array_map( 'trim', $lines ) );
        $this->setAttribute( 'config', json_encode( $config ) );
        $this->setAttribute( 'modified', time() );
        $this->store();
    }

    /** @return XrowExtractTransport|false */
    public function transport()
    {
        $class = self::transportClass( $this->attribute( 'dest_type' ) );
        if ( !$class )
            return false;
        try
        {
            $secrets = XrowExtractSecrets::decrypt( $this->attribute( 'secret' ) );
        }
        catch ( Throwable $e )
        {
            eZDebug::writeError( 'Destination ' . $this->attribute( 'id' ) . ': ' . $e->getMessage(), __METHOD__ );
            $secrets = array();
        }
        return new $class( $this->configArray(), $secrets );
    }

    /** "Test connection": stored with its time (no secret in the message). */
    public function test()
    {
        $transport = $this->transport();
        $reason = $transport ? call_user_func( array( get_class( $transport ), 'unavailableReason' ) ) : 'unknown type';
        if ( $reason !== '' )
            $result = array( 'ok' => false, 'message' => 'Not available on this server: ' . $reason );
        else
        {
            try
            {
                $result = $transport->test();
            }
            catch ( Throwable $e )
            {
                $result = array( 'ok' => false, 'message' => $e->getMessage() );
            }
        }
        $this->setAttribute( 'last_test', time() );
        $this->setAttribute( 'last_test_result', json_encode( array( 'ok' => (bool)$result['ok'], 'message' => (string)$result['message'], 'time' => time() ) ) );
        $this->store();
        return $result;
    }

    /** How often and how patiently a delivery is tried: xrowextract.ini [Destinations] MaxAttempts, BackoffSeconds. */
    public static function retryPolicy()
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        $attempts = $ini->hasVariable( 'Destinations', 'MaxAttempts' ) ? (int)$ini->variable( 'Destinations', 'MaxAttempts' ) : 3;
        $backoff = $ini->hasVariable( 'Destinations', 'BackoffSeconds' ) ? (int)$ini->variable( 'Destinations', 'BackoffSeconds' ) : 10;
        return array( max( 1, min( 10, $attempts ) ), max( 0, min( 600, $backoff ) ) );
    }

    /**
     * Delivers a file (and its manifest, when there is one) with retries and exponential backoff
     * (BackoffSeconds, twice that, four times ...). Returns array( 'ok', 'message', 'attempts', 'location',
     * 'destination' => name, 'destination_id', 'type', 'seconds', 'log' => one line per attempt ).
     */
    public function deliver( $filePath, $manifestPath = null, $remoteName = null )
    {
        list( $maxAttempts, $backoff ) = self::retryPolicy();
        $started = microtime( true );
        $name = $remoteName !== null ? $remoteName : basename( $filePath );
        $transport = $this->transport();
        $log = array();
        $result = array( 'ok' => false, 'message' => 'unknown destination type' );
        for ( $attempt = 1; $transport && $attempt <= $maxAttempts; $attempt++ )
        {
            try
            {
                if ( $transport instanceof XrowExtractTransportHttp )
                    $result = $transport->upload( $filePath, $name, $manifestPath );
                else
                {
                    $result = $transport->upload( $filePath, $name );
                    if ( $result['ok'] && $manifestPath && is_file( $manifestPath ) )
                    {
                        $manifestResult = $transport->upload( $manifestPath, $name . XrowExtractManifest::SIDECAR_SUFFIX );
                        if ( !$manifestResult['ok'] )
                            $result = array( 'ok' => false, 'message' => 'The file arrived, its manifest did not: ' . $manifestResult['message'] );
                    }
                }
            }
            catch ( Throwable $e )
            {
                $result = array( 'ok' => false, 'message' => $e->getMessage() );
            }
            $log[] = date( 'c' ) . ' attempt ' . $attempt . ': ' . ( $result['ok'] ? 'ok' : 'failed' ) . ' - ' . $result['message'];
            if ( $result['ok'] || !empty( $result['needs_host_key'] ) )
                break;
            if ( $attempt < $maxAttempts && $backoff > 0 )
                sleep( $backoff * pow( 2, $attempt - 1 ) );
        }
        return array(
            'ok' => (bool)$result['ok'],
            'message' => (string)$result['message'],
            'attempts' => $transport ? min( $attempt, $maxAttempts ) : 0,
            'location' => isset( $result['location'] ) ? $result['location'] : '',
            'destination' => $this->attribute( 'name' ),
            'destination_id' => (int)$this->attribute( 'id' ),
            'type' => $this->attribute( 'dest_type' ),
            'seconds' => round( microtime( true ) - $started, 2 ),
            'log' => $log,
        );
    }

    /** Whether the current user may manage destinations and their secrets (policy xrowextract/destinations). */
    public static function canManage()
    {
        $access = eZUser::currentUser()->hasAccessTo( 'xrowextract', 'destinations' );
        return $access['accessWord'] !== 'no';
    }
}

?>
