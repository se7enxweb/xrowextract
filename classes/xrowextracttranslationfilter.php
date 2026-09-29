<?php

/**
 * Extended attribute filter "XrowExtractTranslation": only objects that have a translation in the given
 * language. The kernel's Language parameter alone also lets objects through that are always available
 * in another language, and the OnlyTranslated parameter is not applied by subtree fetches and counts.
 *
 * The fetch functions take only one ExtendedAttributeFilter, and the language filter already needs that
 * slot, so a second extended filter the user chooses (extendedattributefilter.ini, e.g. eztags) is chained
 * through the same call: this class's own language SQL is built as before, then the chosen filter's own
 * createExtendedAttributeFilterSQLStrings() is called and the two results are merged (tables, joins,
 * columns), instead of one silently replacing the other.
 *
 *   'ExtendedAttributeFilter' => array( 'id' => 'XrowExtractTranslation', 'params' => array(
 *       'language' => 'ger-DE',
 *       // optional, to chain a second extended filter:
 *       'chain_id' => 'TagsAttributeFilter', 'chain_params' => array( 'tag_id' => 12 ),
 *   ) )
 */
class XrowExtractTranslationFilter
{
    public function createSqlParts( $params )
    {
        $parts = array( 'tables' => '', 'joins' => '', 'columns' => '' );
        $language = isset( $params['language'] ) ? eZContentLanguage::fetchByLocale( $params['language'] ) : false;
        // An unknown language matches nothing, rather than everything
        $bit = $language instanceof eZContentLanguage ? (int)$language->attribute( 'id' ) : 0;
        $parts['joins'] = eZDB::instance()->databaseName() === 'oracle'
                        ? " bitand( ezcontentobject.language_mask, $bit ) > 0 AND "
                        : " ( ezcontentobject.language_mask & $bit ) > 0 AND ";

        if ( !empty( $params['chain_id'] ) )
        {
            $chainParams = isset( $params['chain_params'] ) && is_array( $params['chain_params'] ) ? $params['chain_params'] : array();
            // createExtendedAttributeFilterSQLStrings() takes its argument by reference, so it must be a variable
            $chainFilter = array( 'id' => $params['chain_id'], 'params' => $chainParams );
            $chained = eZContentObjectTreeNode::createExtendedAttributeFilterSQLStrings( $chainFilter );
            $parts['tables'] .= isset( $chained['tables'] ) ? $chained['tables'] : '';
            $parts['joins']  .= isset( $chained['joins'] ) ? $chained['joins'] : '';
            $parts['columns'] .= isset( $chained['columns'] ) ? $chained['columns'] : '';
        }

        return $parts;
    }

    /** The subtree parameter for a language alone, to merge into fetch or count parameters. */
    public static function params( $locale )
    {
        return self::chainedParams( $locale );
    }

    /**
     * The subtree parameter for a language, optionally chained with a second extended attribute filter
     * ($chainID: an id of extendedattributefilter.ini, '' for none; $chainParams: its params).
     */
    public static function chainedParams( $locale, $chainID = '', array $chainParams = array() )
    {
        $params = array( 'language' => $locale );
        if ( $chainID !== '' )
        {
            $params['chain_id'] = $chainID;
            $params['chain_params'] = $chainParams;
        }
        return array( 'id' => 'XrowExtractTranslation', 'params' => $params );
    }
}

?>
