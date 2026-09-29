<?php

/**
 * Extended attribute filter "XrowExtractTranslation": only objects that have a translation in the given
 * language. The kernel's Language parameter alone also lets objects through that are always available
 * in another language, and the OnlyTranslated parameter is not applied by subtree fetches and counts.
 *
 *   'ExtendedAttributeFilter' => array( 'id' => 'XrowExtractTranslation', 'params' => array( 'language' => 'ger-DE' ) )
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
        return $parts;
    }

    /** The subtree parameter for a language, to merge into fetch or count parameters. */
    public static function params( $locale )
    {
        return array( 'id' => 'XrowExtractTranslation', 'params' => array( 'language' => $locale ) );
    }
}

?>
