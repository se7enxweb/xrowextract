<?php

use PHPUnit\Framework\TestCase;

/**
 * XrowExtractSchema::tablesOf(): the extension's tables are seen on every engine, also on SQLite, whose driver lists
 * only the tables that start with "ez" (ensure() then created them again on every write and failed).
 */
class SchemaTablesTest extends TestCase
{
    /** A database stand-in: its engine name, what a query returns, what relationList() returns. */
    /**
     * @param list<array<string, string>>|null $rows
     * @param list<string> $relations
     */
    private function db( string $type, ?array $rows, array $relations ): eZDBInterface
    {
        return new class( $type, $rows, $relations ) extends eZDBInterface {
            /** @var list<string> */
            public $queries = array();
            /** @var string */
            private $kind;
            /** @var list<array<string, string>>|null */
            private $rows;
            /** @var list<string> */
            private $relations;
            /**
             * @param list<array<string, string>>|null $rows
             * @param list<string> $relations
             */
            public function __construct( string $kind, ?array $rows, array $relations )
            {
                $this->kind = $kind;
                $this->rows = $rows;
                $this->relations = $relations;
            }
            function databaseName() { return $this->kind; }
            function arrayQuery( $sql, $params = array(), $server = false ) { $this->queries[] = $sql; return $this->rows; }
            function relationList( $relationType = eZDBInterface::RELATION_TABLE ) { return $this->relations; }
            function eZTableList( $server = self::SERVER_MASTER ) { return array_fill_keys( $this->relations, 1 ); }
        };
    }

    public function testSqliteIsAskedThroughSqliteMaster(): void
    {
        // the SQLite driver's relationList(): only ez* tables
        $db = $this->db( 'sqlite', array( array( 'name' => 'xrowextract_schedule' ), array( 'name' => 'xrowextract_history' ), array( 'NAME' => 'xrowextract_destination' ) ),
                         array( 'ezcontentobject', 'ezuser' ) );
        $tables = XrowExtractSchema::tablesOf( $db );
        $this->assertSame( array( 'xrowextract_schedule', 'xrowextract_history', 'xrowextract_destination' ), $tables );
        $this->assertStringContainsString( 'sqlite_master', $db->queries[0] );
        $this->assertEmpty( array_diff( XrowExtractSchema::TABLES, $tables ), 'every table is seen' );
    }

    public function testPostgresqlIsAskedThroughInformationSchema(): void
    {
        $db = $this->db( 'postgresql', array( array( 'name' => 'XROWEXTRACT_SCHEDULE' ) ), array() );
        $this->assertSame( array( 'xrowextract_schedule' ), XrowExtractSchema::tablesOf( $db ) );
        $this->assertStringContainsString( 'information_schema.tables', $db->queries[0] );
    }

    public function testOtherEnginesUseRelationList(): void
    {
        $db = $this->db( 'mysql', null, array( 'ezuser', 'XROWEXTRACT_HISTORY' ) );
        $this->assertSame( array( 'ezuser', 'xrowextract_history' ), XrowExtractSchema::tablesOf( $db ) );
        $this->assertSame( array(), $db->queries, 'MySQL lists every table itself' );
    }

    public function testAFailedQueryFallsBackToTheKernelsList(): void
    {
        $db = $this->db( 'sqlite', null, array( 'ezuser' ) );
        $this->assertSame( array( 'ezuser' ), XrowExtractSchema::tablesOf( $db ) );
    }

    public function testNoTablesYet(): void
    {
        $db = $this->db( 'sqlite', array(), array( 'ezuser' ) );
        $this->assertSame( array(), XrowExtractSchema::tablesOf( $db ) );
    }
}
