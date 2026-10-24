<?php

use PHPUnit\Framework\TestCase;

/**
 * Uploaded package archives (XrowExtractPackage): the tar listing check refuses traversal, absolute paths and links
 * whatever tar the server has (GNU tar or bsdtar); the kernel's header scan (expPackageArchive, where the kernel has
 * it) refuses what ezcArchive cannot read safely before this class opens the archive itself; and an archive opened
 * here never applies the modes packed into it.
 */
class PackageArchiveTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = XROWEXTRACT_TEST_VAR . '/packagearchive-' . getmypid();
        if ( !is_dir( $this->dir ) )
            mkdir( $this->dir, 0700, true );
    }

    protected function tearDown(): void
    {
        self::remove( $this->dir );
    }

    public function testAPlainPackageIsListed(): void
    {
        $scan = XrowExtractPackage::scanArchiveEntries( $this->archive( 'ok', array( self::file( 'package.xml' ), self::dir( 'files/' ), self::file( 'files/a.txt' ) ) ) );
        $this->assertTrue( $scan['ok'], (string)$scan['error'] );
        $this->assertSame( array( 'package.xml', 'files', 'files/a.txt' ), $scan['entries'] );
    }

    public function testAParentDirectoryEntryIsRefused(): void
    {
        $scan = XrowExtractPackage::scanArchiveEntries( $this->archive( 'dotdot', array( self::file( 'package.xml' ), self::file( '../evil.php' ) ) ) );
        $this->assertFalse( $scan['ok'] );
        $this->assertSame( 'unsafe entry path refused: ../evil.php', $scan['error'] );
    }

    public function testAnAbsoluteEntryIsRefused(): void
    {
        $scan = XrowExtractPackage::scanArchiveEntries( $this->archive( 'absolute', array( self::file( 'package.xml' ), self::file( '/tmp/evil.php' ) ) ) );
        $this->assertFalse( $scan['ok'] );
        $this->assertSame( 'unsafe entry path refused: /tmp/evil.php', $scan['error'] );
    }

    public function testLinksAreRefused(): void
    {
        $scan = XrowExtractPackage::scanArchiveEntries( $this->archive( 'symlink', array( self::file( 'package.xml' ), self::entry( 'link', '2', '', '/etc/passwd' ) ) ) );
        $this->assertFalse( $scan['ok'] );
        $this->assertSame( 'symlink entry refused: link', $scan['error'] );

        $scan = XrowExtractPackage::scanArchiveEntries( $this->archive( 'hardlink', array( self::file( 'package.xml' ), self::entry( 'hard', '1', '', 'package.xml' ) ) ) );
        $this->assertFalse( $scan['ok'] );
        $this->assertSame( 'hard link entry refused: hard', $scan['error'] );
    }

    public function testThePaxArchiveIsRefusedByTheKernelScan(): void
    {
        if ( !class_exists( 'expPackageArchive' ) )
            $this->markTestSkipped( 'the kernel has no expPackageArchive' );
        $path = $this->archive( 'pax', array( self::entry( 'PaxHeader/package.xml', 'x', "20 path=package.xml\n" ), self::file( 'package.xml' ) ) );
        $this->assertTrue( XrowExtractPackage::scanArchiveEntries( $path )['ok'], 'tar itself reads it' );

        $started = microtime( true );
        $this->assertStringStartsWith( 'archive refused: ', (string)XrowExtractPackage::kernelArchiveProblem( $path ) );
        $this->assertNull( self::openArchiveForReading( $path ), 'never handed to ezcArchive' );
        $this->assertLessThan( 5, microtime( true ) - $started );
    }

    public function testAPlainPackagePassesTheKernelScan(): void
    {
        $this->assertNull( XrowExtractPackage::kernelArchiveProblem( $this->archive( 'ok', array( self::file( 'package.xml' ) ) ) ) );
    }

    public function testTheModesOfTheArchiveAreNotApplied(): void
    {
        $path = $this->archive( 'modes', array( self::dir( 'open/', '0000777' ), self::file( 'open/setuid', 'x', '0004777' ) ) );
        $archive = self::openArchiveForReading( $path );
        $this->assertInstanceOf( ezcArchive::class, $archive );
        $target = $this->dir . '/extracted';
        mkdir( $target, 0700 );
        $archive->extract( $target );
        clearstatcache();
        $this->assertSame( 0644, fileperms( $target . '/open/setuid' ) & 07777 );
        $this->assertSame( 0755, fileperms( $target . '/open' ) & 07777 );
    }

    private static function openArchiveForReading( string $path )
    {
        $method = new ReflectionMethod( XrowExtractPackage::class, 'openArchiveForReading' );
        return $method->invoke( null, $path );
    }

    /** A gzip compressed tar archive of $entries (each a string of header and data blocks), at a path of its own */
    private function archive( string $name, array $entries ): string
    {
        $path = $this->dir . '/' . $name . '.ezpkg';
        file_put_contents( $path, gzencode( implode( '', $entries ) . str_repeat( "\0", 1024 ) ) );
        return $path;
    }

    private static function file( string $name, string $data = 'x', string $mode = '0000644' ): string
    {
        return self::entry( $name, '0', $data, '', $mode );
    }

    private static function dir( string $name, string $mode = '0000755' ): string
    {
        return self::entry( $name, '5', '', '', $mode );
    }

    /** One tar entry in the GNU format (magic "ustar  "), as eZPackage writes them */
    private static function entry( string $name, string $type, string $data = '', string $link = '', string $mode = '0000644' ): string
    {
        $header = str_pad( $name, 100, "\0" )
                . $mode . "\0"
                . "0000000\0" . "0000000\0"
                . sprintf( '%011o', strlen( $data ) ) . "\0"
                . sprintf( '%011o', 0 ) . "\0"
                . '        '
                . $type
                . str_pad( $link, 100, "\0" )
                . "ustar  \0";
        $header = str_pad( $header, 512, "\0" );
        $sum = 0;
        for ( $i = 0; $i < 512; $i++ )
            $sum += ord( $header[$i] );
        $header = substr_replace( $header, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
        $padding = strlen( $data ) % 512 === 0 ? 0 : 512 - strlen( $data ) % 512;
        return $header . $data . str_repeat( "\0", $padding );
    }

    private static function remove( string $path ): void
    {
        if ( is_link( $path ) || is_file( $path ) )
        {
            unlink( $path );
            return;
        }
        if ( !is_dir( $path ) )
            return;
        chmod( $path, 0700 );
        foreach ( scandir( $path ) as $name )
        {
            if ( $name !== '.' && $name !== '..' )
                self::remove( $path . '/' . $name );
        }
        rmdir( $path );
    }
}
