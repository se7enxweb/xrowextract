<?php

/**
 * Named fetches from fetchalias.ini usable in the extract view and the CLI: a "tree" / "list" /
 * "tree_count" / "list_count" alias's Constant values read back into the view's own settings (the node,
 * the class, the sort, the depth, the limit/offset, main locations only, and — where the value is a
 * simple "field;op;value" — one condition), so the export a person builds by hand and one described in
 * fetchalias.ini agree on what "the same fetch" means.
 *
 * fetch_alias itself (eZFunctionHandler::executeAlias()) always calls the *current* siteaccess's
 * fetchalias.ini; this class also reads a named siteaccess's copy (eZSiteAccess::getIni()) so the picker
 * can offer the default siteaccess's aliases too, labelled, the way {fetch_alias ...} cannot from a
 * template.
 */
class XrowExtractFetchAlias
{
    /** FunctionName values this view can use: a content tree or list fetch, or its count. */
    const FUNCTIONS = array( 'tree', 'list', 'tree_count', 'list_count' );

    /** Constant[] keys that are split on ';' by eZFunctionHandler::executeAlias() (array parameters). */
    const ARRAY_KEYS = array( 'sort_by', 'class_filter_array', 'group_by', 'limitation' );

    /** Kernel comparison operators (Constant[attribute_filter]) mapped to our own condition-row op keys. */
    protected static $opMap = array( '=' => 'eq', '!=' => 'ne', '>' => 'gt', '<' => 'lt', '>=' => 'gte', '<=' => 'lte',
                                     'like' => 'like', 'not_like' => 'not_like', 'in' => 'in', 'not_in' => 'not_in',
                                     'between' => 'between', 'not_between' => 'not_between' );

    /** The Module=content, tree/list/tree_count/list_count aliases of one fetchalias.ini: name => definition. */
    protected static function readAliases( eZINI $ini )
    {
        $out = array();
        foreach ( $ini->groups() as $name => $vars )
        {
            $module = isset( $vars['Module'] ) ? $vars['Module'] : '';
            $function = isset( $vars['FunctionName'] ) ? $vars['FunctionName'] : '';
            if ( $module !== 'content' || !in_array( $function, self::FUNCTIONS, true ) )
                continue;
            $constant = isset( $vars['Constant'] ) && is_array( $vars['Constant'] ) ? $vars['Constant'] : array();
            $parameter = isset( $vars['Parameter'] ) && is_array( $vars['Parameter'] ) ? $vars['Parameter'] : array();
            $out[$name] = array( 'name' => $name, 'function' => $function, 'constant' => $constant, 'parameter' => $parameter,
                                 'summary' => self::summarize( $function, $constant, $parameter ) );
        }
        ksort( $out );
        return $out;
    }

    protected static function summarize( $function, array $constant, array $parameter )
    {
        $parts = array( $function );
        foreach ( $constant as $key => $value )
            $parts[] = $key . '=' . ( is_array( $value ) ? implode( ';', $value ) : $value );
        foreach ( $parameter as $key => $value )
            $parts[] = $key . '=<' . $value . '>';
        return implode( ', ', $parts );
    }

    /**
     * The picker's choices: one group for the current siteaccess, one for the default siteaccess (only
     * when it differs), each labelled and holding its own usable aliases.
     */
    public static function choices()
    {
        $siteINI = eZINI::instance();
        $currentName = false;
        $access = eZSiteAccess::current();
        if ( is_array( $access ) && !empty( $access['name'] ) )
            $currentName = $access['name'];
        $defaultName = $siteINI->variable( 'SiteSettings', 'DefaultAccess' );

        $groups = array();
        $groups[] = array( 'siteaccess' => (string)$currentName, 'default' => false,
                           'label' => $currentName ? ( 'This siteaccess (' . $currentName . ')' ) : 'This siteaccess',
                           'aliases' => array_values( self::readAliases( eZINI::instance( 'fetchalias.ini' ) ) ) );
        if ( $defaultName && $defaultName !== $currentName )
        {
            $groups[] = array( 'siteaccess' => (string)$defaultName, 'default' => true,
                               'label' => 'Default siteaccess (' . $defaultName . ')',
                               'aliases' => array_values( self::readAliases( eZSiteAccess::getIni( $defaultName, 'fetchalias.ini' ) ) ) );
        }
        return $groups;
    }

