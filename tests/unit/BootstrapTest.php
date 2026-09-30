<?php

use PHPUnit\Framework\TestCase;

/** The test run itself: this checkout's classes, the kernel's, and no installed copy in between. */
class BootstrapTest extends TestCase
{
    public function testExtensionClassesComeFromThisCheckout(): void
    {
        foreach ( array( 'XrowExtractWriter', 'XrowExtractImport', 'XrowBaseHandler', 'XrowExtractTransportSftp', 'xrowextractInfo' ) as $class )
        {
            $file = ( new ReflectionClass( $class ) )->getFileName();
            $this->assertStringStartsWith( realpath( XROWEXTRACT_TEST_CHECKOUT ) . '/', realpath( $file ), $class );
        }
    }

    public function testKernelClassesAreAvailable(): void
    {
        $this->assertTrue( class_exists( 'eZINI' ) );
        $this->assertTrue( class_exists( 'eZPackage' ) );
        $this->assertTrue( class_exists( 'ezpI18n' ) );
    }

    public function testSettingsAreThisCheckoutsOnly(): void
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        $this->assertSame( XROWEXTRACT_TEST_VAR . '/secrets.key', $ini->variable( 'Secrets', 'KeyFile' ) );
        $this->assertTrue( eZINI::instance( 'csv.ini' )->hasGroup( 'General' ) );
    }
}
