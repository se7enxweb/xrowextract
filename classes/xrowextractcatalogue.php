<?php

/**
 * The column catalogue beyond the class attributes as they are:
 *
 * - more special columns, in groups (object, dates, location, relations, owner);
 * - attribute formats: the same attribute in another form, as column "identifier:format"
 *   (rich text as plain text, an image as its absolute URL, relations as ids, dates as ISO 8601 ...);
 * - column sets: ready-made groups of columns added with one click.
 *
 * Values are computed here from the object; nothing from the request picks a class or method.
 */
class XrowExtractCatalogue
{
    /** Special column groups, in the order the picker shows them. */
    public static function groups()
    {
        return array(
            'object'    => ezpI18n::tr( 'design/standard/extract', 'Object' ),
            'dates'     => ezpI18n::tr( 'design/standard/extract', 'Dates' ),
            'location'  => ezpI18n::tr( 'design/standard/extract', 'Location' ),
            'relations' => ezpI18n::tr( 'design/standard/extract', 'Relations' ),
            'user'      => ezpI18n::tr( 'design/standard/extract', 'User account' ),
        );
    }

    /** The group of every special column id (the ones XrowExtractColumns defines and the ones below). */
    public static function groupOf( $id )
    {
        if ( strpos( $id, 'ezuser.' ) === 0 || $id === 'ezcontentobject.owner_login' || $id === 'ezcontentobject.owner_email' )
            return 'user';
        if ( strpos( $id, 'node.' ) === 0 || in_array( $id, array( 'ezcontentobject.url_alias', 'ezcontentobject.full_url_alias', 'ezcontentobject.main_node_id',
                                                                    'ezcontentobject.main_parent_node_id', 'ezcontentobject.main_parent_name', 'ezcontentobject.parent_nodes' ), true ) )
            return 'location';
        if ( strpos( $id, 'relations.' ) === 0 )
            return 'relations';
        if ( preg_match( '/^ezcontentobject\.(published|modified)/', $id ) )
            return 'dates';
        return 'object';
    }

