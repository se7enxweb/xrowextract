<?php
/**
 * The entry parameters of xrowextract/csv: the content tree's Advanced > Export menu opens the CSV form pre-filled
 * for a node with xrowextract/csv/(node)/<node id>[/(scope)/list|tree][/(class)/<class id>][/(columns)/all|migration].
 * The class page's "Export the objects of this class" (the transfer registry) gives a class alone,
 * xrowextract/csv/(class)/<class id>: the form keeps the node it had and switches to that class.
 * The view only fills its form with them (and redirects to itself); the export is started there by the user.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package xrowextract
 */

class XrowExtractEntry
{
    /** The scopes an entry may ask for: the node's children (list) or its whole subtree (tree) */
    const SCOPES = array( 'list', 'tree' );

    /** The columns an entry may ask for: every attribute of the class, or the column set "migration" */
    const COLUMNS = array( 'all', 'migration' );

    /** The view parameters (module.php unordered_params) of an entry */
    const PARAMETERS = array( 'EntryNode', 'EntryScope', 'EntryClass', 'EntryColumns' );

    /** How many classes the menu lists below a node (xrowextract/subtree_classes); more are reached in the form */
    const CLASS_LIST_LIMIT = 12;

    /**
     * Whether the view was opened with entry parameters (any of them given).
     *
     * @param array<string, mixed> $params the view's parameters (false for an unordered parameter not given)
     */
    public static function isEntry( array $params ): bool
    {
        foreach ( self::PARAMETERS as $name )
        {
            if ( isset( $params[$name] ) && $params[$name] !== false )
                return true;
        }
        return false;
    }

    /**
     * Reads and checks the entry parameters. The node is required unless a class is given (node 0: the form keeps
     * its node); the scope is the subtree when not given; the class is 0 (the form chooses) and the columns '' (the
     * form's columns) when not given.
     *
     * @param array<string, mixed> $params the view's parameters
     * @return array{node: int, scope: string, class: int, columns: string, errors: array<int, string>}
     */
    public static function parse( array $params ): array
    {
        $value = function ( string $name ) use ( $params ): ?string
        {
            return isset( $params[$name] ) && $params[$name] !== false && is_scalar( $params[$name] ) ? (string)$params[$name] : null;
        };
        $errors = array();

        $node = self::positiveID( $value( 'EntryNode' ) );
        if ( $node === 0 && ( $value( 'EntryNode' ) !== null || $value( 'EntryClass' ) === null ) )
            $errors[] = 'node';

        $scope = $value( 'EntryScope' ) ?? 'tree';
        if ( !in_array( $scope, self::SCOPES, true ) )
        {
            $errors[] = 'scope';
            $scope = 'tree';
        }

        $class = 0;
        $classValue = $value( 'EntryClass' );
        if ( $classValue !== null )
        {
            $class = self::positiveID( $classValue );
            if ( $class === 0 )
                $errors[] = 'class';
        }

        $columns = $value( 'EntryColumns' ) ?? '';
        if ( $columns !== '' && !in_array( $columns, self::COLUMNS, true ) )
        {
            $errors[] = 'columns';
            $columns = '';
        }

        return array( 'node' => $node, 'scope' => $scope, 'class' => $class, 'columns' => $columns, 'errors' => $errors );
    }

    /**
     * The import target of xrowextract/import/(parent)/<node id> (the node view's Import/Export menu): the node id,
     * or 0 when the value is not a valid id.
     *
     * @param array<string, mixed> $params the view's parameters (EntryParent)
     */
    public static function importParent( array $params ): int
    {
        return isset( $params['EntryParent'] ) && is_string( $params['EntryParent'] ) ? self::positiveID( $params['EntryParent'] ) : 0;
    }

    /**
     * Whether xrowextract/import was opened with an import target (given, valid or not).
     *
     * @param array<string, mixed> $params the view's parameters
     */
    public static function isImportEntry( array $params ): bool
    {
        return isset( $params['EntryParent'] ) && $params['EntryParent'] !== false;
    }

    /**
     * The refusal of an import target as found: 'not_found' (no such node), 'access_denied' (a node the user cannot
     * read, or below which the user may not create content: not a container, or no content/create there), null
     * when the import may start there.
     */
    public static function importRefusal( bool $nodeExists, bool $nodeReadable, bool $isContainer, bool $canCreate ): ?string
    {
        if ( !$nodeExists )
            return 'not_found';
        if ( !$nodeReadable || !$isContainer || !$canCreate )
            return 'access_denied';
        return null;
    }

