<?php

/**
 * Filters of an export: a date range (published, modified, or a date attribute of the class; any, today,
 * the last N days, since, before, between, future, past, changed since the last export), section, object
 * state, visibility, name, an exact/at most/at least tree depth below the node, several conditions (on a
 * class attribute or an object field, joined with and/or), an extended attribute filter chained with the
 * language filter, and a named fetch chosen from fetchalias.ini. They become the kernel's AttributeFilter
 * (and, for the depth and the extended filter, the Depth/DepthOperator and ExtendedAttributeFilter fetch
 * parameters), so counts, preview and download see the same objects.
 *
 * The single condition of the first version (where_attribute/where_op/where_value) is kept: a session or a
 * background job argument list that only has it still works, and it is folded into conditions[] together
 * with the rows added since.
 */
class XrowExtractFilters
{
    public $values = array();

    public static function defaults()
    {
        return array( 'date_field' => 'modified', 'date_mode' => 'any', 'date_from' => '', 'date_to' => '',
                      'section' => 0, 'state' => 0, 'visibility' => 'any', 'name' => '',
                      // The first version's single condition; kept so old sessions and job arguments still work
                      'where_attribute' => '', 'where_op' => 'contains', 'where_value' => '',
                      // Several conditions, joined with and/or
                      'conditions' => array(), 'conditions_join' => 'and',
                      // An exact / at most / at least depth below the node (the tree scope only)
                      'depth_mode' => 'any', 'depth_value' => 0,
                      // An extended attribute filter (extendedattributefilter.ini) chained with the language filter
                      'extended_filter' => '', 'extended_params' => '',
                      // A fetchalias.ini named fetch applied to the view
                      'fetch_alias' => '', 'fetch_alias_siteaccess' => '' );
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

    /** The friendly condition operators (the first version's set). */
    public static function operators()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        return array( 'contains' => $t( 'contains' ), 'starts' => $t( 'starts with' ), 'eq' => $t( 'is' ), 'ne' => $t( 'is not' ),
                      'gt' => $t( 'greater than' ), 'lt' => $t( 'less than' ), 'empty' => $t( 'is empty' ), 'filled' => $t( 'is not empty' ) );
    }

