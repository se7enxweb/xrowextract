<?php
/**
 * xrowextract/schedules: scheduled exports and imports. A schedule runs a saved preset, a site archive,
 * an Export as package or an import from a folder or destination, on a simple frequency or a cron
 * expression, in full or as a delta (only changes since the last successful run), and delivers the
 * file to its destinations. The cronjob part "xrowextract" starts the due ones (or system cron, with
 * the crontab lines shown here). Policy xrowextract/schedule; with xrowextract/all_jobs everyone's.
 */

$module = $Params['Module'];
$http = eZHTTPTool::instance();
$tpl = eZTemplate::factory();
$user = eZUser::currentUser();
$login = $user->attribute( 'login' );
$allowAll = XrowExtractJob::allowAllJobs();
$errors = array();
$notice = false;

$ownSchedule = function ( $id ) use ( $login, $allowAll )
{
    $schedule = XrowExtractSchedule::fetch( (int)$id );
    return $schedule && $schedule->canEdit( $login, $allowAll ) ? $schedule : null;
};
$backTo = function ( $anchor = '' ) use ( $module )
{
    return $module->redirectTo( 'xrowextract/schedules' . ( $anchor !== '' ? '#' . $anchor : '' ) );
};

// The form's values, from the post (a Save that failed shows them again)
$postedValues = function () use ( $http )
{
    $p = $http->hasPostVariable( 'Schedule' ) ? (array)$http->postVariable( 'Schedule' ) : array();
    $get = function ( $key, $default = '' ) use ( $p ) { return isset( $p[$key] ) ? $p[$key] : $default; };
    $kind = (string)$get( 'kind', 'preset' );
    $lines = function ( $text )
    {
        $out = array();
        foreach ( preg_split( '/\r\n|\r|\n/', (string)$text ) as $line )
        {
            if ( strpos( $line, '=' ) === false )
                continue;
            list( $key, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
            if ( $key !== '' )
                $out[$key] = $value;
        }
        return $out;
    };
    $list = function ( $text, $int = false )
    {
        $items = array_values( array_filter( array_map( 'trim', explode( ',', (string)$text ) ), 'strlen' ) );
        return $int ? array_values( array_filter( array_map( 'intval', $items ) ) ) : $items;
    };
    $access = eZSiteAccess::current();
    $definition = array( 'siteaccess' => $access && !empty( $access['name'] ) ? $access['name'] : '' );
    switch ( $kind )
    {
        case 'preset':
            $definition += array( 'preset' => (string)$get( 'preset' ), 'params' => $lines( $get( 'params' ) ), 'class' => (string)$get( 'preset_class' ) );
            break;
        case 'archive':
            $languages = (array)$get( 'languages', array() );
            $definition += array(
                'set' => (string)$get( 'set', 'sites' ), 'nodes' => $list( $get( 'nodes' ), true ), 'classes' => $list( $get( 'classes' ) ),
                'languages' => $languages ? array_values( array_map( 'strval', $languages ) ) : 'all',
                'files' => (string)$get( 'files', 'csv' ), 'format' => (string)$get( 'format', 'zip' ),
                'columns' => (string)$get( 'columns', 'standard' ), 'plain_text' => (bool)$get( 'plain_text', false ),
            );
            if ( $definition['nodes'] )
                $definition['set'] = '';
            break;
        case 'package':
            $definition += array( 'node' => (int)$get( 'node' ), 'subtree' => (bool)$get( 'subtree', false ), 'class' => (string)$get( 'package_class' ) );
            break;
        case 'import':
            $definition += array(
                'source' => $get( 'source' ) === 'destination' ? 'destination' : 'local', 'local_path' => trim( (string)$get( 'local_path' ) ),
                'destination_id' => (int)$get( 'destination_id' ), 'remote_path' => trim( (string)$get( 'remote_path' ) ),
                'class' => (string)$get( 'import_class' ), 'parent' => (int)$get( 'parent' ) ?: '', 'match' => (string)$get( 'match', 'remote_id' ),
                'language' => (string)$get( 'language' ),
            );
            break;
    }
    $frequency = array( 'kind' => (string)$get( 'frequency', 'daily' ), 'time' => (string)$get( 'time', '02:00' ), 'minute' => (int)$get( 'minute', 0 ),
                        'weekday' => (int)$get( 'weekday', 1 ), 'monthday' => (int)$get( 'monthday', 1 ), 'expression' => trim( (string)$get( 'expression' ) ) );
    return array(
        'id' => (int)$get( 'id', 0 ),
        'name' => (string)$get( 'name' ), 'kind' => $kind, 'definition' => $definition, 'frequency' => $frequency,
        'delta_mode' => $get( 'delta_mode' ) === 'delta' ? 'delta' : 'full',
        'destination_ids' => array_map( 'intval', (array)$get( 'destinations', array() ) ),
        'notify' => array( 'failure_emails' => (string)$get( 'failure_emails' ), 'success' => (bool)$get( 'notify_success', false ),
                           'success_emails' => (string)$get( 'success_emails' ), 'admin_notice' => (bool)$get( 'admin_notice', false ),
                           'webhook_url' => trim( (string)$get( 'webhook_url' ) ) ),
        'retention' => array( 'files_days' => (string)$get( 'files_days' ), 'history_days' => (string)$get( 'history_days' ) ),
        'enabled' => (bool)$get( 'enabled', false ),
    );
};

// The form's values from a stored schedule
$scheduleValues = function ( XrowExtractSchedule $s )
{
    return array(
        'id' => (int)$s->attribute( 'id' ), 'name' => $s->attribute( 'name' ), 'kind' => $s->attribute( 'kind' ),
        'definition' => $s->definitionArray(), 'frequency' => $s->frequencyArray(), 'delta_mode' => $s->attribute( 'delta_mode' ),
        'destination_ids' => $s->destinationIDList(), 'notify' => $s->notifyArray(), 'retention' => $s->retentionArray(),
        'enabled' => (bool)$s->attribute( 'enabled' ),
    );
};
$blankValues = array(
    'id' => 0, 'name' => '', 'kind' => 'preset', 'definition' => array( 'set' => 'sites', 'files' => 'csv', 'format' => 'zip', 'languages' => 'all', 'subtree' => true, 'source' => 'local', 'match' => 'remote_id' ),
    'frequency' => array( 'kind' => 'daily', 'time' => '02:00', 'minute' => 0, 'weekday' => 1, 'monthday' => 1, 'expression' => '' ),
    'delta_mode' => 'full', 'destination_ids' => array(),
    'notify' => array( 'failure_emails' => '', 'success' => false, 'success_emails' => '', 'admin_notice' => true, 'webhook_url' => '' ),
    'retention' => array( 'files_days' => '', 'history_days' => '' ), 'enabled' => true,
);

$editValues = null;
if ( $http->hasPostVariable( 'CancelEdit' ) )
    return $backTo();
if ( $http->hasPostVariable( 'NewSchedule' ) )
{
    $editValues = $blankValues;
    if ( $http->hasPostVariable( 'NewScheduleKind' ) && in_array( $http->postVariable( 'NewScheduleKind' ), XrowExtractSchedule::kinds(), true ) )
        $editValues['kind'] = $http->postVariable( 'NewScheduleKind' );
    if ( $http->hasPostVariable( 'NewSchedulePreset' ) )
        $editValues['definition']['preset'] = (string)$http->postVariable( 'NewSchedulePreset' );
}
elseif ( $http->hasPostVariable( 'EditScheduleID' ) )
{
    $schedule = $ownSchedule( $http->postVariable( 'EditScheduleID' ) );
    if ( $schedule )
        $editValues = $scheduleValues( $schedule );
}
elseif ( $http->hasPostVariable( 'SaveSchedule' ) )
{
    $values = $postedValues();
    $existing = $values['id'] ? $ownSchedule( $values['id'] ) : null;
    if ( $values['id'] && !$existing )
        return $backTo();
    $result = XrowExtractSchedule::saveFrom( $values, $login, $existing );
    if ( $result['errors'] )
    {
        $errors = array_map( function ( $message ) { return ezpI18n::tr( 'design/standard/extract', $message ); }, $result['errors'] );
        $editValues = $values;
    }
    else
    {
        $http->setSessionVariable( 'eZExtractScheduleNotice', ezpI18n::tr( 'design/standard/extract', 'Schedule saved. Next run: %time', null,
                                   array( '%time' => $result['schedule']->attribute( 'next_run' ) ? date( 'Y-m-d H:i', $result['schedule']->attribute( 'next_run' ) ) : '-' ) ) );
        return $backTo( 'schedule-' . (int)$result['schedule']->attribute( 'id' ) );
    }
}
elseif ( $http->hasPostVariable( 'DeleteScheduleID' ) )
{
    $schedule = $ownSchedule( $http->postVariable( 'DeleteScheduleID' ) );
    if ( $schedule )
        $schedule->remove();
    return $backTo();
}
elseif ( $http->hasPostVariable( 'EnableScheduleID' ) || $http->hasPostVariable( 'DisableScheduleID' ) )
{
    $on = $http->hasPostVariable( 'EnableScheduleID' );
    $schedule = $ownSchedule( $http->postVariable( $on ? 'EnableScheduleID' : 'DisableScheduleID' ) );
    if ( $schedule )
    {
        $schedule->setEnabled( $on );
        return $backTo( 'schedule-' . (int)$schedule->attribute( 'id' ) );
    }
    return $backTo();
}
elseif ( $http->hasPostVariable( 'RunScheduleID' ) )
{
    $schedule = $ownSchedule( $http->postVariable( 'RunScheduleID' ) );
    if ( $schedule )
    {
        $mode = $http->hasPostVariable( 'RunMode' ) && in_array( $http->postVariable( 'RunMode' ), array( 'full', 'delta' ), true ) ? $http->postVariable( 'RunMode' ) : null;
        $result = XrowExtractScheduler::start( $schedule, $mode, 'manual' );
        if ( $result['job_id'] && $result['ok'] )
        {
            $http->setSessionVariable( 'eZExtractJobStarted', $result['job_id'] );
            return $module->redirectTo( 'xrowextract/jobs' );
        }
        $http->setSessionVariable( 'eZExtractScheduleNotice', $result['message'] );
        return $backTo( 'schedule-' . (int)$schedule->attribute( 'id' ) );
    }
    return $backTo();
}

if ( $http->hasSessionVariable( 'eZExtractScheduleNotice' ) )
{
    $notice = $http->sessionVariable( 'eZExtractScheduleNotice' );
    $http->removeSessionVariable( 'eZExtractScheduleNotice' );
}

// The list
$destinationNames = XrowExtractDestination::nameList();
$rows = array();
foreach ( $allowAll ? XrowExtractSchedule::fetchList() : XrowExtractSchedule::fetchList( $login ) as $schedule )
{
    $next = array();
    $t = time();
    for ( $i = 0; $i < 3 && $schedule->attribute( 'enabled' ); $i++ )
    {
        $t = XrowExtractCron::nextRun( $schedule->attribute( 'cron_expr' ), $t );
        if ( !$t )
            break;
        $next[] = $t;
    }
    $names = array();
    foreach ( $schedule->destinationIDList() as $id )
        $names[] = isset( $destinationNames[$id] ) ? $destinationNames[$id] : '#' . $id;
    $lastJob = $schedule->attribute( 'last_job_id' ) !== '' ? XrowExtractJob::load( $schedule->attribute( 'last_job_id' ) ) : null;
    $rows[] = array(
        'id' => (int)$schedule->attribute( 'id' ),
        'name' => $schedule->attribute( 'name' ),
        'kind' => $schedule->attribute( 'kind' ),
        'summary' => $schedule->summary(),
        'enabled' => (bool)$schedule->attribute( 'enabled' ),
        'frequency_text' => $schedule->frequencyText(),
        'cron' => $schedule->attribute( 'cron_expr' ),
        'delta' => $schedule->attribute( 'delta_mode' ) === 'delta',
        'next_runs' => $next,
        'last_run' => (int)$schedule->attribute( 'last_run' ),
        'last_success' => (int)$schedule->attribute( 'last_success' ),
        'last_state' => (string)$schedule->attribute( 'last_state' ),
        'last_job' => $lastJob ? array( 'id' => $lastJob['id'], 'state' => $lastJob['state'] ) : null,
        'running' => XrowExtractScheduler::isRunning( $schedule ),
        'destinations' => $names,
        'owner_user' => XrowExtractJob::ownerInfo( (string)$schedule->attribute( 'owner_login' ) ),
        'mine' => $schedule->attribute( 'owner_login' ) === $login,
        'crontab' => $schedule->crontabLine(),
    );
}

// Choices for the form
$presets = array();
foreach ( XrowExtractPreset::fetchVisible( $login, $allowAll ) as $preset )
{
    if ( $preset['view'] !== 'csv' )
        continue;
    $placeholders = array();
    foreach ( $preset['placeholders'] as $name => $spec )
        $placeholders[] = $name . '=' . ( isset( $spec['default'] ) ? $spec['default'] : '' );
    $presets[] = array( 'ref' => $preset['ref'], 'name' => $preset['name'], 'site' => $preset['site'], 'placeholders' => implode( "\n", $placeholders ),
                        'summary' => XrowExtractPreset::summaryLine( $preset ) );
}
$classes = array();
foreach ( eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, true, false, array( 'name' => 'asc' ) ) as $class )
    $classes[] = array( 'identifier' => $class->attribute( 'identifier' ), 'name' => $class->attribute( 'name' ) );
