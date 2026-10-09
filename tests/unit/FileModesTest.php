<?php

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * XrowExtractFileModes: every mode the extension gives a file or folder goes through the limits of the kernel
 * (EZP_FILE_MODE_MAX / EZP_DIR_MODE_MAX in config.php). Without them the modes are exactly those of before; with
 * 0770 / 0660 a wider mode is capped and the private 0700 / 0600 ones stay as they are. The limits are constants,
 * so that test runs in a process of its own.
 */
class FileModesTest extends TestCase
{
    /** The classes that call chmod(), mkdir() or umask() */
    const USERS = array( 'XrowExtractArchive', 'XrowExtractImport', 'XrowExtractJob', 'XrowExtractManifest', 'XrowExtractPackage',
                         'XrowExtractScheduler', 'XrowExtractSecrets', 'XrowExtractTransport', 'XrowExtractTransportSftp',
                         'XrowExtractUpload', 'Exponential\Command\Extension\Xrowextract\Import',
                         'Exponential\Command\Extension\Xrowextract\Job' );

    private string $varDir = '';
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = XROWEXTRACT_TEST_VAR . '/filemodes-' . getmypid();
        $this->varDir = (string)eZINI::instance()->variable( 'FileSettings', 'VarDir' );
        eZINI::instance()->setVariable( 'FileSettings', 'VarDir', $this->dir );
    }

    protected function tearDown(): void
    {
        eZINI::instance()->setVariable( 'FileSettings', 'VarDir', $this->varDir );
        self::remove( $this->dir );
    }

    public function testEveryClassThatWritesUsesTheHelpers(): void
    {
        $checkout = XROWEXTRACT_TEST_CHECKOUT;
        foreach ( array( 'php_import', 'php_job' ) as $command )
            require_once $checkout . '/classes/runnable/commands/' . $command . '.php';
        foreach ( self::USERS as $class )
            $this->assertContains( 'XrowExtractFileModes', class_uses( $class ), $class );
    }

    public function testWithoutLimitsTheModesAreThoseOfBefore(): void
    {
        $this->assertFalse( defined( 'EZP_FILE_MODE_MAX' ) || defined( 'EZP_DIR_MODE_MAX' ), 'no limits in this process' );
        $this->assertSame( array( 'file' => 0666, 'dir' => 0777, 'executable' => 0755, 'umask' => 0, 'private umask' => 0077 ),
                           self::helpers() );
        $this->assertSame( array( 'jobs' => 0700, 'job' => 0700, 'job.json' => 0600 ), $this->jobModes() );
    }

    #[RunInSeparateProcess]
    public function testALimitCapsTheModes(): void
    {
        define( 'EZP_DIR_MODE_MAX', 0770 );
        define( 'EZP_FILE_MODE_MAX', 0660 );
        $this->assertSame( array( 'file' => 0660, 'dir' => 0770, 'executable' => 0750, 'umask' => 0007, 'private umask' => 0077 ),
                           self::helpers() );
        $this->assertSame( array( 'jobs' => 0700, 'job' => 0700, 'job.json' => 0600 ), $this->jobModes() );
    }

    /** What the helpers make of the widest modes, called as the classes call them. */
    private static function helpers(): array
    {
        $call = function ( $name, $value ) {
            $method = new ReflectionMethod( 'XrowExtractJob', $name );
            return $method->invoke( null, $value );
        };
        return array( 'file' => $call( 'fileMode', 0666 ), 'dir' => $call( 'dirMode', 0777 ),
                      'executable' => $call( 'executableMode', 0755 ), 'umask' => $call( 'creationUmask', 0 ),
                      'private umask' => $call( 'creationUmask', 0077 ) );
    }

    /** The modes of the jobs folder, a job folder and its job.json, made by XrowExtractJob::create(). */
    private function jobModes(): array
    {
        $old = umask( 0 );
        try
        {
            $id = XrowExtractJob::create( array( 'type' => 'csv', 'owner' => 'admin', 'what' => 'test' ) );
        }
        finally
        {
            umask( $old );
        }
        $path = XrowExtractJob::path( $id );
        $this->assertStringStartsWith( $this->dir . '/', $path );
        clearstatcache();
        return array( 'jobs' => fileperms( dirname( $path ) ) & 0777, 'job' => fileperms( $path ) & 0777,
                      'job.json' => fileperms( $path . '/' . XrowExtractJob::JOB_FILE ) & 0777 );
    }

    private static function remove( string $path ): void
    {
        if ( is_dir( $path ) && !is_link( $path ) )
        {
            foreach ( (array)scandir( $path ) as $entry )
            {
                if ( $entry !== '.' && $entry !== '..' )
                    self::remove( $path . '/' . $entry );
            }
            rmdir( $path );
        }
        elseif ( file_exists( $path ) || is_link( $path ) )
        {
            unlink( $path );
        }
    }
}
