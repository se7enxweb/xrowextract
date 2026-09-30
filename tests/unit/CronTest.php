<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractCron: schedule frequencies as cron expressions, and when they run. */
class CronTest extends TestCase
{
    public function testFrequenciesAsExpressions(): void
    {
        $this->assertSame( '15 * * * *', XrowExtractCron::expressionFor( array( 'kind' => 'hourly', 'minute' => 15 ) ) );
        $this->assertSame( '0 * * * *', XrowExtractCron::expressionFor( array( 'kind' => 'hourly', 'minute' => 99 ) ) );
        $this->assertSame( '30 4 * * *', XrowExtractCron::expressionFor( array( 'kind' => 'daily', 'time' => '04:30' ) ) );
        $this->assertSame( '0 2 * * *', XrowExtractCron::expressionFor( array( 'kind' => 'daily', 'time' => '25:99' ) ) );
        $this->assertSame( '0 6 * * 5', XrowExtractCron::expressionFor( array( 'kind' => 'weekly', 'time' => '06:00', 'weekday' => 5 ) ) );
        $this->assertSame( '0 6 1 * *', XrowExtractCron::expressionFor( array( 'kind' => 'monthly', 'time' => '06:00', 'monthday' => 40 ) ) );
        $this->assertSame( '*/5 8-18 * * 1-5', XrowExtractCron::expressionFor( array( 'kind' => 'cron', 'expression' => " */5   8-18 * * 1-5 " ) ) );
        $this->assertFalse( XrowExtractCron::expressionFor( array( 'kind' => 'cron', 'expression' => '* * *' ) ) );
        $this->assertFalse( XrowExtractCron::expressionFor( array( 'kind' => 'never' ) ) );
    }

    public function testValidation(): void
    {
        foreach ( array( '* * * * *', '@daily', '0 0 1 jan *', '0 0 * * sun', '*/15 * * * *', '0 0 * * 7', '5,10-12/2 * * * *' ) as $ok )
            $this->assertTrue( XrowExtractCron::isValid( $ok ), $ok );
        foreach ( array( '', '* * * *', '60 * * * *', '* 24 * * *', '* * 0 * *', '* * * 13 *', '* * * * 8', '*/0 * * * *',
                         '5-1 * * * *', 'a b c d e', '* * * * * *', '1;2 * * * *' ) as $bad )
            $this->assertFalse( XrowExtractCron::isValid( $bad ), $bad );
    }

    public function testMatchesAndNextRun(): void
    {
        $monday = mktime( 8, 0, 0, 9, 28, 2026 ); // Monday 2026-09-28 08:00
        $this->assertTrue( XrowExtractCron::matches( '0 8 * * 1', $monday ) );
        $this->assertFalse( XrowExtractCron::matches( '0 8 * * 2', $monday ) );
        $this->assertTrue( XrowExtractCron::matches( '0 8 28 * 2', $monday ), 'day or weekday when both are set' );
        $this->assertSame( mktime( 8, 5, 0, 9, 28, 2026 ), XrowExtractCron::nextRun( '*/5 * * * *', $monday ) );
        $this->assertSame( mktime( 0, 0, 0, 10, 1, 2026 ), XrowExtractCron::nextRun( '@monthly', $monday ) );
        $this->assertSame( mktime( 8, 0, 0, 10, 5, 2026 ), XrowExtractCron::nextRun( '0 8 * * 1', $monday ), 'strictly after' );
        $this->assertFalse( XrowExtractCron::nextRun( '0 0 31 2 *', $monday ) );
        $this->assertFalse( XrowExtractCron::nextRun( 'not cron', $monday ) );
    }

    public function testDescribeGivesText(): void
    {
        foreach ( array( array( 'kind' => 'hourly', 'minute' => 5 ), array( 'kind' => 'daily', 'time' => '02:00' ),
                         array( 'kind' => 'weekly', 'time' => '02:00', 'weekday' => 3 ), array( 'kind' => 'monthly', 'time' => '02:00', 'monthday' => 2 ),
                         array( 'kind' => 'cron', 'expression' => '*/5 * * * *' ) ) as $frequency )
        {
            $text = XrowExtractCron::describe( $frequency, XrowExtractCron::expressionFor( $frequency ) );
            $this->assertIsString( $text );
            $this->assertNotSame( '', $text );
        }
    }
}