    /**
     * A database id given as text: 1 to 2147483647 in plain digits (no sign, space or leading zero), else 0.
     *
     * @param mixed $value
     */
    public static function positiveID( $value ): int
    {
        if ( is_int( $value ) )
            return $value > 0 && $value <= 2147483647 ? $value : 0;
        if ( !is_string( $value ) || $value === '' || strlen( $value ) > 10 || !ctype_digit( $value ) || $value[0] === '0' )
            return 0;
        return (int)$value <= 2147483647 ? (int)$value : 0;
    }

    /**
     * The refusal of an entry for a node and class as found: 'not_found' (no such node or class) or 'access_denied'
     * (a node the user cannot read), null when the form may be filled.
     */
    public static function refusal( bool $nodeExists, bool $nodeReadable, bool $classWanted, bool $classExists ): ?string
    {
        if ( !$nodeExists || ( $classWanted && !$classExists ) )
            return 'not_found';
        if ( !$nodeReadable )
            return 'access_denied';
        return null;
    }

    /**
     * Whether a class may be exported: export.ini [ExportSettings] ExportClasses[] names it by id or identifier, or
     * is empty (every class), as the form's class list reads it.
     *
     * @param array<int|string, mixed> $exportClasses
     */
    public static function classAllowed( int $id, string $identifier, array $exportClasses ): bool
    {
        $exportClasses = array_filter( $exportClasses, function ( $value ): bool { return $value !== '' && $value !== null && $value !== false; } );
        if ( !$exportClasses )
            return true;
        foreach ( $exportClasses as $allowed )
        {
            if ( is_scalar( $allowed ) && ( (string)$allowed === (string)$id || (string)$allowed === $identifier ) )
                return true;
        }
        return false;
    }

    /**
     * The view's saved settings (session eZExtractConfig) with the entry filled in: the node, the scope, the class
     * (or none: the form then starts from export.ini DefaultClassID or the class with the most objects), no filters
     * and no offset, and the columns asked for, which the view applies once it knows the class (EntryColumns).
     * The output settings (format, separator, languages ...) stay as the user left them.
     *
     * @param array<string, mixed> $config
     * @param array{node: int, scope: string, class: int, columns: string, errors: array<int, string>} $entry
     * @return array<string, mixed>
     */
    public static function applyToConfig( array $config, array $entry ): array
    {
        if ( $entry['node'] > 0 )
            $config['Subtree'] = $entry['node'];
        $config['Scope'] = $entry['scope'];
        if ( $entry['class'] > 0 )
            $config['Class_id'] = $entry['class'];
        else
            unset( $config['Class_id'] );
        unset( $config['Filters'], $config['EntryColumns'] );
        $config['Offset'] = 0;
        if ( $entry['columns'] !== '' )
            $config['EntryColumns'] = $entry['columns'];
        return $config;
    }

    /**
     * The classes below a node for the menu: the counts as found (class id => count), most objects first, then by
     * name; at most $limit of them, and whether there are more.
     *
     * @param array<int, int> $counts class id => count (classes without objects are left out)
     * @param array<int, array{identifier: string, name: string}> $classes class id => identifier and name
     * @return array{classes: array<int, array{id: int, identifier: string, name: string, count: int}>, more: bool, total: int}
     */
    public static function classList( array $counts, array $classes, int $limit = self::CLASS_LIST_LIMIT ): array
    {
        $list = array();
        foreach ( $counts as $id => $count )
        {
            if ( $count <= 0 || !isset( $classes[$id] ) )
                continue;
            $list[] = array( 'id' => (int)$id, 'identifier' => (string)$classes[$id]['identifier'],
                             'name' => (string)$classes[$id]['name'], 'count' => (int)$count );
        }
        usort( $list, function ( array $a, array $b ): int
        {
            return $b['count'] <=> $a['count'] ?: strcasecmp( $a['name'], $b['name'] ) ?: $a['id'] <=> $b['id'];
        } );
        $total = count( $list );
        return array( 'classes' => array_slice( $list, 0, max( 0, $limit ) ), 'more' => $total > $limit, 'total' => $total );
    }
}

?>
