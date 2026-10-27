<?php
/**
 * A stand-in for the kernel class expPackageArchive (proposed to the kernel separately), for a kernel that does not
 * have it: the same three static methods, so that the branches of XrowExtractPackage that use the kernel's header
 * scan run in the unit tests as well. Loaded only when the kernel has no expPackageArchive of its own, and only in
 * a test that runs in a process of its own. Its scan accepts a PAX archive, as the kernel's does, and refuses an entry outside the package directory.
 */
class expPackageArchive
{
    /** @var list<string> the paths open() was asked for */
    public static array $opened = array();

    public static function scan( string $path ): ?string
    {
        $data = @gzdecode( (string)file_get_contents( $path ) );
        if ( !is_string( $data ) )
            return 'unreadable';
        for ( $offset = 0; $offset + 512 <= strlen( $data ); )
        {
            $header = substr( $data, $offset, 512 );
            if ( trim( $header, "\0" ) === '' )
                return null;
            $type = $header[156];
            if ( $type !== 'x' && $type !== 'g' && strpos( rtrim( substr( $header, 0, 100 ), "\0" ), '../' ) === 0 )
                return 'path outside the package';
            $size = octdec( trim( substr( $header, 124, 12 ), "\0 " ) );
            $offset += 512 + (int)ceil( $size / 512 ) * 512;
        }
        return null;
    }

    public static function problemText( string $problem ): string
    {
        return $problem;
    }

    /** @return ezcArchive|null */
    public static function open( string $path, &$problem )
    {
        self::$opened[] = $path;
        $problem = self::scan( $path );
        if ( $problem !== null )
            return null;
        return ezcArchive::open( "compress.zlib://$path", ezcArchive::TAR_GNU, new ezcArchiveOptions( array( 'readOnly' => true ) ) );
    }
}
