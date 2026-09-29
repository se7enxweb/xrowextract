#!/usr/bin/env php
<?php
/**
 * The site archive from the command line: everything the xrowextract/archive
 * view does (node sets or single nodes, classes, archive format, separator,
 * line endings, quoting), with the same code. One CSV per class, a
 * manifest.json and a README.txt, packed as zip, tar.gz, tar.bz2, tar.xz,
 * 7z or rar.
 *
 * Usage (from the installation root; ./console ext:xrowextract:archive runs it too):
 *   php extension/xrowextract/bin/php/archive.php                                  (content and media, all classes, zip)
 *   php extension/xrowextract/bin/php/archive.php --set=everything --format=tar.xz --output=var/backups/
 *   php extension/xrowextract/bin/php/archive.php --nodes=2,43 --exclude-classes=image,file
 *   php extension/xrowextract/bin/php/archive.php --nodes=89 --classes=ng_article,ng_blog_post --separator=semicolon
 *   php extension/xrowextract/bin/php/archive.php --dry-run --set=content
 *   php extension/xrowextract/bin/php/archive.php --list-sets | --list-formats | --list-classes --set=content_media
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array(
    'description'    => "Exports the content below nodes as one archive: a CSV file for every class, with a manifest (zip, tar.gz, tar.bz2, tar.xz, 7z, rar).",
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
) );
$script->startup();
$options = $script->getOptions(
    '[set:][nodes:][classes:][exclude-classes:][format:][separator:][line-endings:][unquoted][password-hashes][languages:][columns:][plain-text][output:][dry-run][list-sets][list-formats][list-classes][user:]',
    '',
    array(
        'set'             => 'A ready-made node set (default content_media); --list-sets shows them',
        'nodes'           => 'Comma list of node ids instead of a set (a node inside another is read once, with it)',
        'classes'         => 'Comma list of class ids or identifiers to export (default: every class with objects)',
        'exclude-classes' => 'Comma list of class ids or identifiers to leave out',
        'format'          => 'zip (default), tar.gz, tar.bz2, tar.xz, 7z, rar; --list-formats shows what this server can write',
        'separator'       => 'One character, or comma (default), semicolon, tab, pipe',
        'line-endings'    => 'win32/crlf (default), unix/lf, mac/cr',
        'unquoted'        => 'Do not quote cells',
        'languages'       => 'all (default) or a comma list of locales: one row per object per language',
        'columns'         => 'standard (default: identity and every attribute), migration, attributes',
        'plain-text'      => 'Add the plain text of every rich text field',
        'password-hashes' => 'Add the password hash and hash type to classes with a user account (needs the policy xrowextract/password_hash)',
        'output'          => 'File, or directory to write the archive into (default: the current directory, named <site>_export_<date>.<format>)',
        'dry-run'         => 'Show what would be exported, write nothing',
        'list-sets'       => 'List the node sets',
        'list-formats'    => 'List the archive formats and whether this server can write them',
        'list-classes'    => 'List the classes with how many objects each has below the nodes',
        'user'            => 'Export with the read access of this login (default: admin)',
    )
);
$script->initialize();

$fail = function ( $message ) use ( $cli, $script )
{
    $cli->error( $message );
    $script->shutdown( 1 );
};

$login = $options['user'] ? $options['user'] : 'admin';
$user = eZUser::fetchByName( $login );
if ( !$user instanceof eZUser )
    $fail( "No user with login $login (--user)." );
$user->loginCurrent();
if ( $options['password-hashes'] && !XrowExtractColumns::allowPasswordHash() )
    $fail( "$login may not export password hashes (policy xrowextract/password_hash, or csv.ini AllowPasswordHashExport=disabled)." );

$sets = XrowExtractArchive::nodeSets();
$formats = XrowExtractArchive::formats();

if ( $options['list-sets'] )
{
    foreach ( $sets as $id => $set )
        $cli->output( sprintf( '  %-14s %-28s nodes %s', $id, $set['name'], implode( ', ', $set['nodes'] ) ) );
    $script->shutdown( 0 );
}
if ( $options['list-formats'] )
{
    foreach ( $formats as $id => $format )
        $cli->output( sprintf( '  %-8s %-8s %s', $id, $format['available'] ? 'yes' : 'no', $format['available'] ? '' : 'needs ' . $format['needs'] ) );
    $script->shutdown( 0 );
}

// Nodes
if ( $options['nodes'] )
{
    $nodeIDs = array_filter( array_map( 'intval', explode( ',', $options['nodes'] ) ) );
}
else
{
    $setID = $options['set'] ? $options['set'] : 'content_media';
    if ( !isset( $sets[$setID] ) )
        $fail( "Unknown set $setID (--set). Sets: " . implode( ', ', array_keys( $sets ) ) . '.' );
    $nodeIDs = $sets[$setID]['nodes'];
}
$resolved = XrowExtractArchive::resolveNodes( $nodeIDs );
foreach ( $resolved as $item )
{
    if ( !$item['node'] )
        $fail( "Node {$item['id']} does not exist or $login may not read it." );
}
$roots = XrowExtractArchive::exportRoots( $resolved );
$contentLanguages = array_keys( XrowExtractColumns::contentLanguages() );
if ( !$options['languages'] || $options['languages'] === 'all' )
    $languages = $contentLanguages;
else
{
    $languages = array_values( array_filter( array_map( 'trim', explode( ',', $options['languages'] ) ) ) );
    foreach ( $languages as $locale )
        if ( !in_array( $locale, $contentLanguages, true ) )
            $fail( "Unknown language $locale (--languages). Languages: " . implode( ', ', $contentLanguages ) . '.' );
}
$columnChoice = $options['columns'] ? $options['columns'] : 'standard';
if ( !array_key_exists( $columnChoice, XrowExtractArchive::columnChoices() ) )
    $fail( "Unknown column choice $columnChoice (--columns). Choices: " . implode( ', ', array_keys( XrowExtractArchive::columnChoices() ) ) . '.' );
$counts = XrowExtractArchive::classCounts( $roots, $languages );

// Classes
$classID = function ( $value ) use ( $fail )
{
    $class = ctype_digit( $value ) ? eZContentClass::fetch( (int)$value ) : eZContentClass::fetchByIdentifier( $value );
    if ( !$class instanceof eZContentClass )
        $fail( "No class $value." );
    return (int)$class->attribute( 'id' );
};
$list = function ( $option ) use ( $classID )
{
    return array_map( $classID, array_filter( array_map( 'trim', explode( ',', (string)$option ) ) ) );
};
$selected = $options['classes'] ? array_values( array_intersect( $list( $options['classes'] ), array_keys( $counts ) ) ) : array_keys( $counts );
if ( $options['exclude-classes'] )
    $selected = array_values( array_diff( $selected, $list( $options['exclude-classes'] ) ) );

if ( $options['list-classes'] || $options['dry-run'] )
{
    foreach ( $resolved as $item )
        $cli->output( sprintf( '  node %-6d %-30s %s', $item['id'], $item['node']->attribute( 'name' ),
                               $item['covered_by'] ? 'inside ' . $item['covered_by']->attribute( 'name' ) . ', read with it'
                                                   : XrowExtractArchive::subtreeCount( $item['node'] ) . ' objects' ) );
    $rows = 0;
    foreach ( $counts as $id => $count )
    {
        $class = eZContentClass::fetch( $id );
        $on = in_array( $id, $selected, true );
        $rows += $on ? $count : 0;
        $cli->output( sprintf( '  %s %5d  %-32s %6d rows', $on ? '[x]' : '[ ]', $id, $class->attribute( 'identifier' ) . '.csv', $count ) );
    }
    $cli->output( sprintf( '%d files, %d rows, format %s, languages %s, columns %s', count( $selected ), $rows, $options['format'] ? $options['format'] : 'zip',
                           implode( '+', $languages ), $columnChoice ) );
    $script->shutdown( 0 );
}
if ( !$selected )
    $fail( 'No classes to export (--classes / --exclude-classes).' );

// Format
$format = $options['format'] ? $options['format'] : 'zip';
if ( !isset( $formats[$format] ) )
    $fail( "Unknown format $format. Formats: " . implode( ', ', array_keys( $formats ) ) . '.' );
if ( !$formats[$format]['available'] )
    $fail( "This server cannot write $format: it needs {$formats[$format]['needs']}." );
$separators = array( 'comma' => ',', 'semicolon' => ';', 'tab' => "\t", '\t' => "\t", 'pipe' => '|' );
$separator = $options['separator'] ? (string)$options['separator'] : ',';
$separator = isset( $separators[$separator] ) ? $separators[$separator] : $separator;
if ( strlen( $separator ) !== 1 || strpbrk( $separator, "\"\r\n" ) !== false )
    $fail( '--separator is one character (not a quote or line break), or comma, semicolon, tab, pipe.' );
$lines = array( 'win32' => "\r\n", 'crlf' => "\r\n", 'windows' => "\r\n", 'unix' => "\n", 'lf' => "\n", 'mac' => "\r", 'cr' => "\r" );
$lineKey = $options['line-endings'] ? strtolower( $options['line-endings'] ) : 'win32';
if ( !isset( $lines[$lineKey] ) )
    $fail( '--line-endings is win32 (crlf), unix (lf) or mac (cr).' );

$started = microtime( true );
try
{
    $result = XrowExtractArchive::build( $roots, $selected, $format, $separator, !$options['unquoted'], $lines[$lineKey], (bool)$options['password-hashes'],
                                          array( 'languages' => $languages, 'columns' => $columnChoice, 'plain_text' => (bool)$options['plain-text'] ) );
}
catch ( Exception $e )
{
    $fail( 'The archive could not be written: ' . $e->getMessage() );
}
$target = $options['output'] ? $options['output'] : '.';
if ( is_dir( $target ) )
    $target = rtrim( $target, '/' ) . '/' . $result['name'];
if ( !@copy( $result['path'], $target ) )
{
    XrowExtractArchive::removeWork( $result['work'] );
    $fail( "Cannot write $target (--output)." );
}
XrowExtractArchive::removeWork( $result['work'] );
$cli->output( sprintf( 'Wrote %s: %d files, %d rows, %.1f KB, %.1f s (read access of %s%s)', $target, count( $result['manifest']['classes'] ),
                       $result['manifest']['rows'], filesize( $target ) / 1024, microtime( true ) - $started, $login,
                       $result['manifest']['password_hashes'] ? ', with password hashes' : '' ) );
$script->shutdown( 0 );
