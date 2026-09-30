<?php

/**
 * Extended attribute filter "XrowExtractUserStatus": only user objects that are enabled, or only ones that
 * are disabled, via the standard `ezuser_setting.is_enabled` column (there is no "last login" column in the
 * kernel schema to filter on, so a "never logged in" preset is not offered — see settings/xrowextract.ini).
 * Registered in extendedattributefilter.ini.append.php; chained with the language filter the same way as
 * the other extended filters here (see classes/xrowextracttranslationfilter.php).
 *
 *   'ExtendedAttributeFilter' => array( 'id' => 'XrowExtractUserStatus', 'params' => array( 'enabled' => true ) )
 */
class XrowExtractUserStatusFilter
{
    public function createSqlParts( $params )
    {
        $parts = array( 'tables' => '', 'joins' => '', 'columns' => '' );
        $enabled = !array_key_exists( 'enabled', $params ) || self::truthy( $params['enabled'] ) ? 1 : 0;
        $parts['joins'] = " EXISTS ( SELECT 1 FROM ezuser_setting xeus"
                         . " WHERE xeus.user_id = ezcontentobject.id AND xeus.is_enabled = $enabled ) AND ";
        return $parts;
    }

    protected static function truthy( $value )
    {
        return in_array( strtolower( trim( (string)$value ) ), array( '1', 'true', 'yes', 'on' ), true );
    }
}

?>
