<?php

use PHPUnit\Framework\TestCase;

/**
 * "Re-import this file" (XrowExtractReimport): which job files qualify, that the user must be allowed to see the job,
 * and that the manifest sidecar travels with the file.
 */
class ReimportTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = XROWEXTRACT_TEST_VAR . '/reimport-' . getmypid();
        if ( !is_dir( $this->dir ) )
            mkdir( $this->dir, 0700, true );
    }

    protected function tearDown(): void
    {
        foreach ( glob( $this->dir . '/*' ) ?: array() as $file )
            unlink( $file );
        rmdir( $this->dir );
    }

    private static function job( array $values = array() ): array
    {
        return $values + array( 'id' => 'abc', 'type' => 'csv', 'state' => 'done', 'owner' => 'anna', 'output_file' => 'export.csv' );
    }

    public function testAFinishedClassExportOfCsvJsonOrXmlQualifies(): void
    {
        $this->assertTrue( XrowExtractReimport::isReimportable( self::job() ) );
        $this->assertTrue( XrowExtractReimport::isReimportable( self::job( array( 'output_file' => 'export.JSON' ) ) ) );
        $this->assertTrue( XrowExtractReimport::isReimportable( self::job( array( 'output_file' => 'export.xml' ) ) ) );
    }

    public function testOtherJobsDoNot(): void
    {
        $this->assertFalse( XrowExtractReimport::isReimportable( null ) );
        $this->assertFalse( XrowExtractReimport::isReimportable( self::job( array( 'state' => 'running' ) ) ) );
        $this->assertFalse( XrowExtractReimport::isReimportable( self::job( array( 'type' => 'import' ) ) ), 'an import report' );
        $this->assertFalse( XrowExtractReimport::isReimportable( self::job( array( 'type' => 'package', 'output_file' => 'export.ezpkg' ) ) ) );
        $this->assertFalse( XrowExtractReimport::isReimportable( self::job( array( 'type' => 'archive', 'output_file' => 'site.zip' ) ) ) );
        $this->assertFalse( XrowExtractReimport::isReimportable( self::job( array( 'output_file' => '' ) ) ) );
        $this->assertFalse( XrowExtractReimport::isReimportable( self::job( array( 'output_file' => '../x.csv' ) ) ), 'a path, not a file name' );
    }

    public function testTheSourceNeedsTheJobToBeVisibleAndItsFileOnDisk(): void
    {
        $this->assertNull( XrowExtractReimport::source( self::job(), 'anna', false, $this->dir ), 'no file yet' );
        file_put_contents( $this->dir . '/export.csv', "id;name\n1;A\n" );

        $source = XrowExtractReimport::source( self::job(), 'anna', false, $this->dir );
        $this->assertSame( array( 'path' => $this->dir . '/export.csv', 'name' => 'export.csv', 'manifest' => null ), $source );

        $this->assertNull( XrowExtractReimport::source( self::job(), 'bert', false, $this->dir ), "another user's job" );
        $this->assertNotNull( XrowExtractReimport::source( self::job(), 'bert', true, $this->dir ), 'with xrowextract/all_jobs' );
    }

    public function testTheManifestTravelsWithTheFile(): void
    {
        file_put_contents( $this->dir . '/export.csv', "id;name\n1;A\n" );
        file_put_contents( XrowExtractManifest::sidecarPath( $this->dir . '/export.csv' ), '{}' );
        $source = XrowExtractReimport::source( self::job(), 'anna', false, $this->dir );
        $this->assertSame( $this->dir . '/export.csv' . XrowExtractManifest::SIDECAR_SUFFIX, $source['manifest'] );
    }
}
