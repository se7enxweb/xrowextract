<?php

/**
 * The export history index (table xrowextract_history): one row per run - a background job (started by
 * hand, from the command line or by a schedule) or a direct download. The job's own job.json stays the
 * full record while the job folder exists; this row outlives it (xrowextract.ini [History]
 * RetentionDays, overridable per schedule) and is what the History page queries and pages through.
 *
 * run_state: done, warning (done, with warnings), skipped (nothing left to export: what the schedule
 * refers to no longer exists), failed. delivery_state: '' (no destination), ok, partial, failed.
 */
class XrowExtractHistory extends eZPersistentObject
{
    public function __construct( $row = array() )
    {
        parent::__construct( $row );
    }

    public static function definition()
    {
        $int = function ( $name, $default = 0 ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => $default, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'job_id' => $str( 'JobID' ),
                'schedule_id' => $int( 'ScheduleID' ),
                'preset_ref' => $str( 'PresetRef' ),
                'owner_login' => $str( 'OwnerLogin' ),
                'kind' => $str( 'Kind' ),
                'what' => $str( 'What' ),
                'trigger_type' => $str( 'TriggerType' ),
                'run_mode' => $str( 'RunMode' ),
                'output_format' => $str( 'OutputFormat' ),
                'started_at' => $int( 'StartedAt' ),
                'ended_at' => $int( 'EndedAt' ),
                'run_state' => $str( 'RunState' ),
                'row_count' => $int( 'RowCount' ),
                'byte_size' => $int( 'ByteSize' ),
                'checksum' => $str( 'Checksum' ),
                'file_name' => $str( 'FileName' ),
                'destinations' => $str( 'Destinations' ),
                'delivery_state' => $str( 'DeliveryState' ),
                'delivery' => $str( 'Delivery' ),
                'warning_count' => $int( 'WarningCount' ),
                'warnings' => $str( 'Warnings' ),
                'error_text' => $str( 'ErrorText' ),
                'created' => $int( 'Created' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'function_attributes' => array(
                'warning_list' => 'warningList',
                'delivery_list' => 'deliveryList',
                'owner_user' => 'ownerUser',
                'schedule_name' => 'scheduleName',
                'duration_text' => 'durationText',
                'size_kb' => 'sizeKB',
                'job_exists' => 'jobExists',
            ),
            'class_name' => 'XrowExtractHistory',
            'sort' => array( 'created' => 'desc' ),
            'name' => 'xrowextract_history',
        );
    }

    public static function states()
    {
        return array( 'done', 'warning', 'skipped', 'failed' );
    }

    public function warningList()
    {
        $data = json_decode( (string)$this->attribute( 'warnings' ), true );
        return is_array( $data ) ? $data : array();
    }

    public function deliveryList()
    {
        $data = json_decode( (string)$this->attribute( 'delivery' ), true );
        return is_array( $data ) ? $data : array();
    }

    public function ownerUser()
    {
        return XrowExtractJob::ownerInfo( (string)$this->attribute( 'owner_login' ) );
    }

    public function scheduleName()
    {
        static $names = array();
        $id = (int)$this->attribute( 'schedule_id' );
        if ( !$id )
            return '';
        if ( !isset( $names[$id] ) )
        {
            $schedule = XrowExtractSchedule::fetch( $id );
            $names[$id] = $schedule ? $schedule->attribute( 'name' ) : '#' . $id;
        }
        return $names[$id];
    }

    public function durationText()
    {
        $start = (int)$this->attribute( 'started_at' );
        $end = (int)$this->attribute( 'ended_at' );
        if ( !$start || !$end )
            return '';
        $seconds = max( 0, $end - $start );
        if ( $seconds < 60 )
            return $seconds . ' s';
        if ( $seconds < 3600 )
            return floor( $seconds / 60 ) . ' min' . ( $seconds % 60 ? ' ' . ( $seconds % 60 ) . ' s' : '' );
        return floor( $seconds / 3600 ) . ' h' . ( floor( $seconds % 3600 / 60 ) ? ' ' . floor( $seconds % 3600 / 60 ) . ' min' : '' );
    }

    public function sizeKB()
    {
        return (int)$this->attribute( 'byte_size' ) > 0 ? (int)ceil( $this->attribute( 'byte_size' ) / 1024 ) : 0;
    }

    public function jobExists()
    {
        return $this->attribute( 'job_id' ) !== '' && XrowExtractJob::exists( $this->attribute( 'job_id' ) );
    }

    /**
     * Records one run. $data: the fields above (unknown keys ignored). A row for the same job id is
     * updated instead of added (a job recorded when it ends, and again when its delivery finishes).
     */
    public static function record( array $data )
    {
        if ( !XrowExtractSchema::ensure() )
            return null;
        $row = null;
        if ( !empty( $data['job_id'] ) )
            $row = eZPersistentObject::fetchObject( self::definition(), null, array( 'job_id' => (string)$data['job_id'] ) );
        if ( !$row )
            $row = new XrowExtractHistory( array( 'created' => time() ) );
        $fields = self::definition()['fields'];
        foreach ( $data as $key => $value )
        {
            if ( $key === 'id' || !isset( $fields[$key] ) )
                continue;
            if ( is_array( $value ) )
                $value = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( $fields[$key]['datatype'] === 'integer' )
                $value = (int)$value;
            elseif ( in_array( $key, array( 'what', 'file_name', 'destinations' ), true ) )
                $value = mb_substr( (string)$value, 0, 250 );
            $row->setAttribute( $key, $value );
        }
        if ( isset( $data['warnings'] ) && is_array( $data['warnings'] ) )
            $row->setAttribute( 'warning_count', count( $data['warnings'] ) );
        $row->store();
        return $row;
    }

    /** The conditions for a filter array: state, kind, schedule_id, owner, trigger, from, to (Y-m-d), text. */
    protected static function conditions( array $filter, $viewerLogin, $allowAll )
    {
        $conds = array();
        if ( !$allowAll )
            $conds['owner_login'] = (string)$viewerLogin;
        elseif ( !empty( $filter['owner'] ) )
            $conds['owner_login'] = (string)$filter['owner'];
        if ( !empty( $filter['state'] ) && in_array( $filter['state'], self::states(), true ) )
            $conds['run_state'] = $filter['state'];
        if ( !empty( $filter['kind'] ) && preg_match( '/^[a-z_]+$/', $filter['kind'] ) )
            $conds['kind'] = $filter['kind'];
        if ( !empty( $filter['trigger'] ) && preg_match( '/^[a-z_]+$/', $filter['trigger'] ) )
            $conds['trigger_type'] = $filter['trigger'];
        if ( !empty( $filter['schedule_id'] ) )
            $conds['schedule_id'] = (int)$filter['schedule_id'];
        if ( !empty( $filter['delivery'] ) && in_array( $filter['delivery'], array( 'ok', 'partial', 'failed' ), true ) )
            $conds['delivery_state'] = $filter['delivery'];
        $from = !empty( $filter['from'] ) ? strtotime( $filter['from'] . ' 00:00:00' ) : false;
        $to = !empty( $filter['to'] ) ? strtotime( $filter['to'] . ' 23:59:59' ) : false;
        if ( $from && $to )
            $conds['created'] = array( false, array( (int)$from, (int)$to ) );
        elseif ( $from )
            $conds['created'] = array( '>=', (int)$from );
        elseif ( $to )
            $conds['created'] = array( '<=', (int)$to );
        if ( !empty( $filter['text'] ) )
            $conds['what'] = array( 'like', '%' . str_replace( array( '%', '_' ), '', mb_substr( (string)$filter['text'], 0, 100 ) ) . '%' );
        return $conds ? $conds : null;
    }

    /** One page of rows, newest first. */
    public static function fetchPage( array $filter, $viewerLogin, $allowAll, $offset = 0, $limit = 25 )
    {
        if ( !XrowExtractSchema::exists() )
            return array();
        $list = eZPersistentObject::fetchObjectList( self::definition(), null, self::conditions( $filter, $viewerLogin, $allowAll ),
                                                     array( 'created' => 'desc', 'id' => 'desc' ), array( 'offset' => max( 0, (int)$offset ), 'length' => max( 1, min( 200, (int)$limit ) ) ) );
        return is_array( $list ) ? $list : array();
    }

    public static function countFor( array $filter, $viewerLogin, $allowAll )
    {
        if ( !XrowExtractSchema::exists() )
            return 0;
        return (int)eZPersistentObject::count( self::definition(), self::conditions( $filter, $viewerLogin, $allowAll ) );
    }

    /** Counts per run state for the totals row (the same filter, state left out). */
    public static function stateCounts( array $filter, $viewerLogin, $allowAll )
    {
        $counts = array( 'total' => 0 );
        unset( $filter['state'] );
        foreach ( self::states() as $state )
        {
            $counts[$state] = self::countFor( array_merge( $filter, array( 'state' => $state ) ), $viewerLogin, $allowAll );
            $counts['total'] += $counts[$state];
        }
        return $counts;
    }

    /** Failed (or skipped) scheduled runs since $since that the viewer may see: the Jobs tab badge. */
    public static function alertCount( $since, $viewerLogin, $allowAll )
    {
        if ( !XrowExtractSchema::exists() )
            return 0;
        $count = 0;
        foreach ( array( 'failed', 'skipped' ) as $state )
        {
            $conds = self::conditions( array( 'state' => $state ), $viewerLogin, $allowAll );
            $conds['created'] = array( '>', (int)$since );
            $conds['schedule_id'] = array( '>', 0 );
            $count += (int)eZPersistentObject::count( self::definition(), $conds );
        }
        $conds = self::conditions( array( 'delivery' => 'failed' ), $viewerLogin, $allowAll );
        $conds['created'] = array( '>', (int)$since );
        $conds['run_state'] = array( array( 'done', 'warning' ) );
        return $count + (int)eZPersistentObject::count( self::definition(), $conds );
    }

    /** The last successful run of a schedule (done or with warnings), or null. */
    public static function lastSuccess( $scheduleID )
    {
        if ( !XrowExtractSchema::exists() )
            return null;
        $list = eZPersistentObject::fetchObjectList( self::definition(), null,
            array( 'schedule_id' => (int)$scheduleID, 'run_state' => array( array( 'done', 'warning' ) ) ),
            array( 'started_at' => 'desc' ), array( 'offset' => 0, 'length' => 1 ) );
        return $list ? $list[0] : null;
    }

    /**
     * Removes rows older than their retention: a schedule's own history_days, else xrowextract.ini
     * [History] RetentionDays (default 365). Returns how many were removed.
     */
    public static function clean( $now = null )
    {
        if ( !XrowExtractSchema::exists() )
            return 0;
        $now = $now === null ? time() : $now;
        $db = eZDB::instance();
        $removed = 0;
        $default = self::retentionDays();
        $custom = array();
        foreach ( XrowExtractSchedule::fetchList() as $schedule )
        {
            $retention = $schedule->retentionArray();
            if ( !empty( $retention['history_days'] ) )
                $custom[(int)$schedule->attribute( 'id' )] = (int)$retention['history_days'];
        }
        foreach ( $custom as $scheduleID => $days )
        {
            $cutoff = $now - $days * 86400;
            $removed += (int)eZPersistentObject::count( self::definition(), array( 'schedule_id' => $scheduleID, 'created' => array( '<', $cutoff ) ) );
            $db->query( 'DELETE FROM xrowextract_history WHERE schedule_id = ' . (int)$scheduleID . ' AND created < ' . (int)$cutoff );
        }
        $cutoff = $now - $default * 86400;
        $exclude = $custom ? ' AND schedule_id NOT IN (' . implode( ',', array_map( 'intval', array_keys( $custom ) ) ) . ')' : '';
        $rows = $db->arrayQuery( 'SELECT COUNT(*) AS n FROM xrowextract_history WHERE created < ' . (int)$cutoff . $exclude );
        $removed += isset( $rows[0]['n'] ) ? (int)$rows[0]['n'] : 0;
        $db->query( 'DELETE FROM xrowextract_history WHERE created < ' . (int)$cutoff . $exclude );
        return $removed;
    }

    public static function retentionDays()
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        $days = $ini->hasVariable( 'History', 'RetentionDays' ) ? (int)$ini->variable( 'History', 'RetentionDays' ) : 365;
        return $days > 0 ? $days : 365;
    }

    /** Whether the current user may see the history (policy xrowextract/history). */
    public static function canView()
    {
        $access = eZUser::currentUser()->hasAccessTo( 'xrowextract', 'history' );
        return $access['accessWord'] !== 'no';
    }
}

?>
