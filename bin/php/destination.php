#!/usr/bin/env php
<?php
/**
 * Delivery destinations from the command line (./console ext:xrowextract:destination runs it too).
 * Secret values are never printed; they are given through an environment variable or a file, never
 * on the command line itself (which the process list and the shell history would show).
 *
 *   php extension/xrowextract/bin/php/destination.php --list [--json]
 *   php extension/xrowextract/bin/php/destination.php --test=2
 *   php extension/xrowextract/bin/php/destination.php --scan-host-key=2            (SFTP: show the server's host keys)
 *   php extension/xrowextract/bin/php/destination.php --trust-host-key=2 --fingerprint=SHA256:...
 *   php extension/xrowextract/bin/php/destination.php --create --name=Partner --type=sftp \
 *        --config=host=sftp.example.com --config=user=exp --config=path=/incoming --config=auth=key \
 *        --secret-file=private_key=/root/.ssh/partner_ed25519
 *   php extension/xrowextract/bin/php/destination.php --update=2 --secret-env=password=PARTNER_PASSWORD
 *   php extension/xrowextract/bin/php/destination.php --update=2 --clear-secret=password
 *   php extension/xrowextract/bin/php/destination.php --send=2 --file=var/export.csv  (deliver a file by hand)
 *   php extension/xrowextract/bin/php/destination.php --delete=2
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array(
    'description'    => "Lists, tests and manages xrowextract delivery destinations.",
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
) );
$script->startup();
$options = $script->getOptions(
    '[list][json][test:][scan-host-key:][trust-host-key:][fingerprint:][create][update:][delete:][send:][file:][name:][type:]' .
    '[config:*][secret-env:*][secret-file:*][clear-secret:*][owner:]',
    '',
    array(
        'list'           => 'List the destinations: id, type, name, where, which secrets are set, last test',
        'json'           => 'With --list: machine readable (still without secret values)',
        'test'           => '"Test connection" for one destination',
        'scan-host-key'  => 'SFTP: print the host keys and fingerprints the server offers',
        'trust-host-key' => 'SFTP: trust the server\'s host key whose fingerprint is --fingerprint',
        'fingerprint'    => 'With --trust-host-key: the SHA256:... fingerprint you checked',
        'create'         => 'Create a destination: --name, --type, --config, --secret-env/--secret-file',
        'update'         => 'Change a destination: --name, --config, --secret-env/--secret-file, --clear-secret',
        'delete'         => 'Delete a destination',
        'send'           => 'Deliver --file to this destination now (with retries), its manifest too when it has one',
        'file'           => 'With --send: the file',
        'name'           => 'The destination\'s name',
        'type'           => 'sftp, ftp, local, s3, webdav or http',
        'config'         => 'A setting, key=value (repeatable); --list-fields shows the keys of a type in --list --json',
        'secret-env'     => 'A secret from an environment variable: name=VARIABLE (repeatable)',
        'secret-file'    => 'A secret from a file: name=/path (repeatable), e.g. a private key',
        'clear-secret'   => 'Remove a stored secret (repeatable)',
        'owner'          => 'The login recorded as its creator (default: admin)',
    )
);
$script->initialize();

$fail = function ( $message ) use ( $cli, $script ): never
{
    $cli->error( $message );
    $script->shutdown( 1 );
    exit( 1 ); // shutdown() with an exit code exits; this only states it
};
$admin = eZUser::fetchByName( 'admin' );
if ( $admin instanceof eZUser )
    $admin->loginCurrent();
$load = function ( $id ) use ( $fail )
{
    $destination = XrowExtractDestination::fetch( (int)$id );
    if ( !$destination )
        $fail( "No destination $id." );
    return $destination;
};
$pairs = function ( $values )
{
    $out = array();
    foreach ( (array)$values as $entry )
    {
        if ( strpos( (string)$entry, '=' ) === false )
            continue;
        list( $key, $value ) = explode( '=', (string)$entry, 2 );
        $out[trim( $key )] = $value;
    }
    return $out;
};

if ( $options['list'] )
{
    $rows = array();
    foreach ( XrowExtractDestination::fetchList() as $d )
    {
        $class = XrowExtractDestination::transportClass( $d->attribute( 'dest_type' ) );
        $rows[] = array(
            'id' => (int)$d->attribute( 'id' ), 'name' => $d->attribute( 'name' ), 'type' => $d->attribute( 'dest_type' ),
            'where' => $d->summary(), 'secrets_set' => $d->secretNames(), 'unencrypted' => $d->isUnencrypted(),
            'available' => $class ? call_user_func( array( $class, 'unavailableReason' ) ) === '' : false,
            'last_test' => $d->lastTestArray(),
            'fields' => $class ? array_keys( call_user_func( array( $class, 'fields' ) ) ) : array(),
            'secret_fields' => $class ? array_keys( call_user_func( array( $class, 'secretFields' ) ) ) : array(),
        );
    }
    if ( $options['json'] )
    {
        $cli->output( json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $script->shutdown( 0 );
    }
    if ( !$rows )
        $cli->output( 'No destinations.' );
    foreach ( $rows as $row )
    {
        $cli->output( sprintf( '  %4d %-7s %-24s %-44s secrets: %-22s %s%s', $row['id'], $row['type'], mb_substr( $row['name'], 0, 24 ), mb_substr( $row['where'], 0, 44 ),
                               $row['secrets_set'] ? implode( ',', $row['secrets_set'] ) : '-',
                               $row['last_test'] ? ( $row['last_test']['ok'] ? 'test ok ' : 'test FAILED ' ) . date( 'Y-m-d H:i', $row['last_test']['time'] ) : 'never tested',
                               $row['unencrypted'] ? '  [UNENCRYPTED]' : '' ) );
    }
    $script->shutdown( 0 );
}

if ( $options['test'] )
{
    $d = $load( $options['test'] );
    $result = $d->test();
    $cli->output( ( $result['ok'] ? 'PASS' : 'FAIL' ) . ': ' . $result['message'] );
    if ( !empty( $result['keys'] ) )
    {
        foreach ( $result['keys'] as $key )
            $cli->output( '  host key ' . $key['fingerprint'] );
        $cli->output( '  Trust one with --trust-host-key=' . (int)$d->attribute( 'id' ) . ' --fingerprint=<SHA256:...>' );
    }
    $script->shutdown( $result['ok'] ? 0 : 1 );
}

if ( $options['scan-host-key'] || $options['trust-host-key'] )
{
    $d = $load( $options['scan-host-key'] ?: $options['trust-host-key'] );
    $transport = $d->transport();
    if ( !$transport instanceof XrowExtractTransportSftp )
        $fail( 'Only an SFTP destination has host keys.' );
    $scan = $transport->scanHostKeys();
    if ( !$scan['ok'] )
        $fail( $scan['message'] );
    if ( $options['scan-host-key'] )
    {
        foreach ( $scan['keys'] as $key )
            $cli->output( '  ' . $key['fingerprint'] );
        $script->shutdown( 0 );
    }
    $wanted = trim( (string)$options['fingerprint'] );
    if ( $wanted === '' )
        $fail( 'Give the fingerprint you checked: --fingerprint=SHA256:...' );
    $lines = array();
    foreach ( $scan['keys'] as $key )
    {
        if ( strpos( $key['fingerprint'], $wanted ) === 0 )
            $lines[] = $key['line'];
    }
    if ( !$lines )
        $fail( "The server offers no host key with the fingerprint $wanted." );
    $d->trustHostKeys( $lines );
    $cli->output( 'PASS: host key trusted for ' . $d->attribute( 'name' ) . '.' );
    $script->shutdown( 0 );
}

if ( $options['create'] || $options['update'] )
{
    $existing = $options['update'] ? $load( $options['update'] ) : null;
    $secrets = array();
    foreach ( $pairs( $options['secret-env'] ) as $name => $variable )
    {
        $value = getenv( trim( $variable ) );
        if ( $value === false )
            $fail( "The environment variable $variable is not set (--secret-env)." );
        $secrets[$name] = $value;
    }
    foreach ( $pairs( $options['secret-file'] ) as $name => $path )
    {
        if ( !is_file( $path ) || !is_readable( $path ) )
            $fail( "Cannot read $path (--secret-file)." );
        $secrets[$name] = (string)file_get_contents( $path );
    }
    $config = $existing ? $existing->configArray() : array();
    $config = array_merge( $config, $pairs( $options['config'] ) );
    $result = XrowExtractDestination::saveFrom( array(
        'name' => $options['name'] ? $options['name'] : ( $existing ? $existing->attribute( 'name' ) : '' ),
        'type' => (string)$options['type'], 'config' => $config, 'secrets' => $secrets,
        'clear_secrets' => (array)$options['clear-secret'],
    ), $options['owner'] ? $options['owner'] : 'admin', $existing );
    if ( $result['errors'] )
        $fail( implode( "\n", $result['errors'] ) );
    $d = $result['destination'];
    $cli->output( sprintf( 'PASS: destination %d "%s" %s (%s); secrets set: %s.', $d->attribute( 'id' ), $d->attribute( 'name' ), $existing ? 'changed' : 'created',
                           $d->summary(), $d->secretNames() ? implode( ', ', $d->secretNames() ) : 'none' ) );
    $script->shutdown( 0 );
}

if ( $options['send'] )
{
    $d = $load( $options['send'] );
    $file = (string)$options['file'];
    if ( !is_file( $file ) )
        $fail( "No file $file (--file)." );
    $manifest = is_file( XrowExtractManifest::sidecarPath( $file ) ) ? XrowExtractManifest::sidecarPath( $file ) : null;
    $result = $d->deliver( $file, $manifest );
    foreach ( $result['log'] as $line )
        $cli->output( '  ' . $line );
    $cli->output( ( $result['ok'] ? 'PASS' : 'FAIL' ) . ': ' . $result['message'] );
    $script->shutdown( $result['ok'] ? 0 : 1 );
}

if ( $options['delete'] )
{
    $d = $load( $options['delete'] );
    $d->remove();
    $cli->output( 'Destination ' . (int)$options['delete'] . ' deleted.' );
    $script->shutdown( 0 );
}

$fail( 'Nothing to do: --list, --test, --scan-host-key, --trust-host-key, --create, --update, --send or --delete (--help).' );
