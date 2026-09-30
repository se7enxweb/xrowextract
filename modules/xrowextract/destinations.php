<?php
/**
 * xrowextract/destinations: named, reusable delivery destinations (SFTP, FTP/FTPS, a local or NAS
 * folder, S3 compatible storage, WebDAV, HTTP POST). Policy xrowextract/destinations. Secrets are
 * write only: the form offers set, replace or clear, and never puts a stored value into the page.
 */

$module = $Params['Module'];
$http = eZHTTPTool::instance();
$tpl = eZTemplate::factory();
$login = eZUser::currentUser()->attribute( 'login' );
$errors = array();
$notice = false;
$testResult = null;
$editValues = null;

$backTo = function ( $anchor = '' ) use ( $module )
{
    return $module->redirectTo( 'xrowextract/destinations' . ( $anchor !== '' ? '#' . $anchor : '' ) );
};
$types = XrowExtractDestination::types();

// The form for a type: its fields with the current values, and its secret fields with "is it set"
$formFor = function ( $type, array $config, array $secretNames ) use ( $types )
{
    $class = $types[$type][1];
    $fields = array();
    foreach ( call_user_func( array( $class, 'fields' ) ) as $name => $spec )
    {
        $options = array();
        if ( $spec[1] === 'select' )
        {
            foreach ( $spec[3] as $value => $label )
                $options[] = array( 'value' => $value, 'label' => ezpI18n::tr( 'design/standard/extract', $label ) );
        }
        $fields[] = array( 'name' => $name, 'label' => ezpI18n::tr( 'design/standard/extract', $spec[0] ), 'kind' => $spec[1],
                           'value' => isset( $config[$name] ) ? (string)$config[$name] : (string)$spec[2], 'options' => $options );
    }
    $secrets = array();
    foreach ( call_user_func( array( $class, 'secretFields' ) ) as $name => $label )
        $secrets[] = array( 'name' => $name, 'label' => ezpI18n::tr( 'design/standard/extract', $label ), 'is_set' => in_array( $name, $secretNames, true ),
                            'multiline' => $name === 'private_key' );
    return array( 'type' => $type, 'type_name' => ezpI18n::tr( 'design/standard/extract', $types[$type][0] ), 'fields' => $fields, 'secrets' => $secrets,
                  'unavailable' => call_user_func( array( $class, 'unavailableReason' ) ) );
};

if ( $http->hasPostVariable( 'CancelEdit' ) )
    return $backTo();

if ( $http->hasPostVariable( 'NewDestination' ) )
{
    $type = $http->hasPostVariable( 'NewDestinationType' ) && isset( $types[$http->postVariable( 'NewDestinationType' )] ) ? $http->postVariable( 'NewDestinationType' ) : 'sftp';
    $editValues = array( 'id' => 0, 'name' => '', 'form' => $formFor( $type, array(), array() ) );
}
elseif ( $http->hasPostVariable( 'EditDestinationID' ) )
{
    $destination = XrowExtractDestination::fetch( XrowExtractColumns::dbID( $http->postVariable( 'EditDestinationID' ) ) );
    if ( $destination )
        $editValues = array( 'id' => (int)$destination->attribute( 'id' ), 'name' => $destination->attribute( 'name' ),
                             'form' => $formFor( $destination->attribute( 'dest_type' ), $destination->configArray(), $destination->secretNames() ) );
}
elseif ( $http->hasPostVariable( 'SaveDestination' ) )
{
    $posted = $http->hasPostVariable( 'Destination' ) ? (array)$http->postVariable( 'Destination' ) : array();
    $id = isset( $posted['id'] ) ? (int)$posted['id'] : 0;
    $existing = $id ? XrowExtractDestination::fetch( $id ) : null;
    if ( $id && !$existing )
        return $backTo();
    $type = $existing ? $existing->attribute( 'dest_type' ) : ( isset( $posted['type'] ) ? (string)$posted['type'] : '' );
    $result = XrowExtractDestination::saveFrom( array(
        'name' => isset( $posted['name'] ) ? $posted['name'] : '',
        'type' => $type,
        'config' => isset( $posted['config'] ) ? (array)$posted['config'] : array(),
        'secrets' => isset( $posted['secret'] ) ? (array)$posted['secret'] : array(),
        'clear_secrets' => isset( $posted['clear'] ) ? array_keys( (array)$posted['clear'] ) : array(),
    ), $login, $existing );
    $saved = $result['destination'];
    if ( $result['errors'] || !$saved )
    {
        $errors = array_map( function ( $message ) { return ezpI18n::tr( 'design/standard/extract', $message ); }, $result['errors'] );
        if ( isset( $types[$type] ) )
            $editValues = array( 'id' => $id, 'name' => isset( $posted['name'] ) ? (string)$posted['name'] : '',
                                 'form' => $formFor( $type, isset( $posted['config'] ) ? (array)$posted['config'] : array(), $existing ? $existing->secretNames() : array() ) );
    }
    else
    {
        $http->setSessionVariable( 'eZExtractDestinationNotice', ezpI18n::tr( 'design/standard/extract', 'Destination saved. Test the connection to be sure.' ) );
        return $backTo( 'destination-' . (int)$saved->attribute( 'id' ) );
    }
}
elseif ( $http->hasPostVariable( 'DeleteDestinationID' ) )
{
    $destination = XrowExtractDestination::fetch( XrowExtractColumns::dbID( $http->postVariable( 'DeleteDestinationID' ) ) );
    if ( $destination )
        $destination->remove();
    return $backTo();
}
elseif ( $http->hasPostVariable( 'TestDestinationID' ) )
{
    $destination = XrowExtractDestination::fetch( XrowExtractColumns::dbID( $http->postVariable( 'TestDestinationID' ) ) );
    if ( $destination )
    {
        $result = $destination->test();
        $testResult = array( 'id' => (int)$destination->attribute( 'id' ), 'ok' => (bool)$result['ok'], 'message' => (string)$result['message'],
                             'keys' => isset( $result['keys'] ) ? $result['keys'] : array() );
    }
}
elseif ( $http->hasPostVariable( 'TrustHostKey' ) )
{
    // The key is scanned again here and only trusted when its fingerprint is the one the admin saw:
    // nothing the browser sends back is ever written into known_hosts itself
    $destination = XrowExtractDestination::fetch( XrowExtractColumns::dbID( $http->postVariable( 'TrustHostKey' ) ) );
    $fingerprint = $http->hasPostVariable( 'HostKeyFingerprint' ) ? trim( (string)$http->postVariable( 'HostKeyFingerprint' ) ) : '';
    $transport = $destination ? $destination->transport() : null;
    if ( $transport instanceof XrowExtractTransportSftp && $fingerprint !== '' )
    {
        $scan = $transport->scanHostKeys();
        $lines = array();
        foreach ( $scan['ok'] ? $scan['keys'] : array() as $key )
        {
            if ( $key['fingerprint'] === $fingerprint )
                $lines[] = $key['line'];
        }
        if ( $lines )
        {
            $destination->trustHostKeys( $lines );
            $result = $destination->test();
            $testResult = array( 'id' => (int)$destination->attribute( 'id' ), 'ok' => (bool)$result['ok'],
                                 'message' => ezpI18n::tr( 'design/standard/extract', 'Host key trusted.' ) . ' ' . $result['message'], 'keys' => array() );
        }
        else
        {
            $testResult = array( 'id' => (int)$destination->attribute( 'id' ), 'ok' => false, 'keys' => array(),
                                 'message' => ezpI18n::tr( 'design/standard/extract', 'The server no longer offers a host key with that fingerprint: nothing was trusted.' ) );
        }
    }
}

