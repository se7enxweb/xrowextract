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
                'install_details' => 'installDetails',
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
        // A package install is never delivered anywhere; its 'delivery' column holds installDetails()
        if ( $this->attribute( 'kind' ) === self::KIND_INSTALL )
            return array();
        $data = json_decode( (string)$this->attribute( 'delivery' ), true );
        return is_array( $data ) ? $data : array();
    }

    // ------------------------------------------------------------ package installs
    //
    // A package install is one row of kind "install" in the same table, so it outlives its job folder
    // like every export run does: owner_login (who), started_at/ended_at (when), file_name (the package),
    // run_mode/output_format (how existing objects/classes were handled), row_count (objects the package
    // carries that exist after the install), run_state (done, warning, failed), warnings (the datatype
    // check among them), error_text; and, in the 'delivery' column no install ever uses otherwise, the
    // details as JSON: parent, site access, the dry run's counts just before installing, created /
    // already there / not installed, the error messages and the installed objects' node ids (up to
    // INSTALL_NODE_ID_LIMIT, for "Export these again" after the job itself has expired).

    const KIND_INSTALL = 'install';
    const INSTALL_NODE_ID_LIMIT = 5000;

    /** The details of an install row (see above), or an empty array for any other kind. */
    public function installDetails()
    {
        if ( $this->attribute( 'kind' ) !== self::KIND_INSTALL )
            return array();
        $data = json_decode( (string)$this->attribute( 'delivery' ), true );
        return is_array( $data ) ? $data : array();
    }

    /**
     * Records one package install. $data: job_id ('' for one that did not run as a job), owner_login,
     * trigger_type (manual, cli), started_at, ended_at, package, parent_node_id, site_access, object_mode,
     * class_mode, counts (inspect()'s counts just before installing), report (install()'s report: ok,
     * errors, created_classes, created_objects), missing_datatypes (rows of XrowExtractPackage::missingDatatypes()),
     * what (optional text). A row for the same job id is updated, as for every other run.
     */
    public static function recordInstall( array $data )
    {
        $report = isset( $data['report'] ) && is_array( $data['report'] ) ? $data['report'] : array();
        $counts = isset( $data['counts'] ) && is_array( $data['counts'] ) ? $data['counts'] : array();
        $count = function ( $key ) use ( $counts ) { return isset( $counts[$key] ) ? (int)$counts[$key] : 0; };
        $errors = isset( $report['errors'] ) ? array_values( (array)$report['errors'] ) : array();
        $createdObjects = isset( $report['created_objects'] ) ? (array)$report['created_objects'] : array();
        $createdClasses = isset( $report['created_classes'] ) ? (array)$report['created_classes'] : array();
        $missing = isset( $data['missing_datatypes'] ) ? (array)$data['missing_datatypes'] : array();
        $nodeIDs = array();
        foreach ( $createdObjects as $object )
            if ( !empty( $object['node_id'] ) && count( $nodeIDs ) < self::INSTALL_NODE_ID_LIMIT )
                $nodeIDs[] = (int)$object['node_id'];
        $parentID = isset( $data['parent_node_id'] ) ? (int)$data['parent_node_id'] : 0;
        $parent = $parentID ? eZContentObjectTreeNode::fetch( $parentID ) : null;
        $ok = !isset( $report['ok'] ) || $report['ok'];
        $warnings = array();
        if ( class_exists( 'XrowExtractPackage' ) )
            $warnings = XrowExtractPackage::missingDatatypeLines( $missing );
        if ( isset( $data['warnings'] ) )
            $warnings = array_values( array_unique( array_merge( $warnings, (array)$data['warnings'] ) ) );
        $details = array(
            'package' => (string)$data['package'],
            'parent_node_id' => $parentID,
            'parent_name' => $parent instanceof eZContentObjectTreeNode ? (string)$parent->attribute( 'name' ) : '',
            'site_access' => isset( $data['site_access'] ) ? (string)$data['site_access'] : '',
            'object_mode' => isset( $data['object_mode'] ) ? (string)$data['object_mode'] : '',
            'class_mode' => isset( $data['class_mode'] ) ? (string)$data['class_mode'] : '',
            'counts' => $counts,
            'created' => $count( 'classes_create' ) + $count( 'objects_create' ),
            'existing' => $count( 'classes_update' ) + $count( 'objects_update' ) + $count( 'objects_unchanged' ),
            'not_installed' => $count( 'objects_class_missing' ),
            'installed_classes' => count( $createdClasses ),
            'installed_objects' => count( $createdObjects ),
            'errors' => $errors,
            'missing_datatypes' => array_values( array_map( function ( $row ) { return is_array( $row ) ? (string)$row['datatype'] : (string)$row; }, $missing ) ),
            'node_ids' => $nodeIDs,
            'node_ids_cut' => count( $createdObjects ) > count( $nodeIDs ) && count( $nodeIDs ) >= self::INSTALL_NODE_ID_LIMIT,
        );
        return self::record( array(
            'job_id' => isset( $data['job_id'] ) ? (string)$data['job_id'] : '',
            'owner_login' => (string)$data['owner_login'],
            'kind' => self::KIND_INSTALL,
            'what' => isset( $data['what'] ) && $data['what'] !== '' ? (string)$data['what'] : 'Install package ' . $data['package'],
            'trigger_type' => isset( $data['trigger_type'] ) ? (string)$data['trigger_type'] : 'manual',
            'run_mode' => $details['object_mode'],
            'output_format' => $details['class_mode'],
            'started_at' => (int)$data['started_at'],
            'ended_at' => (int)$data['ended_at'],
            'run_state' => !$ok ? 'failed' : ( $warnings ? 'warning' : 'done' ),
            'row_count' => count( $createdObjects ),
            'file_name' => (string)$data['package'],
            'warnings' => $warnings,
            'error_text' => $errors ? implode( ' ', $errors ) : ( isset( $data['error_text'] ) ? (string)$data['error_text'] : '' ),
            'delivery' => $details,
            'delivery_state' => '',
        ) );
    }

    /** The install rows the viewer may see, newest first ($package: only that package's). */
    public static function fetchInstalls( $viewerLogin, $allowAll, $package = '', $offset = 0, $limit = 25 )
    {
        return self::fetchPage( array( 'kind' => self::KIND_INSTALL, 'package' => (string)$package ), $viewerLogin, $allowAll, $offset, $limit );
    }

    public static function countInstalls( $viewerLogin, $allowAll, $package = '' )
    {
        return self::countFor( array( 'kind' => self::KIND_INSTALL, 'package' => (string)$package ), $viewerLogin, $allowAll );
    }

    /** One install row as the Jobs page and the Package tab show it. */
    public function installRow( $viewerLogin )
    {
        $details = $this->installDetails();
        return array(
            'id' => (int)$this->attribute( 'id' ),
            'job_id' => (string)$this->attribute( 'job_id' ),
            'job_exists' => $this->jobExists(),
            'package' => (string)$this->attribute( 'file_name' ),
            'package_exists' => $this->attribute( 'file_name' ) !== '' && eZPackage::fetch( (string)$this->attribute( 'file_name' ) ) instanceof eZPackage,
            'owner_user' => $this->ownerUser(),
            'mine' => $this->attribute( 'owner_login' ) === $viewerLogin,
            'trigger' => (string)$this->attribute( 'trigger_type' ),
            'started' => (int)$this->attribute( 'started_at' ),
            'ended' => (int)$this->attribute( 'ended_at' ),
            'duration' => $this->durationText(),
            'state' => (string)$this->attribute( 'run_state' ),
            'object_mode' => (string)$this->attribute( 'run_mode' ),
            'class_mode' => (string)$this->attribute( 'output_format' ),
            'parent_node_id' => isset( $details['parent_node_id'] ) ? (int)$details['parent_node_id'] : 0,
            'parent_name' => isset( $details['parent_name'] ) ? (string)$details['parent_name'] : '',
            'site_access' => isset( $details['site_access'] ) ? (string)$details['site_access'] : '',
            'created' => isset( $details['created'] ) ? (int)$details['created'] : 0,
            'existing' => isset( $details['existing'] ) ? (int)$details['existing'] : 0,
            'not_installed' => isset( $details['not_installed'] ) ? (int)$details['not_installed'] : 0,
            'installed_objects' => isset( $details['installed_objects'] ) ? (int)$details['installed_objects'] : (int)$this->attribute( 'row_count' ),
            'installed_classes' => isset( $details['installed_classes'] ) ? (int)$details['installed_classes'] : 0,
            'error_count' => isset( $details['errors'] ) ? count( $details['errors'] ) : 0,
            'missing_datatypes' => isset( $details['missing_datatypes'] ) ? (array)$details['missing_datatypes'] : array(),
            'node_count' => isset( $details['node_ids'] ) ? count( $details['node_ids'] ) : 0,
            'warnings' => $this->warningList(),
            'error' => (string)$this->attribute( 'error_text' ),
        );
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
        // One package's installs (an install row keeps the package name in file_name)
        if ( isset( $filter['package'] ) && $filter['package'] !== '' )
            $conds['file_name'] = (string)$filter['package'];
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
