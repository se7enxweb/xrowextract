<?php

/**
 * Extended attribute filter "XrowExtractRelation": only objects that relate to a given object
 * (relation_type ignored — any relation counts, including object-relation and object-relation-list
 * attributes and embedded relations), or, reversed, objects that a given object relates to. Registered in
 * extendedattributefilter.ini so it appears in the extract view's "Extended attribute filter" picker,
 * chained with the language filter the same way (see classes/xrowextracttranslationfilter.php).
 *
 *   'ExtendedAttributeFilter' => array( 'id' => 'XrowExtractRelation', 'params' => array( 'object_id' => 91 ) )
 *   'ExtendedAttributeFilter' => array( 'id' => 'XrowExtractRelation', 'params' => array( 'object_id' => 91, 'reverse' => true ) )
 *
 * 'unrelated' => true ignores object_id and instead matches objects that NO other object relates to at all
 * ("orphaned/unused images or files" — nothing embeds or references them via an object relation, an
 * object-relation-list attribute, or an embedded ezxmltext/ezxmltags link).
 */
class XrowExtractRelationFilter
{
    public function createSqlParts( $params )
    {
        $parts = array( 'tables' => '', 'joins' => '', 'columns' => '' );
        if ( !empty( $params['unrelated'] ) )
        {
            $parts['joins'] = ' ( NOT EXISTS ( SELECT 1 FROM ezcontentobject_link xerl'
                             . ' WHERE xerl.to_contentobject_id = ezcontentobject.id'
                             . ' AND xerl.from_contentobject_id != ezcontentobject.id'
                             . ' AND xerl.from_contentobject_version > 0 ) ) AND ';
            return $parts;
        }
        $objectID = isset( $params['object_id'] ) ? (int)$params['object_id'] : 0;
        if ( $objectID <= 0 )
        {
            $parts['joins'] = ' ( 1 = 0 ) AND '; // no object id: match nothing rather than everything
            return $parts;
        }
        $reverse = isset( $params['reverse'] ) && self::truthy( $params['reverse'] );
        // A correlated EXISTS against ezcontentobject_link, aliased against the outer query's ezcontentobject.
        $exists = $reverse
                ? "EXISTS ( SELECT 1 FROM ezcontentobject_link xerl WHERE xerl.to_contentobject_id = ezcontentobject.id AND xerl.from_contentobject_id = $objectID AND xerl.from_contentobject_version > 0 )"
                : "EXISTS ( SELECT 1 FROM ezcontentobject_link xerl WHERE xerl.from_contentobject_id = ezcontentobject.id AND xerl.to_contentobject_id = $objectID AND xerl.from_contentobject_version > 0 )";
        $parts['joins'] = ' ( ' . $exists . ' ) AND ';
        return $parts;
    }

    protected static function truthy( $value )
    {
        return in_array( strtolower( trim( (string)$value ) ), array( '1', 'true', 'yes', 'on' ), true );
    }
}

?>
