<?php

use PHPUnit\Framework\TestCase;

/**
 * XrowExtractColumns::requestCache(): what one request found must not reach the next one a Velocity
 * worker serves. Velocity unsets the request's globals between requests; that is simulated here.
 */
class RequestCacheTest extends TestCase
{
    private $https;

    protected function setUp(): void
    {
        $this->https = isset( $_SERVER['HTTPS'] ) ? $_SERVER['HTTPS'] : null;
        unset( $GLOBALS['xrowExtractRequestCache'] );
    }

    protected function tearDown(): void
    {
        if ( $this->https === null )
            unset( $_SERVER['HTTPS'] );
        else
            $_SERVER['HTTPS'] = $this->https;
        unset( $GLOBALS['xrowExtractRequestCache'] );
    }

    public function testCacheIsSharedWithinARequestAndGoneAfterIt(): void
    {
        $a =& XrowExtractColumns::requestCache( 'test' );
        $a['x'] = 1;
        $b =& XrowExtractColumns::requestCache( 'test' );
        $this->assertSame( 1, $b['x'] );
        $this->assertSame( array(), XrowExtractColumns::requestCache( 'other' ) );
        unset( $GLOBALS['xrowExtractRequestCache'] ); // the next request
        $this->assertSame( array(), XrowExtractColumns::requestCache( 'test' ) );
    }

    public function testThePublicSiteUrlFollowsTheRequestsScheme(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $first = XrowExtractColumns::publicSiteURL();
        $this->assertStringStartsWith( 'https://', $first );
        $_SERVER['HTTPS'] = 'off';
        $this->assertSame( $first, XrowExtractColumns::publicSiteURL(), 'the same request' );
        unset( $GLOBALS['xrowExtractRequestCache'] ); // the next request, over http
        $this->assertStringStartsWith( 'http://', XrowExtractColumns::publicSiteURL() );
    }
}
