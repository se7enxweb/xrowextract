<?php

/**
 * Destination credentials at rest: libsodium secretbox (XSalsa20-Poly1305) with a 32 byte key kept in a
 * key file outside the database. xrowextract.ini [Secrets] KeyFile names it (set it in settings/override;
 * a relative path is relative to the installation root); empty means settings/override/xrowextract-secrets.key.
 *
 * The key is generated on first use, 0600, and handed to the owner of the folder it is in: PHP-FPM runs as
 * that owner (alpha) and Velocity or a root command line (root) can read it anyway, so both sides decrypt
 * what the other encrypted. The database only ever holds "xs1:" + base64( nonce . box ).
 *
 * Losing the key file makes every stored secret unreadable (they have to be entered again); nothing else
 * breaks. Never commit it: settings/override is not part of any repository.
 */
class XrowExtractSecrets
{
    const PREFIX = 'xs1:';

    /** Where the key file is (absolute). */
    public static function keyFilePath()
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        $path = $ini->hasVariable( 'Secrets', 'KeyFile' ) ? trim( (string)$ini->variable( 'Secrets', 'KeyFile' ) ) : '';
        if ( $path === '' )
            $path = 'settings/override/xrowextract-secrets.key';
        if ( $path[0] !== '/' )
            $path = eZSys::rootDir() . '/' . $path;
        return $path;
    }

    public static function available()
    {
        return function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
    }

    /** The key (32 raw bytes), generated and stored on first use. Throws when it can be neither read nor written. */
    protected static function key()
    {
        static $key = null;
        if ( $key !== null )
            return $key;
        if ( !self::available() )
            throw new RuntimeException( 'The PHP sodium extension is not available: destination secrets cannot be stored.' );
        $path = self::keyFilePath();
        if ( is_file( $path ) )
        {
            $raw = (string)@file_get_contents( $path );
            $decoded = base64_decode( trim( $raw ), true );
            if ( $decoded === false || strlen( $decoded ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES )
                throw new RuntimeException( 'The secrets key file ' . $path . ' is not a valid key.' );
            return $key = $decoded;
        }
        $dir = dirname( $path );
        if ( !is_dir( $dir ) || !is_writable( $dir ) )
            throw new RuntimeException( 'The secrets key file cannot be created in ' . $dir . ' (xrowextract.ini [Secrets] KeyFile).' );
        $new = sodium_crypto_secretbox_keygen();
        $tmp = $path . '.tmp-' . getmypid();
        $umask = umask( 0077 );
        $written = @file_put_contents( $tmp, base64_encode( $new ) . "\n", LOCK_EX );
        umask( $umask );
        if ( $written === false )
            throw new RuntimeException( 'The secrets key file ' . $path . ' could not be written.' );
        @chmod( $tmp, 0600 );
        self::giveToFolderOwner( $tmp, $dir );
        // Two processes creating it at once: the first rename wins, the other one reads that key
        if ( !@link( $tmp, $path ) )
        {
            @unlink( $tmp );
            clearstatcache();
            if ( !is_file( $path ) )
                throw new RuntimeException( 'The secrets key file ' . $path . ' could not be written.' );
            return self::key();
        }
        @unlink( $tmp );
        return $key = $new;
    }

    /** Hands a file to the owner of $dir when running as root (Velocity, a root command line). */
    protected static function giveToFolderOwner( $path, $dir )
    {
        if ( !XrowExtractJob::runningAsRoot() )
            return;
        $owner = @fileowner( $dir );
        $group = @filegroup( $dir );
        // false (the folder cannot be read) would be taken as 0: root
        if ( $owner !== false )
            @chown( $path, $owner );
        if ( $group !== false )
            @chgrp( $path, $group );
    }

    /** Encrypts an array of secret values (JSON inside the box). An empty array is stored as ''. */
    public static function encrypt( array $values )
    {
        $values = array_filter( $values, function ( $v ) { return $v !== null && $v !== ''; } );
        if ( !$values )
            return '';
        $plain = json_encode( $values );
        // json_encode() refuses a value that is not UTF-8; boxing its false would store an empty secret
        if ( $plain === false )
            throw new RuntimeException( 'A secret is not valid UTF-8 text and cannot be stored.' );
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $box = sodium_crypto_secretbox( $plain, $nonce, self::key() );
        return self::PREFIX . base64_encode( $nonce . $box );
    }

    /** The array encrypt() was given, or array() for '' ; throws when the value cannot be opened (wrong key, tampered). */
    public static function decrypt( $stored )
    {
        $stored = (string)$stored;
        if ( $stored === '' )
            return array();
        if ( strpos( $stored, self::PREFIX ) !== 0 )
            throw new RuntimeException( 'Not an encrypted secret.' );
        $raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
        if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES )
            throw new RuntimeException( 'The stored secret is damaged.' );
        $plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
        if ( $plain === false )
            throw new RuntimeException( 'The stored secret cannot be opened with this key (xrowextract.ini [Secrets] KeyFile).' );
        $values = json_decode( $plain, true );
        return is_array( $values ) ? $values : array();
    }

    /** Which secret names are set (never their values), for the write-only secret fields of the GUI. */
    public static function names( $stored )
    {
        try
        {
            return array_keys( self::decrypt( $stored ) );
        }
        catch ( Exception $e )
        {
            return array();
        }
    }
}

?>
