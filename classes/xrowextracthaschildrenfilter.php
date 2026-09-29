<?php

/**
 * Extended attribute filter "XrowExtractHasChildren": only objects whose node has at least one child
 * (leaf content otherwise). Registered in extendedattributefilter.ini so it appears in the extract view's
 * "Extended attribute filter" picker like any other, chained with the language filter the same way
 * (see classes/xrowextracttranslationfilter.php).
 *
 *   'ExtendedAttributeFilter' => array( 'id' => 'XrowExtractHasChildren', 'params' => array( 'has_children' => true ) )
 */
class XrowExtractHasChildrenFilter
{
    public function createSqlParts( $params )
    {
        $parts = array( 'tables' => '', 'joins' => '', 'columns' => '' );
        $wantChildren = !isset( $params['has_children'] ) || self::truthy( $params['has_children'] );
        // A correlated EXISTS against the same table, aliased so it never collides with the outer query's
        // own (unaliased) ezcontentobject_tree.
        $exists = 'EXISTS ( SELECT 1 FROM ezcontentobject_tree xehc WHERE xehc.parent_node_id = ezcontentobject_tree.node_id )';
        $parts['joins'] = ' ( ' . ( $wantChildren ? '' : 'NOT ' ) . $exists . ' ) AND ';
        return $parts;
    }

    protected static function truthy( $value )
    {
        return in_array( strtolower( trim( (string)$value ) ), array( '1', 'true', 'yes', 'on' ), true );
    }
}

?>
