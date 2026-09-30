<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractFilters: what a filter form or a command line gives turned into the kernel's AttributeFilter. */
class FiltersTest extends TestCase
{
    public function testDefaultsGiveNoFilter(): void
    {
        $f = new XrowExtractFilters();
        $this->assertSame( XrowExtractFilters::defaults(), $f->values );
        $this->assertFalse( $f->attributeFilter() );
        $this->assertSame( 0, $f->activeCount() );
    }

    public function testInvalidValuesAreNormalised(): void
    {
        $f = new XrowExtractFilters( array(
            'date_mode' => 'sometime', 'date_from' => 'not a date', 'date_field' => 'x; DROP', 'section' => '-3', 'state' => 'abc',
            'visibility' => 'maybe', 'name' => str_repeat( 'n', 300 ), 'where_attribute' => 'a b', 'where_op' => 'regex',
            'conditions_join' => 'xor', 'depth_mode' => 'deep', 'depth_value' => 99, 'extended_filter' => 'no_such_filter',
            'extended_params' => '{"x":1}', 'fetch_alias' => '../x', 'unknown_key' => 'dropped',
        ) );
        $v = $f->values;
        $this->assertSame( 'any', $v['date_mode'] );
        $this->assertSame( '', $v['date_from'] );
        $this->assertSame( 'modified', $v['date_field'] );
        $this->assertSame( 0, $v['section'] );
        $this->assertSame( 0, $v['state'] );
        $this->assertSame( 'any', $v['visibility'] );
        $this->assertSame( 200, mb_strlen( $v['name'] ) );
        $this->assertSame( '', $v['where_attribute'] );
        $this->assertSame( 'contains', $v['where_op'] );
        $this->assertSame( 'and', $v['conditions_join'] );
        $this->assertSame( 'any', $v['depth_mode'] );
        $this->assertSame( 50, $v['depth_value'] );
        $this->assertSame( '', $v['extended_filter'] );
        $this->assertSame( '', $v['extended_params'] );
        $this->assertSame( '', $v['fetch_alias'] );
        $this->assertArrayNotHasKey( 'unknown_key', $v );
    }

    public function testConditionRowsAreCleanedAndCapped(): void
    {
        $rows = array( 'not a row', array( 'field' => 'ti<t>le', 'op' => 'bogus', 'value' => 'x', 'negate' => '1' ) );
        for ( $i = 0; $i < 30; $i++ )
            $rows[] = array( 'field' => 'f' . $i, 'op' => 'eq', 'value' => (string)$i );
        $f = new XrowExtractFilters( array( 'conditions' => $rows ) );
        $this->assertCount( 20, $f->values['conditions'] );
        $first = $f->values['conditions'][0];
        $this->assertSame( array( 'field' => 'title', 'op' => 'contains', 'value' => 'x', 'value2' => '', 'negate' => true ), $first );
    }

    public function testTimestamps(): void
    {
        $this->assertFalse( XrowExtractFilters::timestamp( '' ) );
        $this->assertFalse( XrowExtractFilters::timestamp( 'no date here' ) );
        $this->assertSame( 1790000000, XrowExtractFilters::timestamp( '1790000000' ) );
        $day = XrowExtractFilters::timestamp( '2026-09-01' );
        $this->assertSame( mktime( 0, 0, 0, 9, 1, 2026 ), $day );
        $this->assertSame( $day + 86399, XrowExtractFilters::timestamp( '2026-09-01', true ) );
        $ago = XrowExtractFilters::timestamp( '7d' );
        $this->assertEqualsWithDelta( time() - 7 * 86400, $ago, 3700 ); // a DST change may move it by an hour
    }

    public function testAttributeFilterParts(): void
    {
        $now = mktime( 12, 0, 0, 9, 15, 2026 );
        $f = new XrowExtractFilters( array( 'date_mode' => '7', 'section' => 3, 'state' => 5, 'visibility' => 'hidden', 'name' => 'a*b' ) );
        $this->assertSame( array( 'and',
            array( 'modified', '>=', $now - 7 * 86400 ),
            array( 'section', '=', 3 ),
            array( 'state', '=', 5 ),
            array( 'visibility', '=', '0' ),
            array( 'name', 'like', '*ab*' ),
        ), $f->attributeFilter( false, null, $now ) );
    }

    public function testDateModesBetweenAndSinceLast(): void
    {
        $f = new XrowExtractFilters( array( 'date_mode' => 'between', 'date_from' => '2026-09-01', 'date_to' => '2026-09-02', 'date_field' => 'published' ) );
        $this->assertSame( array( 'and',
            array( 'published', '>=', mktime( 0, 0, 0, 9, 1, 2026 ) ),
            array( 'published', '<=', mktime( 0, 0, 0, 9, 2, 2026 ) + 86399 ),
        ), $f->attributeFilter() );
        $since = new XrowExtractFilters( array( 'date_mode' => 'since_last' ) );
        $this->assertFalse( $since->attributeFilter( false, null ) );
        $this->assertSame( array( 'and', array( 'modified', '>=', 1001 ) ), $since->attributeFilter( false, 1000 ) );
        // A date attribute needs the class to be known
        $attr = new XrowExtractFilters( array( 'date_mode' => 'past', 'date_field' => 'event_date' ) );
        $this->assertFalse( $attr->attributeFilter( false ) );
        $this->assertSame( 'event/event_date', $attr->attributeFilter( 'event', null, 50 )[1][0] );
    }

    public function testConditionsAndJoin(): void
    {
        $f = new XrowExtractFilters( array(
            'conditions_join' => 'or',
            'conditions' => array(
                array( 'field' => 'title', 'op' => 'starts', 'value' => 'Ab*c' ),
                array( 'field' => 'price', 'op' => 'between', 'value' => '1', 'value2' => '9.5' ),
                array( 'field' => 'tags', 'op' => 'in', 'value' => 'a, b,,c' ),
                array( 'field' => 'body', 'op' => 'contains', 'value' => '' ),
                array( 'field' => 'count', 'op' => 'gt', 'value' => '3', 'negate' => true ),
            ),
        ) );
        $this->assertSame( array( 'or',
            array( 'article/title', 'like', 'Abc*' ),
            array( 'article/price', 'between', array( 1, 9.5 ) ),
            array( 'article/tags', 'in', array( 'a', 'b', 'c' ) ),
            array( 'article/count', '<=', 3 ),
        ), $f->attributeFilter( 'article' ) );
        // Without the class, attribute conditions cannot be resolved and are left out
        $this->assertFalse( $f->attributeFilter( false ) );
        $this->assertTrue( $f->conditionsJoinAffectsEverything() );
    }

    public function testLegacySingleConditionComesFirst(): void
    {
        $f = new XrowExtractFilters( array( 'where_attribute' => 'title', 'where_op' => 'eq', 'where_value' => 'X',
                                            'conditions' => array( array( 'field' => 'body', 'op' => 'filled' ) ) ) );
        $all = $f->allConditions();
        $this->assertSame( 'title', $all[0]['field'] );
        $this->assertSame( 'body', $all[1]['field'] );
        $this->assertSame( array( 'and', array( 'c/title', '=', 'x' ), array( 'c/body', '!=', '' ) ), $f->attributeFilter( 'c' ) );
    }
}
