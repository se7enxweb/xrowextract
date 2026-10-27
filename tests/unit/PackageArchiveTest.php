<?php

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Uploaded package archives (XrowExtractPackage): the tar listing check refuses traversal, absolute paths and links
 * whatever tar the server has (GNU tar or bsdtar); the kernel's header scan (expPackageArchive, where the kernel has
 * it) refuses what ezcArchive cannot read safely before this class opens the archive itself; and an archive opened
 * here never applies the modes packed into it. The listing check itself is tested with fixed listings as GNU tar and
 * bsdtar print them, and the branches that use the kernel's header scan run with a stand-in (tests/stubs/) when the
 * kernel has no expPackageArchive.
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

    public function testThePaxArchiveIsAcceptedByTheKernelScan(): void
    {
        if ( !class_exists( 'expPackageArchive' ) )
            $this->markTestSkipped( 'the kernel has no expPackageArchive' );
        $path = $this->archive( 'pax', array( self::entry( 'PaxHeader/package.xml', 'x', "20 path=package.xml\n" ), self::file( 'package.xml' ) ) );
        $this->assertTrue( XrowExtractPackage::scanArchiveEntries( $path )['ok'], 'tar itself reads it' );

        // an older package imports without issues: the kernel does not refuse it
        $started = microtime( true );
        $this->assertNull( XrowExtractPackage::kernelArchiveProblem( $path ) );
        $this->assertLessThan( 5, microtime( true ) - $started );
    }

    /** The kernel's header scan, with the stand-in of tests/stubs/ when the kernel has none (in a process of its own) */
    #[RunInSeparateProcess]
    public function testTheKernelScanBranchesRunWithAStandIn(): void
    {
        $standIn = !class_exists( 'expPackageArchive' );
        if ( $standIn )
            require dirname( __DIR__ ) . '/stubs/expPackageArchive.php';

        $pax = $this->archive( 'pax', array( self::entry( 'PaxHeader/package.xml', 'x', "20 path=package.xml\n" ), self::file( 'package.xml' ) ) );
        $this->assertNull( XrowExtractPackage::kernelArchiveProblem( $pax ), 'a PAX archive is accepted' );

        $outside = $this->archive( 'outside', array( self::file( '../evil.php' ) ) );
        $this->assertStringStartsWith( 'archive refused: ', (string)XrowExtractPackage::kernelArchiveProblem( $outside ) );
        $this->assertNull( self::openArchiveForReading( $outside ), 'refused by the scan, not opened' );

        $plain = $this->archive( 'plain', array( self::file( 'package.xml' ) ) );
        $this->assertNull( XrowExtractPackage::kernelArchiveProblem( $plain ) );
        $this->assertInstanceOf( ezcArchive::class, self::openArchiveForReading( $plain ) );
        if ( $standIn )
            $this->assertSame( array( $outside, $plain ), expPackageArchive::$opened, 'opened through expPackageArchive::open()' );
    }

    public function testAGnuTarListingIsRead(): void
    {
        $scan = XrowExtractPackage::checkListings(
            array( 'package.xml', 'files/', 'files/a.txt' ),
            array( '-rw-r--r-- 0/0               1 1970-01-01 00:00 package.xml',
                   'drwxr-xr-x 0/0               0 1970-01-01 00:00 files/',
                   '-rw-r--r-- 0/0               1 1970-01-01 00:00 files/a.txt' ) );
        $this->assertSame( array( 'ok' => true, 'entries' => array( 'package.xml', 'files', 'files/a.txt' ), 'error' => null ), $scan );

        $scan = XrowExtractPackage::checkListings(
            array( 'package.xml', 'hard' ),
            array( '-rw-r--r-- 0/0               1 1970-01-01 00:00 package.xml',
                   'hrw-r--r-- 0/0               0 1970-01-01 00:00 hard link to package.xml' ) );
        $this->assertSame( 'hard link entry refused: hard', $scan['error'] );
    }

    public function testABsdtarListingIsRead(): void
    {
        // bsdtar (macOS, the BSDs) prints more columns before the name: the name is taken from the names listing
        $verbose = array( '-rw-r--r--  0 0      0           1 Jan  1  1970 package.xml',
                          '-rw-r--r--  0 0      0           1 Jan  1  1970 ../evil.php' );
        $scan = XrowExtractPackage::checkListings( array( 'package.xml', '../evil.php' ), $verbose );
        $this->assertSame( 'unsafe entry path refused: ../evil.php', $scan['error'] );

        $scan = XrowExtractPackage::checkListings(
            array( 'package.xml', 'link' ),
            array( '-rw-r--r--  0 0      0           1 Jan  1  1970 package.xml',
                   'lrwxr-xr-x  0 0      0           0 Jan  1  1970 link -> /etc/passwd' ) );
        $this->assertSame( 'symlink entry refused: link', $scan['error'] );

        $scan = XrowExtractPackage::checkListings( array( 'package.xml' ), array( '-rw-r--r--  0 0      0           1 Jan  1  1970 package.xml' ) );
        $this->assertTrue( $scan['ok'] );
    }

    public function testANameWithAnEscapedLineBreakIsOneEntry(): void
    {
        // GNU tar and bsdtar both print a line break in a name as "\n": one line in either listing
        $scan = XrowExtractPackage::checkListings(
            array( 'package.xml', 'files/a\nb.txt' ),
            array( '-rw-r--r--  0 0      0           1 Jan  1  1970 package.xml',
                   '-rw-r--r--  0 0      0           1 Jan  1  1970 files/a\nb.txt' ) );
        $this->assertTrue( $scan['ok'], (string)$scan['error'] );
        $this->assertSame( array( 'package.xml', 'files/a\nb.txt' ), $scan['entries'] );
    }

    public function testListingsThatDoNotLineUpAreRefused(): void
    {
        // a tar that printed the line break as it is: two lines in one listing, one in the other
        $scan = XrowExtractPackage::checkListings(
            array( 'package.xml', 'files/a', 'b.txt' ),
            array( '-rw-r--r-- 0/0               1 1970-01-01 00:00 package.xml',
                   '-rw-r--r-- 0/0               1 1970-01-01 00:00 files/a\nb.txt' ) );
        $this->assertSame( array( 'ok' => false, 'entries' => array(), 'error' => 'unreadable archive listing refused' ), $scan );

        $scan = XrowExtractPackage::checkListings( array( 'package.xml', '' ), array( '-rw-r--r-- 0/0 1 1970-01-01 00:00 package.xml', '' ) );
        $this->assertSame( 'unreadable archive listing line refused', $scan['error'] );
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
