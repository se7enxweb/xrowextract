<?php

/**
 * The columns of an export and their values: the special (non class
 * attribute) columns, a class's attribute columns, and one object as a row
 * of CSV cells. Used by the single class CSV view and the site archive.
 */
class XrowExtractColumns
{
    /** The special columns. Only these exist; each value is computed in extraValue(). */
    public static function extraAttributes( $allowPasswordHash = null )
    {
        if ( $allowPasswordHash === null )
            $allowPasswordHash = self::allowPasswordHash();
        $list = array(
            'ezcontentobject.id'                  => array( 'exportname' => 'object_id',           'name' => 'Object ID' ),
            'ezcontentobject.remote_id'           => array( 'exportname' => 'remote_id',           'name' => 'Remote ID' ),
            'ezcontentobject.name'                => array( 'exportname' => 'object_name',         'name' => 'Object Name' ),
            'ezcontentobject.class_identifier'    => array( 'exportname' => 'class',               'name' => 'Class Identifier' ),
            'ezcontentobject.section'             => array( 'exportname' => 'section',             'name' => 'Section' ),
            'ezcontentobject.owner'               => array( 'exportname' => 'owner',               'name' => 'Owner Name' ),
            'ezuser.login'                        => array( 'exportname' => 'login',               'name' => 'Login' ),
            'ezuser.email'                        => array( 'exportname' => 'email',               'name' => 'E-Mail' ),
            'ezuser.password_hash'                => array( 'exportname' => 'password',            'name' => 'Password' ),
            'ezuser.is_enabled'                   => array( 'exportname' => 'user_status',         'name' => 'User Status' ),
            'ezcontentobject.published'           => array( 'exportname' => 'published',           'name' => 'Content Object Published Time' ),
            'ezcontentobject.modified'            => array( 'exportname' => 'modified',            'name' => 'Content Object Modified Time' ),
            'ezcontentobject.url_alias'           => array( 'exportname' => 'url_alias',           'name' => 'URL Alias' ),
            'ezcontentobject.full_url_alias'      => array( 'exportname' => 'full_url_alias',      'name' => 'Absolute URL Alias' ),
            'ezcontentobject.main_parent_name'    => array( 'exportname' => 'parent_name',         'name' => 'Content Object Main Parent Name' ),
            'ezcontentobject.main_node_id'        => array( 'exportname' => 'main_node_id',        'name' => 'Main Node ID' ),
            'ezcontentobject.main_parent_node_id' => array( 'exportname' => 'main_parent_node_id', 'name' => 'Main Parent Node ID' ),
            'ezcontentobject.parent_nodes'        => array( 'exportname' => 'parent_nodes',        'name' => 'Content Object Parent Names' ),
        );
        if ( !$allowPasswordHash )
            unset( $list['ezuser.password_hash'] );
        foreach ( $list as $id => $column )
            $list[$id]['id'] = $id;
        return $list;
    }

    /** The columns that identify an object in an archive, before its attributes. */
    public static function identityColumns()
    {
        $extras = self::extraAttributes( false );
        $columns = array();
        foreach ( array( 'ezcontentobject.id', 'ezcontentobject.remote_id', 'ezcontentobject.main_node_id',
                         'ezcontentobject.main_parent_node_id', 'ezcontentobject.url_alias',
                         'ezcontentobject.published', 'ezcontentobject.modified' ) as $id )
            $columns[] = $extras[$id];
        return $columns;
    }

    /** Every attribute of a class as a column, in class order, named by its identifier. */
    public static function classColumns( $classID )
    {
        $columns = array();
        foreach ( eZContentClassAttribute::fetchListByClassID( (int)$classID, eZContentClass::VERSION_STATUS_DEFINED, true ) as $classattribute )
        {
            $columns[] = array(
                'id' => $classattribute->attribute( 'identifier' ),
                'name' => $classattribute->attribute( 'name' ),
                'exportname' => $classattribute->attribute( 'identifier' ),
            );
        }
        return $columns;
    }

    public static function allowPasswordHash()
    {
        $csvINI = eZINI::instance( 'csv.ini' );
        return $csvINI->hasVariable( 'General', 'AllowPasswordHashExport' )
               && $csvINI->variable( 'General', 'AllowPasswordHashExport' ) === 'enabled';
    }

    /** The header cells of a column list. */
    public static function headerCells( array $columns, ParserInterface $parser )
    {
        $cells = array();
        foreach ( $columns as $column )
            $cells[] = $parser->escape( str_replace( '_', '-', $column['exportname'] ) );
        return $cells;
    }

    /** One object as CSV cells, in the order of $columns. */
    public static function rowCells( array $columns, eZContentObject $obj, ParserInterface $parser, array $extras, $allowPasswordHash )
    {
        $datamap = $obj->attribute( 'data_map' );
        $cells = array();
        foreach ( $columns as $column )
        {
            if ( isset( $extras[$column['id']] ) )
                $cells[] = $parser->escape( self::extraValue( $column['id'], $obj, $allowPasswordHash ) );
            elseif ( isset( $datamap[$column['id']] ) && is_object( $datamap[$column['id']] ) )
                $cells[] = $parser->exportValue( $datamap[$column['id']] );
            else
                $cells[] = $parser->escape( '' );
        }
        return $cells;
    }

