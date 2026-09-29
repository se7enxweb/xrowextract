<?php

/**
 * Content-package (.ezpkg) support for xrowextract/package: inspecting a
 * package's content classes and content objects before anything is written
 * (the dry run), installing it through the same kernel code path
 * package/install uses (eZPackage::install()), and building a rich sample
 * "content + class" template package for a chosen class through the kernel
 * package handlers (eZContentClassPackageHandler, eZContentObjectPackageHandler)
 * so the result is guaranteed to install the same way any other Exponential
 * package does.
 *
 * Nothing here re-implements package parsing: every read goes through
 * eZPackage's own DOM helpers, and every write goes through eZPackage,
 * eZContentClassPackageHandler and eZContentObjectPackageHandler, the same
 * classes kernel/package/{install,create,export}.php use.
 */
class XrowExtractPackage
{
    /** Content object existing-object handling: mirrors eZContentObject::PACKAGE_*. */
    const OBJECT_SKIP = 'skip';
    const OBJECT_UPDATE = 'update';
    const OBJECT_NEW = 'new';

    /** Content class existing-class handling: mirrors eZContentClassPackageHandler::ACTION_*. */
    const CLASS_SKIP = 'skip';
    const CLASS_REPLACE = 'replace';
    const CLASS_NEW = 'new';

    // ------------------------------------------------------------ listing

