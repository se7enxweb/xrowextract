<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The entry parameters of xrowextract/csv (the content tree's Advanced > Export menu): how they are read and
 * checked, what they refuse, how they fill the form's saved settings, and the class list of the menu.
 */
class EntryTest extends TestCase
{
    /** The view's parameters as eZModule gives them: false for an unordered parameter that was not in the URL */
    private static function params( array $given ): array
    {
        return array_merge( array( 'EntryNode' => false, 'EntryScope' => false, 'EntryClass' => false, 'EntryColumns' => false ), $given );
    }

    public function testANodeAloneIsItsSubtreeWithTheFormsClassAndColumns(): void
    {
        $entry = XrowExtractEntry::parse( self::params( array( 'EntryNode' => '42' ) ) );
        $this->assertSame( array( 'node' => 42, 'scope' => 'tree', 'class' => 0, 'columns' => '', 'errors' => array() ), $entry );
    }

    public function testEveryParameter(): void
    {
        $entry = XrowExtractEntry::parse( self::params( array( 'EntryNode' => '7', 'EntryScope' => 'list', 'EntryClass' => '16', 'EntryColumns' => 'migration' ) ) );
        $this->assertSame( array( 'node' => 7, 'scope' => 'list', 'class' => 16, 'columns' => 'migration', 'errors' => array() ), $entry );
        $entry = XrowExtractEntry::parse( self::params( array( 'EntryNode' => '7', 'EntryColumns' => 'all' ) ) );
        $this->assertSame( 'all', $entry['columns'] );
    }

    #[DataProvider('invalidEntries')]
    public function testInvalidValuesAreRefused( array $given, string $error ): void
    {
        $this->assertContains( $error, XrowExtractEntry::parse( self::params( $given ) )['errors'] );
    }

    public static function invalidEntries(): array
    {
        return array(
            'no node'             => array( array( 'EntryScope' => 'tree' ), 'node' ),
            'node zero'           => array( array( 'EntryNode' => '0' ), 'node' ),
            'a negative node'     => array( array( 'EntryNode' => '-3' ), 'node' ),
            'a leading zero'      => array( array( 'EntryNode' => '03' ), 'node' ),
            'a node name'         => array( array( 'EntryNode' => 'home' ), 'node' ),
            'past the largest id' => array( array( 'EntryNode' => '2147483648' ), 'node' ),
            'a script'            => array( array( 'EntryNode' => '<script>' ), 'node' ),
            'empty node'          => array( array( 'EntryNode' => '' ), 'node' ),
            'scope all'           => array( array( 'EntryNode' => '5', 'EntryScope' => 'all' ), 'scope' ),
            'scope in caps'       => array( array( 'EntryNode' => '5', 'EntryScope' => 'TREE' ), 'scope' ),
            'class name'          => array( array( 'EntryNode' => '5', 'EntryClass' => 'article' ), 'class' ),
            'class zero'          => array( array( 'EntryNode' => '5', 'EntryClass' => '0' ), 'class' ),
            'class empty'         => array( array( 'EntryNode' => '5', 'EntryClass' => '' ), 'class' ),
            'columns some'        => array( array( 'EntryNode' => '5', 'EntryColumns' => 'identity' ), 'columns' ),
            'an array'            => array( array( 'EntryNode' => array( '5' ) ), 'node' ),
        );
    }

    public function testAnEntryIsToldByAnyOfItsParameters(): void
    {
        $this->assertFalse( XrowExtractEntry::isEntry( self::params( array() ) ) );
        $this->assertFalse( XrowExtractEntry::isEntry( array( 'Module' => null ) ) );
        $this->assertTrue( XrowExtractEntry::isEntry( self::params( array( 'EntryNode' => '1' ) ) ) );
        $this->assertTrue( XrowExtractEntry::isEntry( self::params( array( 'EntryColumns' => 'all' ) ) ) );
    }

    public function testRefusals(): void
    {
        $this->assertNull( XrowExtractEntry::refusal( true, true, false, false ) );
        $this->assertNull( XrowExtractEntry::refusal( true, true, true, true ) );
        $this->assertSame( 'not_found', XrowExtractEntry::refusal( false, false, false, false ) );
        $this->assertSame( 'not_found', XrowExtractEntry::refusal( true, true, true, false ), 'a class that is not there or not offered' );
        $this->assertSame( 'access_denied', XrowExtractEntry::refusal( true, false, false, false ) );
        $this->assertSame( 'access_denied', XrowExtractEntry::refusal( true, false, true, true ) );
    }

    public function testClassAllowedFollowsExportClasses(): void
    {
        $this->assertTrue( XrowExtractEntry::classAllowed( 16, 'article', array() ) );
        $this->assertTrue( XrowExtractEntry::classAllowed( 16, 'article', array( '' ) ), 'ExportClasses[] alone is empty' );
        $this->assertTrue( XrowExtractEntry::classAllowed( 16, 'article', array( 'user', 'article' ) ) );
        $this->assertTrue( XrowExtractEntry::classAllowed( 16, 'article', array( '16' ) ) );
        $this->assertFalse( XrowExtractEntry::classAllowed( 16, 'article', array( 'user' ) ) );
        $this->assertFalse( XrowExtractEntry::classAllowed( 16, 'article', array( '160' ) ) );
    }

    public function testTheEntryFillsTheFormsSettings(): void
    {
        $saved = array( 'Version' => 2, 'Subtree' => 2, 'Scope' => 'all', 'Class_id' => 4, 'Filters' => array( 'x' => 1 ),
                        'Offset' => 50, 'Separator' => ';', 'OutputFormat' => 'json', 'Attributes' => array( 4 => array( 'kept' ) ) );
        $config = XrowExtractEntry::applyToConfig( $saved, XrowExtractEntry::parse( self::params( array( 'EntryNode' => '99', 'EntryScope' => 'list' ) ) ) );
        $this->assertSame( 99, $config['Subtree'] );
        $this->assertSame( 'list', $config['Scope'] );
        $this->assertArrayNotHasKey( 'Class_id', $config, 'no class: the form chooses' );
        $this->assertArrayNotHasKey( 'Filters', $config );
        $this->assertSame( 0, $config['Offset'] );
        $this->assertArrayNotHasKey( 'EntryColumns', $config );
        $this->assertSame( ';', $config['Separator'], 'the output settings stay' );
        $this->assertSame( 'json', $config['OutputFormat'] );
        $this->assertSame( 2, $config['Version'] );
        $this->assertSame( array( 4 => array( 'kept' ) ), $config['Attributes'], 'the saved columns stay unless the entry asks for others' );

        $config = XrowExtractEntry::applyToConfig( $saved, XrowExtractEntry::parse( self::params( array( 'EntryNode' => '99', 'EntryClass' => '16', 'EntryColumns' => 'all' ) ) ) );
        $this->assertSame( 16, $config['Class_id'] );
        $this->assertSame( 'tree', $config['Scope'] );
        $this->assertSame( 'all', $config['EntryColumns'] );
    }

    public function testTheMenusClassListIsSortedAndLimited(): void
    {
        $classes = array( 1 => array( 'identifier' => 'folder', 'name' => 'Folder' ),
                          2 => array( 'identifier' => 'article', 'name' => 'Article' ),
                          3 => array( 'identifier' => 'image', 'name' => 'Image' ),
                          4 => array( 'identifier' => 'blog', 'name' => 'Blog' ) );
        $list = XrowExtractEntry::classList( array( 1 => 2, 2 => 5, 3 => 2, 4 => 0, 9 => 3 ), $classes );
        $this->assertSame( array( 'article', 'folder', 'image' ), array_column( $list['classes'], 'identifier' ),
            'most objects first, then by name; none without objects or without a class' );
        $this->assertSame( array( 'id' => 2, 'identifier' => 'article', 'name' => 'Article', 'count' => 5 ), $list['classes'][0] );
        $this->assertFalse( $list['more'] );
        $this->assertSame( 3, $list['total'] );

        $limited = XrowExtractEntry::classList( array( 1 => 2, 2 => 5, 3 => 2 ), $classes, 2 );
        $this->assertCount( 2, $limited['classes'] );
        $this->assertTrue( $limited['more'] );
        $this->assertSame( 3, $limited['total'] );
        $this->assertSame( array( 'classes' => array(), 'more' => false, 'total' => 0 ), XrowExtractEntry::classList( array(), $classes ) );
    }
}