    /** One alias's definition by name, from a siteaccess's fetchalias.ini ('' or false: the current one). */
    public static function find( $name, $siteaccess = '' )
    {
        $ini = $siteaccess ? eZSiteAccess::getIni( $siteaccess, 'fetchalias.ini' ) : eZINI::instance( 'fetchalias.ini' );
        $aliases = self::readAliases( $ini );
        return isset( $aliases[$name] ) ? $aliases[$name] : false;
    }

    /** The alias's own Parameter[] keys the caller may fill in (besides parent_node_id, filled from the view). */
    public static function fillableParameters( array $alias )
    {
        return array_values( array_diff( array_keys( $alias['parameter'] ), array( 'parent_node_id' ) ) );
    }

    /** A Constant value split the way eZFunctionHandler::executeAlias() splits an array parameter (';', \; escaped). */
    protected static function splitConstant( $value )
    {
        $parts = preg_split( '/((?<=\\\\\\\\)|(?<!\\\\));/', (string)$value );
        if ( !is_array( $parts ) ) // a PCRE failure (backtrack limit)
            return array();
        $parts = array_values( array_diff( $parts, array( '' ) ) );
        return array_map( function ( $part ) { return str_replace( '\\;', ';', $part ); }, $parts );
    }

    protected static function truthy( $value )
    {
        return in_array( strtolower( trim( (string)$value ) ), array( '1', 'true', 'yes', 'on' ), true );
    }

    /** A Constant[attribute_filter] of the simple form "field;op;value" (one condition, no and/or), or false. */
    protected static function parseSimpleAttributeFilter( $value )
    {
        $parts = self::splitConstant( $value );
        if ( count( $parts ) !== 3 || $parts[0] === '' || $parts[1] === '' )
            return false;
        $op = isset( self::$opMap[$parts[1]] ) ? self::$opMap[$parts[1]] : $parts[1];
        if ( !array_key_exists( $op, XrowExtractFilters::conditionOperators() ) )
            return false;
        return array( 'field' => $parts[0], 'op' => $op, 'value' => $parts[2] );
    }

