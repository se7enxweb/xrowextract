<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractWriter: the format lists and what each format writes. */
class WriterTest extends TestCase
{
    private function columns()
    {
        return array(
            array( 'id' => 'name', 'name' => 'Name', 'exportname' => 'name' ),
            array( 'id' => 'short_name', 'name' => 'Short name', 'exportname' => 'short_name' ),
            array( 'id' => 'ezcontentobject.name', 'name' => 'Object name', 'exportname' => 'name' ),
        );
    }

    public function testFormatListsAndValidation(): void
    {
        $formats = XrowExtractWriter::formats();
        $this->assertSame( array( 'csv', 'json', 'xml', 'ezpkg' ), array_keys( $formats ) );
        foreach ( $formats as $id => $format )
        {
            $this->assertSame( $id, $format['id'] );
            $this->assertNotSame( '', $format['extension'] );
            $this->assertNotSame( '', $format['type'] );
        }
        $this->assertSame( array( 'csv', 'json', 'xml' ), array_keys( XrowExtractWriter::rowFormats() ) );
        $this->assertTrue( XrowExtractWriter::isFormat( 'ezpkg' ) );
        $this->assertFalse( XrowExtractWriter::isRowFormat( 'ezpkg' ) );
        foreach ( array( null, 1, array( 'csv' ), 'CSV', '', 'csv ' ) as $bad )
        {
            $this->assertFalse( XrowExtractWriter::isFormat( $bad ), var_export( $bad, true ) );
            $this->assertFalse( XrowExtractWriter::isRowFormat( $bad ), var_export( $bad, true ) );
        }
    }

    public function testUnknownFormatFallsBackToCsv(): void
    {
        $writer = new XrowExtractWriter( 'no-such-format', $this->columns() );
        $this->assertSame( 'csv', $writer->format() );
        $this->assertSame( 'csv', $writer->extension() );
        $this->assertSame( 'text/csv; charset=utf-8', $writer->contentType() );
    }

    public function testCsvHeaderNumbersRepeatedNamesAndUsesTheSeparator(): void
    {
        $writer = new XrowExtractWriter( 'csv', $this->columns(), ';', true, "\r\n" );
        $this->assertSame( '"name";"short-name";"name-2"' . "\r\n", $writer->begin() );
        $parser = $writer->parser();
        $row = $writer->row( array( $parser->escape( 'a;b' ), $parser->escape( 'say "hi"' ), $parser->escape( '=1+1' ) ) );
        $this->assertSame( '"a;b";"say ""hi""";"\'=1+1"' . "\r\n", $row );
        $this->assertSame( '', $writer->end() );
        $this->assertSame( 1, $writer->rowCount() );
    }

    public function testJsonIsAnArrayOfObjectsEvenWithBytesThatAreNotUtf8(): void
    {
        $writer = new XrowExtractWriter( 'json', $this->columns() );
        $out = $writer->begin() . $writer->row( array( 'Grüße', "caf\xE9", 'x' ) ) . $writer->row( array( '2' ) ) . $writer->end();
        $data = json_decode( $out, true );
        $this->assertIsArray( $data, $out );
        $this->assertCount( 2, $data );
        $this->assertSame( array( 'name' => 'Grüße', 'short-name' => "caf\u{FFFD}", 'name-2' => 'x' ), $data[0] );
        $this->assertSame( array( 'name' => '2', 'short-name' => '', 'name-2' => '' ), $data[1] );
    }

    public function testEmptyJsonIsAnEmptyArray(): void
    {
        $writer = new XrowExtractWriter( 'json', $this->columns() );
        $this->assertSame( array(), json_decode( $writer->begin() . $writer->end(), true ) );
    }

    public function testXmlIsWellFormedWithoutCharactersXmlForbids(): void
    {
        $writer = new XrowExtractWriter( 'xml', $this->columns(), ',', true, "\n",
                                         array( 'class' => 'folder', 'Bad Name' => 'dropped', 'site' => 'A & B <c>' ) );
        $out = $writer->begin() . $writer->row( array( "a\x01b\x0Bc", '<x>&"', "caf\xE9" ) ) . $writer->end();
        $doc = new DOMDocument();
        $this->assertTrue( $doc->loadXML( $out ), $out );
        $root = $doc->documentElement;
        $this->assertSame( 'export', $root->nodeName );
        $this->assertSame( 'folder', $root->getAttribute( 'class' ) );
        $this->assertSame( 'A & B <c>', $root->getAttribute( 'site' ) );
        $this->assertFalse( $root->hasAttribute( 'Bad Name' ) );
        $fields = $doc->getElementsByTagName( 'field' );
        $this->assertSame( 3, $fields->length );
        $this->assertSame( 'abc', $fields->item( 0 )->textContent );
        $this->assertSame( '<x>&"', $fields->item( 1 )->textContent );
        $columns = $doc->getElementsByTagName( 'column' );
        $this->assertSame( 'name-2', $columns->item( 2 )->getAttribute( 'name' ) );
        $this->assertSame( 'ezcontentobject.name', $columns->item( 2 )->getAttribute( 'id' ) );
    }

    public function testParsedBackByTheImporter(): void
    {
        foreach ( array( 'csv', 'json', 'xml' ) as $format )
        {
            $writer = new XrowExtractWriter( $format, $this->columns(), ',', true );
            $parser = $writer->parser();
            $cell = function ( $text ) use ( $parser, $format ) { return $format === 'csv' ? $parser->escape( $text ) : $text; };
            $text = $writer->begin() . $writer->row( array( $cell( 'Line 1' ), $cell( "two\nlines" ), $cell( 'x,y' ) ) ) . $writer->end();
            $parsed = $format === 'csv' ? XrowExtractImport::parseCSV( $text, ',' )
                    : ( $format === 'json' ? XrowExtractImport::parseJSON( $text ) : XrowExtractImport::parseXML( $text ) );
            $this->assertSame( array( 'name', 'short-name', 'name-2' ), $parsed['header'], $format );
            $this->assertCount( 1, $parsed['rows'], $format );
            $this->assertSame( "two\nlines", $parsed['rows'][0]['short-name'], $format );
            $this->assertSame( 'x,y', $parsed['rows'][0]['name-2'], $format );
        }
    }
}