$languages = array();
foreach ( XrowExtractColumns::contentLanguages() as $locale => $language )
    $languages[] = array( 'locale' => $locale, 'name' => $language['name'] );
$archiveFormats = array();
foreach ( XrowExtractArchive::formats() as $id => $format )
{
    if ( $format['available'] )
        $archiveFormats[] = array( 'id' => $id, 'name' => $format['name'] );
}
$nodeSets = array();
foreach ( XrowExtractArchive::nodeSets() as $id => $set )
    $nodeSets[] = array( 'id' => $id, 'name' => $set['name'], 'nodes' => implode( ', ', $set['nodes'] ) );
$destinationChoices = array();
foreach ( $destinationNames as $id => $name )
    $destinationChoices[] = array( 'id' => $id, 'name' => $name );

if ( $editValues !== null )
{
    // Plain strings for the template's inputs
    $d = $editValues['definition'];
    $editValues['params_text'] = isset( $d['params'] ) && is_array( $d['params'] ) ? implode( "\n", array_map( function ( $k, $v ) { return $k . '=' . $v; }, array_keys( $d['params'] ), $d['params'] ) ) : '';
    $editValues['nodes_text'] = isset( $d['nodes'] ) ? implode( ', ', (array)$d['nodes'] ) : '';
    $editValues['classes_text'] = isset( $d['classes'] ) ? implode( ', ', (array)$d['classes'] ) : '';
    $editValues['language_list'] = isset( $d['languages'] ) && is_array( $d['languages'] ) ? $d['languages'] : array();
    foreach ( array( 'preset', 'set', 'files', 'format', 'columns', 'node', 'class', 'source', 'local_path', 'destination_id', 'remote_path', 'parent', 'match', 'language' ) as $key )
        $editValues['d_' . $key] = isset( $d[$key] ) ? (string)$d[$key] : '';
    $editValues['d_subtree'] = !empty( $d['subtree'] );
    $editValues['d_plain_text'] = !empty( $d['plain_text'] );
}

