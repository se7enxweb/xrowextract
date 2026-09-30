<?php

/**
 * SFTP. This server's PHP has no ssh2 extension, so the system's OpenSSH sftp client is used: started
 * with proc_open() and an argument array (no shell is ever involved, nothing is interpolated into a
 * command line), with its own private known_hosts file holding exactly the host key the admin trusted
 * on the Destinations page (StrictHostKeyChecking=yes: an unknown or changed key is refused), and the
 * batch commands in a private file.
 *
 * Authentication:
 *  - key (preferred): the private key, stored encrypted like every secret, is written to a 0600 file in a
 *    private folder for the length of one call; BatchMode=yes, so nothing ever waits for a prompt.
 *  - password: through SSH_ASKPASS (OpenSSH 8.4+, SSH_ASKPASS_REQUIRE=force) and a helper that prints the
 *    password from the child's own environment; no password is written to disk or put on a command line.
 *
 * When the ssh2 extension is present it is not used either: one code path, the one tested here.
 */
class XrowExtractTransportSftp extends XrowExtractTransport
{
    public static function fields()
    {
        return array(
            'host' => array( 'Host', 'text', '' ),
            'port' => array( 'Port', 'number', '22' ),
            'user' => array( 'User', 'text', '' ),
            'path' => array( 'Folder', 'text', '' ),
            'auth' => array( 'Sign in with', 'select', 'key', array( 'key' => 'Private key', 'password' => 'Password' ) ),
        );
    }

    public static function secretFields()
    {
        return array( 'private_key' => 'Private key (OpenSSH format, without a passphrase)', 'password' => 'Password' );
    }