    /** Every condition-row operator: the friendly set plus the kernel's own (>=, <=, in, not in, between, like pattern). */
    public static function conditionOperators()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        return array_merge( self::operators(), array(
            'gte' => $t( 'greater than or equal to' ), 'lte' => $t( 'less than or equal to' ),
            'in' => $t( 'in list' ), 'not_in' => $t( 'not in list' ),
            'between' => $t( 'between (both ends included)' ), 'not_between' => $t( 'not between' ),
            'like' => $t( 'matches pattern (* wildcard)' ), 'not_like' => $t( 'does not match pattern (* wildcard)' ),
        ) );
    }

    /** Operators that need two values (Filter[...][value] and [value2]). */
    public static function twoValueOperators()
    {
        return array( 'between', 'not_between' );
    }

    /** Operators that take a comma separated list of values. */
    public static function listOperators()
    {
        return array( 'in', 'not_in' );
    }

    /** Operators that need no value at all. */
    public static function noValueOperators()
    {
        return array( 'empty', 'filled' );
    }

    /** Datatypes a condition or a date field can use. */
    public static function filterableDatatypes()
    {
        return array( 'ezstring', 'eztext', 'ezinteger', 'ezfloat', 'ezboolean', 'ezemail', 'ezidentifier', 'ezisbn', 'ezdate', 'ezdatetime', 'ezselection' );
    }

    /**
     * The object fields a condition row can use besides a class attribute: the kernel's own AttributeFilter
     * fields (createAttributeFilterSQLStrings()), minus the ones that already have their own control
     * (date_field/date_mode, section, state, visibility, name). id => (name, kind, ops).
     * kind: string | int | date | state — decides how a typed value is read and which operators apply.
     */
    public static function objectFields()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        $all = array_keys( self::conditionOperators() );
        $stateOps = array( 'eq', 'ne', 'in', 'not_in' ); // the kernel's state filter supports only these
        return array(
            'name'              => array( 'name' => $t( 'Name' ), 'kind' => 'string', 'ops' => $all ),
            'published'         => array( 'name' => $t( 'Published' ), 'kind' => 'date', 'ops' => $all ),
            'modified'          => array( 'name' => $t( 'Modified' ), 'kind' => 'date', 'ops' => $all ),
            'modified_subnode'  => array( 'name' => $t( 'Modified, including sub items' ), 'kind' => 'date', 'ops' => $all ),
            'section'           => array( 'name' => $t( 'Section (id)' ), 'kind' => 'int', 'ops' => $all ),
            'owner'             => array( 'name' => $t( 'Owner (user id or login)' ), 'kind' => 'user', 'ops' => $all ),
            'priority'          => array( 'name' => $t( 'Priority' ), 'kind' => 'int', 'ops' => $all ),
            'depth'             => array( 'name' => $t( 'Tree depth (absolute)' ), 'kind' => 'int', 'ops' => $all ),
            'class_identifier'  => array( 'name' => $t( 'Class identifier' ), 'kind' => 'string', 'ops' => $all ),
            'class_name'        => array( 'name' => $t( 'Class name' ), 'kind' => 'string', 'ops' => $all ),
            'node_id'           => array( 'name' => $t( 'Node id' ), 'kind' => 'int', 'ops' => $all ),
            'contentobject_id'  => array( 'name' => $t( 'Object id' ), 'kind' => 'int', 'ops' => $all ),
            'path'              => array( 'name' => $t( 'Location in the tree (path)' ), 'kind' => 'string', 'ops' => $all ),
            'state'             => array( 'name' => $t( 'Object state (id)' ), 'kind' => 'state', 'ops' => $stateOps ),
        );
    }

    /** The exact / at most / at least depth choices, keyed as used by $v['depth_mode']. */
    public static function depthModes()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        return array( 'any' => $t( 'Any depth' ), 'exact' => $t( 'Exactly' ), 'atmost' => $t( 'At most' ), 'atleast' => $t( 'At least' ) );
    }

    /** depth_mode => the kernel's DepthOperator. */
    protected static function depthOperatorFor( $mode )
    {
        $map = array( 'exact' => 'eq', 'atmost' => 'le', 'atleast' => 'ge' );
        return isset( $map[$mode] ) ? $map[$mode] : false;
    }

    public function __construct( array $values = array() )
    {
        $this->values = array_merge( self::defaults(), array_intersect_key( $values, self::defaults() ) );
        if ( isset( $values['conditions'] ) )
            $this->values['conditions'] = $values['conditions'];
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

        // Condition rows: field, op, value, value2; at most 20, each value length capped
        $ops = self::conditionOperators();
        $conditions = array();
        foreach ( (array)$v['conditions'] as $row )
        {
            if ( !is_array( $row ) )
                continue;
            $field = isset( $row['field'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string)$row['field'] ) : '';
            $op = isset( $row['op'] ) && array_key_exists( (string)$row['op'], $ops ) ? (string)$row['op'] : 'contains';
            $conditions[] = array(
                'field' => $field,
                'op' => $op,
                'value' => mb_substr( isset( $row['value'] ) ? (string)$row['value'] : '', 0, 500 ),
                'value2' => mb_substr( isset( $row['value2'] ) ? (string)$row['value2'] : '', 0, 500 ),
                'negate' => !empty( $row['negate'] ),
            );
            if ( count( $conditions ) >= 20 )
                break;
        }
        $v['conditions'] = $conditions;
        $v['conditions_join'] = $v['conditions_join'] === 'or' ? 'or' : 'and';

        $v['depth_mode'] = array_key_exists( (string)$v['depth_mode'], self::depthModes() ) ? $v['depth_mode'] : 'any';
        $v['depth_value'] = max( 0, min( 50, (int)$v['depth_value'] ) );

        $v['extended_filter'] = ( $v['extended_filter'] !== '' && array_key_exists( (string)$v['extended_filter'], self::extendedFilters() ) )
                               ? (string)$v['extended_filter'] : '';
        $v['extended_params'] = trim( (string)$v['extended_params'] );
        if ( $v['extended_filter'] === '' )
            $v['extended_params'] = '';
        elseif ( $v['extended_params'] !== '' )
        {
            json_decode( $v['extended_params'], true );
            if ( json_last_error() !== JSON_ERROR_NONE )
                $v['extended_params'] = ''; // invalid JSON: drop it rather than fail the fetch
        }

        $v['fetch_alias'] = preg_match( '/^[A-Za-z0-9_.-]*$/', (string)$v['fetch_alias'] ) ? (string)$v['fetch_alias'] : '';
        $v['fetch_alias_siteaccess'] = preg_match( '/^[A-Za-z0-9_.-]*$/', (string)$v['fetch_alias_siteaccess'] ) ? (string)$v['fetch_alias_siteaccess'] : '';
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
     *
     * The kernel's AttributeFilter is a flat list with a single and/or for the whole list — it has no
     * nested groups. Every active filter here (date, section, state, visibility, name and every condition
     * row) is joined the same way: 'and' unless a condition row's join is 'or' and at least one condition
     * is active, in which case the join for the *whole* filter becomes 'or'. That is a real limit of the
     * fetch, not a simplification on our part; conditionsJoinAffectsEverything() below reports it so a
     * caller can tell the user when it applies.
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

        $conditions = $this->allConditions();
        $conditionsUsed = false;
        foreach ( $conditions as $condition )
        {
            $part = $this->conditionPart( $condition, $classIdentifier );
            if ( $part !== false )
            {
                $parts[] = $part;
                $conditionsUsed = true;
            }
        }

        if ( !$parts )
            return false;
        $join = ( $conditionsUsed && $v['conditions_join'] === 'or' ) ? 'or' : 'and';
        return array_merge( array( $join ), $parts );
    }

    /** The condition rows this filter set applies: the legacy single condition (if set) then conditions[]. */
    public function allConditions()
    {
        $v = $this->values;
        $rows = array();
        if ( $v['where_attribute'] !== '' )
            $rows[] = array( 'field' => $v['where_attribute'], 'op' => $v['where_op'], 'value' => $v['where_value'], 'value2' => '' );
        foreach ( $v['conditions'] as $row )
        {
            if ( $row['field'] !== '' )
                $rows[] = $row;
        }
        return $rows;
    }

    /** True when "any" (or) is chosen and there is more than one active filter, so the join covers everything. */
    public function conditionsJoinAffectsEverything()
    {
        if ( $this->values['conditions_join'] !== 'or' )
            return false;
        $active = ( $this->values['date_mode'] !== 'any' ? 1 : 0 ) + ( $this->values['section'] ? 1 : 0 ) + ( $this->values['state'] ? 1 : 0 )
                + ( $this->values['visibility'] !== 'any' ? 1 : 0 ) + ( $this->values['name'] !== '' ? 1 : 0 ) + count( $this->allConditions() );
        return $active > 1;
    }

    /** One condition row as an AttributeFilter part (field, op, value[, value2]), or false. */
    protected function conditionPart( array $c, $classIdentifier )
    {
        $field = trim( (string)$c['field'] );
        $op = array_key_exists( $c['op'], self::conditionOperators() ) ? $c['op'] : 'contains';
        if ( $field === '' )
            return false;
        $objectFields = self::objectFields();
        if ( isset( $objectFields[$field] ) )
        {
            if ( !in_array( $op, $objectFields[$field]['ops'], true ) )
                return false; // e.g. "like" on state: the kernel's state filter only takes =, !=, in, not in
            $part = self::buildPart( $field, $op, $c, $objectFields[$field]['kind'], false );
        }
        elseif ( $classIdentifier )
        {
            $part = self::buildPart( $classIdentifier . '/' . $field, $op, $c, 'attribute', true );
        }
        else
        {
            return false; // a class attribute condition needs the class to resolve
        }
        if ( $part !== false && !empty( $c['negate'] ) )
            $part = self::negatePart( $part );
        return $part;
    }

    /** The same (key, op, value) part with its operator inverted (the row's "not" checkbox). */
    protected static function negatePart( array $part )
    {
        $inverse = array( '=' => '!=', '!=' => '=', '>' => '<=', '<' => '>=', '>=' => '<', '<=' => '>',
                          'like' => 'not_like', 'not_like' => 'like', 'in' => 'not_in', 'not_in' => 'in',
                          'between' => 'not_between', 'not_between' => 'between' );
        if ( isset( $inverse[$part[1]] ) )
            $part[1] = $inverse[$part[1]];
        return $part;
    }

    /** One (key, op, value) or (key, op, array(...)) AttributeFilter part, or false when the row has no usable value. */
    protected static function buildPart( $key, $op, array $c, $kind, $lowercaseText )
    {
        $raw = trim( (string)$c['value'] );
        $raw2 = trim( (string)( isset( $c['value2'] ) ? $c['value2'] : '' ) );
        $coerce = function ( $text ) use ( $kind, $lowercaseText )
        {
            $text = trim( $text );
            if ( $kind === 'date' )
            {
                $t = XrowExtractFilters::timestamp( $text );
                return $t !== false ? $t : $text;
            }
            if ( $kind === 'int' || $kind === 'state' )
                return (int)$text;
            if ( $kind === 'user' )
            {
                if ( $text !== '' && ctype_digit( $text ) )
                    return (int)$text;
                $byLogin = $text !== '' ? eZUser::fetchByName( $text ) : false;
                return $byLogin instanceof eZUser ? (int)$byLogin->attribute( 'contentobject_id' ) : -1; // no such login: match nothing
            }
            if ( $kind === 'attribute' )
                return ( $text !== '' && is_numeric( $text ) ) ? $text + 0 : ( $lowercaseText ? mb_strtolower( $text, 'UTF-8' ) : $text );
            return $text;
        };
        switch ( $op )
        {
            case 'contains': return $raw === '' ? false : array( $key, 'like', '*' . self::likeText( $raw ) . '*' );
            case 'starts':   return $raw === '' ? false : array( $key, 'like', self::likeText( $raw ) . '*' );
            case 'eq':       return array( $key, '=', $coerce( $raw ) );
            case 'ne':       return array( $key, '!=', $coerce( $raw ) );
            case 'gt':       return array( $key, '>', $coerce( $raw ) );
            case 'lt':       return array( $key, '<', $coerce( $raw ) );
            case 'gte':      return array( $key, '>=', $coerce( $raw ) );
            case 'lte':      return array( $key, '<=', $coerce( $raw ) );
            case 'empty':    return array( $key, '=', '' );
            case 'filled':   return array( $key, '!=', '' );
            case 'like':     return $raw === '' ? false : array( $key, 'like', $raw );
            case 'not_like': return $raw === '' ? false : array( $key, 'not_like', $raw );
            case 'in': case 'not_in':
                $items = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), function ( $x ) { return $x !== ''; } ) );
                return $items ? array( $key, $op, array_map( $coerce, $items ) ) : false;
            case 'between': case 'not_between':
                return ( $raw === '' || $raw2 === '' ) ? false : array( $key, $op, array( $coerce( $raw ), $coerce( $raw2 ) ) );
        }
        return false;
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
             + count( array_filter( $v['conditions'], function ( $c ) { return $c['field'] !== ''; } ) )
             + ( $v['where_attribute'] !== '' && ( $v['where_value'] !== '' || in_array( $v['where_op'], array( 'empty', 'filled' ), true ) ) ? 1 : 0 )
             + ( $v['depth_mode'] !== 'any' ? 1 : 0 )
             + ( $v['extended_filter'] !== '' ? 1 : 0 )
             + ( $v['fetch_alias'] !== '' ? 1 : 0 );
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

    /** The registered extendedattributefilter.ini filters, minus the one used internally for the language filter. id => "id (Class::method)". */
    public static function extendedFilters()
    {
        $ini = eZINI::instance( 'extendedattributefilter.ini' );
        $result = array();
        foreach ( array_keys( $ini->groups() ) as $id )
        {
            if ( $id === 'XrowExtractTranslation' )
                continue;
            $class = $ini->hasVariable( $id, 'ClassName' ) ? $ini->variable( $id, 'ClassName' ) : '';
            $method = $ini->hasVariable( $id, 'MethodName' ) ? $ini->variable( $id, 'MethodName' ) : '';
            $result[$id] = $id . ( $class ? ' (' . $class . ( $method ? '::' . $method : '' ) . ')' : '' );
        }
        ksort( $result );
        return $result;
    }

    /** The chosen extended filter's params, decoded from the JSON typed in the view (empty when there is none). */
    public function extendedParamsArray()
    {
        if ( $this->values['extended_params'] === '' )
            return array();
        $decoded = json_decode( $this->values['extended_params'], true );
        return is_array( $decoded ) ? $decoded : array();
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

    /**
     * A primary sort and an optional secondary sort combined into one kernel SortBy: a single (field, asc)
     * pair when there is no secondary sort, or two pairs (the kernel sorts by the first, then the second)
     * when there is.
     */
    public static function combineSort( $primary, $secondary )
    {
        return $secondary === null ? $primary : array( $primary, $secondary );
    }

    /** The depth/depthOperator pair for the fetch: $defaultDepth/$defaultDepthOperator unless a depth mode is set. */
    public function depthParams( $defaultDepth, $defaultDepthOperator )
    {
        $v = $this->values;
        if ( $v['depth_mode'] === 'any' )
            return array( $defaultDepth, $defaultDepthOperator );
        return array( $v['depth_value'], self::depthOperatorFor( $v['depth_mode'] ) );
    }

    /**
     * These filters as command line options of ext:xrowextract:csv / archive (the same values, so a background
     * job exports what the view shows). $lastExport: the time "changed since my last export" starts from.
     * $withClassParts: false for the archive (no date attributes, no condition, no extended filter).
     */
    public function cliArgs( $lastExport = 0, $withClassParts = true )
    {
        $v = $this->values;
        $args = array();
        if ( $v['date_mode'] !== 'any' )
        {
            if ( $withClassParts || in_array( $v['date_field'], array( 'published', 'modified' ), true ) )
                $args[] = '--date-field=' . $v['date_field'];
            switch ( $v['date_mode'] )
            {
                case 'since':      if ( $v['date_from'] !== '' ) $args[] = '--since=' . $v['date_from']; break;
                case 'before':     if ( $v['date_to'] !== '' ) $args[] = '--before=' . $v['date_to']; break;
                case 'between':
                    if ( $v['date_from'] !== '' ) $args[] = '--since=' . $v['date_from'];
                    if ( $v['date_to'] !== '' ) $args[] = '--before=' . $v['date_to'];
                    break;
                case 'since_last': if ( $lastExport ) $args[] = '--since=' . ( (int)$lastExport + 1 ); break;
                default:           $args[] = '--date=' . $v['date_mode'];
            }
        }
        if ( $v['section'] )
            $args[] = '--section=' . $v['section'];
        if ( $v['state'] && $withClassParts )
            $args[] = '--state=' . $v['state'];
        if ( $v['visibility'] !== 'any' )
            $args[] = '--visibility=' . $v['visibility'];
        if ( $v['name'] !== '' )
            $args[] = '--name=' . $v['name'];
        if ( $withClassParts )
        {
            $conditions = array_filter( $this->allConditions(), function ( $c ) { return $c['field'] !== ''; } );
            if ( $conditions )
            {
                $glue = $v['conditions_join'] === 'or' ? ' || ' : ' && ';
                $pieces = array();
                foreach ( $conditions as $c )
                {
                    $value = $c['value'];
                    if ( in_array( $c['op'], self::twoValueOperators(), true ) )
                        $value = $c['value'] . '..' . $c['value2'];
                    $pieces[] = trim( $c['field'] . ' ' . ( !empty( $c['negate'] ) ? 'not ' : '' ) . $c['op'] . ' ' . $value );
                }
                $args[] = '--where=' . implode( $glue, $pieces );
            }
            if ( $v['depth_mode'] !== 'any' )
            {
                $args[] = '--depth=' . $v['depth_value'];
                $args[] = '--depth-operator=' . self::depthOperatorFor( $v['depth_mode'] );
            }
            if ( $v['extended_filter'] !== '' )
            {
                $args[] = '--extended-filter=' . $v['extended_filter'];
                if ( $v['extended_params'] !== '' )
                    $args[] = '--extended-params=' . $v['extended_params'];
            }
        }
        return $args;
    }

    /** The preference that holds the time of the user's last export of a class. */
    public static function lastExportPreference( $classID )
    {
        return 'admin_xrowextract_last_export_' . (int)$classID;
    }

    /**
     * The resolved fetch parameters as the literal `fetch( 'content', 'tree', hash( ... ) )` a template
     * author would write, so the "Fetch parameters" box can be copied straight into a .tpl. $sortBy: the
     * kernel SortBy already resolved by sortParam()/combineSort(), or null for the node's own order.
     */
    public static function fetchLiteral( $parentNodeID, $classID, $depth, $depthOperator, $mainNodeOnly, $attributeFilter, $extendedAttributeFilter, $sortBy = null )
    {
        $pairs = array( 'parent_node_id' => (int)$parentNodeID, 'class_id' => (int)$classID );
        if ( $sortBy !== null )
            $pairs['sort_by'] = $sortBy;
        if ( $depth !== false && $depth !== null )
        {
            $pairs['depth'] = $depth;
            $pairs['depth_operator'] = $depthOperator;
        }
        if ( $attributeFilter !== false )
            $pairs['attribute_filter'] = $attributeFilter;
        if ( $extendedAttributeFilter )
            $pairs['extended_attribute_filter'] = $extendedAttributeFilter;
        $pairs['main_node_only'] = (bool)$mainNodeOnly;
        return "fetch( 'content', 'tree', " . self::hashLiteral( $pairs ) . " )";
    }

    /** array('k'=>v, ...) as the eZ TPL hash(...) literal a template would use. */
    public static function hashLiteral( array $pairs )
    {
        $parts = array();
        foreach ( $pairs as $key => $value )
            $parts[] = "'" . $key . "', " . self::phpLiteral( $value );
        return 'hash( ' . implode( ', ', $parts ) . ' )';
    }

    /** A PHP array()/scalar literal for a value, for hashLiteral() and CLI/debug output. */
    protected static function phpLiteral( $value )
    {
        if ( is_array( $value ) )
        {
            $isList = array_keys( $value ) === range( 0, count( $value ) - 1 );
            $items = array();
            foreach ( $value as $k => $v )
                $items[] = $isList ? self::phpLiteral( $v ) : var_export( (string)$k, true ) . ' => ' . self::phpLiteral( $v );
            return 'array( ' . implode( ', ', $items ) . ' )';
        }
        if ( is_bool( $value ) )
            return $value ? 'true' : 'false';
        if ( is_int( $value ) || is_float( $value ) )
            return (string)$value;
        return var_export( (string)$value, true );
    }
}

?>
