<?php

/**
 * A scheduled export or import (table xrowextract_schedule, created lazily by XrowExtractSchema).
 *
 * kind:        preset (a saved CSV preset, own or site, with its placeholders filled per schedule),
 *              archive (a site archive run: set or nodes, classes, languages, file and archive format),
 *              package (Export as package: node, subtree, class),
 *              import (a recurring import of a file from a local path or a destination: a dry run first,
 *              applied only when the dry run reports no errors; both are recorded).
 * definition:  JSON, the kind's own settings (see XrowExtractScheduler::jobSpec()).
 * frequency:   JSON, XrowExtractCron's simple choice or an advanced expression; cron_expr is the
 *              expression it resolves to, next_run the next due time.
 * delta_mode:  full, or delta ("only changes since the last successful run", through the existing
 *              date filters; a run can still be started as full by hand).
 * notify:      JSON: failure_emails (the owner always gets failures), success (bool), success_emails,
 *              admin_notice (bool, default true), webhook_url.
 * retention:   JSON: files_days, history_days (empty: xrowextract.ini [Schedules] defaults).
 */
class XrowExtractSchedule extends eZPersistentObject
{
    /** @param array<string, mixed> $row */
    public function __construct( $row = array() )
    {
        parent::__construct( $row );
    }

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        $int = function ( $name, $default = 0 ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => $default, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'name' => $str( 'Name' ),
                'owner_login' => $str( 'OwnerLogin' ),
                'enabled' => $int( 'Enabled', 1 ),
                'kind' => $str( 'Kind' ),
                'definition' => $str( 'Definition', '{}' ),
                'frequency' => $str( 'Frequency', '{}' ),
                'cron_expr' => $str( 'CronExpr' ),
                'delta_mode' => $str( 'DeltaMode', 'full' ),
                'destination_ids' => $str( 'DestinationIDs' ),
                'notify' => $str( 'Notify', '{}' ),
                'retention' => $str( 'Retention', '{}' ),
                'last_run' => $int( 'LastRun' ),
                'last_success' => $int( 'LastSuccess' ),
                'next_run' => $int( 'NextRun' ),
                'last_job_id' => $str( 'LastJobID' ),
                'last_state' => $str( 'LastState' ),
                'created' => $int( 'Created' ),
                'modified' => $int( 'Modified' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'function_attributes' => array(
                'definition_array' => 'definitionArray',
                'frequency_array' => 'frequencyArray',
                'notify_array' => 'notifyArray',
                'retention_array' => 'retentionArray',
                'destination_id_list' => 'destinationIDList',
                'frequency_text' => 'frequencyText',
                'summary' => 'summary',
            ),
            'class_name' => 'XrowExtractSchedule',
            'sort' => array( 'id' => 'asc' ),
            'name' => 'xrowextract_schedule',
        );
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array( 'preset', 'archive', 'package', 'import' );
    }

    /**
     * @param mixed $json
     * @return array<mixed>
     */
    protected static function decode( $json ): array
    {
        $data = json_decode( (string)$json, true );
        return is_array( $data ) ? $data : array();
    }

    /** @return array<mixed> */
    public function definitionArray(): array { return self::decode( $this->attribute( 'definition' ) ); }
    /** @return array<mixed> */
    public function frequencyArray(): array { return self::decode( $this->attribute( 'frequency' ) ); }
    /** @return array<mixed> failure_emails, success, success_emails, admin_notice, webhook_url and whatever else is stored */
    public function notifyArray(): array
    {
        return array_merge( array( 'failure_emails' => '', 'success' => false, 'success_emails' => '', 'admin_notice' => true, 'webhook_url' => '' ),
                            self::decode( $this->attribute( 'notify' ) ) );
    }
    /** @return array<mixed> files_days, history_days */
    public function retentionArray(): array
    {
        return array_merge( array( 'files_days' => '', 'history_days' => '' ), self::decode( $this->attribute( 'retention' ) ) );
    }

    /** @return list<int> */
    public function destinationIDList(): array
    {
        return array_values( array_filter( array_map( 'intval', explode( ',', (string)$this->attribute( 'destination_ids' ) ) ) ) );
    }

    public function frequencyText(): string
    {
        return XrowExtractCron::describe( $this->frequencyArray(), $this->attribute( 'cron_expr' ) );
    }