$tpl->setVariable( 'schedules', $rows );
$tpl->setVariable( 'edit', $editValues );
$tpl->setVariable( 'errors', $errors );
$tpl->setVariable( 'notice', $notice );
$tpl->setVariable( 'all_jobs', $allowAll );
$tpl->setVariable( 'presets', $presets );
$tpl->setVariable( 'classes', $classes );
$tpl->setVariable( 'languages', $languages );
$tpl->setVariable( 'archive_formats', $archiveFormats );
$tpl->setVariable( 'node_sets', $nodeSets );
$tpl->setVariable( 'destination_choices', $destinationChoices );
$tpl->setVariable( 'can_manage_destinations', XrowExtractDestination::canManage() );
$tpl->setVariable( 'can_view_history', XrowExtractHistory::canView() );
$tpl->setVariable( 'cronjob_line', XrowExtractScheduler::cronjobPartLine( (int)eZINI::instance( 'xrowextract.ini' )->variable( 'Schedules', 'CronjobEveryMinutes' ) ?: 5 ) );
$tpl->setVariable( 'jobs_available', XrowExtractJob::available() );
$tpl->setVariable( 'file_retention_days', XrowExtractJob::retentionDays() );
$tpl->setVariable( 'history_retention_days', XrowExtractHistory::retentionDays() );
$tpl->setVariable( 'local_roots', XrowExtractTransportLocal::allowedRoots() );
$tpl->setVariable( 'RunningJobsCount', XrowExtractJob::countRunning( $login, $allowAll ) );
$scriptFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract.js';
$tpl->setVariable( 'ScriptVersion', is_file( $scriptFile ) ? substr( md5_file( $scriptFile ), 0, 12 ) : '0' );
$scheduleScript = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract-schedules.js';
$tpl->setVariable( 'ScheduleScriptVersion', is_file( $scheduleScript ) ? substr( md5_file( $scheduleScript ), 0, 12 ) : '0' );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/schedules.tpl' );
$Result['path'] = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Schedules' ) ),
);
$Result['left_menu'] = 'design:xrowextract/menu_schedules.tpl';

?>
