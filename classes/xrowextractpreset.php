<?php

/**
 * A saved export preset: a complete, named CSV or archive export definition (scope, node(s), class or
 * sets, columns, languages, every filter, sort, output format) that can be loaded back into the view,
 * run directly (in the foreground or the background), or applied from the command line.
 *
 * Two layers, as fetchalias.ini and a user's own saved searches already work elsewhere in Exponential:
 *  - user presets: created and edited from the view, stored one eZSiteData row per preset (name
 *    "xrowextract_preset_<20 hex>", value the JSON record below). A small key/value table already shipped
 *    with the kernel is enough for this — it needs no schema of its own, and stays lazy: nothing is
 *    written until the first preset is saved.
 *  - site presets: `[Preset_<id>]` blocks in xrowextract.ini (Name, Description, optional Extends, and
 *    Definition as one JSON value) — admin authored, read only from the view, shipped with an extension.
 *
 * A preset can `Extends` another preset ("user:<id>" / "site:<id>") or a fetchalias.ini named fetch
 * ("alias:<name>" / "alias:<name>:<siteaccess>"), overriding single keys of what it extends; the chain is
 * resolved (and placeholders substituted) only when a preset is loaded or run, never when it is saved, so
 * editing what a preset extends changes every preset built on it.
 *
 * A definition may hold `{name}` placeholders in any string value; `Placeholders` (name => default)
 * declares them, and apply()'s $params fill them in — the same shape as XrowExtractFetchAlias's own
 * Parameter[] values, so a preset and a named fetch feel like one system.
 */
class XrowExtractPreset
{
    const NAME_PREFIX = 'xrowextract_preset_';
    const MAX_EXTENDS_DEPTH = 5;

    /** A fresh preset id (the eZSiteData row name, without the prefix). */
    public static function newID()
    {
        return bin2hex( random_bytes( 10 ) );
    }

    public static function isValidUserID( $id )
    {
        return is_string( $id ) && preg_match( '/^[0-9a-f]{20}$/', $id ) === 1;
    }

    /** One user preset's eZSiteData row name. */
    protected static function siteDataName( $id )
    {
        return self::NAME_PREFIX . $id;
    }

    /** array('id'=>..., 'ref'=>'user:<id>', ...record...) or false. */
    public static function fetchUser( $id )
    {
        if ( !self::isValidUserID( $id ) )
            return false;
        $row = eZSiteData::fetchByName( self::siteDataName( $id ) );
        if ( !$row instanceof eZSiteData )
            return false;
        $record = json_decode( $row->attribute( 'value' ), true );
        if ( !is_array( $record ) )
            return false;
        $record['id'] = $id;
        $record['ref'] = 'user:' . $id;
        $record['site'] = false;
        $record['audience'] = 'Personal';
        return $record;
    }

    /** Every user preset (both layers use the same record shape); newest first. */
    public static function fetchUserList()
    {
        $rows = eZPersistentObject::fetchObjectList( eZSiteData::definition(), null,
            array( 'name' => array( 'like', self::NAME_PREFIX . '%' ) ) );
        $presets = array();
        foreach ( $rows as $row )
        {
            $id = substr( $row->attribute( 'name' ), strlen( self::NAME_PREFIX ) );
            $record = self::fetchUser( $id );
            if ( $record )
                $presets[] = $record;
        }
        usort( $presets, function ( $a, $b ) { return (int)$b['modified'] - (int)$a['modified']; } );
        return $presets;
    }

    /**
     * Save (id given: update; else: create) a user preset. $data: name, description, view (csv|archive),
     * shared (bool), extends ('' or a ref), placeholders (array), definition (array). Returns the id.
     */
    public static function saveUser( $ownerLogin, array $data, $id = false )
    {
        $now = time();
        $existing = $id ? self::fetchUser( $id ) : false;
        $id = $existing ? $id : self::newID();
        $record = array(
            'name' => mb_substr( trim( (string)$data['name'] ), 0, 150 ),
            'description' => mb_substr( trim( (string)( isset( $data['description'] ) ? $data['description'] : '' ) ), 0, 1000 ),
            'view' => in_array( $data['view'], array( 'csv', 'archive' ), true ) ? $data['view'] : 'csv',
            'owner_login' => $existing ? $existing['owner_login'] : $ownerLogin,
            'shared' => !empty( $data['shared'] ),
            'extends' => isset( $data['extends'] ) ? (string)$data['extends'] : '',
            'placeholders' => isset( $data['placeholders'] ) && is_array( $data['placeholders'] ) ? $data['placeholders'] : array(),
            'definition' => isset( $data['definition'] ) && is_array( $data['definition'] ) ? $data['definition'] : array(),
            'created' => $existing ? $existing['created'] : $now,
            'modified' => $now,
        );
        $json = json_encode( $record );
        $name = self::siteDataName( $id );
        $row = eZSiteData::fetchByName( $name );
        if ( $row instanceof eZSiteData )
        {
            $row->setAttribute( 'value', $json );
            $row->store();
        }
        else
        {
            eZSiteData::create( $name, $json )->store();
        }
        return $id;
    }