if ( $http->hasSessionVariable( 'eZExtractDestinationNotice' ) )
{
    $notice = $http->sessionVariable( 'eZExtractDestinationNotice' );
    $http->removeSessionVariable( 'eZExtractDestinationNotice' );
}

// Which schedules use a destination (so deleting one is a considered step)
$usedBy = array();
foreach ( XrowExtractSchedule::fetchList() as $schedule )
{
    foreach ( $schedule->destinationIDList() as $id )
        $usedBy[$id][] = $schedule->attribute( 'name' );
    $def = $schedule->definitionArray();
    if ( $schedule->attribute( 'kind' ) === 'import' && !empty( $def['destination_id'] ) )
        $usedBy[(int)$def['destination_id']][] = $schedule->attribute( 'name' );
}

$rows = array();
foreach ( XrowExtractDestination::fetchList() as $destination )
{
    $id = (int)$destination->attribute( 'id' );
    $config = $destination->configArray();
    $class = XrowExtractDestination::transportClass( $destination->attribute( 'dest_type' ) );
    $rows[] = array(
        'id' => $id,
        'name' => $destination->attribute( 'name' ),
        'type' => $destination->attribute( 'dest_type' ),
        'type_name' => ezpI18n::tr( 'design/standard/extract', $destination->typeName() ),
        'summary' => $destination->summary(),
        'secret_names' => $destination->secretNames(),
        'unencrypted' => $destination->isUnencrypted(),
        'last_test' => $destination->lastTestArray(),
        'host_key_trusted' => $destination->attribute( 'dest_type' ) === 'sftp' ? !empty( $config['host_keys'] ) : null,
        'used_by' => isset( $usedBy[$id] ) ? array_values( array_unique( $usedBy[$id] ) ) : array(),
        'owner_user' => XrowExtractJob::ownerInfo( (string)$destination->attribute( 'owner_login' ) ),
        'test' => $testResult && $testResult['id'] === $id ? $testResult : null,
        // A destination of a type this installation no longer has: listed, marked, never called
        'unavailable' => $class ? $class::unavailableReason() : 'unknown destination type',
    );
}
$typeChoices = array();
foreach ( $types as $type => $spec )
    $typeChoices[] = array( 'type' => $type, 'name' => ezpI18n::tr( 'design/standard/extract', $spec[0] ),
                            'unavailable' => call_user_func( array( $spec[1], 'unavailableReason' ) ) );

$tpl->setVariable( 'destinations', $rows );
$tpl->setVariable( 'edit', $editValues );
$tpl->setVariable( 'errors', $errors );
$tpl->setVariable( 'notice', $notice );
$tpl->setVariable( 'type_choices', $typeChoices );
$tpl->setVariable( 'local_roots', XrowExtractTransportLocal::allowedRoots() );
$tpl->setVariable( 'secrets_available', XrowExtractSecrets::available() );
$tpl->setVariable( 'RunningJobsCount', XrowExtractJob::countRunning( $login, XrowExtractJob::allowAllJobs() ) );
$scriptFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract.js';
$tpl->setVariable( 'ScriptVersion', is_file( $scriptFile ) ? substr( (string)md5_file( $scriptFile ), 0, 12 ) : '0' );
$scheduleScript = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract-schedules.js';
$tpl->setVariable( 'ScheduleScriptVersion', is_file( $scheduleScript ) ? substr( (string)md5_file( $scheduleScript ), 0, 12 ) : '0' );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/destinations.tpl' );
$Result['path'] = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Destinations' ) ),
);
$Result['left_menu'] = 'design:xrowextract/menu_schedules.tpl';

?>