    /** The binaries this transport runs. */
    public static function binary( $name )
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        $configured = $ini->hasVariable( 'Destinations', 'SshBinaryDir' ) ? trim( (string)$ini->variable( 'Destinations', 'SshBinaryDir' ) ) : '';
        foreach ( array_filter( array( $configured, '/usr/bin', '/usr/local/bin', '/bin' ) ) as $dir )
        {
            if ( is_executable( $dir . '/' . $name ) )
                return $dir . '/' . $name;
        }
        return false;
    }

    public static function unavailableReason()
    {
        if ( !function_exists( 'proc_open' ) )
            return 'proc_open() is disabled';
        foreach ( array( 'sftp', 'ssh-keyscan', 'ssh-keygen' ) as $name )
        {
            if ( !self::binary( $name ) )
                return 'the ' . $name . ' binary (OpenSSH client) is not installed';
        }
        return '';
    }

    protected function host()
    {
        $host = trim( (string)$this->config( 'host' ) );
        return preg_match( '/^[A-Za-z0-9][A-Za-z0-9.-]*$|^[0-9a-fA-F:]+$/', $host ) ? $host : false;
    }

    protected function port()
    {
        $port = (int)$this->config( 'port', 22 );
        return $port > 0 && $port < 65536 ? $port : 22;
    }

    protected function user()
    {
        $user = trim( (string)$this->config( 'user' ) );
        return preg_match( '/^[A-Za-z0-9._][A-Za-z0-9._-]*$/', $user ) ? $user : false;
    }

    /**
     * Runs a command given as an argument array (no shell). Returns array( exit code, stdout, stderr ).
     * $env: extra environment; $stdin: text for the child's stdin.
     */
    public static function run( array $argv, array $env = array(), $stdin = '', $timeout = 120 )
    {
        $descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
        $baseEnv = array( 'PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'HOME' => sys_get_temp_dir() );
        $process = @proc_open( $argv, $descriptors, $pipes, null, array_merge( $baseEnv, $env ) );
        if ( !is_resource( $process ) )
            return array( 127, '', 'could not start ' . $argv[0] );
        fwrite( $pipes[0], (string)$stdin );
        fclose( $pipes[0] );
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );
        $out = '';
        $err = '';
        $deadline = time() + $timeout;
        while ( true )
        {
            $read = array( $pipes[1], $pipes[2] );
            $write = null;
            $except = null;
            if ( @stream_select( $read, $write, $except, 1 ) )
            {
                foreach ( $read as $pipe )
                {
                    $chunk = fread( $pipe, 65536 );
                    if ( $pipe === $pipes[1] )
                        $out .= $chunk;
                    else
                        $err .= $chunk;
                }
            }
            $status = proc_get_status( $process );
            if ( !$status['running'] )
            {
                $out .= stream_get_contents( $pipes[1] );
                $err .= stream_get_contents( $pipes[2] );
                break;
            }
            if ( time() > $deadline )
            {
                proc_terminate( $process );
                $err .= "\ntimed out after $timeout s";
                break;
            }
        }
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $code = proc_close( $process );
        if ( !$status['running'] && $status['exitcode'] >= 0 )
            $code = $status['exitcode'];
        return array( (int)$code, $out, $err );
    }

    /** The host's keys as known_hosts lines (ssh-keyscan), with their SHA256 fingerprints. */
    public function scanHostKeys()
    {
        $host = $this->host();
        if ( !$host )
            return array( 'ok' => false, 'message' => 'The host name is not valid.' );
        list( $code, $out, $err ) = self::run( array( self::binary( 'ssh-keyscan' ), '-T', '10', '-p', (string)$this->port(), '--', $host ), array(), '', 30 );
        $lines = array_values( array_filter( array_map( 'trim', explode( "\n", $out ) ), function ( $l ) { return $l !== '' && $l[0] !== '#'; } ) );
        if ( !$lines )
            return array( 'ok' => false, 'message' => 'No host key received from ' . $host . ':' . $this->port() . ( trim( $err ) !== '' ? ' (' . trim( $err ) . ')' : '' ) );
        $keys = array();
        foreach ( $lines as $line )
            $keys[] = array( 'line' => $line, 'fingerprint' => self::fingerprint( $line ) );
        return array( 'ok' => true, 'keys' => $keys, 'message' => count( $keys ) . ' host key(s) received.' );
    }

    /** The SHA256 fingerprint of one known_hosts line ("SHA256:... (ED25519)"). */
    public static function fingerprint( $line )
    {
        $dir = self::privateTempDir();
        if ( !$dir )
            return '';
        file_put_contents( $dir . '/key', $line . "\n" );
        list( $code, $out ) = self::run( array( self::binary( 'ssh-keygen' ), '-l', '-E', 'sha256', '-f', $dir . '/key' ) );
        self::removeDir( $dir );
        return $code === 0 && preg_match( '/(SHA256:\S+).*(\([A-Z0-9-]+\))/', $out, $m ) ? $m[1] . ' ' . $m[2] : '';
    }

    /** The trusted host key lines (config 'host_keys', one per line), for this host and port only. */
    protected function knownHosts()
    {
        $lines = array();
        foreach ( explode( "\n", (string)$this->config( 'host_keys' ) ) as $line )
        {
            $line = trim( $line );
            if ( $line === '' || $line[0] === '#' )
                continue;
            $parts = preg_split( '/\s+/', $line );
            if ( count( $parts ) < 3 || !preg_match( '/^(ssh-|ecdsa-|sk-)/', $parts[1] ) )
                continue;
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * One sftp session running $commands (sftp batch lines). Returns array( ok, message, stdout ).
     * Refuses to connect without a trusted host key.
     */
    protected function session( array $commands )
    {
        $reason = self::unavailableReason();
        if ( $reason !== '' )
            return array( 'ok' => false, 'message' => 'SFTP is not available: ' . $reason );
        $host = $this->host();
        $user = $this->user();
        if ( !$host || !$user )
            return array( 'ok' => false, 'message' => 'The host or user name is not valid.' );
        $known = $this->knownHosts();
        if ( !$known )
            return array( 'ok' => false, 'message' => 'No trusted host key yet: use "Test connection" and trust the host key first.', 'needs_host_key' => true );
        $dir = self::privateTempDir();
        if ( !$dir )
            return array( 'ok' => false, 'message' => 'No private temp folder.' );
        file_put_contents( $dir . '/known_hosts', implode( "\n", $known ) . "\n" );
        file_put_contents( $dir . '/batch', implode( "\n", $commands ) . "\n" );
        $argv = array( self::binary( 'sftp' ) );
        $env = array();
        $options = array(
            'StrictHostKeyChecking=yes', 'UserKnownHostsFile=' . $dir . '/known_hosts', 'GlobalKnownHostsFile=/dev/null',
            'ConnectTimeout=20', 'ServerAliveInterval=15', 'ServerAliveCountMax=4', 'LogLevel=ERROR',
            'UpdateHostKeys=no', 'HashKnownHosts=no', 'ControlMaster=no', 'ForwardAgent=no',
        );
        if ( $this->config( 'auth', 'key' ) === 'password' )
        {
            if ( $this->secret( 'password' ) === '' )
            {
                self::removeDir( $dir );
                return array( 'ok' => false, 'message' => 'No password is set for this destination.' );
            }
            // The first value of an option wins in ssh: this BatchMode=no comes before the one sftp adds for -b
            $options = array_merge( array( 'BatchMode=no', 'PubkeyAuthentication=no', 'PreferredAuthentications=password,keyboard-interactive',
                                           'NumberOfPasswordPrompts=1', 'IdentitiesOnly=yes', 'IdentityFile=none' ), $options );
            file_put_contents( $dir . '/askpass', "#!/bin/sh\nprintf '%s\\n' \"\$XRE_SFTP_SECRET\"\n" );
            chmod( $dir . '/askpass', 0700 );
            $env = array( 'SSH_ASKPASS' => $dir . '/askpass', 'SSH_ASKPASS_REQUIRE' => 'force', 'DISPLAY' => 'xrowextract:0',
                          'XRE_SFTP_SECRET' => $this->secret( 'password' ) );
        }
        else
        {
            $key = str_replace( "\r\n", "\n", trim( $this->secret( 'private_key' ) ) ) . "\n";
            if ( trim( $key ) === '' )
            {
                self::removeDir( $dir );
                return array( 'ok' => false, 'message' => 'No private key is set for this destination.' );
            }
            file_put_contents( $dir . '/id', $key );
            chmod( $dir . '/id', 0600 );
            $options = array_merge( array( 'BatchMode=yes', 'IdentitiesOnly=yes', 'IdentityFile=' . $dir . '/id', 'PasswordAuthentication=no',
                                           'KbdInteractiveAuthentication=no' ), $options );
        }
        foreach ( $options as $option )
        {
            $argv[] = '-o';
            $argv[] = $option;
        }
        $argv[] = '-P';
        $argv[] = (string)$this->port();
        $argv[] = '-b';
        $argv[] = $dir . '/batch';
        $argv[] = $user . '@' . ( strpos( $host, ':' ) !== false ? '[' . $host . ']' : $host );
        list( $code, $out, $err ) = self::run( $argv, $env, '', 1800 );
        self::removeDir( $dir );
        $err = trim( preg_replace( '/\s+/', ' ', $err ) );
        if ( $code !== 0 )
        {
            if ( stripos( $err, 'host key verification failed' ) !== false || stripos( $err, 'REMOTE HOST IDENTIFICATION HAS CHANGED' ) !== false )
                return array( 'ok' => false, 'message' => 'The host key does not match the trusted one: refused. ' . $err, 'needs_host_key' => true );
            return array( 'ok' => false, 'message' => 'sftp exit code ' . $code . ( $err !== '' ? ': ' . mb_substr( $err, 0, 400 ) : '' ) );
        }
        return array( 'ok' => true, 'message' => '', 'stdout' => $out );
    }

    /** An sftp batch argument: double quoted, with no quote, backslash or control character inside. */
    protected static function quote( $path )
    {
        return '"' . preg_replace( '/["\\\\\x00-\x1f\x7f]/', '', (string)$path ) . '"';
    }

    protected function folder()
    {
        $folder = self::safeFolder( $this->config( 'path', '' ) );
        return $folder === false ? false : $folder;
    }

    public function test()
    {
        $folder = $this->folder();
        if ( $folder === false )
            return array( 'ok' => false, 'message' => 'The folder is not valid.' );
        if ( !$this->knownHosts() )
        {
            $scan = $this->scanHostKeys();
            if ( !$scan['ok'] )
                return $scan;
            return array( 'ok' => false, 'needs_host_key' => true, 'keys' => $scan['keys'],
                          'message' => 'The server answered. Check its host key fingerprint and trust it to continue.' );
        }
        $commands = array_merge( array( 'pwd' ), $this->folderCommands( $folder ) );
        $commands[] = 'ls -1';
        $result = $this->session( $commands );
        if ( !$result['ok'] )
            return $result;
        return array( 'ok' => true, 'message' => 'Signed in as ' . $this->user() . ' and listed ' . ( $folder !== '' ? $folder : 'the home folder' ) . '.' );
    }

    /** Batch lines creating each level of the folder if missing ("-": an existing one is no error), then going there. */
    protected function folderCommands( $folder )
    {
        $commands = array();
        if ( $folder === '' )
            return $commands;
        $walk = strpos( $folder, '/' ) === 0 ? '' : null;
        foreach ( array_filter( explode( '/', $folder ), static function ( $part ) { return $part !== ''; } ) as $segment )
        {
            $walk = $walk === null ? $segment : $walk . '/' . $segment;
            $commands[] = '-mkdir ' . self::quote( $walk );
        }
        $commands[] = 'cd ' . self::quote( $folder );
        return $commands;
    }

    public function upload( $localPath, $remoteName )
    {
        $folder = $this->folder();
        if ( $folder === false )
            return array( 'ok' => false, 'message' => 'The folder is not valid.' );
        $name = self::safeName( $remoteName );
        $commands = $this->folderCommands( $folder );
        $commands[] = 'put ' . self::quote( $localPath ) . ' ' . self::quote( '.' . $name . '.part' );
        $commands[] = '-rm ' . self::quote( $name );
        $commands[] = 'rename ' . self::quote( '.' . $name . '.part' ) . ' ' . self::quote( $name );
        $result = $this->session( $commands );
        if ( !$result['ok'] )
            return $result;
        $location = 'sftp://' . $this->user() . '@' . $this->host() . ':' . $this->port() . ( $folder !== '' ? '/' . ltrim( $folder, '/' ) : '' ) . '/' . $name;
        return array( 'ok' => true, 'message' => 'Uploaded to ' . $location, 'location' => $location );
    }

    public function download( $remotePath, $localPath )
    {
        $folder = $this->folder();
        if ( $folder === false || strpos( (string)$remotePath, '..' ) !== false )
            return array( 'ok' => false, 'message' => 'Not a path below the destination folder.' );
        $commands = array();
        if ( $folder !== '' )
            $commands[] = 'cd ' . self::quote( $folder );
        $commands[] = 'get ' . self::quote( $remotePath ) . ' ' . self::quote( $localPath );
        $result = $this->session( $commands );
        if ( !$result['ok'] )
            return $result;
        return array( 'ok' => is_file( $localPath ), 'message' => is_file( $localPath ) ? 'Downloaded ' . $remotePath : 'Nothing was downloaded.' );
    }
}

?>
