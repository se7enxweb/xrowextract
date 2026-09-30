<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractSchedule::saveFrom(): values it cannot store are refused before anything is written. */
class ScheduleSaveTest extends TestCase
{
    /** @return array<string, mixed> A package schedule that passes validation without a database. */
    private function values(): array
    {
        return array(
            'name' => 'Nightly package',
            'kind' => 'package',
            'definition' => array( 'node' => 2 ),
            'frequency' => array( 'kind' => 'daily', 'time' => '02:00' ),
        );
    }

    public function testTextThatIsNotUtf8IsRefusedInsteadOfStoredEmpty(): void
    {
        foreach ( array(
            'definition' => array( 'node' => 2, 'label' => "caf\xe9" ),
            'frequency' => array( 'kind' => 'daily', 'time' => '02:00', 'note' => "\xff" ),
        ) as $key => $value )
        {
            $values = $this->values();
            $values[$key] = $value;
            $result = XrowExtractSchedule::saveFrom( $values, 'admin' );
            $this->assertSame( array( 'A value of the schedule is not valid UTF-8 text and cannot be stored.' ), $result['errors'], $key );
            $this->assertNull( $result['schedule'], $key );
        }
        $values = $this->values();
        $values['notify'] = array( 'webhook_url' => "https://example.com/\xff" );
        $result = XrowExtractSchedule::saveFrom( $values, 'admin' );
        $this->assertSame( array( 'A value of the schedule is not valid UTF-8 text and cannot be stored.' ), $result['errors'] );
    }

    public function testValidationErrorsComeFirst(): void
    {
        $values = $this->values();
        $values['name'] = '';
        $values['definition'] = array( 'node' => 0, 'label' => "\xff" );
        $result = XrowExtractSchedule::saveFrom( $values, 'admin' );
        $this->assertSame( array( 'A schedule needs a name.', 'Choose the node to export as a package.' ), $result['errors'] );
    }

    public function testCleanEmails(): void
    {
        $this->assertSame( 'a@example.com, b@example.org', XrowExtractSchedule::cleanEmails( " a@example.com;b@example.org,, nope a@example.com " ) );
        $this->assertSame( '', XrowExtractSchedule::cleanEmails( '' ) );
        $this->assertSame( '', XrowExtractSchedule::cleanEmails( null ) );
    }
}
