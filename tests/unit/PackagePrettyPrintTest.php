<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractPackage::prettyPrintXML(): the XML file preview of the package browser. */
class PackagePrettyPrintTest extends TestCase
{
    public function testEmptyAndBlankBytesComeBackUnchanged(): void
    {
        // An empty .xml file in a package: loadXML('') throws a ValueError, the preview must not fail
        $this->assertSame( '', XrowExtractPackage::prettyPrintXML( '' ) );
        $this->assertSame( '', XrowExtractPackage::prettyPrintXML( false ) );
        $this->assertSame( '', XrowExtractPackage::prettyPrintXML( null ) );
        $this->assertSame( "  \n", XrowExtractPackage::prettyPrintXML( "  \n" ) );
    }

    public function testXmlIsIndentedAndOtherBytesAreKept(): void
    {
        $pretty = XrowExtractPackage::prettyPrintXML( '<a><b>x</b></a>' );
        $this->assertStringContainsString( "<a>\n  <b>x</b>\n</a>", $pretty );
        $this->assertSame( 'not xml <', XrowExtractPackage::prettyPrintXML( 'not xml <' ) );
    }
}
