<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractPreset::encodeRecord(): a user preset is never stored as an empty value. */
class PresetRecordEncodingTest extends TestCase
{
    public function testValidRecordIsStoredAsBefore(): void
    {
        $record = array( 'name' => 'Grüße', 'view' => 'csv', 'shared' => false, 'definition' => array( 'columns' => array( 'name' ) ) );
        $this->assertSame( json_encode( $record ), XrowExtractPreset::encodeRecord( $record ) );
    }

    public function testTextThatIsNotUtf8IsKept(): void
    {
        // A name sent in Latin-1: json_encode() alone returns false, and the preset was stored empty
        $json = XrowExtractPreset::encodeRecord( array( 'name' => "Gr\xFC\xDFe", 'definition' => array( 'where' => "K\xF6ln" ) ) );
        $decoded = json_decode( $json, true );
        $this->assertIsArray( $decoded );
        $this->assertSame( "Gr\u{FFFD}\u{FFFD}e", $decoded['name'] );
        $this->assertSame( "K\u{FFFD}ln", $decoded['definition']['where'] );
    }

    public function testARecordThatCannotBeEncodedThrows(): void
    {
        $this->expectException( RuntimeException::class );
        XrowExtractPreset::encodeRecord( array( 'name' => 'x', 'value' => NAN ) );
    }
}