    /**
     * Apply an alias's Constant values to a view: what they mean for the node, the class, the sort, the
     * depth, the limit/offset, main-locations-only and a condition. $nodeID: the node currently chosen in
     * the view, used when the alias takes parent_node_id as a Parameter (from the caller) rather than a
     * Constant (fixed by the alias itself).
     *
     * Returns array( 'applied' => array of short descriptions, 'unknown' => array of short descriptions of
     * what was not understood, 'values' => array of resolved view values: parent_node_id, class_id,
     * class_filter_type, class_filter_array, sort_by (one or two (field,asc) pairs), depth, depth_operator,
     * limit, offset, main_node_only, condition ).
     *
     * $paramOverrides: values for the alias's own Parameter[] entries, keyed by the function parameter
     * name exactly as fetchalias.ini's Parameter[<key>] names it — e.g. a fetchalias.ini alias with
     * `Parameter[limit]=limit` accepts $paramOverrides['limit']. Unknown keys, or ones the alias does not
     * declare as a Parameter, are ignored (an alias only exposes what it itself declares as fillable).
     * Parameter[parent_node_id] falls back to $nodeID (the node currently chosen in the view / --node)
     * when $paramOverrides carries no override for it.
     */
    public static function apply( array $alias, $nodeID, array $paramOverrides = array() )
    {
        $applied = array();
        $unknown = array();
        $values = array();
        $constants = $alias['constant'];
        foreach ( array_keys( $alias['parameter'] ) as $parameterKey )
        {
            if ( isset( $paramOverrides[$parameterKey] ) && $paramOverrides[$parameterKey] !== '' )
                $constants[$parameterKey] = $paramOverrides[$parameterKey];
        }

        if ( array_key_exists( 'parent_node_id', $constants ) )
        {
            $values['parent_node_id'] = (int)$constants['parent_node_id'];
            $applied[] = 'node ' . $values['parent_node_id'];
        }
        elseif ( isset( $alias['parameter']['parent_node_id'] ) )
        {
            $values['parent_node_id'] = (int)$nodeID;
            $applied[] = 'node (the one currently chosen, ' . (int)$nodeID . ')';
        }

        if ( array_key_exists( 'class_id', $constants ) && !is_array( $constants['class_id'] ) )
        {
            $values['class_id'] = (int)$constants['class_id'];
            $applied[] = 'class id ' . $values['class_id'];
        }
        elseif ( array_key_exists( 'class_filter_array', $constants ) )
        {
            $classes = self::splitConstant( $constants['class_filter_array'] );
            if ( $classes )
            {
                $values['class_filter_array'] = $classes;
                $values['class_filter_type'] = array_key_exists( 'class_filter_type', $constants ) ? $constants['class_filter_type'] : 'include';
                $applied[] = $values['class_filter_type'] . ' classes ' . implode( ', ', $classes )
                           . ( count( $classes ) > 1 ? ' (the export shows one class: the first is used)' : '' );
            }
        }
        elseif ( array_key_exists( 'class_filter_type', $constants ) )
        {
            $unknown[] = 'class_filter_type without class_filter_array';
        }

        if ( array_key_exists( 'sort_by', $constants ) )
        {
            $sort = self::splitConstant( $constants['sort_by'] );
            $pairs = array();
            for ( $i = 0; $i + 1 < count( $sort ); $i += 2 )
                $pairs[] = array( $sort[$i], self::truthy( $sort[$i + 1] ) || $sort[$i + 1] === '1' );
            if ( $pairs )
            {
                $values['sort_by'] = count( $pairs ) === 1 ? $pairs[0] : $pairs;
                $applied[] = 'sort ' . implode( ', then ', array_map( function ( $p ) { return $p[0] . ' ' . ( $p[1] ? 'ascending' : 'descending' ); }, $pairs ) );
            }
            else
                $unknown[] = 'sort_by=' . $constants['sort_by'];
        }

        if ( array_key_exists( 'depth', $constants ) && !is_array( $constants['depth'] ) )
        {
            $values['depth'] = (int)$constants['depth'];
            $depthOperator = array_key_exists( 'depth_operator', $constants ) ? $constants['depth_operator'] : 'le';
            $values['depth_operator'] = in_array( $depthOperator, array( 'eq', 'le', 'ge', 'lt', 'gt' ), true ) ? $depthOperator : 'le';
            $applied[] = 'depth ' . $values['depth_operator'] . ' ' . $values['depth'];
        }

        if ( array_key_exists( 'limit', $constants ) && !is_array( $constants['limit'] ) )
        {
            $values['limit'] = max( 0, (int)$constants['limit'] );
            $applied[] = 'limit ' . $values['limit'];
        }
        if ( array_key_exists( 'offset', $constants ) && !is_array( $constants['offset'] ) )
        {
            $values['offset'] = max( 0, (int)$constants['offset'] );
            $applied[] = 'offset ' . $values['offset'];
        }
        if ( array_key_exists( 'main_node_only', $constants ) )
        {
            $values['main_node_only'] = self::truthy( $constants['main_node_only'] );
            $applied[] = 'main locations only: ' . ( $values['main_node_only'] ? 'yes' : 'no' );
        }
        if ( array_key_exists( 'ignore_visibility', $constants ) && self::truthy( $constants['ignore_visibility'] ) )
        {
            $unknown[] = 'ignore_visibility=1 (the export always applies your read access; it never ignores visibility)';
        }
        if ( array_key_exists( 'attribute_filter', $constants ) )
        {
            $parsed = self::parseSimpleAttributeFilter( $constants['attribute_filter'] );
            if ( $parsed )
            {
                $values['condition'] = $parsed;
                $applied[] = 'condition ' . $parsed['field'] . ' ' . $parsed['op'] . ' ' . $parsed['value'];
            }
            else
            {
                $raw = is_array( $constants['attribute_filter'] ) ? implode( ';', $constants['attribute_filter'] ) : $constants['attribute_filter'];
                $unknown[] = 'attribute_filter=' . $raw . ' (more than one condition, or and/or joins, cannot be read back into a condition row)';
            }
        }

        $understood = array( 'parent_node_id', 'class_id', 'class_filter_array', 'class_filter_type', 'sort_by',
                             'depth', 'depth_operator', 'limit', 'offset', 'main_node_only', 'ignore_visibility', 'attribute_filter' );
        foreach ( $constants as $key => $value )
        {
            if ( !in_array( $key, $understood, true ) )
                $unknown[] = $key . '=' . ( is_array( $value ) ? implode( ';', $value ) : $value );
        }

        return array( 'applied' => $applied, 'unknown' => $unknown, 'values' => $values );
    }
}

?>
