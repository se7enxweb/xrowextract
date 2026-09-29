<?php

/**
 * Filters of an export: a date range (published, modified, or a date attribute of the class; any, today,
 * the last N days, since, before, between, future, past, changed since the last export), section,
 * object state, visibility, name, and one condition on a class attribute. They become the kernel's
 * AttributeFilter, so counts, preview and download see the same objects.
 */
class XrowExtractFilters
{
    public $values = array();

    public static function defaults()
    {
        return array( 'date_field' => 'modified', 'date_mode' => 'any', 'date_from' => '', 'date_to' => '',
                      'section' => 0, 'state' => 0, 'visibility' => 'any', 'name' => '',
                      'where_attribute' => '', 'where_op' => 'contains', 'where_value' => '' );
    }

    /** The date modes: id => label. */
    public static function dateModes()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        return array( 'any' => $t( 'Any time' ), 'today' => $t( 'Today' ), '7' => $t( 'Last 7 days' ), '30' => $t( 'Last 30 days' ),
                      '90' => $t( 'Last 90 days' ), '365' => $t( 'Last year' ), 'since' => $t( 'Since' ), 'before' => $t( 'Before' ),
                      'between' => $t( 'Between' ), 'future' => $t( 'In the future' ), 'past' => $t( 'In the past' ),
                      'since_last' => $t( 'Changed since my last export' ) );
    }

    /** The condition operators: id => label. */
    public static function operators()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        return array( 'contains' => $t( 'contains' ), 'starts' => $t( 'starts with' ), 'eq' => $t( 'is' ), 'ne' => $t( 'is not' ),
                      'gt' => $t( 'greater than' ), 'lt' => $t( 'less than' ), 'empty' => $t( 'is empty' ), 'filled' => $t( 'is not empty' ) );
    }

    /** Datatypes a condition or a date field can use. */
    public static function filterableDatatypes()
    {
        return array( 'ezstring', 'eztext', 'ezinteger', 'ezfloat', 'ezboolean', 'ezemail', 'ezidentifier', 'ezisbn', 'ezdate', 'ezdatetime', 'ezselection' );
    }

    public function __construct( array $values = array() )
    {
        $this->values = array_merge( self::defaults(), array_intersect_key( $values, self::defaults() ) );
        $this->normalize();
    }

    protected function normalize()
    {
        $v =& $this->values;
        if ( !array_key_exists( (string)$v['date_mode'], self::dateModes() ) )
            $v['date_mode'] = 'any';
        foreach ( array( 'date_from', 'date_to' ) as $key )
        {
            $v[$key] = is_string( $v[$key] ) ? trim( $v[$key] ) : '';
            if ( $v[$key] !== '' && self::timestamp( $v[$key] ) === false )
                $v[$key] = '';
        }
        $v['date_field'] = preg_match( '/^[A-Za-z0-9_]+$/', (string)$v['date_field'] ) ? (string)$v['date_field'] : 'modified';
        $v['section'] = max( 0, (int)$v['section'] );
        $v['state'] = max( 0, (int)$v['state'] );
        $v['visibility'] = in_array( $v['visibility'], array( 'any', 'visible', 'hidden' ), true ) ? $v['visibility'] : 'any';
        $v['name'] = mb_substr( trim( (string)$v['name'] ), 0, 200 );
        $v['where_attribute'] = preg_match( '/^[A-Za-z0-9_]*$/', (string)$v['where_attribute'] ) ? (string)$v['where_attribute'] : '';
        $v['where_op'] = array_key_exists( (string)$v['where_op'], self::operators() ) ? $v['where_op'] : 'contains';
        $v['where_value'] = mb_substr( (string)$v['where_value'], 0, 500 );
    }

    /**
     * A date as a timestamp: Y-m-d, Y-m-d H:i, ISO 8601, "today", "yesterday", or relative like "7d", "2w",
     * "3m", "1y" (that long ago); false when it cannot be read.
     */
    public static function timestamp( $text, $endOfDay = false )
    {
        $text = trim( (string)$text );
        if ( $text === '' )
            return false;
        if ( preg_match( '/^(\d+)([dwmy])$/', $text, $m ) )
        {
            $units = array( 'd' => 'day', 'w' => 'week', 'm' => 'month', 'y' => 'year' );
            return strtotime( '-' . $m[1] . ' ' . $units[$m[2]] );
        }
        if ( ctype_digit( $text ) && strlen( $text ) >= 9 )
            return (int)$text;
        $time = strtotime( $text );
        if ( $time === false )
            return false;
        if ( $endOfDay && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) )
            $time += 86399;
        return $time;
    }

    /** The date field's AttributeFilter key: published, modified, or class/attribute for a date attribute. */
    protected function dateKey( $classIdentifier )
    {
        $field = $this->values['date_field'];
        if ( $field === 'published' || $field === 'modified' )
            return $field;
        return $classIdentifier ? $classIdentifier . '/' . $field : false;
    }

    /**
     * The kernel AttributeFilter for these filters, or false for none. $classIdentifier: the class the
     * attribute parts refer to (false: only the parts that work for every class). $lastExport: the time of
     * the user's last export of this class, for "changed since my last export".
     */
    public function attributeFilter( $classIdentifier = false, $lastExport = null, $now = null )
    {
        $now = $now === null ? time() : $now;
        $v = $this->values;
        $parts = array();
        $dateKey = $this->dateKey( $classIdentifier );
        if ( $dateKey !== false )
        {
            $start = false; $end = false;
            switch ( $v['date_mode'] )
            {
                case 'today':      $start = strtotime( 'today', $now ); break;
                case '7': case '30': case '90': case '365':
                                   $start = $now - (int)$v['date_mode'] * 86400; break;
                case 'since':      $start = self::timestamp( $v['date_from'] ); break;
                case 'before':     $end = self::timestamp( $v['date_to'], true ); break;
                case 'between':    $start = self::timestamp( $v['date_from'] ); $end = self::timestamp( $v['date_to'], true ); break;
                case 'future':     $start = $now + 1; break;
                case 'past':       $end = $now; break;
                case 'since_last': $start = $lastExport ? (int)$lastExport + 1 : false; break;
            }
            if ( $start !== false )
                $parts[] = array( $dateKey, '>=', (int)$start );
            if ( $end !== false )
                $parts[] = array( $dateKey, '<=', (int)$end );
        }
        if ( $v['section'] )
            $parts[] = array( 'section', '=', $v['section'] );
        if ( $v['state'] )
            $parts[] = array( 'state', '=', $v['state'] );
        if ( $v['visibility'] !== 'any' )
            $parts[] = array( 'visibility', '=', $v['visibility'] === 'visible' ? '1' : '0' );
        if ( $v['name'] !== '' )
            $parts[] = array( 'name', 'like', '*' . self::likeText( $v['name'] ) . '*' );
        if ( $classIdentifier && $v['where_attribute'] !== '' )
        {
            $key = $classIdentifier . '/' . $v['where_attribute'];
            // Text is compared with the attribute's sort key, which the kernel keeps in lower case
            $value = is_numeric( $v['where_value'] ) ? $v['where_value'] : mb_strtolower( $v['where_value'], 'UTF-8' );
            $number = is_numeric( $value );
            $date = self::timestamp( $value );
            switch ( $v['where_op'] )
            {
                case 'contains': if ( $value !== '' ) $parts[] = array( $key, 'like', '*' . self::likeText( $value ) . '*' ); break;
                case 'starts':   if ( $value !== '' ) $parts[] = array( $key, 'like', self::likeText( $value ) . '*' ); break;
                case 'eq':       $parts[] = array( $key, '=', $number ? $value + 0 : $value ); break;
                case 'ne':       $parts[] = array( $key, '!=', $number ? $value + 0 : $value ); break;
                case 'gt':       $parts[] = array( $key, '>', $number ? $value + 0 : ( $date !== false ? $date : $value ) ); break;
                case 'lt':       $parts[] = array( $key, '<', $number ? $value + 0 : ( $date !== false ? $date : $value ) ); break;
                case 'empty':    $parts[] = array( $key, '=', '' ); break;
                case 'filled':   $parts[] = array( $key, '!=', '' ); break;
            }
        }
        if ( !$parts )
            return false;
        return array_merge( array( 'and' ), $parts );
    }

    /** Text for a LIKE filter: the kernel reads * as the wildcard, so a * in the text is taken out. */
    protected static function likeText( $text )
    {
        return str_replace( '*', '', $text );
    }

    /** How many filters are set (for the card's badge). */
    public function activeCount()
    {
        $v = $this->values;
        return ( $v['date_mode'] !== 'any' ? 1 : 0 ) + ( $v['section'] ? 1 : 0 ) + ( $v['state'] ? 1 : 0 )
             + ( $v['visibility'] !== 'any' ? 1 : 0 ) + ( $v['name'] !== '' ? 1 : 0 )
             + ( $v['where_attribute'] !== '' && ( $v['where_value'] !== '' || in_array( $v['where_op'], array( 'empty', 'filled' ), true ) ) ? 1 : 0 );
    }

    /** The class attributes a date field or a condition can use: identifier => (name, datatype). */
    public static function classFields( $classID )
    {
        $fields = array();
        foreach ( eZContentClassAttribute::fetchListByClassID( (int)$classID, eZContentClass::VERSION_STATUS_DEFINED, true ) as $attribute )
        {
            if ( in_array( $attribute->attribute( 'data_type_string' ), self::filterableDatatypes(), true ) )
                $fields[$attribute->attribute( 'identifier' )] = array( 'identifier' => $attribute->attribute( 'identifier' ), 'name' => $attribute->attribute( 'name' ),
                                                                         'datatype' => $attribute->attribute( 'data_type_string' ),
                                                                         'is_date' => in_array( $attribute->attribute( 'data_type_string' ), array( 'ezdate', 'ezdatetime' ), true ) );
        }
        return $fields;
    }

    /** The sort fields: tree (the node's own sorting), name, published, modified, priority; then class attributes. */
    public static function sortFields()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        return array( 'tree' => $t( 'Tree order (as the node sorts)' ), 'name' => $t( 'Name' ), 'published' => $t( 'Published' ),
                      'modified' => $t( 'Modified' ), 'priority' => $t( 'Priority' ), 'path' => $t( 'Location in the tree' ) );
    }

    /**
     * The kernel SortBy for a sort choice: $field one of sortFields() or a class attribute identifier;
     * $default the node's own sorting (used for "tree"). An attribute that is not sortable falls back to name.
     */
    public static function sortParam( $field, $ascending, $classID, $classIdentifier, $default )
    {
        $ascending = (bool)$ascending;
        switch ( $field )
        {
            case 'tree':
                return $default;
            case 'name': case 'published': case 'modified': case 'priority': case 'path':
                return array( $field, $ascending );
        }
        $fields = self::classFields( $classID );
        if ( isset( $fields[$field] ) && $classIdentifier )
            return array( 'attribute', $ascending, $classIdentifier . '/' . $field );
        return array( 'name', $ascending );
    }

    /** The preference that holds the time of the user's last export of a class. */
    public static function lastExportPreference( $classID )
    {
        return 'admin_xrowextract_last_export_' . (int)$classID;
    }
}

?>