    /**
     * What the schedule runs, in one line.
     *
     * @return string
     */
    public function summary()
    {
        $def = $this->definitionArray();
        switch ( $this->attribute( 'kind' ) )
        {
            case 'preset':
                $ref = isset( $def['preset'] ) ? $def['preset'] : '';
                return 'Preset ' . XrowExtractPreset::presetName( $ref ) . ( !empty( $def['params'] ) ? ' (' . self::paramsText( $def['params'] ) . ')' : '' );
            case 'archive':
                $what = !empty( $def['nodes'] ) ? 'nodes ' . implode( ', ', (array)$def['nodes'] ) : 'set ' . ( isset( $def['set'] ) ? $def['set'] : 'sites' );
                return 'Site archive: ' . $what . ( !empty( $def['classes'] ) ? ', classes ' . implode( ', ', (array)$def['classes'] ) : '' )
                       . ', ' . ( isset( $def['format'] ) ? $def['format'] : 'zip' );
            case 'package':
                return 'Package: node ' . ( isset( $def['node'] ) ? (int)$def['node'] : 0 ) . ( !empty( $def['subtree'] ) ? ' with its subtree' : '' )
                       . ( !empty( $def['class'] ) ? ', class ' . $def['class'] : '' );
            case 'import':
                $source = !empty( $def['destination_id'] ) ? ( 'destination #' . (int)$def['destination_id'] . ' ' . ( isset( $def['remote_path'] ) ? $def['remote_path'] : '' ) )
                                                           : ( isset( $def['local_path'] ) ? $def['local_path'] : '' );
                return 'Import from ' . $source . ( !empty( $def['class'] ) ? ', class ' . $def['class'] : '' );
        }
        return $this->attribute( 'kind' );
    }

    /** @param array<string, mixed> $params */
    public static function paramsText( array $params ): string
    {
        $parts = array();
        foreach ( $params as $key => $value )
            $parts[] = $key . '=' . $value;
        return implode( ', ', $parts );
    }

    /**
     * @param mixed $id
     * @return XrowExtractSchedule|null
     */
    public static function fetch( $id ): ?XrowExtractSchedule
    {
        $id = XrowExtractColumns::dbID( $id ); // never an id the database cannot even compare
        if ( !$id )
            return null;
        if ( !XrowExtractSchema::exists() )
            return null;
        $object = eZPersistentObject::fetchObject( self::definition(), null, array( 'id' => $id ) );
        return $object instanceof self ? $object : null;
    }

    /**
     * Every schedule (or only $login's), by name.
     *
     * @param string|null $login
     * @return array<int, XrowExtractSchedule>
     */
    public static function fetchList( $login = null ): array
    {
        if ( !XrowExtractSchema::exists() )
            return array();
        $conds = $login === null ? null : array( 'owner_login' => (string)$login );
        /** @var array<int, XrowExtractSchedule>|null $list the definition's class_name */
        $list = eZPersistentObject::fetchObjectList( self::definition(), null, $conds, array( 'name' => 'asc' ) );
        return is_array( $list ) ? $list : array();
    }

    /**
     * Enabled schedules due at $now.
     *
     * @param int $now
     * @return array<int, XrowExtractSchedule>
     */
    public static function fetchDue( $now ): array
    {
        if ( !XrowExtractSchema::exists() )
            return array();
        /** @var array<int, XrowExtractSchedule>|null $list the definition's class_name */
        $list = eZPersistentObject::fetchObjectList( self::definition(), null,
            array( 'enabled' => 1, 'next_run' => array( '<=', (int)$now ) ), array( 'next_run' => 'asc' ) );
        return is_array( $list ) ? $list : array();
    }

