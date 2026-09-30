<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractTransportSftp: the OpenSSH programs it runs, when one of them is missing. */
class SftpRunTest extends TestCase
{
    public function testAProgramThatWasNotFoundIsAnErrorNotAnException(): void
    {
        // What self::binary() gives for a missing program, as the argument array would carry it
        $this->assertSame( array( 127, '', 'the program to run was not found' ), XrowExtractTransportSftp::run( array( '', '-l' ) ) );
        $this->assertSame( 127, XrowExtractTransportSftp::run( array() )[0] );
    }

    public function testAProgramThatIsThereRuns(): void
    {
        if ( !is_executable( '/bin/sh' ) )
            $this->markTestSkipped( 'no /bin/sh' );
        $this->assertSame( array( 3, "out\n", "err\n" ), XrowExtractTransportSftp::run( array( '/bin/sh', '-c', 'echo out; echo err >&2; exit 3' ) ) );
    }

    public function testFingerprint(): void
    {
        if ( XrowExtractTransportSftp::binary( 'ssh-keygen' ) === false )
        {
            $this->assertSame( '', XrowExtractTransportSftp::fingerprint( 'host ssh-ed25519 AAAA' ) );
            return;
        }
        $line = 'example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
        $this->assertMatchesRegularExpression( '/^SHA256:\S+ \(ED25519\)$/', XrowExtractTransportSftp::fingerprint( $line ) );
        $this->assertSame( '', XrowExtractTransportSftp::fingerprint( 'not a key line' ) );
    }
}
