<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractJob::save(): job.json survives values json_encode() cannot write as they are. */
class JobSaveTest extends TestCase
{
    private string $varDir = '';

    protected function setUp(): void
    {
        // The jobs of this test live below the tests' own var/, never in the installation's
        $this->varDir = (string)eZINI::instance()->variable( 'FileSettings', 'VarDir' );
        eZINI::instance()->setVariable( 'FileSettings', 'VarDir', XROWEXTRACT_TEST_VAR . '/jobsave' );
    }

    protected function tearDown(): void
    {
        eZINI::instance()->setVariable( 'FileSettings', 'VarDir', $this->varDir );
    }

    public function testTextThatIsNotUtf8KeepsTheJob(): void
    {
        $id = XrowExtractJob::create( array( 'type' => 'csv', 'owner' => 'admin', 'what' => 'test' ) );
        $this->assertStringStartsWith( XROWEXTRACT_TEST_VAR . '/jobsave/', XrowExtractJob::path( $id ) );
        $job = XrowExtractJob::update( $id, array( 'state' => 'failed', 'error' => "Fehler in Zeile \xFC 3" ) );
        $this->assertIsArray( $job );

        $loaded = XrowExtractJob::load( $id );
        $this->assertIsArray( $loaded, 'job.json is still readable' );
        $this->assertSame( 'failed', $loaded['state'] );
        $this->assertSame( "Fehler in Zeile \u{FFFD} 3", $loaded['error'] );
        $this->assertTrue( XrowExtractJob::delete( $id ) );
    }

    public function testAValueJsonCannotWriteLeavesTheSavedJob(): void
    {
        $id = XrowExtractJob::create( array( 'type' => 'csv', 'owner' => 'admin', 'what' => 'test' ) );
        $before = (string)file_get_contents( XrowExtractJob::path( $id ) . '/' . XrowExtractJob::JOB_FILE );
        XrowExtractJob::save( $id, array( 'id' => $id, 'state' => 'running', 'rows' => NAN ) );
        $this->assertSame( $before, (string)file_get_contents( XrowExtractJob::path( $id ) . '/' . XrowExtractJob::JOB_FILE ) );
        $this->assertSame( 'queued', XrowExtractJob::load( $id )['state'] ?? null );
        $this->assertSame( array( XrowExtractJob::JOB_FILE ), array_values( array_diff( (array)scandir( XrowExtractJob::path( $id ) ), array( '.', '..' ) ) ), 'no temporary file left' );
        $this->assertTrue( XrowExtractJob::delete( $id ) );
    }
}
