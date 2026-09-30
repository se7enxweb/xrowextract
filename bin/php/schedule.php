#!/usr/bin/env php
<?php
/**
 * Scheduled exports and imports from the command line (./console ext:xrowextract:schedule runs it too).
 *
 *   php extension/xrowextract/bin/php/schedule.php --list
 *   php extension/xrowextract/bin/php/schedule.php --show=3
 *   php extension/xrowextract/bin/php/schedule.php --run=3 [--mode=full|delta] [--wait]
 *   php extension/xrowextract/bin/php/schedule.php --run=3 --if-enabled        (what a generated crontab line runs)
 *   php extension/xrowextract/bin/php/schedule.php --enable=3 | --disable=3
 *   php extension/xrowextract/bin/php/schedule.php --cron [--wait]            (the cronjob part, by hand)
 *   php extension/xrowextract/bin/php/schedule.php --crontab                  (crontab lines for system cron)
 *   php extension/xrowextract/bin/php/schedule.php --create --name=Nightly --kind=preset --preset=site:hidden_content \
 *        --param=node=2 --frequency=daily --time=02:30 [--delta] [--destinations=1,2] [--owner=admin]
 *   php extension/xrowextract/bin/php/schedule.php --delete=3
 *   php extension/xrowextract/bin/php/schedule.php --next="30 2 * * 1-5"      (the next five run times of an expression)
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array(
    'description'    => "Lists, runs, enables and disables xrowextract schedules; runs the cronjob part by hand.",
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
) );
$script->startup();
$options = $script->getOptions(
    '[list][show:][run:][mode:][wait][if-enabled][enable:][disable:][cron][crontab][create][delete:][next:]' .
    '[name:][kind:][preset:][param:*][nodes:][set:][classes:][languages:][files:][format:][node:][subtree][class:]' .
    '[source:][local-path:][destination:][remote-path:][parent:][match:][language:]' .
    '[frequency:][time:][minute:][weekday:][monthday:][expression:][delta][destinations:][notify-emails:][owner:][json]',
    '',
    array(
        'list'       => 'List the schedules: id, state, kind, frequency, next run, last run',
        'show'       => 'One schedule in full (JSON with --json)',
        'run'        => 'Start a schedule now (a background job unless --wait)',
        'mode'       => 'With --run: full or delta (default: the schedule\'s own)',
        'wait'       => 'With --run/--cron: run the job in this process and wait for it',
        'if-enabled' => 'With --run: do nothing for a disabled schedule (for system cron lines)',
        'enable'     => 'Enable a schedule',
        'disable'    => 'Disable a schedule',
        'cron'       => 'Run the cronjob part now: start every due schedule, clean up',
        'crontab'    => 'Print crontab lines: the cronjob part, and one per enabled schedule',
        'create'     => 'Create a schedule (--name, --kind and the options of that kind, --frequency ...)',
        'delete'     => 'Delete a schedule (its history rows are kept)',
        'next'       => 'Print the next five run times of a cron expression',
        'name'       => '--create: the schedule\'s name',
        'kind'       => '--create: preset, archive, package or import',
        'preset'     => '--create --kind=preset: the preset (user:<id> or site:<id>)',
        'param'      => '--create --kind=preset: a placeholder value, key=value (repeatable)',
        'nodes'      => '--create --kind=archive: node ids, comma separated (or --set)',
        'set'        => '--create --kind=archive: a node set (bin/php/archive.php --list-sets)',
        'classes'    => '--create --kind=archive: class identifiers, comma separated (default: all)',
        'languages'  => '--create --kind=archive: locales, comma separated (default: all)',
        'files'      => '--create --kind=archive: csv, json or xml',
        'format'     => '--create --kind=archive: zip, tar.gz ...',
        'node'       => '--create --kind=package: the node to export',
        'subtree'    => '--create --kind=package: with its subtree',
        'class'      => '--create --kind=package/import: the class',
        'source'     => '--create --kind=import: local or destination',
        'local-path' => '--create --kind=import: the file (below [Destinations] LocalPathRoots[])',
        'destination' => '--create --kind=import: the destination id to read from',
        'remote-path' => '--create --kind=import: the file at the destination',
        'parent'     => '--create --kind=import: the parent node for new objects',
        'match'      => '--create --kind=import: remote_id, object_id or none',
        'language'   => '--create --kind=import: the language of rows without one',
        'frequency'  => '--create: hourly, daily, weekly, monthly or cron',
        'time'       => '--create: HH:MM (daily, weekly, monthly)',
        'minute'     => '--create --frequency=hourly: the minute',
        'weekday'    => '--create --frequency=weekly: 0 (Sunday) to 6',
        'monthday'   => '--create --frequency=monthly: 1 to 31',
        'expression' => '--create --frequency=cron: a 5-field cron expression',
        'delta'      => '--create: only changes since the last successful run',
        'destinations' => '--create: destination ids to deliver to, comma separated',
        'notify-emails' => '--create: more addresses for failures (the owner always gets them)',
        'owner'      => '--create: the login the schedule runs as (default: admin)',
        'json'       => 'Machine readable output for --list and --show',
    )
);
$script->initialize();

$fail = function ( $message ) use ( $cli, $script ): never
{
    $cli->error( $message );
    $script->shutdown( 1 );
    exit( 1 ); // shutdown() with an exit code exits; this only states it
};
$when = function ( $time ) { return $time ? date( 'Y-m-d H:i', $time ) : '-'; };
$load = function ( $id ) use ( $fail )
{
    $schedule = XrowExtractSchedule::fetch( (int)$id );
    if ( !$schedule )
        $fail( "No schedule $id." );
    return $schedule;
};
$asArray = function ( XrowExtractSchedule $s )
{
    return array(
        'id' => (int)$s->attribute( 'id' ), 'name' => $s->attribute( 'name' ), 'owner' => $s->attribute( 'owner_login' ),
        'enabled' => (bool)$s->attribute( 'enabled' ), 'kind' => $s->attribute( 'kind' ), 'summary' => $s->summary(),
        'definition' => $s->definitionArray(), 'frequency' => $s->frequencyArray(), 'cron' => $s->attribute( 'cron_expr' ),
        'delta_mode' => $s->attribute( 'delta_mode' ), 'destinations' => $s->destinationIDList(), 'notify' => $s->notifyArray(),
        'retention' => $s->retentionArray(), 'next_run' => (int)$s->attribute( 'next_run' ), 'last_run' => (int)$s->attribute( 'last_run' ),
        'last_success' => (int)$s->attribute( 'last_success' ), 'last_state' => $s->attribute( 'last_state' ), 'last_job_id' => $s->attribute( 'last_job_id' ),
    );
};

// Admin by default: a schedule runs as its owner anyway; this user only decides who may list/change here
$admin = eZUser::fetchByName( 'admin' );
if ( $admin instanceof eZUser )
    $admin->loginCurrent();

if ( $options['next'] )
{
    $expression = (string)$options['next'];
    if ( !XrowExtractCron::isValid( $expression ) )
        $fail( "Not a valid cron expression: $expression" );
    $t = time();
    for ( $i = 0; $i < 5; $i++ )
    {
        $t = XrowExtractCron::nextRun( $expression, $t );
        if ( !$t )
            break;
        $cli->output( date( 'D Y-m-d H:i', $t ) );
    }
    $script->shutdown( 0 );
}

if ( $options['list'] )
{
    $list = XrowExtractSchedule::fetchList();
    if ( $options['json'] )
    {
        $cli->output( json_encode( array_map( $asArray, $list ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $script->shutdown( 0 );
    }
    if ( !$list )
        $cli->output( 'No schedules.' );
    foreach ( $list as $s )
    {
        $cli->output( sprintf( '  %4d %-8s %-8s %-28s %-18s next %-16s last %-16s %-10s %s', $s->attribute( 'id' ), $s->attribute( 'enabled' ) ? 'enabled' : 'disabled',
                               $s->attribute( 'kind' ), mb_substr( $s->attribute( 'name' ), 0, 28 ), $s->attribute( 'cron_expr' ) . ( $s->attribute( 'delta_mode' ) === 'delta' ? ' Δ' : '' ),
                               $when( $s->attribute( 'next_run' ) ), $when( $s->attribute( 'last_run' ) ), $s->attribute( 'last_state' ) ?: '-', $s->summary() ) );
    }
    $script->shutdown( 0 );
}

if ( $options['show'] )
{
    $s = $load( $options['show'] );
    $data = $asArray( $s );
    if ( $options['json'] )
        $cli->output( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    else
    {
        foreach ( $data as $key => $value )
            $cli->output( sprintf( '  %-13s %s', $key, is_array( $value ) ? json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value ) ) );
        $cli->output( '  frequency     ' . $s->frequencyText() );
        $cli->output( '  crontab       ' . $s->crontabLine() );
    }
    $script->shutdown( 0 );
}

if ( $options['enable'] || $options['disable'] )
{
    $s = $load( $options['enable'] ?: $options['disable'] );
    $s->setEnabled( (bool)$options['enable'] );
    $cli->output( sprintf( 'Schedule %d is %s%s.', $s->attribute( 'id' ), $options['enable'] ? 'enabled' : 'disabled',
                           $options['enable'] ? ', next run ' . $when( $s->attribute( 'next_run' ) ) : '' ) );
    $script->shutdown( 0 );
}

if ( $options['delete'] )
{
    $s = $load( $options['delete'] );
    $s->remove();
    $cli->output( 'Schedule ' . (int)$options['delete'] . ' deleted (its history is kept).' );
    $script->shutdown( 0 );
}

if ( $options['run'] )
{
    $s = $load( $options['run'] );
    if ( $options['if-enabled'] && !$s->attribute( 'enabled' ) )
    {
        $cli->output( 'Schedule ' . (int)$options['run'] . ' is disabled; nothing started.' );
        $script->shutdown( 0 );
    }
    if ( $options['mode'] && !in_array( $options['mode'], array( 'full', 'delta' ), true ) )
        $fail( '--mode is full or delta.' );
    $result = XrowExtractScheduler::start( $s, $options['mode'] ?: null, $options['if-enabled'] ? 'system_cron' : 'cli', (bool)$options['wait'] );
    $cli->output( sprintf( '%s: %s (%s)', $result['ok'] ? 'PASS' : ( $result['state'] === 'skipped' ? 'SKIPPED' : 'FAIL' ), $result['message'], $result['state'] ) );
    if ( $result['job_id'] )
        $cli->output( 'Job: ' . $result['job_id'] );
    $script->shutdown( $result['ok'] || $result['state'] === 'skipped' || $result['state'] === 'running' ? 0 : 1 );
}

if ( $options['cron'] )
{
    $stats = XrowExtractScheduler::runDue( time(), function ( $line ) use ( $cli ) { $cli->output( '  ' . $line ); }, (bool)$options['wait'] );
    $cli->output( sprintf( '%d started, %d skipped, %d still running, %d job folder(s) and %d history row(s) cleaned',
                           $stats['started'], $stats['skipped'], $stats['busy'], $stats['cleaned_jobs'], $stats['cleaned_history'] ) );
    $script->shutdown( 0 );
}

if ( $options['crontab'] )
{
    $cli->output( '# Either the cronjob part (every due schedule, every 5 minutes):' );
    $cli->output( XrowExtractScheduler::cronjobPartLine() );
    $cli->output( '# or one line per schedule (system cron decides when):' );
    foreach ( XrowExtractSchedule::fetchList() as $s )
    {
        if ( $s->attribute( 'enabled' ) )
            $cli->output( $s->crontabLine() . '   # ' . $s->attribute( 'name' ) );
    }
    $script->shutdown( 0 );
}

if ( $options['create'] )
{
    $kind = (string)$options['kind'];
    $definition = array();
    switch ( $kind )
    {
        case 'preset':
            $params = array();
            foreach ( (array)$options['param'] as $entry )
            {
                foreach ( explode( ',', (string)$entry ) as $pair )
                {
                    if ( strpos( $pair, '=' ) !== false )
                    {
                        list( $k, $v ) = array_map( 'trim', explode( '=', $pair, 2 ) );
                        $params[$k] = $v;
                    }
                }
            }
            $definition = array( 'preset' => (string)$options['preset'], 'params' => $params, 'class' => (string)$options['class'] );
            break;
        case 'archive':
            $definition = array(
                'nodes' => $options['nodes'] ? array_values( array_filter( array_map( 'intval', explode( ',', $options['nodes'] ) ) ) ) : array(),
                'set' => $options['set'] ? $options['set'] : ( $options['nodes'] ? '' : 'sites' ),
                'classes' => $options['classes'] ? array_values( array_filter( array_map( 'trim', explode( ',', $options['classes'] ) ) ) ) : array(),
                'languages' => $options['languages'] ? array_values( array_filter( array_map( 'trim', explode( ',', $options['languages'] ) ) ) ) : 'all',
                'files' => $options['files'] ? $options['files'] : 'csv',
                'format' => $options['format'] ? $options['format'] : 'zip',
            );
            break;
        case 'package':
            $definition = array( 'node' => (int)$options['node'], 'subtree' => (bool)$options['subtree'], 'class' => (string)$options['class'] );
            break;
        case 'import':
            $definition = array( 'source' => $options['source'] === 'destination' ? 'destination' : 'local', 'local_path' => (string)$options['local-path'],
                                 'destination_id' => (int)$options['destination'], 'remote_path' => (string)$options['remote-path'],
                                 'class' => (string)$options['class'], 'parent' => (int)$options['parent'] ?: '', 'match' => (string)$options['match'],
                                 'language' => (string)$options['language'] );
            break;
    }
    $frequency = array( 'kind' => $options['frequency'] ? $options['frequency'] : 'daily', 'time' => $options['time'] ? $options['time'] : '02:00',
                        'minute' => (int)$options['minute'], 'weekday' => $options['weekday'] !== null && $options['weekday'] !== false ? (int)$options['weekday'] : 1,
                        'monthday' => $options['monthday'] ? (int)$options['monthday'] : 1, 'expression' => (string)$options['expression'] );
    $owner = $options['owner'] ? $options['owner'] : 'admin';
    if ( !eZUser::fetchByName( $owner ) )
        $fail( "No user $owner (--owner)." );
    $result = XrowExtractSchedule::saveFrom( array(
        'name' => (string)$options['name'], 'kind' => $kind, 'definition' => $definition, 'frequency' => $frequency,
        'delta_mode' => $options['delta'] ? 'delta' : 'full',
        'destination_ids' => $options['destinations'] ? explode( ',', $options['destinations'] ) : array(),
        'notify' => array( 'failure_emails' => (string)$options['notify-emails'] ),
    ), $owner );
    if ( $result['errors'] )
        $fail( implode( "\n", $result['errors'] ) );
    $s = $result['schedule'];
    $cli->output( sprintf( 'PASS: schedule %d "%s" created: %s, next run %s.', $s->attribute( 'id' ), $s->attribute( 'name' ), $s->frequencyText(), $when( $s->attribute( 'next_run' ) ) ) );
    $script->shutdown( 0 );
}

$fail( 'Nothing to do: --list, --show, --run, --enable, --disable, --cron, --crontab, --create, --delete or --next (--help).' );
