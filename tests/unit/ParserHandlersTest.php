<?php

use PHPUnit\Framework\TestCase;

/** A handler of csv.ini only needs exportAttribute(); one whose class is not there is left out, not fatal. */
class ParserHandlersTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = array();

    protected function setUp(): void
    {
        $ini = eZINI::instance( 'csv.ini' );
        $this->saved['ExportableDatatypes'] = $ini->variable( 'General', 'ExportableDatatypes' );
        $file = XROWEXTRACT_TEST_CHECKOUT . '/classes/xrowbasehandler.php';
        $ini->setVariable( 'General', 'ExportableDatatypes', array( 'ezstring', 'xetestduck', 'xetestmissing' ) );
        $ini->setVariable( 'xetestduck', 'HandlerFile', $file );
        $ini->setVariable( 'xetestduck', 'HandlerClass', 'XrowExtractTestDuckHandler' );
        $ini->setVariable( 'xetestmissing', 'HandlerFile', $file );
        $ini->setVariable( 'xetestmissing', 'HandlerClass', 'XrowExtractTestNoSuchHandler' );
    }

    protected function tearDown(): void
    {
        eZINI::instance( 'csv.ini' )->setVariable( 'General', 'ExportableDatatypes', $this->saved['ExportableDatatypes'] );
    }

    public function testMissingClassIsLeftOutAndAnyExportAttributeClassWorks(): void
    {
        $parser = new ParserInterface( ';', true );
        $this->assertArrayNotHasKey( 'xetestmissing', $parser->handlerMap, 'a class that is not there is left out' );
        $this->assertArrayHasKey( 'ezstring', $parser->handlerMap );
        $this->assertArrayHasKey( 'xetestduck', $parser->handlerMap, 'a class with exportAttribute() is a handler' );
        $duck = $parser->handlerMap['xetestduck']['handler'];
        $this->assertInstanceOf( XrowExtractTestDuckHandler::class, $duck );
        $this->assertSame( ';', $duck->separationChar, 'the parser settings reach a handler that has them' );

        $attribute = new eZContentObjectAttribute( array( 'data_type_string' => 'xetestduck' ) );
        $this->assertSame( 'duck', $parser->exportValue( $attribute ) );
        $missing = new eZContentObjectAttribute( array( 'data_type_string' => 'xetestmissing' ) );
        $this->assertSame( '""', $parser->exportValue( $missing ), 'no handler: an empty cell' );
    }
}

/** A csv.ini handler that does not extend XrowBaseHandler. */
class XrowExtractTestDuckHandler
{
    /** @var string */
    public $separationChar = ',';

    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( $attribute ): string
    {
        return 'duck';
    }
}