    /** Packages in the repository that carry a content class or content object install item. */
    public static function repositoryPackages()
    {
        $out = array();
        foreach ( eZPackage::fetchPackages() as $package )
        {
            $types = self::installItemTypes( $package );
            if ( !in_array( 'ezcontentclass', $types, true ) && !in_array( 'ezcontentobject', $types, true ) )
                continue;
            $out[] = array(
                'name'          => $package->attribute( 'name' ),
                'summary'       => (string)$package->attribute( 'summary' ),
                'version'       => (string)$package->attribute( 'version-number' ),
                'is_installed'  => (bool)$package->attribute( 'is_installed' ),
                'install_type'  => (string)$package->attribute( 'install_type' ),
                'has_classes'   => in_array( 'ezcontentclass', $types, true ),
                'has_objects'   => in_array( 'ezcontentobject', $types, true ),
                'class_count'   => self::countItems( $package, 'ezcontentclass' ),
                'object_item_count' => self::countItems( $package, 'ezcontentobject' ),
            );
        }
        usort( $out, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        return $out;
    }

    protected static function installItemTypes( eZPackage $package )
    {
        $types = array();
        foreach ( $package->installItemsList() as $item )
            if ( isset( $item['type'] ) )
                $types[$item['type']] = true;
        return array_keys( $types );
    }

    protected static function countItems( eZPackage $package, $type )
    {
        return count( self::installItemsOfType( $package, $type ) );
    }

    /**
     * Every install item of one type. Not eZPackage::installItemsList($type):
     * called with only a type and no $os, its "found" fallback treats every
     * item as a match once the type check fails, so it silently returns the
     * whole install list instead of filtering - see the kernel bug note in
     * this extension's port docs (installItemsList( 'ezcontentclass' ) was
     * observed to also return the package's 'ezcontentobject' item).
     */
    protected static function installItemsOfType( eZPackage $package, $type )
    {
        $matches = array();
        foreach ( $package->installItemsList() as $item )
            if ( isset( $item['type'] ) && $item['type'] === $type )
                $matches[] = $item;
        return $matches;
    }

    // ------------------------------------------------------------ metadata

    /** Package-level metadata for the inspect screen: name, summary, version, licence, dependencies, changelog. */
    public static function packageMeta( eZPackage $package )
    {
        $changelog = array();
        foreach ( (array)$package->attribute( 'changelog' ) as $entry )
        {
            $changelog[] = array(
                'person'    => isset( $entry['person'] ) ? $entry['person'] : '',
                'timestamp' => isset( $entry['timestamp'] ) ? (int)$entry['timestamp'] : 0,
                'changes'   => isset( $entry['changes'] ) ? (array)$entry['changes'] : array(),
            );
        }
        $dependencies = array();
        $depData = (array)$package->attribute( 'dependencies' );
        foreach ( array( 'requires', 'provides', 'obsoletes', 'conflicts' ) as $section )
        {
            foreach ( (array)( isset( $depData[$section] ) ? $depData[$section] : array() ) as $dep )
                $dependencies[] = array( 'section' => $section, 'type' => isset( $dep['type'] ) ? $dep['type'] : '',
                                         'name' => isset( $dep['name'] ) ? $dep['name'] : '', 'value' => isset( $dep['value'] ) ? $dep['value'] : '' );
        }
        return array(
            'name'          => $package->attribute( 'name' ),
            'summary'       => (string)$package->attribute( 'summary' ),
            'description'   => (string)$package->attribute( 'description' ),
            'version'       => (string)$package->attribute( 'version-number' ),
            'release'       => (string)$package->attribute( 'release-number' ),
            'licence'       => (string)$package->attribute( 'licence' ),
            'vendor'        => (string)$package->attribute( 'vendor' ),
            'install_type'  => (string)$package->attribute( 'install_type' ),
            'is_installed'  => (bool)$package->attribute( 'is_installed' ),
            'maintainers'   => (array)$package->attribute( 'maintainers' ),
            'dependencies'  => $dependencies,
            'changelog'     => $changelog,
        );
    }

    // ------------------------------------------------------------ inspect (dry run)

    /**
     * The dry run: every content class and content object install item, matched
     * against what already exists on this site by remote id (classes fall back
     * to identifier). Nothing is written.
     */
    public static function inspect( eZPackage $package )
    {
        $classes = array();
        $objects = array();
        $errors = array();
        $classRemoteIDsInPackage = array();
        $classIdentifiersInPackage = array();

        foreach ( self::installItemsOfType( $package, 'ezcontentclass' ) as $item )
        {
            $row = self::inspectClassItem( $package, $item );
            if ( $row === null )
            {
                $errors[] = 'Could not read a content class item from the package (' . ( isset( $item['filename'] ) ? $item['filename'] : '?' ) . ').';
                continue;
            }
            $classes[] = $row;
            if ( $row['remote_id'] )
                $classRemoteIDsInPackage[$row['remote_id']] = true;
            if ( $row['identifier'] )
                $classIdentifiersInPackage[$row['identifier']] = true;
        }

        foreach ( self::installItemsOfType( $package, 'ezcontentobject' ) as $item )
        {
            foreach ( self::inspectObjectItem( $package, $item, $classRemoteIDsInPackage, $classIdentifiersInPackage ) as $row )
                $objects[] = $row;
        }

        return array(
            'meta'    => self::packageMeta( $package ),
            'classes' => $classes,
            'objects' => $objects,
            'errors'  => $errors,
            'counts'  => array(
                'classes_create' => count( array_filter( $classes, function ( $c ) { return $c['state'] === 'create'; } ) ),
                'classes_update' => count( array_filter( $classes, function ( $c ) { return $c['state'] === 'update'; } ) ),
                'objects_create' => count( array_filter( $objects, function ( $o ) { return $o['state'] === 'create'; } ) ),
                'objects_update' => count( array_filter( $objects, function ( $o ) { return $o['state'] === 'update'; } ) ),
                'objects_unchanged' => count( array_filter( $objects, function ( $o ) { return $o['state'] === 'unchanged'; } ) ),
                'objects_class_missing' => count( array_filter( $objects, function ( $o ) { return $o['state'] === 'class_missing'; } ) ),
            ),
        );
    }

    protected static function inspectClassItem( eZPackage $package, array $item )
    {
        if ( empty( $item['filename'] ) )
            return null;
        $dom = self::fetchItemDOM( $package, $item );
        if ( !$dom )
            return null;
        $content = $dom;
        $identifierNode = $content->getElementsByTagName( 'identifier' )->item( 0 );
        $identifier = $identifierNode ? $identifierNode->textContent : '';
        $remoteIDNode = $content->getElementsByTagName( 'remote-id' )->item( 0 );
        $remoteID = $remoteIDNode ? $remoteIDNode->textContent : '';
        $name = '';
        $nameNode = $content->getElementsByTagName( 'name' )->item( 0 );
        if ( $nameNode )
            $name = $nameNode->textContent;
        else
        {
            $serializedNameListNode = $content->getElementsByTagName( 'serialized-name-list' )->item( 0 );
            if ( $serializedNameListNode && class_exists( 'eZContentClassNameList' ) )
            {
                $nameList = new eZContentClassNameList( $serializedNameListNode->textContent );
                $name = $nameList->name();
            }
        }
        $attributesNode = $content->getElementsByTagName( 'attributes' )->item( 0 );
        $attributeRows = array();
        if ( $attributesNode )
        {
            foreach ( $attributesNode->getElementsByTagName( 'attribute' ) as $attrNode )
            {
                $attrIdentifierNode = $attrNode->getElementsByTagName( 'identifier' )->item( 0 );
                $attrTypeNode = $attrNode->getElementsByTagName( 'type' )->item( 0 );
                $attributeRows[] = array(
                    'identifier' => $attrIdentifierNode ? $attrIdentifierNode->textContent : '',
                    'datatype'   => $attrTypeNode ? $attrTypeNode->textContent : '',
                    'required'   => $attrNode->getAttribute( 'required' ) === 'true',
                );
            }
        }

        $existing = $remoteID ? eZContentClass::fetchByRemoteID( $remoteID ) : null;
        if ( !$existing && $identifier )
            $existing = eZContentClass::fetchByIdentifier( $identifier );
        $state = $existing instanceof eZContentClass ? 'update' : 'create';

        return array(
            'identifier'  => $identifier,
            'remote_id'   => $remoteID,
            'name'        => $name,
            'state'       => $state,
            'existing_id' => $existing instanceof eZContentClass ? (int)$existing->attribute( 'id' ) : null,
            'attributes'  => $attributeRows,
            'attribute_count' => count( $attributeRows ),
        );
    }

    /** One install item can carry many content objects (inline or one XML file per object). */
    protected static function inspectObjectItem( eZPackage $package, array $item, array $classRemoteIDsInPackage, array $classIdentifiersInPackage )
    {
        $rows = array();
        foreach ( self::objectDOMNodes( $package, $item ) as $objectNode )
        {
            $name = $objectNode->getAttribute( 'name' );
            $remoteID = $objectNode->getAttribute( 'remote_id' );
            $classRemoteID = $objectNode->getAttribute( 'class_remote_id' );
            $classIdentifier = $objectNode->getAttributeNS( 'http://ez.no/ezobject', 'class_identifier' );
            $modifiedText = $objectNode->getAttributeNS( 'http://ez.no/ezobject', 'modified' );

            $languages = array();
            $versionListNode = $objectNode->getElementsByTagName( 'version-list' )->item( 0 );
            if ( $versionListNode )
            {
                foreach ( $versionListNode->getElementsByTagName( 'object-translation' ) as $translationNode )
                {
                    $lang = $translationNode->getAttribute( 'language' );
                    if ( $lang && !in_array( $lang, $languages, true ) )
                        $languages[] = $lang;
                }
            }

            $classExists = ( $classRemoteID && eZContentClass::fetchByRemoteID( $classRemoteID ) )
                         || ( $classIdentifier && eZContentClass::fetchByIdentifier( $classIdentifier ) )
                         || ( $classRemoteID && isset( $classRemoteIDsInPackage[$classRemoteID] ) )
                         || ( $classIdentifier && isset( $classIdentifiersInPackage[$classIdentifier] ) );

            $existing = $remoteID ? eZContentObject::fetchByRemoteID( $remoteID ) : null;
            if ( !$classExists )
                $state = 'class_missing';
            elseif ( !$existing instanceof eZContentObject )
                $state = 'create';
            else
            {
                $state = 'update';
                if ( $modifiedText !== '' && class_exists( 'eZDateUtils' ) )
                {
                    $packageModified = eZDateUtils::textToDate( $modifiedText );
                    if ( $packageModified && (int)$packageModified === (int)$existing->attribute( 'modified' ) )
                        $state = 'unchanged';
                }
            }

            $rows[] = array(
                'name'             => $name,
                'remote_id'        => $remoteID,
                'class_identifier' => $classIdentifier,
                'languages'        => $languages,
                'state'            => $state,
                'existing_id'      => $existing instanceof eZContentObject ? (int)$existing->attribute( 'id' ) : null,
            );
        }
        return $rows;
    }

    /** DOM nodes for every content object an install item carries, inline or in separate files. */
    protected static function objectDOMNodes( eZPackage $package, array $item )
    {
        if ( empty( $item['filename'] ) )
            return array();
        $dom = self::fetchItemDOM( $package, $item );
        if ( !$dom )
            return array();

        $objectListNode = $dom->getElementsByTagName( 'object-list' )->item( 0 );
        if ( $objectListNode )
            return iterator_to_array( $objectListNode->getElementsByTagName( 'object' ) );

        $nodes = array();
        $objectFilesListNode = $dom->getElementsByTagName( 'object-files-list' )->item( 0 );
        if ( !$objectFilesListNode )
            return $nodes;
        $handler = eZPackage::packageHandler( 'ezcontentobject' );
        $directory = $handler ? $handler->contentObjectDirectory() : 'ezcontentobject';
        foreach ( $objectFilesListNode->getElementsByTagName( 'object-file' ) as $fileNode )
        {
            $filePath = $package->path() . '/' . $directory . '/' . $fileNode->getAttribute( 'filename' );
            $objectDOM = $package->fetchDOMFromFile( $filePath );
            if ( $objectDOM )
                $nodes[] = $objectDOM->documentElement;
        }
        return $nodes;
    }

    /** The document element of an install item's own XML file (class or top-level content object file). */
    protected static function fetchItemDOM( eZPackage $package, array $item )
    {
        $filename = $item['filename'];
        $subdirectory = isset( $item['sub-directory'] ) ? $item['sub-directory'] : false;
        $filePath = $subdirectory ? $subdirectory . '/' . $filename . '.xml' : $filename . '.xml';
        $filePath = $package->path() . '/' . $filePath;
        $dom = $package->fetchDOMFromFile( $filePath );
        return $dom ? $dom->documentElement : false;
    }

    // ------------------------------------------------------------ install

    /**
     * Installs a package through eZPackage::install(), the same convenience
     * method kernel/package/install.php's per-item loop is built on. All
     * "top" nodes the package carries (a content package's own root objects)
     * are placed under $parentNodeID; existing classes/objects are handled
     * per $classMode/$objectMode.
     */
    public static function install( eZPackage $package, $parentNodeID, $siteAccess, $objectMode, $classMode, $userID = false )
    {
        $report = array( 'ok' => false, 'errors' => array(), 'created_classes' => array(), 'created_objects' => array() );

        $parentNode = eZContentObjectTreeNode::fetch( (int)$parentNodeID );
        if ( !$parentNode instanceof eZContentObjectTreeNode || !$parentNode->canRead() )
        {
            $report['errors'][] = "No node $parentNodeID, or you may not read it.";
            return $report;
        }

        // Remote ids present in the package before install, so afterwards we can
        // resolve exactly the classes/objects this run touched (eZPackage does not
        // hand back a report of what installItem() created).
        $beforeClasses = array();
        foreach ( self::installItemsOfType( $package, 'ezcontentclass' ) as $item )
        {
            $row = self::inspectClassItem( $package, $item );
            if ( $row && $row['remote_id'] )
                $beforeClasses[] = $row['remote_id'];
        }
        $beforeObjects = array();
        foreach ( self::installItemsOfType( $package, 'ezcontentobject' ) as $item )
            foreach ( self::objectDOMNodes( $package, $item ) as $node )
                if ( $node->getAttribute( 'remote_id' ) )
                    $beforeObjects[] = $node->getAttribute( 'remote_id' );

        $objectActionMap = array(
            self::OBJECT_SKIP   => eZContentObject::PACKAGE_SKIP,
            self::OBJECT_UPDATE => eZContentObject::PACKAGE_UPDATE,
            self::OBJECT_NEW    => eZContentObject::PACKAGE_NEW,
        );
        $classActionMap = array(
            self::CLASS_SKIP    => eZContentClassPackageHandler::ACTION_SKIP,
            self::CLASS_REPLACE => eZContentClassPackageHandler::ACTION_REPLACE,
            self::CLASS_NEW     => eZContentClassPackageHandler::ACTION_NEW,
        );

        $installParameters = array(
            // eZPackageHandler::errorChoosenAction() unconditionally does
            // count( $installParameters['error'] ) once error_default_actions
            // is set for a handler type/code (kernel/classes/ezpackagehandler.php);
            // the step-wizard install.php always seeds this, a direct
            // eZPackage::install() call must do the same or PHP 8 throws a
            // TypeError (count(): Argument #1 must be of type Countable|array,
            // null given) the moment a class/object it carries already exists.
            'error'           => array(),
            'top_nodes_map'   => array( '*' => (int)$parentNodeID ),
            'site_access_map' => array( '*' => $siteAccess ),
            'language_map'    => $package->defaultLanguageMap(),
            'user_id'         => $userID ? (int)$userID : eZUser::currentUserID(),
            'continue-on-error' => true,
            'error_default_actions' => array(
                'ezcontentobject' => array( eZContentObject::PACKAGE_ERROR_EXISTS => isset( $objectActionMap[$objectMode] ) ? $objectActionMap[$objectMode] : eZContentObject::PACKAGE_UPDATE ),
                'ezcontentclass'  => array( eZContentClassPackageHandler::ERROR_EXISTS => isset( $classActionMap[$classMode] ) ? $classActionMap[$classMode] : eZContentClassPackageHandler::ACTION_SKIP ),
            ),
        );

        $ok = $package->install( $installParameters );
        $package->setInstalled();

        foreach ( $beforeClasses as $remoteID )
        {
            $class = eZContentClass::fetchByRemoteID( $remoteID );
            if ( $class instanceof eZContentClass )
                $report['created_classes'][] = array( 'id' => (int)$class->attribute( 'id' ), 'identifier' => $class->attribute( 'identifier' ), 'name' => $class->attribute( 'name' ) );
        }
        foreach ( $beforeObjects as $remoteID )
        {
            eZContentObject::clearCache();
            $object = eZContentObject::fetchByRemoteID( $remoteID );
            if ( $object instanceof eZContentObject )
            {
                $mainNode = $object->attribute( 'main_node' );
                $report['created_objects'][] = array(
                    'id'      => (int)$object->attribute( 'id' ),
                    'name'    => $object->name(),
                    'node_id' => $mainNode instanceof eZContentObjectTreeNode ? (int)$mainNode->attribute( 'node_id' ) : null,
                );
            }
        }

        $report['ok'] = (bool)$ok;
        if ( !$ok )
            $report['errors'][] = 'One or more package items failed to install; see the debug log for the exact item. Items that did install are listed above.';

        return $report;
    }

    // ------------------------------------------------------------ template / sample package builder

    public static function templateVariants()
    {
        return array(
            'class'   => 'Class only',
            'content' => 'Content only',
            'both'    => 'Class + content',
        );
    }

    /**
     * Builds a real, installable sample package for one content class: the
     * class definition itself (variant class/both) and/or 2-3 real content
     * objects with a valid value for every importable datatype the class has
     * (variant content/both). The sample objects are created for real, added
     * to the package through the kernel handlers exactly as package/create
     * does, and then removed again - nothing from this call is left in the
     * content tree.
     */
    public static function buildTemplatePackage( $classID, $variant, array $options = array() )
    {
        $result = array( 'ok' => false, 'errors' => array(), 'package' => null, 'sample_object_ids' => array(), 'scratch_location' => null );

        $variant = in_array( $variant, array( 'class', 'content', 'both' ), true ) ? $variant : 'both';
        $class = ctype_digit( (string)$classID ) ? eZContentClass::fetch( (int)$classID ) : eZContentClass::fetchByIdentifier( $classID );
        if ( !$class instanceof eZContentClass )
        {
            $result['errors'][] = "No such class: $classID";
            return $result;
        }
        $classID = (int)$class->attribute( 'id' );
        $classIdentifier = $class->attribute( 'identifier' );
        $needsContent = $variant !== 'class';
        $needsOwnClassItem = $variant === 'class';

        $languages = self::sampleLanguages( $options );
        $objectCount = isset( $options['object_count'] ) ? max( 1, min( 5, (int)$options['object_count'] ) ) : 3;

        $createdObjectIDs = array();
        $createdNodeIDs = array();
        $scratchFolderNodeID = false;

        if ( $needsContent )
        {
            $scratchNodeID = isset( $options['scratch_node_id'] ) ? (int)$options['scratch_node_id'] : self::defaultScratchNodeID();
            $scratchNode = eZContentObjectTreeNode::fetch( $scratchNodeID );
            if ( !$scratchNode instanceof eZContentObjectTreeNode || !$scratchNode->checkAccess( 'create', $classID ) )
            {
                $result['errors'][] = "Cannot create sample content of class '$classIdentifier' below node $scratchNodeID (export.ini [PackageTemplate] ScratchNodeID, or content.ini [NodeSettings] MediaRootNode): no such node, or no create permission.";
                return $result;
            }

            // A temporary, explicitly hidden folder below the scratch node, so the sample
            // objects are not merely unrendered but hidden for the seconds they exist; if
            // this site has no creatable 'folder' class here, they go straight below the
            // scratch node instead (still never the public front page).
            $scratchFolderNodeID = self::createScratchFolder( $scratchNodeID, $languages[0], $result['errors'] );
            $creationNodeID = $scratchFolderNodeID ?: $scratchNodeID;

            $created = self::createSampleObjects( $class, $objectCount, $languages, $creationNodeID, $result['errors'] );
            if ( !$created )
            {
                if ( $scratchFolderNodeID )
                    eZContentObjectTreeNode::removeSubtrees( array( $scratchFolderNodeID ), false );
                $result['errors'][] = "Could not create any sample content object for class '$classIdentifier'; see the errors above.";
                return $result;
            }
            $createdObjectIDs = $created['object_ids'];
            $createdNodeIDs = $created['node_ids'];
            $result['scratch_location'] = array(
                'node_id'           => $creationNodeID,
                'base_node_id'      => $scratchNodeID,
                'base_path'         => $scratchNode->attribute( 'path_identification_string' ) ?: ( 'node ' . $scratchNodeID ),
                'used_hidden_folder' => (bool)$scratchFolderNodeID,
            );
        }

        $packageName = isset( $options['package_name'] ) && $options['package_name'] !== ''
                     ? preg_replace( '/[^A-Za-z0-9_.-]+/', '_', $options['package_name'] )
                     : self::uniquePackageName( 'xrowextract_template_' . $classIdentifier . '_' . $variant );

        $variantLabel = array( 'class' => 'the content class only', 'content' => 'sample content only (the class must already exist on the installing site)', 'both' => 'the content class and matching sample content' );
        $package = eZPackage::create( $packageName, array(
            'summary'     => "Content-package template for '$classIdentifier': " . $variantLabel[$variant],
            'description' => "Generated by the xrowextract Package Template builder.\n" .
                              "Class: $classIdentifier (" . $class->name() . ")\n" .
                              "Variant: $variant\n" .
                              ( $needsContent ? 'Sample objects: ' . $objectCount . ', languages: ' . implode( ', ', $languages ) . "\n" : '' ) .
                              "Every attribute of the class that this extension's import feature can write was filled with a real, valid sample value; " .
                              "attributes with no import handler for this class keep their class default. " .
                              "Install with xrowextract/package (or kernel package/install) and choose where the sample content is placed.",
            'vendor'      => 'xrowextract',
            'licence'     => 'GPL-2.0-or-later',
        ) );
        $package->setRelease( '1.0', '1', time(), 'GPL-2.0-or-later', 'stable' );
        $package->appendMaintainer( 'xrowextract Package template', '', 'developer' );
        $package->appendChange( 'xrowextract Package template', '', array( "Generated a '$variant' sample package for class '$classIdentifier'." ) );
        $package->appendProvides( 'ezcontentclass', $classIdentifier, $class->attribute( 'remote_id' ) );
        if ( $needsContent )
            $package->appendDependency( 'requires', array( 'type' => 'ezcontentclass', 'name' => $classIdentifier, 'value' => $class->attribute( 'remote_id' ) ) );

        if ( $needsOwnClassItem )
        {
            eZContentClassPackageHandler::addClass( $package, $classID );
        }

        if ( $needsContent )
        {
            $objectHandler = eZPackage::packageHandler( 'ezcontentobject' );
            foreach ( $createdNodeIDs as $nodeID )
                $objectHandler->addNode( $nodeID, false );
            $objectHandler->generatePackage( $package, array(
                'include_classes'      => ( $variant === 'both' ),
                'include_templates'    => false,
                'site_access_array'    => array(),
                'versions'             => 'current',
                'language_array'       => $languages,
                'node_assignment'      => 'selected',
                'related_objects'      => 'selected',
                'embed_objects'        => 'selected',
            ) );
        }

        $package->setAttribute( 'is_active', true );
        $package->store();

        // Removing the hidden scratch folder takes its children (the sample objects) with
        // it in one call; without a folder, remove the sample objects directly. Either way
        // $moveToTrash = false: a final removal, not a trip through the trash can, and the
        // kernel's own remove path clears the search index and URL aliases for the removed
        // ids along with it (checked in this extension's sandbox tests).
        if ( $scratchFolderNodeID )
            eZContentObjectTreeNode::removeSubtrees( array( $scratchFolderNodeID ), false );
        elseif ( $createdNodeIDs )
            eZContentObjectTreeNode::removeSubtrees( $createdNodeIDs, false );

        $result['ok'] = true;
        $result['package'] = $package;
        $result['sample_object_ids'] = $createdObjectIDs;
        return $result;
    }

    protected static function sampleLanguages( array $options )
    {
        if ( !empty( $options['languages'] ) && is_array( $options['languages'] ) )
            return array_values( $options['languages'] );
        $languages = array_keys( XrowExtractColumns::contentLanguages() );
        if ( !$languages )
            $languages = array( 'eng-US' );
        // At most two: the default/first, plus one more to show a real translation.
        return array_slice( $languages, 0, 2 );
    }

    /**
     * Where the template builder's sample objects are created while a package
     * is being built. Never the public site's front page: export.ini
     * [PackageTemplate] ScratchNodeID if set, otherwise content.ini
     * [NodeSettings] MediaRootNode of the default siteaccess - a location no
     * shipped layout, search index or the static/content-view cache renders
     * for a visitor, unlike RootNode.
     */
    public static function defaultScratchNodeID()
    {
        $exportINI = eZINI::instance( 'export.ini' );
        if ( $exportINI->hasVariable( 'PackageTemplate', 'ScratchNodeID' ) )
        {
            $configured = trim( (string)$exportINI->variable( 'PackageTemplate', 'ScratchNodeID' ) );
            if ( $configured !== '' )
                return (int)$configured;
        }
        $publicContentINI = eZSiteAccess::getIni( eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ), 'content.ini' );
        return (int)$publicContentINI->variable( 'NodeSettings', 'MediaRootNode' );
    }

