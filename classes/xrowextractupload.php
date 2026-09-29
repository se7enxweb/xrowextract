<?php

/**
 * A chunked upload: one folder per upload
 * (var/<site>/xrowextract/uploads/<random hex id>/), holding meta.json
 * (owner, original name, total size, bytes received so far, whether it is
 * complete) and data.bin (the bytes received, in order).
 *
 * The browser sends the file as a series of chunks (XrowExtractUploadJS);
 * this class only ever appends a chunk at the exact offset it already has,
 * so a chunk resent after a network error either lands once (offset still
 * matched) or is rejected with the true offset to resync to - never
 * duplicated, never corrupted. An upload is bound to the login that started
 * it: no other user's request can append to it, read its status, or finish
 * it, independent of how easy its id is to guess.
 *
 * Ownership: this process may run as root (Velocity) while PHP-FPM (:443)
 * is alpha; every folder and file this class writes is handed to the var
 * directory's owner exactly like XrowExtractJob does (XrowExtractJob::
 * fixOwnership() is reused directly, not duplicated).
 */
class XrowExtractUpload
{
    const ID_PATTERN = '/^[0-9a-f]{32}$/';
    const META_FILE = 'meta.json';
    const DATA_FILE = 'data.bin';

    /** The folder every chunked upload lives in, created (and handed to the var directory's owner) if missing. */
    public static function baseDir()
    {
        $dir = eZSys::varDirectory() . '/xrowextract/uploads';
        if ( !is_dir( $dir ) )
        {
            $umask = umask( 0077 );
            @mkdir( $dir, 0700, true );
            umask( $umask );
            XrowExtractJob::fixOwnership( $dir );
        }
        return $dir;
    }

    public static function isValidID( $id )
    {
        return is_string( $id ) && preg_match( self::ID_PATTERN, $id ) === 1;
    }

    public static function dir( $id )
    {
        return self::baseDir() . '/' . $id;
    }

    /** A new upload for $login. Returns the new upload id. */
    public static function create( $login, $originalName, $totalSize )
    {
        $id = bin2hex( random_bytes( 16 ) );
        $dir = self::dir( $id );
        $umask = umask( 0077 );
        $made = @mkdir( $dir, 0700, true );
        umask( $umask );
        if ( !$made )
            throw new RuntimeException( 'Cannot create the upload folder' );
        self::saveMeta( $id, array(
            'id' => $id, 'owner' => (string)$login, 'name' => (string)$originalName,
            'total_size' => max( 0, (int)$totalSize ), 'received' => 0, 'complete' => false,
            'created' => time(), 'updated' => time(),
        ) );
        XrowExtractJob::fixOwnership( $dir );
        return $id;
    }

    public static function loadMeta( $id )
    {
        if ( !self::isValidID( $id ) || !is_file( self::dir( $id ) . '/' . self::META_FILE ) )
            return null;
        $raw = @file_get_contents( self::dir( $id ) . '/' . self::META_FILE );
        $meta = $raw !== false ? json_decode( $raw, true ) : null;
        return is_array( $meta ) ? $meta : null;
    }

