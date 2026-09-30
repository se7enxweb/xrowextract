<?php

/**
 * A local folder, or a NAS share mounted on this server. The folder must lie below one of
 * xrowextract.ini [Destinations] LocalPathRoots[] (the same list bounds where a scheduled import may read
 * from), so a destination can never write into the web root, the settings or another installation.
 */
class XrowExtractTransportLocal extends XrowExtractTransport
{
    /** @return array<string, array<mixed>> */
    public static function fields(): array
    {
        return array(
            'path' => array( 'Folder', 'text', '' ),
        );
    }

    /**
     * The allowed roots (absolute, resolved); empty means none is allowed.
     *
     * @return list<string>
     */
    public static function allowedRoots(): array
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        $roots = $ini->hasVariable( 'Destinations', 'LocalPathRoots' ) ? (array)$ini->variable( 'Destinations', 'LocalPathRoots' ) : array();
        $resolved = array();
        foreach ( $roots as $root )
        {
            $root = trim( (string)$root );
            if ( $root === '' )
                continue;
            if ( $root[0] !== '/' )
                $root = eZSys::rootDir() . '/' . $root;
            $real = realpath( $root );
            if ( $real !== false && is_dir( $real ) )
                $resolved[] = rtrim( $real, '/' );
        }
        return $resolved;
    }

    /**
     * $path resolved and checked against the allowed roots: array( 'ok', 'path' => the real path,
     * 'message' ). $mustExist: the folder (or, for a file, the file) must already exist.
     *
     * @param mixed $path
     * @param bool $isFile
     * @return array{ok: true, path: string, message: string}|array{ok: false, message: string}
     */
    public static function checkPath( $path, $isFile = false ): array
    {
        $path = trim( (string)$path );
        if ( $path === '' || $path[0] !== '/' || strpos( $path, "\0" ) !== false )
            return array( 'ok' => false, 'message' => 'The folder must be an absolute path.' );
        $roots = self::allowedRoots();
        if ( !$roots )
            return array( 'ok' => false, 'message' => 'No local folder is allowed: set xrowextract.ini [Destinations] LocalPathRoots[] first.' );
        $real = realpath( $path );
        if ( $real === false )
            return array( 'ok' => false, 'message' => 'The ' . ( $isFile ? 'file' : 'folder' ) . ' ' . $path . ' does not exist.' );
        if ( $isFile ? !is_file( $real ) : !is_dir( $real ) )
            return array( 'ok' => false, 'message' => $path . ' is not a ' . ( $isFile ? 'file' : 'folder' ) . '.' );
        foreach ( $roots as $root )
        {
            if ( $real === $root || strpos( $real, $root . '/' ) === 0 )
                return array( 'ok' => true, 'path' => $real, 'message' => '' );
        }
        return array( 'ok' => false, 'message' => $path . ' is not below an allowed folder (xrowextract.ini [Destinations] LocalPathRoots[]).' );
    }

    public function test(): array
    {
        $check = self::checkPath( $this->config( 'path' ) );
        if ( !$check['ok'] )
            return $check;
        if ( !is_writable( $check['path'] ) )
            return array( 'ok' => false, 'message' => $check['path'] . ' is not writable for this process.' );
        $probe = $check['path'] . '/.xrowextract-test-' . bin2hex( random_bytes( 4 ) );
        if ( @file_put_contents( $probe, 'test' ) === false )
            return array( 'ok' => false, 'message' => 'A test file could not be written to ' . $check['path'] . '.' );
        @unlink( $probe );
        return array( 'ok' => true, 'message' => 'The folder ' . $check['path'] . ' is writable.' );
    }

    public function upload( $localPath, $remoteName ): array
    {
        $check = self::checkPath( $this->config( 'path' ) );
        if ( !$check['ok'] )
            return $check;
        $name = self::safeName( $remoteName );
        $target = $check['path'] . '/' . $name;
        $part = $check['path'] . '/.' . $name . '.part-' . getmypid();
        if ( !@copy( $localPath, $part ) )
            return array( 'ok' => false, 'message' => 'Could not write ' . $target . '.' );
        if ( !@rename( $part, $target ) )
        {
            @unlink( $part );
            return array( 'ok' => false, 'message' => 'Could not move the file into place as ' . $target . '.' );
        }
        return array( 'ok' => true, 'message' => 'Copied to ' . $target, 'location' => $target, 'bytes' => filesize( $target ) );
    }

    public function download( $remotePath, $localPath ): array
    {
        $remotePath = (string)$remotePath;
        $full = $remotePath !== '' && $remotePath[0] === '/' ? $remotePath : rtrim( (string)$this->config( 'path' ), '/' ) . '/' . $remotePath;
        $check = self::checkPath( $full, true );
        if ( !$check['ok'] )
            return $check;
        if ( !@copy( $check['path'], $localPath ) )
            return array( 'ok' => false, 'message' => 'Could not read ' . $check['path'] . '.' );
        return array( 'ok' => true, 'message' => 'Read ' . $check['path'], 'location' => $check['path'] );
    }
}

?>