    /** Node id and path of where a template build's sample objects would be created, for display before/after a build. */
    public static function scratchLocationInfo( $nodeID = false )
    {
        $nodeID = $nodeID ? (int)$nodeID : self::defaultScratchNodeID();
        $node = eZContentObjectTreeNode::fetch( $nodeID );
        $path = $node instanceof eZContentObjectTreeNode ? $node->attribute( 'path_identification_string' ) : false;
        return array(
            'node_id' => $nodeID,
            'path'    => $path ?: ( 'node ' . $nodeID ),
            'exists'  => $node instanceof eZContentObjectTreeNode,
        );
    }

    /**
     * Creates a temporary, hidden "scratch" folder below $parentNodeID to hold
     * a template build's sample objects, so even the seconds they exist they
     * are not merely unrendered but explicitly hidden. Returns the new
     * folder's node id, or false if this site has no creatable 'folder' class
     * here (the caller then creates the sample objects directly below
     * $parentNodeID instead - still not the public front page, just without
     * the extra hidden layer).
     */
    protected static function createScratchFolder( $parentNodeID, $language, array &$errors )
    {
        $folderClass = eZContentClass::fetchByIdentifier( 'folder' );
        if ( !$folderClass instanceof eZContentClass )
            return false;
        $folderClassID = (int)$folderClass->attribute( 'id' );
        $parentNode = eZContentObjectTreeNode::fetch( (int)$parentNodeID );
        if ( !$parentNode instanceof eZContentObjectTreeNode || !$parentNode->checkAccess( 'create', $folderClassID ) )
            return false;

        $classAttributes = eZContentClassAttribute::fetchListByClassID( $folderClassID, eZContentClass::VERSION_STATUS_DEFINED, true );
        $remoteID = 'xrowextract-pkgtpl-scratch-' . date( 'YmdHis' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
        list( $row, $mapping ) = self::sampleRow( $classAttributes, 'folder', 1, array( 1 => $remoteID ), $parentNodeID, $language );

        $runResult = XrowExtractImport::run( array(
            'rows'         => array( $row ),
            'mapping'      => $mapping,
            'classID'      => $folderClassID,
            'match'        => 'remote_id',
            'language'     => $language,
            'parentNodeID' => $parentNodeID,
            'apply'        => true,
        ) );
        $rowResult = $runResult['rows'][0];
        if ( $rowResult['action'] === 'error' || !$rowResult['object_id'] )
        {
            $errors[] = 'scratch folder (not fatal, sample objects go directly below the scratch node instead): ' . $rowResult['reason'];
            return false;
        }

        eZContentObject::clearCache( array( (int)$rowResult['object_id'] ) );
        $object = eZContentObject::fetch( (int)$rowResult['object_id'] );
        $mainNode = $object instanceof eZContentObject ? $object->attribute( 'main_node' ) : null;
        if ( !$mainNode instanceof eZContentObjectTreeNode )
            return false;
        $folderNodeID = (int)$mainNode->attribute( 'node_id' );

        // Hidden immediately: eZContentOperationCollection::changeHideStatus() is the same
        // call kernel/content/action.php's HideButton uses, toggling is_hidden on a node
        // that starts visible (a freshly published node always does).
        if ( class_exists( 'eZContentOperationCollection' ) )
            eZContentOperationCollection::changeHideStatus( $folderNodeID );

        return $folderNodeID;
    }

    /** $base, or $base_2, $base_3, ... the first one not already in the local package repository. */
    protected static function uniquePackageName( $base )
    {
        $base = preg_replace( '/[^A-Za-z0-9_.-]+/', '_', $base );
        $name = $base;
        $suffix = 1;
        while ( eZPackage::fetch( $name, false, 'local', false ) )
        {
            $suffix++;
            $name = $base . '_' . $suffix;
        }
        return $name;
    }

    /** Creates $count real content objects of $class below $scratchNodeID, with a value for every importable attribute. Returns false if none could be created. */
    protected static function createSampleObjects( eZContentClass $class, $count, array $languages, $scratchNodeID, array &$errors )
    {
        $classID = (int)$class->attribute( 'id' );
        $classIdentifier = $class->attribute( 'identifier' );
        $classAttributes = eZContentClassAttribute::fetchListByClassID( $classID, eZContentClass::VERSION_STATUS_DEFINED, true );
        $primaryLanguage = $languages[0];
        $stamp = date( 'YmdHis' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );

        $remoteIDs = array();
        for ( $i = 1; $i <= $count; $i++ )
            $remoteIDs[$i] = 'xrowextract-pkgtpl-' . $classIdentifier . '-' . $stamp . '-' . $i;

        $objectIDs = array();
        for ( $i = 1; $i <= $count; $i++ )
        {
            list( $row, $mapping ) = self::sampleRow( $classAttributes, $classIdentifier, $i, $remoteIDs, $scratchNodeID, $primaryLanguage );
            $runResult = XrowExtractImport::run( array(
                'rows'         => array( $row ),
                'mapping'      => $mapping,
                'classID'      => $classID,
                'match'        => 'remote_id',
                'language'     => $primaryLanguage,
                'parentNodeID' => $scratchNodeID,
                'apply'        => true,
            ) );
            $rowResult = $runResult['rows'][0];
            if ( $rowResult['action'] === 'error' || !$rowResult['object_id'] )
            {
                $errors[] = "sample object $i: " . $rowResult['reason'];
                continue;
            }
            $objectIDs[] = (int)$rowResult['object_id'];

            foreach ( array_slice( $languages, 1 ) as $extraLanguage )
            {
                list( $row2, $mapping2 ) = self::sampleRow( $classAttributes, $classIdentifier, $i, $remoteIDs, $scratchNodeID, $extraLanguage );
                $runResult2 = XrowExtractImport::run( array(
                    'rows'         => array( $row2 ),
                    'mapping'      => $mapping2,
                    'classID'      => $classID,
                    'match'        => 'remote_id',
                    'language'     => $extraLanguage,
                    'parentNodeID' => $scratchNodeID,
                    'apply'        => true,
                ) );
                if ( $runResult2['rows'][0]['action'] === 'error' )
                    $errors[] = "sample object $i, translation $extraLanguage: " . $runResult2['rows'][0]['reason'];
            }
        }

        $nodeIDs = array();
        foreach ( $objectIDs as $objectID )
        {
            eZContentObject::clearCache( array( $objectID ) );
            $object = eZContentObject::fetch( $objectID );
            if ( $object instanceof eZContentObject )
            {
                $mainNode = $object->attribute( 'main_node' );
                if ( $mainNode instanceof eZContentObjectTreeNode )
                    $nodeIDs[] = (int)$mainNode->attribute( 'node_id' );
            }
        }

        if ( !$objectIDs )
            return false;
        return array( 'object_ids' => $objectIDs, 'node_ids' => $nodeIDs );
    }

    /** One row + mapping (as XrowExtractImport::run() expects) with a real sample value for every attribute this importer can write. */
    protected static function sampleRow( array $classAttributes, $classIdentifier, $index, array $remoteIDs, $scratchNodeID, $language )
    {
        $row = array();
        $mapping = array();
        $column = 0;
        $add = function ( $target, $value ) use ( &$row, &$mapping, &$column )
        {
            $key = 'c' . $column++;
            $row[$key] = $value;
            $mapping[] = array( 'column' => $key, 'target' => $target );
        };

        $add( 'special:ezcontentobject.remote_id', $remoteIDs[$index] );
        $add( 'special:ezcontentobject.class_identifier', $classIdentifier );
        $add( 'special:ezcontentobject.language', $language );
        $add( 'special:ezcontentobject.main_parent_node_id', (string)$scratchNodeID );

        foreach ( $classAttributes as $classAttribute )
        {
            $identifier = $classAttribute->attribute( 'identifier' );
            $datatype = $classAttribute->attribute( 'data_type_string' );
            $sample = self::sampleAttributeValue( $classAttribute, $datatype, $index, $classIdentifier, $remoteIDs, $language );
            if ( $sample === null )
                continue;
            $target = $sample['kind'] === 'attrfmt' ? ( 'attrfmt:' . $identifier . ':' . $sample['format'] ) : ( 'attr:' . $identifier );
            $add( $target, $sample['value'] );
        }

        return array( $row, $mapping );
    }

    /** A real, valid value for one class attribute's datatype, or null to leave the class default (no import handler, or not applicable for this row). */
    protected static function sampleAttributeValue( eZContentClassAttribute $classAttribute, $datatype, $index, $classIdentifier, array $remoteIDs, $language )
    {
        $label = $classAttribute->attribute( 'name' );
        switch ( $datatype )
        {
            case 'ezstring':
                return array( 'kind' => 'attr', 'value' => "Sample $label $index ($language)" );
            case 'eztext':
                return array( 'kind' => 'attr', 'value' => "Sample body text for $label, item $index ($language). Generated by the xrowextract Package template builder to show a real, valid value for this datatype." );
            case 'ezinteger':
                return array( 'kind' => 'attr', 'value' => (string)( 10 * $index ) );
            case 'ezfloat':
                return array( 'kind' => 'attr', 'value' => sprintf( '%.2f', 3.5 * $index ) );
            case 'ezboolean':
                return array( 'kind' => 'attr', 'value' => (string)( $index % 2 ) );
            case 'ezemail':
                return array( 'kind' => 'attr', 'value' => "sample{$index}-{$language}@example.com" );
            case 'ezidentifier':
                return array( 'kind' => 'attr', 'value' => "sample-{$classIdentifier}-{$index}" );
            case 'ezurl':
                return array( 'kind' => 'attr', 'value' => "https://example.com/sample-{$index}-{$language}" );
            case 'ezdate':
                return array( 'kind' => 'attr', 'value' => date( 'Y-m-d', strtotime( "+{$index} day" ) ) );
            case 'ezdatetime':
                return array( 'kind' => 'attr', 'value' => date( 'Y-m-d H:i:s', strtotime( "+{$index} hour" ) ) );
            case 'ezselection':
                $content = $classAttribute->content();
                $options = isset( $content['options'] ) ? $content['options'] : array();
                if ( !$options )
                    return null;
                $keys = array_keys( $options );
                $pick = $keys[( $index - 1 ) % count( $keys )];
                return array( 'kind' => 'attrfmt', 'format' => 'ids', 'value' => (string)$pick );
            case 'ezkeyword':
                return array( 'kind' => 'attr', 'value' => "sample, keyword $index $language" );
            case 'eztags':
                return array( 'kind' => 'attr', 'value' => "Sample tag A $language, Sample tag $index $language" );
            case 'ezxmltext':
                return array( 'kind' => 'attr', 'value' => "<p>Sample rich text paragraph $index for $label ($language).</p>" );
            case 'ezimage':
                return array( 'kind' => 'attr', 'value' => self::sampleAssetPath( 'sample-image.jpg' ) );
            case 'ezbinaryfile':
            case 'ezmedia':
                return array( 'kind' => 'attr', 'value' => self::sampleAssetPath( 'sample-document.txt' ) );
            case 'ezobjectrelation':
            case 'ezobjectrelationlist':
                if ( $index <= 1 )
                    return null;
                if ( !self::classAllowsRelation( $classAttribute, $classIdentifier ) )
                    return null;
                return array( 'kind' => 'attrfmt', 'format' => 'remote_ids', 'value' => $remoteIDs[1] );
        }
        return null;
    }

    /** Whether an ezobjectrelation(list) attribute's class restriction (if any) allows relating to $classIdentifier itself. */
    protected static function classAllowsRelation( eZContentClassAttribute $classAttribute, $classIdentifier )
    {
        $content = $classAttribute->content();
        $list = isset( $content['class_constraint_list'] ) ? (array)$content['class_constraint_list'] : array();
        return !$list || in_array( $classIdentifier, $list, true );
    }

    protected static function sampleAssetPath( $name )
    {
        return dirname( __FILE__ ) . '/../share/sample/' . $name;
    }
}
