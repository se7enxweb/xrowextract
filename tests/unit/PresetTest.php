<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractPreset: the site preset catalog, Extends chains and {placeholder} filling (no database). */
class PresetTest extends TestCase
{
    private $added = array();

    private function sitePreset( $id, array $vars )
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        foreach ( $vars as $name => $value )
            $ini->setVariable( 'Preset_' . $id, $name, $value );
        $this->added[] = 'Preset_' . $id;
    }

    protected function tearDown(): void
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        foreach ( $this->added as $group )
            $ini->removeGroup( $group );
        $this->added = array();
    }

    public function testShippedCatalogIsValid(): void
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        $count = 0;
        foreach ( $ini->groups() as $group => $vars )
        {
            if ( strpos( $group, 'Preset_' ) !== 0 )
                continue;
            $count++;
            foreach ( array( 'Definition', 'Placeholders' ) as $key )
            {
                if ( isset( $vars[$key] ) && $vars[$key] !== '' )
                    $this->assertIsArray( json_decode( $vars[$key], true ), "$group $key is not a JSON object" );
            }
            $this->assertArrayHasKey( 'Name', $vars, $group );
            $id = substr( $group, strlen( 'Preset_' ) );
            $resolved = XrowExtractPreset::resolve( 'site:' . $id );
            $this->assertSame( '', $resolved['error'], $group );
            $this->assertSame( 'site:' . $id, $resolved['chain'][0] );
        }
        $this->assertGreaterThan( 0, $count, 'the catalog has presets' );
        $this->assertCount( $count, XrowExtractPreset::fetchSiteList() );
    }

    public function testExtendsChainMergesAndOverrides(): void
    {
        $this->sitePreset( 'xtest_base', array( 'Name' => 'Base', 'Definition' => '{"subtree":"{node}","limit":5,"filters":{"section":1,"name":"a"}}',
                                                'Placeholders' => '{"node":{"default":"2"}}' ) );
        $this->sitePreset( 'xtest_child', array( 'Name' => 'Child', 'Extends' => 'site:xtest_base', 'Definition' => '{"limit":9,"filters":{"name":"b"}}' ) );
        $r = XrowExtractPreset::resolve( 'site:xtest_child' );
        $this->assertSame( '', $r['error'] );
        $this->assertSame( array( 'site:xtest_child', 'site:xtest_base' ), $r['chain'] );
        $this->assertSame( 9, $r['definition']['limit'] );
        $this->assertSame( '{node}', $r['definition']['subtree'] );
        $this->assertSame( array( 'section' => 1, 'name' => 'b' ), $r['definition']['filters'] );
        $this->assertSame( array( 'node' => array( 'default' => '2' ) ), $r['placeholders'] );
    }

    public function testBrokenChains(): void
    {
        $this->sitePreset( 'xtest_a', array( 'Name' => 'A', 'Extends' => 'site:xtest_b' ) );
        $this->sitePreset( 'xtest_b', array( 'Name' => 'B', 'Extends' => 'site:xtest_a' ) );
        $this->assertStringContainsString( 'cycle', XrowExtractPreset::resolve( 'site:xtest_a' )['error'] );
        $this->assertStringContainsString( 'not found', XrowExtractPreset::resolve( 'site:xtest_nothing' )['error'] );
        $this->assertStringContainsString( 'not found', XrowExtractPreset::resolve( 'bogus' )['error'] );
        $this->sitePreset( 'xtest_bad_json', array( 'Name' => 'Bad', 'Definition' => '{not json', 'Placeholders' => '[1' ) );
        $bad = XrowExtractPreset::fetchSite( 'xtest_bad_json' );
        $this->assertSame( array(), $bad['definition'] );
        $this->assertSame( array(), $bad['placeholders'] );
        $this->assertFalse( XrowExtractPreset::fetchSite( 'xtest_nothing' ) );
    }

    public function testFillPlaceholders(): void
    {
        $definition = array( 'subtree' => '{node}', 'filters' => array( 'name' => 'x{word}y', 'conditions' => array( array( 'value' => '{a}{b}' ) ) ),
                             'limit' => 5, 'flag' => true );
        $filled = XrowExtractPreset::fillPlaceholders( $definition, array( 'node' => array( 'default' => '2' ), 'word' => array() ),
                                                       array( 'word' => 'W', 'a' => 1 ) );
        $this->assertSame( '2', $filled['definition']['subtree'] );
        $this->assertSame( 'xWy', $filled['definition']['filters']['name'] );
        $this->assertSame( '1', $filled['definition']['filters']['conditions'][0]['value'] );
        $this->assertSame( 5, $filled['definition']['limit'] );
        $this->assertTrue( $filled['definition']['flag'] );
        $this->assertSame( array( 'b' ), $filled['unresolved'] );
        // A braced text that is not a placeholder name is left alone
        $this->assertSame( '{not a name}', XrowExtractPreset::fillPlaceholders( array( 'x' => '{not a name}' ), array(), array() )['definition']['x'] );
    }
}
