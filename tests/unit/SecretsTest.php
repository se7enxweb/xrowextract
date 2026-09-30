<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractSecrets: destination credentials encrypted at rest with the test run's own key file. */
class SecretsTest extends TestCase
{
    protected function setUp(): void
    {
        if ( !XrowExtractSecrets::available() )
            $this->markTestSkipped( 'the sodium extension is not loaded' );
    }

    public function testRoundTrip(): void
    {
        $values = array( 'password' => 'sécret "1"', 'token' => str_repeat( 'x', 500 ) );
        $box = XrowExtractSecrets::encrypt( $values );
        $this->assertStringStartsWith( XrowExtractSecrets::PREFIX, $box );
        $this->assertStringNotContainsString( 'sécret', $box );
        $this->assertSame( $values, XrowExtractSecrets::decrypt( $box ) );
        $this->assertSame( array( 'password', 'token' ), XrowExtractSecrets::names( $box ) );
        $this->assertNotSame( $box, XrowExtractSecrets::encrypt( $values ), 'a fresh nonce every time' );
    }

    public function testKeyFileIsPrivate(): void
    {
        XrowExtractSecrets::encrypt( array( 'a' => 'b' ) );
        $path = XrowExtractSecrets::keyFilePath();
        $this->assertSame( XROWEXTRACT_TEST_VAR . '/secrets.key', $path );
        $this->assertFileExists( $path );
        $this->assertSame( '0600', substr( sprintf( '%o', fileperms( $path ) ), -4 ) );
    }

    public function testEmptyValuesAreNotStored(): void
    {
        $this->assertSame( '', XrowExtractSecrets::encrypt( array() ) );
        $this->assertSame( '', XrowExtractSecrets::encrypt( array( 'password' => '', 'other' => null ) ) );
        $this->assertSame( array(), XrowExtractSecrets::decrypt( '' ) );
        $this->assertSame( array( 'kept' ), XrowExtractSecrets::names( XrowExtractSecrets::encrypt( array( 'empty' => '', 'kept' => '0' ) ) ) );
    }

    public function testTamperedOrForeignValuesAreRefused(): void
    {
        $box = XrowExtractSecrets::encrypt( array( 'password' => 'p' ) );
        $raw = base64_decode( substr( $box, strlen( XrowExtractSecrets::PREFIX ) ) );
        $raw[strlen( $raw ) - 1] = chr( ord( $raw[strlen( $raw ) - 1] ) ^ 1 );
        $tampered = XrowExtractSecrets::PREFIX . base64_encode( $raw );
        foreach ( array( $tampered, 'plain text', XrowExtractSecrets::PREFIX . '***', XrowExtractSecrets::PREFIX . base64_encode( 'short' ) ) as $bad )
        {
            try
            {
                XrowExtractSecrets::decrypt( $bad );
                $this->fail( 'decrypt() accepted ' . $bad );
            }
            catch ( RuntimeException $e )
            {
                $this->assertNotSame( '', $e->getMessage() );
            }
            $this->assertSame( array(), XrowExtractSecrets::names( $bad ), 'names() of a value that cannot be opened' );
        }
    }

    public function testSecretThatIsNotUtf8IsRefused(): void
    {
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'UTF-8' );
        XrowExtractSecrets::encrypt( array( 'password' => "caf\xE9" ) );
    }
}