    /** The name of a node the current user may read, else ''. */
    public static function nodeName( $nodeID )
    {
        $node = $nodeID ? eZContentObjectTreeNode::fetch( (int)$nodeID ) : null;
        return ( $node instanceof eZContentObjectTreeNode && $node->canRead() ) ? $node->attribute( 'name' ) : '';
    }

    /** A file name from a name: letters, digits, dot, dash and underscore only. */
    public static function fileName( $name, $suffix = '_export.csv', $fallback = 'export' )
    {
        $name = trim( preg_replace( '/[^A-Za-z0-9._-]+/', '_', (string)$name ), '._' );
        return ( $name === '' ? $fallback : substr( $name, 0, 80 ) ) . $suffix;
    }

    /** The public site's site.ini (DefaultAccess), not the admin one the view runs in. */
    public static function publicSiteINI()
    {
        $siteINI = eZINI::instance();
        $defaultAccess = $siteINI->variable( 'SiteSettings', 'DefaultAccess' );
        $currentAccess = isset( $GLOBALS['eZCurrentAccess']['name'] ) ? $GLOBALS['eZCurrentAccess']['name'] : '';
        if ( $defaultAccess && $defaultAccess !== $currentAccess )
            $siteINI = eZSiteAccess::getIni( $defaultAccess, 'site.ini' );
        return $siteINI;
    }

    /** The public site's address (DefaultAccess), not the admin one the view runs in. */
    public static function publicSiteURL()
    {
        static $url = null;
        if ( $url === null )
        {
            $siteINI = self::publicSiteINI();
            $scheme = eZSys::isSSLNow() ? 'https://' : 'http://';
            $url = $scheme . rtrim( preg_replace( '#^https?://#', '', $siteINI->variable( 'SiteSettings', 'SiteURL' ) ), '/' );
        }
        return $url;
    }

    /**
     * The value of a special column for one object. Nothing from the request
     * decides which class or method is called.
     */
    public static function extraValue( $key, eZContentObject $obj, $allowPasswordHash )
    {
        if ( strpos( $key, 'ezuser.' ) === 0 )
        {
            $user = eZUser::fetch( $obj->attribute( 'id' ) );
            if ( !$user instanceof eZUser )
                return '';
            switch ( $key )
            {
                case 'ezuser.login':         return $user->attribute( 'login' );
                case 'ezuser.email':         return $user->attribute( 'email' );
                case 'ezuser.password_hash': return $allowPasswordHash ? $user->attribute( 'password_hash' ) : '';
                case 'ezuser.is_enabled':
                    return $user->attribute( 'is_enabled' ) ? ezpI18n::tr( 'design/standard/extract', 'enabled' )
                                                            : ezpI18n::tr( 'design/standard/extract', 'disabled' );
            }
            return '';
        }

        switch ( $key )
        {
            case 'ezcontentobject.id':               return $obj->attribute( 'id' );
            case 'ezcontentobject.remote_id':        return $obj->attribute( 'remote_id' );
            case 'ezcontentobject.name':             return $obj->attribute( 'name' );
            case 'ezcontentobject.class_identifier': return $obj->attribute( 'class_identifier' );
            case 'ezcontentobject.section':
                $section = eZSection::fetch( $obj->attribute( 'section_id' ) );
                return $section ? $section->attribute( 'name' ) : '';
            case 'ezcontentobject.owner':
                $owner = eZContentObject::fetch( $obj->attribute( 'owner_id' ) );
                return ( $owner && $owner->canRead() ) ? $owner->attribute( 'name' ) : '';
            case 'ezcontentobject.published':
            case 'ezcontentobject.modified':
                $time = (int)$obj->attribute( substr( $key, 16 ) );
                return $time > 0 ? date( 'Y-m-d', $time ) : '';
            case 'ezcontentobject.main_node_id':        return $obj->attribute( 'main_node_id' );
            case 'ezcontentobject.main_parent_node_id': return $obj->attribute( 'main_parent_node_id' );
            case 'ezcontentobject.main_parent_name':    return self::nodeName( $obj->attribute( 'main_parent_node_id' ) );
            case 'ezcontentobject.parent_nodes':
                $names = array();
                foreach ( (array)$obj->attribute( 'parent_nodes' ) as $nodeID )
                {
                    $name = self::nodeName( $nodeID );
                    if ( $name !== '' )
                        $names[] = $name;
                }
                return join( " ", $names );
        }

        $mainNode = $obj->attribute( 'main_node' );
        if ( !$mainNode )
            return '';
        switch ( $key )
        {
            case 'ezcontentobject.url_alias':      return $mainNode->attribute( 'url_alias' );
            case 'ezcontentobject.full_url_alias': return self::publicSiteURL() . '/' . $mainNode->attribute( 'url_alias' );
        }
        return '';
    }
}

?>
