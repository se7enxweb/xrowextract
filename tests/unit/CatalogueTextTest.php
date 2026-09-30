<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractCatalogue: the plain text and word count formats, also of text that is not valid UTF-8. */
class CatalogueTextTest extends TestCase
{
    private function plainText( string $html ): string
    {
        $method = new ReflectionMethod( 'XrowExtractCatalogue', 'plainText' );
        return $method->invoke( null, $html );
    }

    public function testWordCount(): void
    {
        $this->assertSame( 0, XrowExtractCatalogue::wordCount( '' ) );
        $this->assertSame( 0, XrowExtractCatalogue::wordCount( " \n\t " ) );
        $this->assertSame( 3, XrowExtractCatalogue::wordCount( "  eins zwei\n\tdrei " ) );
        $this->assertSame( 2, XrowExtractCatalogue::wordCount( "Grüße aus" ) );
        // Latin-1 bytes: the /u split fails on them; the words are still counted (was a TypeError: count(false))
        $this->assertSame( 3, XrowExtractCatalogue::wordCount( "Gr\xFC\xDFe aus K\xF6ln" ) );
    }

    public function testPlainText(): void
    {
        $this->assertSame( 'Eins zwei & drei vier', $this->plainText( "<p>Eins  <b>zwei</b></p>\n&amp; drei<br/>vier" ) );
        $this->assertSame( '', $this->plainText( '' ) );
        // Not valid UTF-8: the text is kept, white space collapsed (was '' with a deprecation of trim(null))
        $this->assertSame( "Gr\xFC\xDFe aus K\xF6ln", $this->plainText( "<p>Gr\xFC\xDFe</p>  aus\n K\xF6ln" ) );
    }

    public function testWordsFormatOfATextAttribute(): void
    {
        $attribute = new eZContentObjectAttribute( array( 'data_type_string' => 'eztext', 'data_text' => "Gr\xFC\xDFe  aus K\xF6ln und Bonn" ) );
        $this->assertSame( '5', XrowExtractCatalogue::formatValue( $attribute, 'words' ) );
    }
}
