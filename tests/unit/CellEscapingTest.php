<?php

use PHPUnit\Framework\TestCase;

/** XrowBaseHandler / ParserInterface: how one CSV cell is written (quoting, the formula guard, encodings). */
class CellEscapingTest extends TestCase
{
    public function testQuotedCells(): void
    {
        $h = new XrowBaseHandler();
        $h->escape = true;
        $cases = array(
            'abc' => '"abc"',
            'a"b' => '"a""b"',
            "a\nb" => "\"a\nb\"",
            '=HYPERLINK("x")' => '"\'=HYPERLINK(""x"")"',
            '+cmd' => '"\'+cmd"',
            '@SUM(A1)' => '"\'@SUM(A1)"',
            "\t=1" => "\"'\t=1\"",
            '-12.5' => '"-12.5"',
            '+49' => '"+49"',
            '' => '""',
        );
        foreach ( $cases as $in => $want )
            $this->assertSame( $want, $h->escape( (string)$in ), var_export( $in, true ) );
        $this->assertSame( '""', $h->escape( null ) );
    }

    public function testFormulaGuardOffAndUnquoted(): void
    {
        $h = new XrowBaseHandler();
        $h->escape = true;
        $h->neutralizeFormulas = false;
        $this->assertSame( '"=1+1"', $h->escape( '=1+1' ) );
        $h->escape = false;
        $this->assertSame( 'ab', $h->escape( "a\r\nb" ) );
    }

    public function testUtf8(): void
    {
        $this->assertSame( 'Grüße', XrowBaseHandler::utf8( 'Grüße' ) );
        $this->assertSame( 'Grüße', XrowBaseHandler::utf8( "Gr\xfc\xdfe" ) );
    }

    public function testParserUsesTheBaseHandler(): void
    {
        $p = new ParserInterface( ';', true );
        $this->assertSame( '"\'=x"', $p->escape( '=x' ) );
        $this->assertSame( ';', $p->separationChar );
    }
}