    public static function saveMeta( $id, array $meta )
    {
        $meta['updated'] = time();
        $file = self::dir( $id ) . '/' . self::META_FILE;
        $tmp = $file . '.tmp-' . getmypid();
        file_put_contents( $tmp, json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
        @chmod( $tmp, 0600 );
        rename( $tmp, $file );
        XrowExtractJob::fixOwnership( $file );
    }

    /** The upload's meta, only when $login started it; null otherwise (no such upload, or someone else's). */
    public static function verifyOwner( $id, $login )
    {
        $meta = self::loadMeta( $id );
        if ( !$meta || !isset( $meta['owner'] ) || $meta['owner'] !== (string)$login )
            return null;
        return $meta;
    }

    /**
     * Appends one chunk. $offset is the byte position the browser believes
     * the server is at (bytes already received); if that does not match
     * reality the chunk is refused and the real offset is returned so the
     * browser can resync (a network error mid-chunk, or a resumed upload in
     * a new tab, both self-heal this way). Returns:
     *   array( 'ok' => true,  'received' => int, 'complete' => bool )
     *   array( 'ok' => false, 'error' => 'not_found'|'offset_mismatch'|'too_large'|'write_failed', 'received' => int|null )
     */
    public static function appendChunk( $id, $login, $offset, $chunkTmpPath, $chunkSize )
    {
        $meta = self::verifyOwner( $id, $login );
        if ( !$meta )
            return array( 'ok' => false, 'error' => 'not_found', 'received' => null );
        $dataFile = self::dir( $id ) . '/' . self::DATA_FILE;
        $current = is_file( $dataFile ) ? filesize( $dataFile ) : 0;
        if ( $meta['complete'] )
            return array( 'ok' => true, 'received' => $current, 'complete' => true );
        if ( (int)$offset !== $current )
            return array( 'ok' => false, 'error' => 'offset_mismatch', 'received' => $current );
        if ( $meta['total_size'] > 0 && $current + $chunkSize > $meta['total_size'] )
            return array( 'ok' => false, 'error' => 'too_large', 'received' => $current );

        $in = @fopen( $chunkTmpPath, 'rb' );
        $out = @fopen( $dataFile, 'ab' );
        if ( !$in || !$out )
        {
            if ( $in ) fclose( $in );
            if ( $out ) fclose( $out );
            return array( 'ok' => false, 'error' => 'write_failed', 'received' => $current );
        }
        $written = stream_copy_to_stream( $in, $out );
        fclose( $in );
        fclose( $out );
        @chmod( $dataFile, 0600 );
        if ( $written === false || $written !== $chunkSize )
        {
            XrowExtractJob::fixOwnership( $dataFile );
            return array( 'ok' => false, 'error' => 'write_failed', 'received' => is_file( $dataFile ) ? filesize( $dataFile ) : $current );
        }
        XrowExtractJob::fixOwnership( $dataFile );

        $received = $current + $written;
        $complete = $meta['total_size'] > 0 && $received >= $meta['total_size'];
        $meta['received'] = $received;
        $meta['complete'] = $complete;
        self::saveMeta( $id, $meta );
        return array( 'ok' => true, 'received' => $received, 'complete' => $complete );
    }

    /** Bytes received so far for $login's upload, or null when it does not exist or is not theirs. */
    public static function receivedBytes( $id, $login )
    {
        $meta = self::verifyOwner( $id, $login );
        if ( !$meta )
            return null;
        $dataFile = self::dir( $id ) . '/' . self::DATA_FILE;
        return is_file( $dataFile ) ? filesize( $dataFile ) : 0;
    }

    /**
     * The finished upload's file path, for the CURRENT user only, or false:
     * no such upload, not this user's, or not yet complete. The small,
     * self-contained API a package-inspection or other consumer of a
     * finished upload needs, independent of how it got there.
     */
    public static function path( $id )
    {
        if ( !self::isValidID( $id ) )
            return false;
        $login = eZUser::currentUser()->attribute( 'login' );
        $meta = self::verifyOwner( $id, $login );
        if ( !$meta || empty( $meta['complete'] ) )
            return false;
        $path = self::dir( $id ) . '/' . self::DATA_FILE;
        return is_file( $path ) ? $path : false;
    }

    /** The original file name of a (the current user's) upload, or ''. */
    public static function originalName( $id )
    {
        $login = eZUser::currentUser()->attribute( 'login' );
        $meta = self::verifyOwner( $id, $login );
        return $meta && isset( $meta['name'] ) ? (string)$meta['name'] : '';
    }

    /** Deletes one upload's folder. */
    public static function delete( $id )
    {
        if ( !self::isValidID( $id ) )
            return false;
        $dir = self::dir( $id );
        if ( !is_dir( $dir ) )
            return false;
        foreach ( (array)@scandir( $dir ) as $entry )
        {
            if ( $entry === '.' || $entry === '..' )
                continue;
            $full = $dir . '/' . $entry;
            if ( is_file( $full ) )
                @unlink( $full );
        }
        return @rmdir( $dir );
    }

    /** How many hours an incomplete (or complete but unclaimed) upload is kept: csv.ini [Uploads] RetentionHours, default 24. */
    public static function retentionHours()
    {
        $ini = eZINI::instance( 'csv.ini' );
        if ( $ini->hasVariable( 'Uploads', 'RetentionHours' ) )
        {
            $hours = (int)$ini->variable( 'Uploads', 'RetentionHours' );
            if ( $hours > 0 )
                return $hours;
        }
        return 24;
    }

    /** Removes upload folders untouched for longer than the retention. Returns how many. */
    public static function cleanupStale()
    {
        $cutoff = time() - self::retentionHours() * 3600;
        $removed = 0;
        foreach ( (array)@scandir( self::baseDir() ) as $entry )
        {
            if ( !self::isValidID( $entry ) )
                continue;
            $meta = self::loadMeta( $entry );
            $when = $meta && isset( $meta['updated'] ) ? (int)$meta['updated'] : 0;
            if ( $when < $cutoff )
            {
                self::delete( $entry );
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Free disk space of the upload folder's filesystem, and whether an
     * incoming file of $incomingSize bytes would still leave at least
     * $marginBytes free afterwards. There is no fixed file size cap - only
     * this: real capacity, checked before the first byte is accepted.
     */
    public static function freeDiskSpace()
    {
        $dir = self::baseDir();
        $free = @disk_free_space( $dir );
        return $free === false ? null : (float)$free;
    }

    public static function marginBytes()
    {
        $ini = eZINI::instance( 'csv.ini' );
        if ( $ini->hasVariable( 'Uploads', 'MinFreeMarginMB' ) )
        {
            $mb = (int)$ini->variable( 'Uploads', 'MinFreeMarginMB' );
            if ( $mb > 0 )
                return $mb * 1024 * 1024;
        }
        return 256 * 1024 * 1024; // 256 MB headroom left after the file, for everything else on the same filesystem
    }

    /** array( ok, message|null, free|null ): whether $incomingSize more bytes still fit with the margin. */
    public static function hasRoomFor( $incomingSize )
    {
        $free = self::freeDiskSpace();
        if ( $free === null )
            return array( true, null, null ); // disk_free_space() not available: do not block on a check we cannot make
        $needed = (float)$incomingSize + self::marginBytes();
        if ( $free < $needed )
        {
            return array( false, sprintf(
                'Not enough free disk space: %s available, %s needed for this file plus a safety margin.',
                self::humanSize( $free ), self::humanSize( $needed )
            ), $free );
        }
        return array( true, null, $free );
    }

    public static function humanSize( $bytes )
    {
        $bytes = (float)$bytes;
        foreach ( array( 'B', 'KB', 'MB', 'GB', 'TB' ) as $unit )
        {
            if ( $bytes < 1024 || $unit === 'TB' )
                return ( $unit === 'B' ? (int)$bytes : round( $bytes, 1 ) ) . ' ' . $unit;
            $bytes /= 1024;
        }
        return $bytes . ' B';
    }
}

?>
