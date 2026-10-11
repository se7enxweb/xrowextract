<?php

/**
 * The modes the extension gives its files and directories, within the limits EZP_FILE_MODE_MAX /
 * EZP_DIR_MODE_MAX the kernel reads from config.php (see doc/bc/6.0/file-modes.md of Exponential): a mode is
 * only ever narrowed by them, never widened, and without them it is the mode asked for, as before. On a kernel
 * without those helpers every method returns what it was given, so the extension behaves as it always did.
 *
 * Used by every class of the extension that calls chmod(), mkdir() or umask():
 *   chmod( $file, self::fileMode( 0600 ) );
 *   mkdir( $dir, self::dirMode( 0700 ), true );
 *   $oldUmask = umask( self::creationUmask( 0077 ) );  ...  umask( $oldUmask );
 */
trait XrowExtractFileModes
{
    /**
     * Whether the kernel this runs on has the helper $class::$method(). The helpers are newer than the
     * kernels the extension also runs on, so this is asked at run time, of the kernel that is there, and
     * not answered from the kernel the code is checked against (which has them).
     *
     * @param string $class
     * @param string $method
     * @return bool
     */
    private static function kernelHas( string $class, string $method ): bool
    {
        return method_exists( $class, $method );
    }

    /**
     * The mode $mode of a file within EZP_FILE_MODE_MAX (eZFile::fileMode()).
     *
     * @param int $mode
     * @return int
     */
    private static function fileMode( $mode )
    {
        return self::kernelHas( 'eZFile', 'fileMode' ) ? eZFile::fileMode( $mode ) : (int)$mode;
    }

    /**
     * The mode $mode of a directory within EZP_DIR_MODE_MAX (eZDir::dirMode()).
     *
     * @param int $mode
     * @return int
     */
    private static function dirMode( $mode )
    {
        return self::kernelHas( 'eZDir', 'dirMode' ) ? eZDir::dirMode( $mode ) : (int)$mode;
    }

    /**
     * The mode $mode of a file that has to stay executable (a script ssh runs), within EZP_DIR_MODE_MAX
     * (eZFile::executableMode()).
     *
     * @param int $mode
     * @return int
     */
    private static function executableMode( $mode )
    {
        return self::kernelHas( 'eZFile', 'executableMode' ) ? eZFile::executableMode( $mode ) : (int)$mode;
    }

    /**
     * The umask $umask, narrowed further by the limits (eZFile::creationUmask()): never wider than $umask.
     *
     * @param int $umask
     * @return int
     */
    private static function creationUmask( $umask )
    {
        return self::kernelHas( 'eZFile', 'creationUmask' ) ? eZFile::creationUmask( $umask ) : (int)$umask & 0777;
    }
}
