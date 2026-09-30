<?php
/**
 * xrowextract/history: every export run (background jobs started by hand, from the command line or a
 * schedule, and direct downloads), newest first, filtered (GET: state, kind, schedule, trigger,
 * delivery, from, to, text, owner) and paged (offset). Policy xrowextract/history; your own runs, or
 * everyone's with xrowextract/all_jobs. "Mark as seen" clears the failure badge of the Jobs tab.
 */

$module = $Params['Module'];
$http = eZHTTPTool::instance();
$tpl = eZTemplate::factory();
$user = eZUser::currentUser();
$login = $user->attribute( 'login' );
$allowAll = XrowExtractJob::allowAllJobs();

$get = function ( $name, $pattern = '/^[A-Za-z0-9_ .:-]*$/' )
{
    $value = isset( $_GET[$name] ) ? trim( (string)$_GET[$name] ) : '';
    return preg_match( $pattern, $value ) ? mb_substr( $value, 0, 100 ) : '';
};
$filter = array(
    'state' => $get( 'state', '/^[a-z]*$/' ),
    'kind' => $get( 'kind', '/^[a-z_]*$/' ),
    'schedule_id' => (int)$get( 'schedule', '/^\d*$/' ),
    'trigger' => $get( 'trigger', '/^[a-z_]*$/' ),
    'delivery' => $get( 'delivery', '/^[a-z]*$/' ),
    'from' => $get( 'from', '/^(\d{4}-\d{2}-\d{2})?$/' ),
    'to' => $get( 'to', '/^(\d{4}-\d{2}-\d{2})?$/' ),
    'text' => isset( $_GET['text'] ) ? mb_substr( trim( (string)$_GET['text'] ), 0, 100 ) : '',
    'owner' => $allowAll ? $get( 'owner', '/^[A-Za-z0-9_.@-]*$/' ) : '',
);
$offset = max( 0, (int)$get( 'offset', '/^\d*$/' ) );
$ini = eZINI::instance( 'xrowextract.ini' );
$pageSize = $ini->hasVariable( 'History', 'PageSize' ) ? max( 5, min( 200, (int)$ini->variable( 'History', 'PageSize' ) ) ) : 25;

if ( $http->hasPostVariable( 'AcknowledgeAlerts' ) )
{
    eZPreferences::setValue( XrowExtractFunctionCollection::ALERTS_SEEN_PREFERENCE, time() );
    return $module->redirectTo( 'xrowextract/history' );
}

$total = XrowExtractHistory::countFor( $filter, $login, $allowAll );
if ( $offset >= $total && $total > 0 )
    $offset = (int)( floor( ( $total - 1 ) / $pageSize ) * $pageSize );
$rows = array();
foreach ( XrowExtractHistory::fetchPage( $filter, $login, $allowAll, $offset, $pageSize ) as $h )
{
    $rows[] = array(
        'id' => (int)$h->attribute( 'id' ),
        'job_id' => $h->attribute( 'job_id' ),
        'job_exists' => $h->jobExists(),
        'kind' => $h->attribute( 'kind' ),
        'what' => $h->attribute( 'what' ),
        'trigger' => $h->attribute( 'trigger_type' ),
        'run_mode' => $h->attribute( 'run_mode' ),
        // A package install keeps its class mode in output_format; say what it is instead
        'format' => $h->attribute( 'kind' ) === XrowExtractHistory::KIND_INSTALL
                    ? 'ezpkg · install · ' . $h->attribute( 'run_mode' ) . '/' . $h->attribute( 'output_format' ) : $h->attribute( 'output_format' ),
        'state' => $h->attribute( 'run_state' ),
        'schedule_id' => (int)$h->attribute( 'schedule_id' ),
        'schedule_name' => $h->scheduleName(),
        'preset' => $h->attribute( 'preset_ref' ),
        'preset_name' => $h->attribute( 'preset_ref' ) !== '' ? XrowExtractPreset::presetName( $h->attribute( 'preset_ref' ) ) : '',
        'owner_user' => $h->ownerUser(),
        'mine' => $h->attribute( 'owner_login' ) === $login,
        'started' => (int)$h->attribute( 'started_at' ),
        'ended' => (int)$h->attribute( 'ended_at' ),
        'duration' => $h->durationText(),
        'rows' => (int)$h->attribute( 'row_count' ),
        'size_kb' => $h->sizeKB(),
        'checksum' => $h->attribute( 'checksum' ),
        'file_name' => $h->attribute( 'file_name' ),
        'destinations' => $h->attribute( 'destinations' ),
        'delivery_state' => $h->attribute( 'delivery_state' ),
        'delivery' => $h->deliveryList(),
        'warnings' => $h->warningList(),
        'error' => $h->attribute( 'error_text' ),
    );
}
$counts = XrowExtractHistory::stateCounts( $filter, $login, $allowAll );

// The query string of the current filter (for the pager and the state totals)
$query = array();
foreach ( array( 'state' => $filter['state'], 'kind' => $filter['kind'], 'schedule' => $filter['schedule_id'] ?: '', 'trigger' => $filter['trigger'],
                 'delivery' => $filter['delivery'], 'from' => $filter['from'], 'to' => $filter['to'], 'text' => $filter['text'], 'owner' => $filter['owner'] ) as $key => $value )
{
    if ( $value !== '' && $value !== 0 )
        $query[$key] = $value;
}
$queryWithout = function ( $drop ) use ( $query )
{
    $q = $query;
    unset( $q[$drop] );
    return $q ? '&' . http_build_query( $q ) : '';
};

$schedules = array();
foreach ( $allowAll ? XrowExtractSchedule::fetchList() : XrowExtractSchedule::fetchList( $login ) as $schedule )
    $schedules[] = array( 'id' => (int)$schedule->attribute( 'id' ), 'name' => $schedule->attribute( 'name' ) );

$since = (int)eZPreferences::value( XrowExtractFunctionCollection::ALERTS_SEEN_PREFERENCE, $user );

$tpl->setVariable( 'rows', $rows );
$tpl->setVariable( 'filter', $filter );
$tpl->setVariable( 'counts', $counts );
$tpl->setVariable( 'total', $total );
$tpl->setVariable( 'offset', $offset );
$tpl->setVariable( 'page_size', $pageSize );
$tpl->setVariable( 'page', (int)floor( $offset / $pageSize ) + 1 );
$tpl->setVariable( 'pages', max( 1, (int)ceil( $total / $pageSize ) ) );
$tpl->setVariable( 'prev_offset', $offset > 0 ? max( 0, $offset - $pageSize ) : -1 );
$tpl->setVariable( 'next_offset', $offset + $pageSize < $total ? $offset + $pageSize : -1 );
$tpl->setVariable( 'query_all', $query ? '&' . http_build_query( $query ) : '' );
$tpl->setVariable( 'query_no_state', $queryWithout( 'state' ) );
$tpl->setVariable( 'schedules', $schedules );
$tpl->setVariable( 'all_jobs', $allowAll );
$tpl->setVariable( 'alert_count', XrowExtractHistory::alertCount( $since, $login, $allowAll ) );
$tpl->setVariable( 'retention_days', XrowExtractHistory::retentionDays() );
$tpl->setVariable( 'RunningJobsCount', XrowExtractJob::countRunning( $login, $allowAll ) );
$scriptFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract.js';
$tpl->setVariable( 'ScriptVersion', is_file( $scriptFile ) ? substr( md5_file( $scriptFile ), 0, 12 ) : '0' );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/history.tpl' );
$Result['path'] = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'History' ) ),
);
$Result['left_menu'] = 'design:xrowextract/menu_schedules.tpl';

?>
