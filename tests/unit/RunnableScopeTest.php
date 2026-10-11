<?php

use PHPUnit\Framework\TestCase;

/** The module views read $Params from the variables eZProcess::runFile() hands them, and return their $Result. */
class RunnableScopeTest extends TestCase
{
    public function testViewReadsParamsFromItsScopeAndReturnsItsResult(): void
    {
        $view = \Exponential\View\Extension\Xrowextract\Xrowextract\Browse::create( XROWEXTRACT_TEST_CHECKOUT . '/modules/xrowextract/browse.php' );
        $result = $view->run( array( 'Params' => array( 'Module' => null, 'PackageName' => '' ) ) );
        $this->assertIsArray( $result );
        $this->assertStringContainsString( 'No package', $result['content'], 'a package name that is not there: the page says so' );
        $this->assertCount( 2, $result['path'] );
    }
}