    /**
     * A new or changed schedule from the form/CLI values. Returns array( 'schedule' => ..., 'errors' => array ).
     * $values: name, kind, definition (array), frequency (array), delta_mode, destination_ids (array),
     * notify (array), retention (array), enabled.
     *
     * @param array<string, mixed> $values
     * @param string $ownerLogin
     * @return array{schedule: XrowExtractSchedule|null, errors: list<string>}
     */
    public static function saveFrom( array $values, $ownerLogin, ?XrowExtractSchedule $schedule = null ): array
    {
        $errors = array();
        $name = mb_substr( trim( (string)( isset( $values['name'] ) ? $values['name'] : '' ) ), 0, 150 );
        if ( $name === '' )
            $errors[] = 'A schedule needs a name.';
        $kind = isset( $values['kind'] ) ? (string)$values['kind'] : '';
        if ( !in_array( $kind, self::kinds(), true ) )
            $errors[] = 'Choose what the schedule runs.';
        $frequency = isset( $values['frequency'] ) && is_array( $values['frequency'] ) ? $values['frequency'] : array( 'kind' => 'daily', 'time' => '02:00' );
        $expression = XrowExtractCron::expressionFor( $frequency );
        if ( $expression === false )
            $errors[] = 'The cron expression is not valid: five fields, minute hour day-of-month month day-of-week.';
        $definition = isset( $values['definition'] ) && is_array( $values['definition'] ) ? $values['definition'] : array();
        $errors = array_merge( $errors, XrowExtractScheduler::validateDefinition( $kind, $definition ) );
        if ( $errors || $expression === false )
            return array( 'schedule' => $schedule, 'errors' => $errors );

        $notify = isset( $values['notify'] ) && is_array( $values['notify'] ) ? $values['notify'] : array();
        $retention = isset( $values['retention'] ) && is_array( $values['retention'] ) ? $values['retention'] : array();
        $json = array(
            'definition' => json_encode( $definition ),
            'frequency' => json_encode( $frequency ),
            'notify' => json_encode( array(
                'failure_emails' => self::cleanEmails( isset( $notify['failure_emails'] ) ? $notify['failure_emails'] : '' ),
                'success' => !empty( $notify['success'] ),
                'success_emails' => self::cleanEmails( isset( $notify['success_emails'] ) ? $notify['success_emails'] : '' ),
                'admin_notice' => !isset( $notify['admin_notice'] ) || !empty( $notify['admin_notice'] ),
                'webhook_url' => isset( $notify['webhook_url'] ) && preg_match( '#^https?://#i', trim( $notify['webhook_url'] ) ) ? trim( $notify['webhook_url'] ) : '',
            ) ),
            'retention' => json_encode( array(
                'files_days' => isset( $retention['files_days'] ) && (int)$retention['files_days'] > 0 ? (int)$retention['files_days'] : '',
                'history_days' => isset( $retention['history_days'] ) && (int)$retention['history_days'] > 0 ? (int)$retention['history_days'] : '',
            ) ),
        );
        // json_encode() refuses text that is not UTF-8: storing its false would silently empty that part of the schedule
        foreach ( $json as $encoded )
        {
            if ( $encoded === false )
                return array( 'schedule' => $schedule, 'errors' => array( 'A value of the schedule is not valid UTF-8 text and cannot be stored.' ) );
        }

        if ( !XrowExtractSchema::ensure() )
            return array( 'schedule' => $schedule, 'errors' => array( 'The schedule tables could not be created (see the debug log).' ) );
        $now = time();
        if ( !$schedule )
        {
            $schedule = new XrowExtractSchedule( array( 'owner_login' => (string)$ownerLogin, 'created' => $now ) );
        }
        $schedule->setAttribute( 'name', $name );
        $schedule->setAttribute( 'kind', $kind );
        $schedule->setAttribute( 'definition', $json['definition'] );
        $schedule->setAttribute( 'frequency', $json['frequency'] );
        $schedule->setAttribute( 'cron_expr', $expression );
        $schedule->setAttribute( 'delta_mode', isset( $values['delta_mode'] ) && $values['delta_mode'] === 'delta' ? 'delta' : 'full' );
        $ids = isset( $values['destination_ids'] ) ? array_values( array_unique( array_filter( array_map( 'intval', (array)$values['destination_ids'] ) ) ) ) : array();
        $schedule->setAttribute( 'destination_ids', implode( ',', $ids ) );
        $schedule->setAttribute( 'notify', $json['notify'] );
        $schedule->setAttribute( 'retention', $json['retention'] );
        $schedule->setAttribute( 'enabled', !isset( $values['enabled'] ) || !empty( $values['enabled'] ) ? 1 : 0 );
        $schedule->setAttribute( 'next_run', (int)XrowExtractCron::nextRun( $expression, $now ) );
        $schedule->setAttribute( 'modified', $now );
        $schedule->store();
        return array( 'schedule' => $schedule, 'errors' => array() );
    }

    /**
     * A comma/space/semicolon list of e-mail addresses, the valid ones only.
     *
     * @param mixed $text
     */
    public static function cleanEmails( $text ): string
    {
        $valid = array();
        foreach ( preg_split( '/[\s,;]+/', (string)$text, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $address )
        {
            if ( eZMail::validate( $address ) )
                $valid[] = $address;
        }
        return implode( ', ', array_unique( $valid ) );
    }

    /** @param bool $on */
    public function setEnabled( $on ): void
    {
        $this->setAttribute( 'enabled', $on ? 1 : 0 );
        if ( $on )
            $this->setAttribute( 'next_run', (int)XrowExtractCron::nextRun( $this->attribute( 'cron_expr' ), time() ) );
        $this->setAttribute( 'modified', time() );
        $this->store();
    }

    /**
     * Whether $login may see and change this schedule (the owner, or anyone with xrowextract/all_jobs).
     *
     * @param string $login
     * @param bool $allowAll
     */
    public function canEdit( $login, $allowAll ): bool
    {
        return $allowAll || $this->attribute( 'owner_login' ) === $login;
    }

    /**
     * The crontab line that runs this schedule from system cron instead of the cronjob part.
     *
     * @param string|null $phpBinary
     */
    public function crontabLine( $phpBinary = null ): string
    {
        $php = $phpBinary ?: ( XrowExtractJob::phpCliBinary() ?: 'php' );
        $access = eZSiteAccess::current();
        return $this->attribute( 'cron_expr' ) . ' cd ' . escapeshellarg( (string)eZSys::rootDir() ) . ' && ' . escapeshellarg( $php )
             . ' extension/xrowextract/bin/php/schedule.php --run=' . (int)$this->attribute( 'id' ) . ' --if-enabled'
             . ( $access && !empty( $access['name'] ) ? ' --siteaccess=' . $access['name'] : '' ) . ' >/dev/null 2>&1';
    }
}

?>
