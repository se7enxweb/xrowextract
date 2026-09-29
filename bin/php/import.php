#!/usr/bin/env php
<?php
/**
 * Reads a CSV or JSON file (as XrowExtractWriter writes it, any column set,
 * in particular "migration") back into content objects: the other direction
 * of bin/php/csv.php. A dry run (the default) never touches the database.
 *
 * Usage (from the installation root; ./console ext:xrowextract:import runs it too):
 *   php extension/xrowextract/bin/php/import.php --file=articles.csv --class=ng_article --parent=2
 *   php extension/xrowextract/bin/php/import.php --file=articles.csv --class=ng_article --parent=2 --apply
 *   php extension/xrowextract/bin/php/import.php --file=export.csv --match=object_id --language=ger-DE
 *   php extension/xrowextract/bin/php/import.php --file=export.csv --map=title=title,authors-ids=authors:ids
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array(
    'description'    => "Imports a CSV or JSON file (as exported by xrowextract) back into content objects. Dry run by default.",
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
) );
$script->startup();
$options = $script->getOptions(
    '[file:][class:][parent:][match:][language:][map:][apply][user:][report:]',
    '',
    array(
        'file'     => 'CSV or JSON file to read (required); - for stdin',
        'class'    => 'Class id or identifier, used for rows without a "class" column',
        'parent'   => 'Parent node id for new objects without a parent-remote-id/main-parent-node-id column',
        'match'    => 'remote_id (default, when the file has a remote-id column), object_id, or none (always create)',
        'language' => 'Locale for rows without a language column (default: the site default language)',
        'map'      => 'Column overrides: file-column=target,... (target: an attribute identifier, identifier:format, ' .
                       'a special column id such as ezcontentobject.remote_id, or "ignore"); default: automatic',
        'apply'    => 'Write the changes (default: dry run only)',
        'user'     => 'Import with the permissions of this login (default: admin)',
        'report'   => 'Write the per-row result as JSON to this file',
    )
);
$script->initialize();

$fail = function ( $message ) use ( $cli, $script )
{
    $cli->error( $message );
    $script->shutdown( 1 );
};

if ( !$options['file'] )
    $fail( 'Missing --file.' );

$login = $options['user'] ? $options['user'] : 'admin';
$user = eZUser::fetchByName( $login );
if ( !$user instanceof eZUser )
    $fail( "No user with login $login (--user)." );
$user->loginCurrent();

XrowExtractImport::cleanupOldUploads();

$path = $options['file'];
if ( $path === '-' )
{
    $tmp = XrowExtractImport::uploadDir() . '/cli_stdin_' . uniqid() . '.csv';
    file_put_contents( $tmp, stream_get_contents( STDIN ) );
    $path = $tmp;
}
elseif ( !is_file( $path ) )
{
    $fail( "No such file: $path (--file)." );
}
$parsed = XrowExtractImport::parseFile( $path );
if ( isset( $parsed['error'] ) )
    $fail( $parsed['error'] );
if ( !$parsed['header'] )
    $fail( 'No columns found in the file.' );

// Class (a fallback; a "class" column in the file still wins per row)
$classID = 0;
if ( $options['class'] )
{
    $class = ctype_digit( (string)$options['class'] ) ? eZContentClass::fetch( (int)$options['class'] ) : eZContentClass::fetchByIdentifier( $options['class'] );
    if ( !$class instanceof eZContentClass )
        $fail( "No class {$options['class']} (--class)." );
    $classID = (int)$class->attribute( 'id' );
}

// Mapping: automatic, then --map overrides by file column name
$mapping = XrowExtractImport::suggestMapping( $parsed['header'], $classID );
if ( $options['map'] )
{
    $overrides = array();
    foreach ( explode( ',', $options['map'] ) as $pair )
    {
        list( $col, $target ) = array_pad( array_map( 'trim', explode( '=', $pair, 2 ) ), 2, '' );
        if ( $col === '' )
            continue;
        if ( strpos( $target, ':' ) !== false && strpos( $target, 'special:' ) !== 0 && strpos( $target, 'attrfmt:' ) !== 0 && strpos( $target, 'attr:' ) !== 0 )
            $target = 'attrfmt:' . $target;
        elseif ( $target !== '' && $target !== 'ignore' && strpos( $target, ':' ) === false )
            $target = strpos( $target, '.' ) !== false ? 'special:' . $target : 'attr:' . $target;
        $overrides[$col] = $target;
    }
    foreach ( $mapping as $i => $map )
    {
        if ( isset( $overrides[$map['column']] ) )
            $mapping[$i]['target'] = $overrides[$map['column']];
    }
}

$defaultLanguage = $options['language'] ?: null;
if ( !$defaultLanguage )
{
    $languages = XrowExtractColumns::contentLanguages();
    foreach ( $languages as $locale => $lang )
    {
        if ( $lang['default'] )
            $defaultLanguage = $locale;
    }
}

$result = XrowExtractImport::run( array(
    'rows'         => $parsed['rows'],
    'mapping'      => $mapping,
    'classID'      => $classID,
    'match'        => $options['match'] ?: 'remote_id',
    'language'     => $defaultLanguage,
    'parentNodeID' => $options['parent'] ? (int)$options['parent'] : 0,
    'apply'        => (bool)$options['apply'],
) );

$cli->output( sprintf( '%s: %d rows read from %s (%s, %s)', $options['apply'] ? 'Applied' : 'Dry run',
                       count( $parsed['rows'] ), $options['file'], $parsed['format'], isset( $parsed['separator'] ) ? "separator \"{$parsed['separator']}\"" : 'JSON' ) );
foreach ( $result['counts'] as $action => $count )
    $cli->output( sprintf( '  %-10s %d', $action, $count ) );
if ( $result['ezoe'] === false )
    $cli->output( 'Note: the ezoe extension is not active; rich text columns were imported as plain paragraphs.' );

foreach ( $result['rows'] as $row )
{
    if ( $row['action'] === 'error' )
        $cli->output( sprintf( '  #%-5d ERROR  %s', $row['number'], $row['reason'] ) );
    elseif ( $row['action'] !== 'unchanged' && $row['changes'] )
    {
        $cli->output( sprintf( '  #%-5d %-8s object %s', $row['number'], strtoupper( $row['action'] ), $row['object_id'] ?: '(new)' ) );
        foreach ( $row['changes'] as $change )
            $cli->output( sprintf( '           %-24s %s -> %s', $change['field'], mb_substr( $change['old'], 0, 40 ), mb_substr( $change['new'], 0, 40 ) ) );
    }
}

if ( $options['report'] )
{
    file_put_contents( $options['report'], json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    $cli->output( 'Report written to ' . $options['report'] );
}

$script->shutdown( $result['counts']['error'] > 0 ? 1 : 0 );
