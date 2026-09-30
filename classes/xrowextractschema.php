<?php

/**
 * The extension's own tables (xrowextract_schedule, xrowextract_destination, xrowextract_history),
 * created lazily and idempotently the first time one of them is written, from share/db_schema.dba
 * through the kernel's own schema handler for the database in use (dbschema.ini: mysql, postgresql,
 * sqlite, and oracle when the ezoracle extension provides its handler). An engine without a schema
 * handler falls back to extension/xrowextract/sql/<engine>/schema.sql. Reading never creates anything:
 * exists() is false until the first write, and every reader treats that as "nothing yet".
 */
class XrowExtractSchema
{
    const TABLES = array( 'xrowextract_schedule', 'xrowextract_destination', 'xrowextract_history' );

    protected static $ready = null;

    /** The .dba file (the canonical definition). */
    public static function dbaPath()
    {
        return dirname( __FILE__ ) . '/../share/db_schema.dba';
    }

    /** The tables of this database, lower case. */
    protected static function existingTables( eZDBInterface $db )
    {
        $list = $db->relationList();
        if ( !is_array( $list ) || !$list )
            $list = array_keys( (array)$db->eZTableList() );
        return array_map( 'strtolower', (array)$list );
    }

    /**
     * Whether every table exists already (never creates one). Asks the database until the answer is yes;
     * ensure() relies on asking again after it created the tables.
     *
     * @phpstan-impure
     */
    public static function exists()
    {
        if ( self::$ready === true )
            return true;
        $db = eZDB::instance();
        $have = self::existingTables( $db );
        foreach ( self::TABLES as $table )
        {
            if ( !in_array( $table, $have, true ) )
                return false;
        }
        return self::$ready = true;
    }

    /** Creates whatever table is missing. True when all of them exist afterwards. */
    public static function ensure()
    {
        if ( self::exists() )
            return true;
        $db = eZDB::instance();
        $schema = eZDbSchema::read( self::dbaPath(), false );
        if ( !is_array( $schema ) )
        {
            eZDebug::writeError( 'Cannot read ' . self::dbaPath(), __METHOD__ );
            return false;
        }
        $have = self::existingTables( $db );
        $missing = array( '_info' => isset( $schema['_info'] ) ? $schema['_info'] : array( 'format' => 'generic' ) );
        foreach ( self::TABLES as $table )
        {
            if ( !in_array( $table, $have, true ) && isset( $schema[$table] ) )
                $missing[$table] = $schema[$table];
        }
        $handler = @eZDbSchema::instance( array( 'instance' => $db, 'schema' => $missing ) );
        $ok = false;
        if ( $handler )
        {
            $ok = $handler->insertSchema( array( 'schema' => true, 'data' => false ) );
        }
        else
        {
            $ok = self::runSQLFile( $db, array_keys( $missing ) );
        }
        self::$ready = null;
        if ( !$ok || !self::exists() )
        {
            eZDebug::writeError( 'Could not create the xrowextract tables (' . implode( ', ', array_diff( array_keys( $missing ), array( '_info' ) ) ) . ')', __METHOD__ );
            return false;
        }
        return true;
    }

    /** The schema.sql fallback for an engine without a schema handler: only the statements of $tables. */
    protected static function runSQLFile( eZDBInterface $db, array $tables )
    {
        $engine = $db->databaseName();
        $file = dirname( __FILE__ ) . '/../sql/' . $engine . '/schema.sql';
        if ( !is_file( $file ) )
            return false;
        $sql = (string)file_get_contents( $file );
        foreach ( preg_split( '/;\s*\n/', $sql ) as $statement )
        {
            $statement = trim( preg_replace( '/^--.*$/m', '', $statement ) );
            if ( $statement === '' )
                continue;
            $concerns = false;
            foreach ( $tables as $table )
                $concerns = $concerns || stripos( $statement, $table ) !== false;
            if ( $concerns && !$db->query( $statement ) )
                return false;
        }
        return true;
    }
}

?>