    public static function deleteUser( $id )
    {
        if ( !self::isValidUserID( $id ) )
            return false;
        $row = eZSiteData::fetchByName( self::siteDataName( $id ) );
        if ( !$row instanceof eZSiteData )
            return false;
        $row->remove();
        return true;
    }

    /** Every `[Preset_*]` block of xrowextract.ini (this siteaccess's merged copy, extensions included). */
    public static function fetchSiteList()
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        $presets = array();
        foreach ( $ini->groups() as $group => $vars )
        {
            if ( strpos( $group, 'Preset_' ) !== 0 )
                continue;
            $id = substr( $group, strlen( 'Preset_' ) );
            $record = self::siteRecordFromVars( $id, $vars );
            if ( $record )
                $presets[] = $record;
        }
        usort( $presets, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        return $presets;
    }

    public static function fetchSite( $id )
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        $group = 'Preset_' . $id;
        if ( !$ini->hasGroup( $group ) )
            return false;
        return self::siteRecordFromVars( $id, $ini->group( $group ) );
    }

    protected static function siteRecordFromVars( $id, array $vars )
    {
        $definition = array();
        if ( isset( $vars['Definition'] ) )
        {
            $decoded = json_decode( $vars['Definition'], true );
            if ( is_array( $decoded ) )
                $definition = $decoded;
        }
        $placeholders = array();
        if ( isset( $vars['Placeholders'] ) )
        {
            $decoded = json_decode( $vars['Placeholders'], true );
            if ( is_array( $decoded ) )
                $placeholders = $decoded;
        }
        // Site presets are shipped text (xrowextract.ini), so their Name/Description are run through the
        // same translation context as the rest of the view; a string with no matching translation.ts entry
        // simply falls back to the ini's own (English) text, exactly as ezpI18n::tr always does.
        $rawName = isset( $vars['Name'] ) ? $vars['Name'] : $id;
        $rawDescription = isset( $vars['Description'] ) ? $vars['Description'] : '';
        return array(
            'id' => $id,
            'ref' => 'site:' . $id,
            'site' => true,
            'name' => $rawName !== '' ? ezpI18n::tr( 'design/standard/extract', $rawName ) : $rawName,
            'description' => $rawDescription !== '' ? ezpI18n::tr( 'design/standard/extract', $rawDescription ) : $rawDescription,
            'audience' => isset( $vars['Audience'] ) && $vars['Audience'] !== '' ? $vars['Audience'] : 'Site',
            'view' => isset( $vars['View'] ) && in_array( $vars['View'], array( 'csv', 'archive' ), true ) ? $vars['View'] : 'csv',
            'owner_login' => '',
            'shared' => true,
            'extends' => isset( $vars['Extends'] ) ? $vars['Extends'] : '',
            'placeholders' => $placeholders,
            'definition' => $definition,
            'created' => 0,
            'modified' => 0,
        );
    }

    /** The fixed display order of site-preset audience groups; anything else sorts after, alphabetically. */
    public static function audienceOrder()
    {
        return array( 'Site', 'Editors', 'Developers', 'Partners', 'Users', 'Maintenance' );
    }

    /** Site presets only, grouped and ordered by Audience: array( audience => array(preset, ...) ). */
    public static function fetchSiteListByAudience()
    {
        $groups = array();
        foreach ( self::fetchSiteList() as $preset )
            $groups[$preset['audience']][] = $preset;
        $order = self::audienceOrder();
        uksort( $groups, function ( $a, $b ) use ( $order )
        {
            $ia = array_search( $a, $order, true );
            $ib = array_search( $b, $order, true );
            if ( $ia === false ) $ia = count( $order ) + strcmp( $a, '' );
            if ( $ib === false ) $ib = count( $order ) + strcmp( $b, '' );
            if ( $ia === $ib )
                return strcasecmp( $a, $b );
            return $ia - $ib;
        } );
        return $groups;
    }

    /** A preset by its ref ("user:<id>" or "site:<id>"), or false. */
    public static function fetch( $ref )
    {
        if ( strpos( $ref, 'user:' ) === 0 )
            return self::fetchUser( substr( $ref, 5 ) );
        if ( strpos( $ref, 'site:' ) === 0 )
            return self::fetchSite( substr( $ref, 5 ) );
        return false;
    }

    /** A preset's name by its ref, for the Jobs page (a deleted preset still shows its ref). */
    public static function presetName( $ref )
    {
        $preset = self::fetch( $ref );
        return $preset ? $preset['name'] : $ref;
    }

    /** Every preset the current user may see: their own, every shared one, and the site's. */
    public static function fetchVisible( $login, $allowAll )
    {
        $visible = array();
        foreach ( self::fetchUserList() as $preset )
        {
            if ( $allowAll || $preset['owner_login'] === $login || $preset['shared'] )
                $visible[] = $preset;
        }
        foreach ( self::fetchSiteList() as $preset )
            $visible[] = $preset;
        return $visible;
    }

    /** Whether $login may change or delete this preset. */
    public static function canEdit( array $preset, $login, $allowAll )
    {
        if ( $preset['site'] )
            return false; // site presets are read only from the view; edit xrowextract.ini instead
        return $allowAll || $preset['owner_login'] === $login;
    }

    /**
     * The preset's definition/placeholders resolved through its Extends chain (a preset, or a fetchalias
     * named fetch converted the same way XrowExtractFetchAlias::apply() reads one into view values):
     * array( 'definition' => merged array, 'placeholders' => merged array, 'chain' => array of refs,
     * 'error' => '' or why it could not resolve ). $paramOverrides: the same key=value the caller will
     * also use for the preset's own {placeholder} substitution, forwarded to XrowExtractFetchAlias::apply()
     * when the chain ends in "alias:..." — so a param such as "limit" fills the alias's own
     * Parameter[limit] exactly as choosing that named fetch directly would.
     */
    public static function resolve( $ref, $nodeIDForAlias = 0, array $paramOverrides = array() )
    {
        $chain = array();
        $definition = array();
        $placeholders = array();
        $current = $ref;
        for ( $depth = 0; $current !== '' && $depth < self::MAX_EXTENDS_DEPTH; $depth++ )
        {
            if ( in_array( $current, $chain, true ) )
                return array( 'definition' => array(), 'placeholders' => array(), 'chain' => $chain, 'error' => "Extends cycle at $current" );
            $chain[] = $current;
            if ( strpos( $current, 'alias:' ) === 0 )
            {
                $aliasSpec = substr( $current, 6 );
                $parts = explode( ':', $aliasSpec, 2 );
                $aliasDefinition = XrowExtractFetchAlias::find( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
                if ( !$aliasDefinition )
                    return array( 'definition' => $definition, 'placeholders' => $placeholders, 'chain' => $chain, 'error' => "named fetch not found: $aliasSpec" );
                // A preset names its start node "node" (the word its users see); a content fetch alias
                // calls the same thing Parameter[parent_node_id]. One fills the other unless both are given.
                if ( isset( $paramOverrides['node'] ) && trim( (string)$paramOverrides['node'] ) !== ''
                     && ( !isset( $paramOverrides['parent_node_id'] ) || trim( (string)$paramOverrides['parent_node_id'] ) === '' ) )
                {
                    $paramOverrides['parent_node_id'] = $paramOverrides['node'];
                }
                $applied = XrowExtractFetchAlias::apply( $aliasDefinition, $nodeIDForAlias, $paramOverrides );
                $layer = self::definitionFromAliasValues( $applied['values'] );
                $definition = array_replace_recursive( $layer, $definition );
                break; // a named fetch is always a leaf: it has no Extends of its own
            }
            $preset = self::fetch( $current );
            if ( !$preset )
                return array( 'definition' => $definition, 'placeholders' => $placeholders, 'chain' => $chain, 'error' => "preset not found: $current" );
            $definition = array_replace_recursive( $preset['definition'], $definition );
            $placeholders = array_replace( $preset['placeholders'], $placeholders );
            $current = $preset['extends'];
        }
        return array( 'definition' => $definition, 'placeholders' => $placeholders, 'chain' => $chain, 'error' => '' );
    }

    /** XrowExtractFetchAlias::apply()'s 'values' as a preset definition fragment. */
    protected static function definitionFromAliasValues( array $values )
    {
        $definition = array();
        if ( isset( $values['parent_node_id'] ) )
            $definition['subtree'] = $values['parent_node_id'];
        if ( isset( $values['class_id'] ) )
            $definition['class_id'] = $values['class_id'];
        if ( isset( $values['sort_by'] ) )
        {
            $pair = isset( $values['sort_by'][0] ) && is_array( $values['sort_by'][0] ) ? $values['sort_by'][0] : $values['sort_by'];
            $definition['sort_field'] = isset( $pair[0] ) ? $pair[0] : 'tree';
            $definition['sort_ascending'] = isset( $pair[1] ) ? (bool)$pair[1] : true;
        }
        if ( isset( $values['depth'] ) )
        {
            $map = array( 'eq' => 'exact', 'le' => 'atmost', 'ge' => 'atleast' );
            $operator = isset( $values['depth_operator'] ) ? $values['depth_operator'] : 'le';
            $definition['filters']['depth_mode'] = isset( $map[$operator] ) ? $map[$operator] : 'any';
            $definition['filters']['depth_value'] = $values['depth'];
        }
        if ( isset( $values['limit'] ) )
            $definition['limit'] = $values['limit'];
        if ( isset( $values['offset'] ) )
            $definition['offset'] = $values['offset'];
        if ( isset( $values['main_node_only'] ) )
            $definition['mainnodeonly'] = $values['main_node_only'] ? '1' : '0';
        if ( isset( $values['condition'] ) )
            $definition['filters']['conditions'] = array( array_merge( array( 'value2' => '', 'negate' => false ), $values['condition'] ) );
        return $definition;
    }

    /**
     * Substitutes {name} placeholders in every string leaf of $definition. $params: name => value
     * (overrides $placeholders' defaults). Returns array( 'definition' => ..., 'unresolved' => array of
     * placeholder names left with no value — an empty string is written in their place, which almost
     * always resolves to "matches nothing" rather than silently exporting the wrong rows ).
     */
    public static function fillPlaceholders( $definition, array $placeholders, array $params )
    {
        $unresolved = array();
        $values = array();
        foreach ( $placeholders as $name => $spec )
            $values[$name] = isset( $spec['default'] ) ? $spec['default'] : '';
        foreach ( $params as $name => $value )
            $values[$name] = $value;
        $fill = function ( $node ) use ( &$fill, &$unresolved, $values )
        {
            if ( is_array( $node ) )
            {
                foreach ( $node as $key => $value )
                    $node[$key] = $fill( $value );
                return $node;
            }
            if ( !is_string( $node ) || strpos( $node, '{' ) === false )
                return $node;
            return preg_replace_callback( '/\{([A-Za-z0-9_]+)\}/', function ( $m ) use ( &$unresolved, $values )
            {
                if ( array_key_exists( $m[1], $values ) )
                    return $values[$m[1]];
                $unresolved[] = $m[1];
                return '';
            }, $node );
        };
        return array( 'definition' => $fill( $definition ), 'unresolved' => array_values( array_unique( $unresolved ) ) );
    }

    /**
     * A short, one-line "what this sets" summary for the picker — from the preset's own definition only
     * (never resolves an Extends chain, so listing many presets stays cheap).
     */
    public static function summaryLine( array $preset )
    {
        $def = $preset['definition'];
        $parts = array();
        if ( $preset['extends'] !== '' )
            $parts[] = 'extends ' . $preset['extends'];
        if ( !empty( $def['class_identifier'] ) )
            $parts[] = 'class ' . $def['class_identifier'];
        elseif ( isset( $def['class_id'] ) )
            $parts[] = 'class #' . $def['class_id'];
        if ( isset( $def['subtree'] ) )
            $parts[] = 'node ' . $def['subtree'];
        if ( isset( $def['sort_field'] ) && $def['sort_field'] !== 'tree' )
            $parts[] = 'sorted by ' . $def['sort_field'];
        if ( !empty( $def['limit'] ) )
            $parts[] = 'limit ' . $def['limit'];
        if ( isset( $def['filters']['visibility'] ) && $def['filters']['visibility'] !== 'any' )
            $parts[] = $def['filters']['visibility'];
        if ( !empty( $def['filters']['conditions'] ) && is_array( $def['filters']['conditions'] ) )
            $parts[] = count( $def['filters']['conditions'] ) . ' condition(s)';
        if ( isset( $def['output_format'] ) && $def['output_format'] !== 'csv' )
            $parts[] = strtoupper( $def['output_format'] );
        return $parts ? implode( ', ', $parts ) : 'no settings of its own';
    }

    /** The preset as a `[Preset_<id>]` xrowextract.ini block, to copy into settings. */
    public static function toIniBlock( array $preset )
    {
        $lines = array( '[Preset_' . ( $preset['site'] ? $preset['id'] : $preset['id'] ) . ']' );
        $lines[] = 'Name=' . str_replace( "\n", ' ', $preset['name'] );
        if ( $preset['description'] !== '' )
            $lines[] = 'Description=' . str_replace( "\n", ' ', $preset['description'] );
        if ( !empty( $preset['audience'] ) && $preset['audience'] !== 'Site' )
            $lines[] = 'Audience=' . $preset['audience'];
        $lines[] = 'View=' . $preset['view'];
        if ( $preset['extends'] !== '' )
            $lines[] = 'Extends=' . $preset['extends'];
        $lines[] = 'Definition=' . json_encode( $preset['definition'] );
        if ( $preset['placeholders'] )
            $lines[] = 'Placeholders=' . json_encode( $preset['placeholders'] );
        return implode( "\n", $lines );
    }
}

?>
