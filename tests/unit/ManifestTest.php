<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractManifest: the typed column manifest, its sidecar file, the mapping it gives an import, zips. */
class ManifestTest extends TestCase
{
    private $dir;

    protected function setUp(): void
    {
        $this->dir = XROWEXTRACT_TEST_VAR . '/manifest-' . getmypid();
        if ( !is_dir( $this->dir ) )
            mkdir( $this->dir, 0700, true );
    }

    protected function tearDown(): void
    {
        foreach ( (array)glob( $this->dir . '/*' ) as $file )
            unlink( $file );
        rmdir( $this->dir );
    }

    private function manifest()
    {
        return array(
            'manifest_version' => 1,
            'class' => array( 'identifier' => 'folder' ),
            'columns' => array(
                array( 'key' => 'name', 'id' => 'name' ),
                array( 'key' => 'renamed', 'id' => 'short_name' ),
                array( 'key' => 'remote-id', 'id' => 'ezcontentobject.remote_id' ),
            ),
            'file' => array( 'sha256' => hash( 'sha256', "a\n" ) ),
            'counts' => array( 'rows' => 1 ),
        );
    }

    public function testRecognition(): void
    {
        $this->assertTrue( XrowExtractManifest::isManifest( $this->manifest() ) );
        $this->assertTrue( XrowExtractManifest::isColumnManifest( $this->manifest() ) );
        $this->assertTrue( XrowExtractManifest::isManifest( array( 'manifest_version' => 1, 'classes' => array() ) ) );
        $this->assertFalse( XrowExtractManifest::isColumnManifest( array( 'manifest_version' => 1, 'classes' => array() ) ) );
        foreach ( array( null, 'x', array(), array( 'columns' => array() ), array( 'manifest_version' => 1, 'columns' => 'x' ) ) as $bad )
            $this->assertFalse( XrowExtractManifest::isManifest( $bad ), var_export( $bad, true ) );
    }

    public function testSidecarRoundTrip(): void
    {
        $data = $this->dir . '/folders.csv';
        file_put_contents( $data, "a\n" );
        $this->assertSame( $data . '.manifest.json', XrowExtractManifest::sidecarPath( $data ) );
        file_put_contents( XrowExtractManifest::sidecarPath( $data ), XrowExtractManifest::encode( $this->manifest() + array( 'note' => "caf\xE9" ) ) );
        $read = XrowExtractManifest::readFile( XrowExtractManifest::sidecarPath( $data ) );
        $this->assertSame( $this->manifest()['columns'], $read['columns'] );
        $this->assertSame( "caf\u{FFFD}", $read['note'] );
        $this->assertNull( XrowExtractManifest::readFile( $this->dir . '/missing.json' ) );
        file_put_contents( $this->dir . '/broken.json', '{not json' );
        $this->assertNull( XrowExtractManifest::readFile( $this->dir . '/broken.json' ) );
    }

    public function testHeaderCopyDropsTheFinishedParts(): void
    {
        $copy = XrowExtractManifest::headerCopy( $this->manifest() + array( 'finished' => 'x' ) );
        $this->assertArrayNotHasKey( 'file', $copy );
        $this->assertArrayNotHasKey( 'counts', $copy );
        $this->assertArrayNotHasKey( 'finished', $copy );
        $this->assertSame( $this->manifest()['columns'], $copy['columns'] );
    }

    public function testImportMapping(): void
    {
        $data = $this->dir . '/folders.csv';
        file_put_contents( $data, "a\n" );
        $mapping = XrowExtractManifest::importMapping( $this->manifest(), array( 'name', 'renamed', 'extra' ), $data );
        $this->assertSame( array( 'name' => 'name', 'renamed' => 'short_name' ), $mapping['columnIDs'] );
        $this->assertSame( array( 'extra' ), $mapping['unknown'] );
        $this->assertSame( array( 'remote-id' ), $mapping['missing'] );
        $this->assertSame( 'folder', $mapping['class'] );
        $this->assertSame( 'ok', $mapping['checksum'] );
        file_put_contents( $data, "b\n" );
        $this->assertSame( 'mismatch', XrowExtractManifest::importMapping( $this->manifest(), array( 'name' ), $data )['checksum'] );
    }

    private function zip( array $entries )
    {
        $path = $this->dir . '/test.zip';
        $zip = new ZipArchive();
        $this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
        foreach ( $entries as $name => $bytes )
            $zip->addFromString( $name, $bytes );
        $zip->close();
        return $path;
    }

    public function testUnpackZip(): void
    {
        if ( !class_exists( 'ZipArchive' ) )
            $this->markTestSkipped( 'the zip extension is not loaded' );
        $target = $this->dir . '/out.csv';
        $r = XrowExtractManifest::unpackZip( $this->zip( array( 'folders.csv' => "a\n", 'folders.csv.manifest.json' => '{"manifest_version":1,"columns":[]}' ) ), $target );
        $this->assertTrue( $r['ok'], json_encode( $r ) );
        $this->assertSame( 'folders.csv', $r['name'] );
        $this->assertTrue( $r['has_manifest'] );
        $this->assertSame( "a\n", file_get_contents( $target ) );
        $this->assertFileExists( $target . '.manifest.json' );

        foreach ( array(
            'a path' => array( '../evil.csv' => 'x' ),
            'a folder path' => array( 'dir/folders.csv' => 'x' ),
            'two data files' => array( 'a.csv' => 'x', 'b.json' => '[]' ),
        ) as $label => $entries )
        {
            $r = XrowExtractManifest::unpackZip( $this->zip( $entries ), $this->dir . '/refused.csv' );
            $this->assertFalse( $r['ok'], $label );
            $this->assertNotSame( '', $r['error'], $label );
        }
        $notZip = $this->dir . '/plain.csv';
        file_put_contents( $notZip, "a,b\n" );
        $this->assertSame( array( 'ok' => false, 'error' => '' ), XrowExtractManifest::unpackZip( $notZip, $this->dir . '/x' ) );
        $this->assertSame( array( 'ok' => false, 'error' => '' ), XrowExtractManifest::unpackZip( $this->dir . '/does-not-exist.zip', $this->dir . '/x' ) );
    }
}
