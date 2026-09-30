<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractJob: job ids, the progress-bar parser and the job log as the Jobs page shows it. */
class JobLogTest extends TestCase
{
    private function syntheticLog()
    {
        $raw = "[12:00:00] Installing test package: 2 class(es), 200 object(s)\n\n";
        for ( $i = 1; $i <= 20; $i++ )
            $raw .= "\x1b[1;32mInstalling content classes | " . ( $i * 5 ) . "% ($i/20) | elapsed 00:00:" . sprintf( '%02d', $i ) . " | end @ 12:01\x1b[0m\r";
        $raw .= "\n\n";
        for ( $i = 1; $i <= 200; $i++ )
            $raw .= "\x1b[1;32mInstalling content objects | " . rtrim( rtrim( number_format( $i / 2, 1 ), '0' ), '.' ) . "% ($i/200) | elapsed 00:01:" . sprintf( '%02d', $i % 60 ) . " | end @ 12:05\x1b[0m\r";
        return $raw . "\nWARNING: one thing\n\n\n\nDone after 65 s. Classes: 2 created. Objects: 200 created.\n";
    }

    private function timeline( $text )
    {
        return array_values( preg_grep( '/ · [\d.]+% \(/', explode( "\n", $text ) ) );
    }

    public function testJobIds(): void
    {
        $this->assertTrue( XrowExtractJob::isValidID( bin2hex( random_bytes( 16 ) ) ) );
        foreach ( array( '', 'abc', '../etc', str_repeat( 'g', 32 ), strtoupper( bin2hex( random_bytes( 16 ) ) ), null, 5, array() ) as $bad )
            $this->assertFalse( XrowExtractJob::isValidID( $bad ), var_export( $bad, true ) );
    }

    public function testParseProgressLine(): void
    {
        $this->assertSame( array( 'phase' => 'Installing content objects', 'percent' => 40.0, 'done' => 1736, 'total' => 4339,
                                  'elapsed' => '00:10:13', 'end_at' => '16:16' ),
                           XrowExtractJob::parseProgressLine( "\x1b[1;32mInstalling content objects | 40% (1736/4339) | elapsed 00:10:13 | end @ 16:16\x1b[0m" ) );
        $bare = XrowExtractJob::parseProgressLine( '12.5% (1/8)' );
        $this->assertSame( '', $bare['phase'] );
        $this->assertSame( '', $bare['elapsed'] );
        foreach ( array( '', 'Done.', 'WARNING: 40% of nothing', null ) as $line )
            $this->assertNull( XrowExtractJob::parseProgressLine( $line ), var_export( $line, true ) );
    }

    public function testCleanLogKeepsATimeline(): void
    {
        $clean = XrowExtractJob::cleanLog( $this->syntheticLog() );
        $this->assertStringNotContainsString( "\x1b", $clean );
        $this->assertDoesNotMatchRegularExpression( '/\|\s*[\d.]+%\s*\(\d+\/\d+\)/', $clean );
        $this->assertStringNotContainsString( "\n\n\n", $clean );
        $timeline = $this->timeline( $clean );
        $this->assertCount( 22, $timeline, implode( "\n", $timeline ) ); // 0 % bucket to 100 %, two phases
        $this->assertContains( 'Installing content objects · 100% (200/200) · elapsed 00:01:20 · expected end 12:05', $timeline );
        $this->assertStringStartsWith( '[12:00:00] Installing test package', $clean );
        $this->assertStringEndsWith( 'Objects: 200 created.', $clean );
        $this->assertStringContainsString( 'WARNING: one thing', $clean );
    }

    public function testCleanLogInPiecesGivesTheSameTimeline(): void
    {
        $raw = $this->syntheticLog();
        $state = null;
        $out = array();
        foreach ( array_chunk( preg_split( '/(?<=[\r\n])/', $raw ), 37 ) as $piece )
        {
            $cleaned = XrowExtractJob::cleanLog( implode( '', $piece ), $state );
            if ( $cleaned !== '' )
                $out[] = $cleaned;
        }
        $this->assertSame( $this->timeline( XrowExtractJob::cleanLog( $raw ) ), $this->timeline( implode( "\n", $out ) ) );
        $this->assertSame( array( 'phase' => 'Installing content objects', 'step' => 10 ), $state );
    }

    public function testCleanLogFileAndLogProgress(): void
    {
        $file = XROWEXTRACT_TEST_VAR . '/job-' . getmypid() . '.log';
        file_put_contents( $file, $this->syntheticLog() );
        try
        {
            $fromFile = XrowExtractJob::cleanLogFile( $file );
            $this->assertSame( $this->timeline( XrowExtractJob::cleanLog( $this->syntheticLog() ) ), $this->timeline( $fromFile['text'] ) );
            $this->assertSame( array( 'phase' => 'Installing content objects', 'step' => 10 ), $fromFile['state'] );
            $this->assertSame( filesize( $file ), $fromFile['size'] );
            $progress = XrowExtractJob::logProgress( $file );
            $this->assertSame( 200, $progress['done'] );
            $this->assertSame( 200, $progress['total'] );
            $this->assertSame( 'Installing content objects · 100% · elapsed 00:01:20 · expected end 12:05', $progress['phase'] );
            // Only the last bytes of a log beyond the limit, after a marker line
            $cut = XrowExtractJob::cleanLogFile( $file, 2048 );
            $this->assertStringStartsWith( '…', $cut['text'] );
        }
        finally
        {
            unlink( $file );
        }
        $this->assertNull( XrowExtractJob::logProgress( $file ) );
        $this->assertSame( array( 'text' => '', 'state' => array( 'phase' => '', 'step' => -1 ), 'size' => 0 ), XrowExtractJob::cleanLogFile( $file ) );
    }
}