    /** The special columns added by the catalogue: id => (exportname, name, cell). */
    public static function extraColumns()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        return array(
            'ezcontentobject.class_name'          => array( 'class_name',          'Class Name',                 $t( 'text' ) ),
            'ezcontentobject.languages'           => array( 'languages',           'All Languages',              $t( 'language codes' ) ),
            'ezcontentobject.initial_language'    => array( 'initial_language',    'Initial Language',           $t( 'language code' ) ),
            'ezcontentobject.always_available'    => array( 'always_available',    'Always Available',           $t( '1 or 0' ) ),
            'ezcontentobject.states'              => array( 'states',              'Object States',              $t( 'group/state' ) ),
            'ezcontentobject.version'             => array( 'version',             'Current Version',            $t( 'number' ) ),
            'ezcontentobject.creator'             => array( 'creator',             'Version Creator',            $t( 'user name' ) ),
            'ezcontentobject.owner_login'         => array( 'owner_login',         'Owner Login',                $t( 'login' ) ),
            'ezcontentobject.owner_email'         => array( 'owner_email',         'Owner E-Mail',               $t( 'e-mail address' ) ),
            'ezcontentobject.published_iso'       => array( 'published_iso',       'Published (ISO 8601)',       $t( 'YYYY-MM-DDTHH:MM:SS+00:00' ) ),
            'ezcontentobject.modified_iso'        => array( 'modified_iso',        'Modified (ISO 8601)',        $t( 'YYYY-MM-DDTHH:MM:SS+00:00' ) ),
            'ezcontentobject.published_timestamp' => array( 'published_timestamp', 'Published (Unix time)',      $t( 'seconds since 1970' ) ),
            'ezcontentobject.modified_timestamp'  => array( 'modified_timestamp',  'Modified (Unix time)',       $t( 'seconds since 1970' ) ),
            'node.remote_id'                      => array( 'node_remote_id',      'Main Node Remote ID',        $t( 'identifier' ) ),
            'node.parent_remote_id'               => array( 'parent_remote_id',    'Parent Node Remote ID',      $t( 'identifier' ) ),
            'node.path'                           => array( 'path',                'Path (names)',               $t( 'node names' ) ),
            'node.path_ids'                       => array( 'path_ids',            'Path (node ids)',            $t( 'numbers' ) ),
            'node.depth'                          => array( 'depth',               'Depth',                      $t( 'number' ) ),
            'node.priority'                       => array( 'priority',            'Priority',                   $t( 'number' ) ),
            'node.visibility'                     => array( 'visibility',          'Visibility',                 $t( 'visible, hidden or hidden by a parent' ) ),
            'node.locations'                      => array( 'locations',           'Number of Locations',        $t( 'number' ) ),
            'node.children'                       => array( 'children',            'Number of Children',         $t( 'number' ) ),
            'relations.count'                     => array( 'relations',           'Related Objects (count)',    $t( 'number' ) ),
            'relations.names'                     => array( 'related_names',       'Related Objects (names)',    $t( 'related object names' ) ),
            'relations.reverse_count'             => array( 'reverse_relations',   'Reverse Related (count)',    $t( 'number' ) ),
        );
    }

    /** The value of a catalogue special column, or null when $key is not one of them. */
    public static function extraValue( $key, eZContentObject $obj )
    {
        switch ( $key )
        {
            case 'ezcontentobject.class_name':       return $obj->attribute( 'class_name' );
            case 'ezcontentobject.languages':        return implode( ',', $obj->availableLanguages() );
            case 'ezcontentobject.initial_language': return $obj->attribute( 'initial_language_code' );
            case 'ezcontentobject.always_available': return $obj->attribute( 'always_available' ) ? '1' : '0';
            case 'ezcontentobject.states':           return implode( ',', (array)$obj->attribute( 'state_identifier_array' ) );
            case 'ezcontentobject.version':          return $obj->attribute( 'current_version' );
            case 'ezcontentobject.creator':
                $version = $obj->currentVersion();
                $creator = $version ? eZContentObject::fetch( $version->attribute( 'creator_id' ) ) : null;
                return ( $creator && $creator->canRead() ) ? $creator->attribute( 'name' ) : '';
            case 'ezcontentobject.owner_login':
            case 'ezcontentobject.owner_email':
                $owner = eZUser::fetch( $obj->attribute( 'owner_id' ) );
                return $owner ? $owner->attribute( $key === 'ezcontentobject.owner_login' ? 'login' : 'email' ) : '';
            case 'ezcontentobject.published_iso':
            case 'ezcontentobject.modified_iso':
                $time = (int)$obj->attribute( strpos( $key, 'published' ) ? 'published' : 'modified' );
                return $time > 0 ? date( 'c', $time ) : '';
            case 'ezcontentobject.published_timestamp':
            case 'ezcontentobject.modified_timestamp':
                return (string)(int)$obj->attribute( strpos( $key, 'published' ) ? 'published' : 'modified' );
            case 'relations.count':         return (string)(int)$obj->relatedObjectCount( false, 0, false, array( 'AllRelations' => true ) );
            case 'relations.reverse_count': return (string)(int)$obj->reverseRelatedObjectCount( false, 0, array( 'AllRelations' => true ) );
            case 'relations.names':
                $names = array();
                foreach ( (array)$obj->relatedObjects( false, false, 0, false, array( 'AllRelations' => true ) ) as $related )
                {
                    if ( $related instanceof eZContentObject && $related->canRead() )
                        $names[] = $related->attribute( 'name' );
                }
                return implode( ', ', array_unique( $names ) );
        }
        if ( strpos( $key, 'node.' ) !== 0 )
            return null;
        $node = $obj->attribute( 'main_node' );
        if ( !$node instanceof eZContentObjectTreeNode )
            return '';
        switch ( $key )
        {
            case 'node.remote_id': return $node->attribute( 'remote_id' );
            case 'node.parent_remote_id':
                $parent = $node->attribute( 'parent' );
                return $parent ? $parent->attribute( 'remote_id' ) : '';
            case 'node.path':
                $names = array();
                foreach ( (array)$node->attribute( 'path' ) as $ancestor )
                {
                    if ( (int)$ancestor->attribute( 'node_id' ) > 1 )
                        $names[] = $ancestor->attribute( 'name' );
                }
                $names[] = $node->attribute( 'name' );
                return implode( ' / ', $names );
            case 'node.path_ids':  return trim( $node->attribute( 'path_string' ), '/' );
            case 'node.depth':     return (string)(int)$node->attribute( 'depth' );
            case 'node.priority':  return (string)(int)$node->attribute( 'priority' );
            case 'node.visibility':
                if ( $node->attribute( 'is_hidden' ) )
                    return ezpI18n::tr( 'design/standard/extract', 'hidden' );
                return $node->attribute( 'is_invisible' ) ? ezpI18n::tr( 'design/standard/extract', 'hidden by a parent' ) : ezpI18n::tr( 'design/standard/extract', 'visible' );
            case 'node.locations': return (string)count( (array)$obj->assignedNodes( false ) );
            case 'node.children':  return (string)(int)$node->attribute( 'children_count' );
        }
        return '';
    }

    /** The formats of a datatype: format => label. */
    public static function formatsFor( $datatype )
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        $relations = array( 'ids' => $t( 'object ids' ), 'remote_ids' => $t( 'remote ids' ), 'urls' => $t( 'URL aliases' ) );
        $files = array( 'url' => $t( 'download URL' ), 'name' => $t( 'file name' ), 'bytes' => $t( 'size in bytes' ), 'mime' => $t( 'MIME type' ) );
        $dates = array( 'iso' => $t( 'ISO 8601' ), 'timestamp' => $t( 'Unix time' ) );
        switch ( $datatype )
        {
            case 'ezxmltext':            return array( 'text' => $t( 'plain text' ), 'words' => $t( 'word count' ) );
            case 'eztext':               return array( 'words' => $t( 'word count' ) );
            case 'ezimage':              return array( 'url' => $t( 'absolute URL' ), 'alt' => $t( 'alternative text' ), 'size' => $t( 'width × height' ) );
            case 'ezbinaryfile':
            case 'ezmedia':              return $files;
            case 'ezobjectrelation':
            case 'ezobjectrelationlist':
            case 'ezenhancedobjectrelation': return $relations;
            case 'ezselection':          return array( 'ids' => $t( 'option ids' ) );
            case 'ezdate':
            case 'ezdatetime':           return $dates;
            case 'ezprice':              return array( 'ex_vat' => $t( 'price excl. VAT' ), 'vat' => $t( 'VAT rate %' ) );
            case 'ezboolean':            return array( 'yesno' => $t( 'yes or no' ) );
            case 'eztags':               return array( 'ids' => $t( 'tag ids' ) );
            case 'ezurl':                return array( 'url' => $t( 'URL only' ), 'text' => $t( 'link text' ) );
            case 'ezuser':               return array( 'email' => $t( 'e-mail address' ), 'enabled' => $t( 'enabled or disabled' ) );
            case 'ezmatrix':             return array( 'json' => $t( 'as JSON' ) );
            case 'xrowmetadata':
                return array( 'keywords' => $t( 'keywords' ), 'description' => $t( 'description' ), 'canonical' => $t( 'canonical URL' ),
                              'priority' => $t( 'sitemap priority' ), 'change' => $t( 'change frequency' ), 'sitemap' => $t( 'in the sitemap' ),
                              'og_image' => $t( 'Open Graph image' ), 'og_image_alt' => $t( 'Open Graph image text' ), 'json' => $t( 'all fields as JSON' ) );
        }
        return array();
    }

    /** Every attribute format of a class as a column: id "identifier:format". */
    public static function formatColumns( $classID )
    {
        $columns = array();
        foreach ( eZContentClassAttribute::fetchListByClassID( (int)$classID, eZContentClass::VERSION_STATUS_DEFINED, true ) as $attribute )
        {
            $identifier = $attribute->attribute( 'identifier' );
            foreach ( self::formatsFor( $attribute->attribute( 'data_type_string' ) ) as $format => $label )
            {
                $columns[$identifier . ':' . $format] = array(
                    'id' => $identifier . ':' . $format,
                    'name' => $attribute->attribute( 'name' ) . ': ' . $label,
                    'exportname' => $identifier . '_' . $format,
                    'format' => $label,
                    'datatype' => $attribute->attribute( 'data_type_string' ),
                );
            }
        }
        return $columns;
    }

    /** Plain text from HTML: no tags, entities decoded, white space collapsed. */
    protected static function plainText( $html )
    {
        return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( preg_replace( '#<(br|/p|/li|/h\d|/td|/tr)[^>]*>#i', ' ', (string)$html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
    }

    /** The objects an attribute relates to (relation datatypes). */
    protected static function relatedObjects( eZContentObjectAttribute $attribute )
    {
        $content = $attribute->content();
        $ids = array();
        switch ( $attribute->attribute( 'data_type_string' ) )
        {
            case 'ezobjectrelation':
                if ( $content instanceof eZContentObject )
                    $ids[] = $content->attribute( 'id' );
                break;
            case 'ezobjectrelationlist':
                foreach ( isset( $content['relation_list'] ) ? $content['relation_list'] : array() as $item )
                    $ids[] = $item['contentobject_id'];
                break;
            case 'ezenhancedobjectrelation':
                $ids = isset( $content['id_list'] ) ? (array)$content['id_list'] : array();
                break;
        }
        $objects = array();
        foreach ( $ids as $id )
        {
            $object = eZContentObject::fetch( (int)$id );
            if ( $object instanceof eZContentObject && $object->canRead() )
                $objects[] = $object;
        }
        return $objects;
    }

    /** One attribute in one of its formats, as text. */
    public static function formatValue( eZContentObjectAttribute $attribute, $format )
    {
        $datatype = $attribute->attribute( 'data_type_string' );
        $content = $attribute->content();
        switch ( $datatype . ':' . $format )
        {
            case 'ezxmltext:text':
            case 'ezxmltext:words':
                $text = is_object( $content ) ? self::plainText( $content->attribute( 'output' )->attribute( 'output_text' ) ) : '';
                return $format === 'text' ? $text : (string)count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
            case 'eztext:words':
                return (string)count( preg_split( '/\s+/u', (string)$content, -1, PREG_SPLIT_NO_EMPTY ) );
            case 'ezimage:url':
            case 'ezimage:size':
                $alias = ( is_object( $content ) && $attribute->hasContent() ) ? $content->imageAlias( 'original' ) : false;
                if ( !is_array( $alias ) || empty( $alias['url'] ) )
                    return '';
                return $format === 'url' ? XrowExtractColumns::publicHostURL() . '/' . ltrim( $alias['url'], '/' ) : $alias['width'] . '×' . $alias['height'];
            case 'ezimage:alt':
                return is_object( $content ) ? (string)$content->attribute( 'alternative_text' ) : '';
            case 'ezbinaryfile:url':
            case 'ezmedia:url':
                if ( !is_object( $content ) || !$attribute->hasContent() )
                    return '';
                return XrowExtractColumns::publicSiteURL() . '/content/download/' . (int)$attribute->attribute( 'contentobject_id' ) . '/'
                       . (int)$attribute->attribute( 'id' ) . '/file/' . rawurlencode( $content->attribute( 'original_filename' ) );
            case 'ezbinaryfile:name':
            case 'ezmedia:name':  return is_object( $content ) ? (string)$content->attribute( 'original_filename' ) : '';
            case 'ezbinaryfile:bytes':
            case 'ezmedia:bytes': return is_object( $content ) && $attribute->hasContent() ? (string)(int)$content->attribute( 'filesize' ) : '';
            case 'ezbinaryfile:mime':
            case 'ezmedia:mime':  return is_object( $content ) ? (string)$content->attribute( 'mime_type' ) : '';
            case 'ezobjectrelation:ids':
            case 'ezobjectrelationlist:ids':
            case 'ezenhancedobjectrelation:ids':
            case 'ezobjectrelation:remote_ids':
            case 'ezobjectrelationlist:remote_ids':
            case 'ezenhancedobjectrelation:remote_ids':
            case 'ezobjectrelation:urls':
            case 'ezobjectrelationlist:urls':
            case 'ezenhancedobjectrelation:urls':
                $values = array();
                foreach ( self::relatedObjects( $attribute ) as $object )
                {
                    if ( $format === 'ids' )
                        $values[] = $object->attribute( 'id' );
                    elseif ( $format === 'remote_ids' )
                        $values[] = $object->attribute( 'remote_id' );
                    elseif ( $object->attribute( 'main_node' ) )
                        $values[] = $object->attribute( 'main_node' )->attribute( 'url_alias' );
                }
                return implode( ',', $values );
            case 'ezselection:ids':
                return implode( ',', (array)$content );
            case 'ezdate:iso':
            case 'ezdatetime:iso':
                $time = (int)$attribute->attribute( 'data_int' );
                return $time > 0 ? date( $datatype === 'ezdate' ? 'Y-m-d' : 'c', $time ) : '';
            case 'ezdate:timestamp':
            case 'ezdatetime:timestamp':
                $time = (int)$attribute->attribute( 'data_int' );
                return $time > 0 ? (string)$time : '';
            case 'ezprice:ex_vat':
                return is_object( $content ) ? eZLocale::instance()->formatCleanCurrency( $content->attribute( 'ex_vat_price' ) ) : '';
            case 'ezprice:vat':
                return is_object( $content ) ? (string)$content->attribute( 'vat_percent' ) : '';
            case 'ezboolean:yesno':
                return $attribute->attribute( 'data_int' ) ? ezpI18n::tr( 'design/standard/extract', 'Yes' ) : ezpI18n::tr( 'design/standard/extract', 'No' );
            case 'eztags:ids':
                return is_object( $content ) ? implode( ',', (array)$content->attribute( 'tag_ids' ) ) : '';
            case 'ezurl:url':
                return (string)$content;
            case 'ezurl:text':
                return (string)$attribute->attribute( 'data_text' );
            case 'ezuser:email':
            case 'ezuser:enabled':
                if ( !$content instanceof eZUser )
                    return '';
                if ( $format === 'email' )
                    return (string)$content->attribute( 'email' );
                return $content->attribute( 'is_enabled' ) ? ezpI18n::tr( 'design/standard/extract', 'enabled' ) : ezpI18n::tr( 'design/standard/extract', 'disabled' );
            case 'ezmatrix:json':
                $rows = is_object( $content ) ? $content->attribute( 'rows' ) : array();
                $out = array();
                foreach ( isset( $rows['sequential'] ) ? $rows['sequential'] : array() as $row )
                    $out[] = array_values( $row['columns'] );
                return json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }
        if ( $datatype === 'xrowmetadata' )
        {
            if ( !is_object( $content ) )
                return '';
            switch ( $format )
            {
                case 'keywords':     return implode( ', ', (array)$content->keywords );
                case 'description':  return (string)$content->description;
                case 'canonical':    return (string)$content->canonical_url;
                case 'priority':     return (string)$content->priority;
                case 'change':       return (string)$content->change;
                case 'sitemap':      return $content->sitemap_use ? '1' : '0';
                case 'og_image':     return $content->og_image ? (string)$content->og_image : '';
                case 'og_image_alt': return (string)$content->og_image_alt;
                case 'json':         return json_encode( get_object_vars( $content ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            }
        }
        return '';
    }

    /**
     * Column sets: id => (name, description, column ids). "@attributes" stands for every class attribute,
     * "@metadata" for the SEO formats of the class's meta data attributes.
     */
    public static function columnSets()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        return array(
            'identity'   => array( $t( 'Identity' ), $t( 'Object id, remote id, class, name, language' ),
                                   array( 'ezcontentobject.id', 'ezcontentobject.remote_id', 'ezcontentobject.class_identifier', 'ezcontentobject.name', 'ezcontentobject.language' ) ),
            'urls'       => array( $t( 'URLs and SEO' ), $t( 'Name, URL alias, absolute URL, path, last change, meta data' ),
                                   array( 'ezcontentobject.name', 'ezcontentobject.url_alias', 'ezcontentobject.full_url_alias', 'node.path', 'ezcontentobject.modified_iso', '@metadata' ) ),
            'publishing' => array( $t( 'Publishing' ), $t( 'Dates, version, owner, creator, section, states, visibility' ),
                                   array( 'ezcontentobject.published_iso', 'ezcontentobject.modified_iso', 'ezcontentobject.version', 'ezcontentobject.owner',
                                          'ezcontentobject.creator', 'ezcontentobject.section', 'ezcontentobject.states', 'node.visibility' ) ),
            'location'   => array( $t( 'Location' ), $t( 'Nodes, parent, path, depth, priority, locations, children' ),
                                   array( 'ezcontentobject.main_node_id', 'node.remote_id', 'ezcontentobject.main_parent_node_id', 'node.parent_remote_id',
                                          'ezcontentobject.main_parent_name', 'node.path', 'node.depth', 'node.priority', 'node.locations', 'node.children' ) ),
            'migration'  => array( $t( 'Migration' ), $t( 'Everything to rebuild the content elsewhere: identity, parent, languages, dates, every attribute' ),
                                   array( 'ezcontentobject.id', 'ezcontentobject.remote_id', 'ezcontentobject.class_identifier', 'ezcontentobject.language',
                                          'ezcontentobject.initial_language', 'ezcontentobject.always_available', 'node.remote_id', 'node.parent_remote_id',
                                          'node.priority', 'ezcontentobject.section', 'ezcontentobject.published_timestamp', 'ezcontentobject.modified_timestamp',
                                          '@attributes' ) ),
        );
    }

    /** Column ids of a class resolved to column entries (class attributes, attribute formats, special columns). */
    public static function resolveColumns( array $ids, $classID, array $extras )
    {
        $byID = array();
        foreach ( XrowExtractColumns::classColumns( $classID ) as $column )
            $byID[$column['id']] = $column;
        $byID = array_merge( $byID, self::formatColumns( $classID ), $extras );
        $columns = array();
        foreach ( $ids as $id )
        {
            if ( isset( $byID[$id] ) )
                $columns[] = $byID[$id];
        }
        return $columns;
    }

    /** The column ids of a set for a class, placeholders expanded. */
    public static function setColumnIDs( $setID, $classID )
    {
        $sets = self::columnSets();
        if ( !isset( $sets[$setID] ) )
            return array();
        $ids = array();
        foreach ( $sets[$setID][2] as $id )
        {
            if ( $id === '@attributes' )
            {
                foreach ( XrowExtractColumns::classColumns( $classID ) as $column )
                    $ids[] = $column['id'];
            }
            elseif ( $id === '@metadata' )
            {
                foreach ( self::formatColumns( $classID ) as $formatID => $column )
                {
                    if ( $column['datatype'] === 'xrowmetadata' && preg_match( '/:(keywords|description|canonical)$/', $formatID ) )
                        $ids[] = substr( $formatID, 0, strpos( $formatID, ':' ) );   // the title (the attribute itself)
                    if ( $column['datatype'] === 'xrowmetadata' && preg_match( '/:(keywords|description|canonical)$/', $formatID ) )
                        $ids[] = $formatID;
                }
            }
            else
                $ids[] = $id;
        }
        return array_values( array_unique( $ids ) );
    }
}

?>
