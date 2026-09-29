<?php

/**
 * The columns of an export and their values: the special (non class
 * attribute) columns, a class's attribute columns, and one object as a row
 * of CSV cells. Used by the single class CSV view and the site archive.
 */
class XrowExtractColumns
{
    /** The language rows are written in (a locale such as eng-US), or null for the object's own language order. */
    public static $language = null;

    /** The special columns. Only these exist; each value is computed in extraValue(). */
    public static function extraAttributes( $allowPasswordHash = null )
    {
        if ( $allowPasswordHash === null )
            $allowPasswordHash = self::allowPasswordHash();
        $list = array(
            'ezcontentobject.id'                  => array( 'exportname' => 'object_id',           'name' => 'Object ID' ),
            'ezcontentobject.remote_id'           => array( 'exportname' => 'remote_id',           'name' => 'Remote ID' ),
            'ezcontentobject.name'                => array( 'exportname' => 'object_name',         'name' => 'Object Name' ),
            'ezcontentobject.language'            => array( 'exportname' => 'language',            'name' => 'Language' ),
            'ezcontentobject.class_identifier'    => array( 'exportname' => 'class',               'name' => 'Class Identifier' ),
            'ezcontentobject.section'             => array( 'exportname' => 'section',             'name' => 'Section' ),
            'ezcontentobject.owner'               => array( 'exportname' => 'owner',               'name' => 'Owner Name' ),
            'ezuser.login'                        => array( 'exportname' => 'login',               'name' => 'Login' ),
            'ezuser.email'                        => array( 'exportname' => 'email',               'name' => 'E-Mail' ),
            'ezuser.password_hash'                => array( 'exportname' => 'password_hash',       'name' => 'Password hash' ),
            'ezuser.password_hash_type'           => array( 'exportname' => 'password_hash_type',  'name' => 'Password hash type' ),
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
            unset( $list['ezuser.password_hash'], $list['ezuser.password_hash_type'] );
        foreach ( XrowExtractCatalogue::extraColumns() as $id => $column )
            $list[$id] = array( 'exportname' => $column[0], 'name' => $column[1] );
        foreach ( $list as $id => $column )
        {
            $list[$id]['id'] = $id;
            $list[$id]['group'] = XrowExtractCatalogue::groupOf( $id );
        }
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

    /** What a cell holds for a datatype, as the export handlers write it; false when no handler exports it. */
    public static function cellDescription( $datatype )
    {
        $exportable = (array)eZINI::instance( 'csv.ini' )->variable( 'General', 'ExportableDatatypes' );
        if ( !in_array( $datatype, $exportable, true ) )
            return false;
        $cells = array(
            'ezstring' => 'text', 'eztext' => 'text', 'hmregexpline' => 'text', 'ezidentifier' => 'identifier',
            'ezxmltext' => 'HTML', 'ezinteger' => 'number', 'ezfloat' => 'number', 'ezprice' => 'price incl. VAT',
            'ezboolean' => '1 or 0', 'ezdate' => 'YYYY-MM-DD', 'ezdatetime' => 'YYYY-MM-DD HH:MM:SS', 'eztime' => 'HH:MM',
            'ezemail' => 'e-mail address', 'ezurl' => 'URL', 'ezuser' => 'login',
            'ezimage' => 'image path', 'ezbinaryfile' => 'file path', 'ezmedia' => 'file path', 'ezmatrix' => 'cells | and rows &',
            'ezselection' => 'chosen option', 'ezenhancedselection' => 'chosen options', 'ezcountry' => 'country',
            'ezenum' => 'value', 'ezkeyword' => 'keywords', 'eztags' => 'tags',
            'ezobjectrelation' => 'related object name', 'ezobjectrelationlist' => 'related object names',
            'ezenhancedobjectrelation' => 'related object names', 'xrowmetadata' => 'title',
        );
        return ezpI18n::tr( 'design/standard/extract', isset( $cells[$datatype] ) ? $cells[$datatype] : 'value' );
    }

    /** The translated name of a datatype, or its identifier when it is not installed. */
    public static function datatypeName( $datatype )
    {
        $type = eZDataType::create( $datatype );
        return ( $type && isset( $type->Name ) && $type->Name !== '' ) ? $type->Name : $datatype;
    }

    /**
     * Meta information for every column id of a class (its attributes, keyed by identifier) and for the
     * special columns (keyed by their id): what the view shows beside a column.
     */
    public static function attributeMeta( $classID )
    {
        $meta = array();
        foreach ( eZContentClassAttribute::fetchListByClassID( (int)$classID, eZContentClass::VERSION_STATUS_DEFINED, true ) as $attribute )
        {
            $datatype = $attribute->attribute( 'data_type_string' );
            $cell = self::cellDescription( $datatype );
            $meta[$attribute->attribute( 'identifier' )] = array(
                'special' => false,
                'sensitive' => false,
                'datatype' => $datatype,
                'datatype_name' => self::datatypeName( $datatype ),
                'required' => (bool)$attribute->attribute( 'is_required' ),
                'translatable' => (bool)$attribute->attribute( 'can_translate' ),
                'searchable' => (bool)$attribute->attribute( 'is_searchable' ),
                'collector' => (bool)$attribute->attribute( 'is_information_collector' ),
                'exportable' => $cell !== false,
                'cell' => $cell === false ? '' : $cell,
                'position' => (int)$attribute->attribute( 'placement' ),
            );
        }
        $extraCells = array(
            'ezcontentobject.id' => 'number', 'ezcontentobject.remote_id' => 'identifier', 'ezcontentobject.name' => 'text', 'ezcontentobject.language' => 'language code',
            'ezcontentobject.class_identifier' => 'identifier', 'ezcontentobject.section' => 'section name', 'ezcontentobject.owner' => 'owner name',
            'ezuser.login' => 'login', 'ezuser.email' => 'e-mail address', 'ezuser.password_hash' => 'password hash', 'ezuser.password_hash_type' => 'md5_password, bcrypt ...', 'ezuser.is_enabled' => 'enabled or disabled',
            'ezcontentobject.published' => 'YYYY-MM-DD', 'ezcontentobject.modified' => 'YYYY-MM-DD',
            'ezcontentobject.url_alias' => 'URL path', 'ezcontentobject.full_url_alias' => 'URL',
            'ezcontentobject.main_parent_name' => 'node name', 'ezcontentobject.main_node_id' => 'number',
            'ezcontentobject.main_parent_node_id' => 'number', 'ezcontentobject.parent_nodes' => 'node names',
        );
        foreach ( XrowExtractCatalogue::extraColumns() as $id => $column )
            $extraCells[$id] = $column[2];
        foreach ( XrowExtractCatalogue::formatColumns( $classID ) as $id => $column )
        {
            $base = $meta[substr( $id, 0, strpos( $id, ':' ) )];
            $meta[$id] = array_merge( $base, array( 'format' => $column['format'], 'cell' => $column['format'], 'exportable' => true ) );
        }
        foreach ( self::extraAttributes() as $id => $column )
        {
            $meta[$id] = array(
                'group' => $column['group'],
                'special' => true, 'sensitive' => strpos( $id, 'ezuser.password_hash' ) === 0, 'datatype' => strtok( $id, '.' ), 'datatype_name' => ezpI18n::tr( 'design/standard/extract', 'Special column' ),
                'required' => false, 'translatable' => false, 'searchable' => false, 'collector' => false, 'exportable' => true,
                'cell' => isset( XrowExtractCatalogue::extraColumns()[$id] ) ? $extraCells[$id] : ezpI18n::tr( 'design/standard/extract', isset( $extraCells[$id] ) ? $extraCells[$id] : 'value' ), 'position' => 0,
            );
        }
        return $meta;
    }

    /**
     * Whether the current user may export password hashes: the policy xrowextract/password_hash
     * (administrators have it), unless csv.ini AllowPasswordHashExport=disabled switches it off.
     */
    public static function allowPasswordHash()
    {
        $csvINI = eZINI::instance( 'csv.ini' );
        if ( $csvINI->hasVariable( 'General', 'AllowPasswordHashExport' )
             && $csvINI->variable( 'General', 'AllowPasswordHashExport' ) === 'disabled' )
            return false;
        $access = eZUser::currentUser()->hasAccessTo( 'xrowextract', 'password_hash' );
        return $access['accessWord'] !== 'no';
    }

    /** The name of a password hash type (md5_password, bcrypt ...), or "type <n>" for one the kernel does not name. */
    public static function passwordHashTypeName( $type )
    {
        $name = eZUser::passwordHashTypeName( (int)$type );
        return $name ? $name : 'type ' . (int)$type;
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
        $datamap = self::$language ? $obj->fetchDataMap( false, self::$language ) : $obj->attribute( 'data_map' );
        $cells = array();
        foreach ( $columns as $column )
        {
            if ( strpos( $column['id'], ':' ) !== false )
            {
                // An attribute format: identifier:format
                list( $identifier, $format ) = explode( ':', $column['id'], 2 );
                $cells[] = $parser->escape( isset( $datamap[$identifier] ) && is_object( $datamap[$identifier] )
                                            ? XrowExtractCatalogue::formatValue( $datamap[$identifier], $format ) : '' );
            }
            elseif ( isset( $extras[$column['id']] ) )
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

    /**
     * The content languages: locale => (locale, name, default), the public site's default language
     * (ContentObjectLocale of DefaultAccess) first, then by name.
     */
    public static function contentLanguages()
    {
        $default = self::publicSiteINI()->variable( 'RegionalSettings', 'ContentObjectLocale' );
        $languages = array();
        foreach ( eZContentLanguage::fetchList() as $language )
        {
            $locale = $language->attribute( 'locale' );
            $languages[$locale] = array( 'locale' => $locale, 'name' => $language->attribute( 'name' ), 'default' => $locale === $default );
        }
        uasort( $languages, function ( $a, $b ) {
            return $a['default'] !== $b['default'] ? ( $a['default'] ? -1 : 1 ) : strcasecmp( $a['name'], $b['name'] );
        } );
        return $languages;
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

    /** The public site's scheme and host only: stored files (images) are served from there, not from a siteaccess path. */
    public static function publicHostURL()
    {
        $url = self::publicSiteURL();
        return preg_match( '#^(https?://[^/]+)#', $url, $m ) ? $m[1] : $url;
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
        $value = XrowExtractCatalogue::extraValue( $key, $obj );
        if ( $value !== null )
            return $value;
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
                case 'ezuser.password_hash_type':
                    return $allowPasswordHash ? self::passwordHashTypeName( $user->attribute( 'password_hash_type' ) ) : '';
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
            case 'ezcontentobject.name':             return self::$language ? $obj->name( false, self::$language ) : $obj->attribute( 'name' );
            case 'ezcontentobject.language':         return self::$language ? self::$language : $obj->attribute( 'initial_language_code' );
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
