<?php
/**
 * The code of extension/xrowextract/bin/php/archive.php, moved into a class (#207 stage 1). The file extension/xrowextract/bin/php/archive.php is one call to it.
 * Guide: doc/bc/6.0/cli_cronjob_view_abstractions.md
 * @description Export the content below nodes as one archive: a CSV file per class and a manifest
 */
/*
 * The original header of extension/xrowextract/bin/php/archive.php:
 *
 *
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
 *
 */

namespace Exponential\Command\Extension\Xrowextract
{

class Archive extends \Exponential\Runnable\Command
{
    public function run()
    {
        // the script's variables were globals; functions of the script read them with "global"
        foreach ( array( 'acc', 'archiveFilters', 'archiveManifest', 'attributeFilter', 'buildOptions', 'changedSince', 'class', 'classID', 'cli', 'columnChoice', 'contentLanguages', 'count', 'counts', 'dateOption', 'e', 'fail', 'files', 'filterValues', 'format', 'formats', 'id', 'identifier', 'index', 'item', 'languages', 'lenient', 'lineKey', 'lines', 'list', 'locale', 'login', 'nodeIDs', 'on', 'options', 'progressBase', 'progressFile', 'progressTotal', 'resolved', 'result', 'roots', 'rows', 'script', 'section', 'selected', 'separator', 'separators', 'set', 'setID', 'sets', 'sidecar', 'skipRun', 'started', 'target', 'user', 'warn', 'warnings' ) as $__name )
            ${$__name} = &$GLOBALS[$__name];
        unset( $__name );

        $cli = $this->cli();
        $script = $this->script( array(
            'description'    => "Exports the content below nodes as one archive: a CSV file for every class, with a manifest (zip, tar.gz, tar.bz2, tar.xz, 7z, rar).",
            'use-session'    => false,
            'use-modules'    => true,
            'use-extensions' => true,
        ) );
        $options = $this->startup(
            '[set:][nodes:][classes:][exclude-classes:][format:][separator:][line-endings:][unquoted][password-hashes][languages:][columns:][plain-text][files:][date-field:][since:][before:][date:][section:][visibility:][name:][output:][dry-run][list-sets][list-formats][list-classes][user:][progress-file:][changed-since:][lenient][no-manifest][schedule:][run-mode:]',
            '',
            array(
                'set'             => 'A ready-made node set (default sites: the default site, see export.ini [SiteArchive]); --list-sets shows them',
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
                'files'           => 'The files in the archive: csv (default), json, xml',
                'date-field'      => 'modified (default) or published, for --since / --before / --date',
                'since'           => 'Only objects dated on or after this: 2026-09-01, or 7d / 2w / 3m / 1y ago',
                'before'          => 'Only objects dated on or before this',
                'date'            => 'today, 7, 30, 90, 365 (last days) or past',
                'section'         => 'Only objects in this section (id or identifier)',
                'visibility'      => 'visible or hidden',
                'name'            => 'Only objects whose name contains this',
                'password-hashes' => 'Add the password hash and hash type to classes with a user account (needs the policy xrowextract/password_hash)',
                'output'          => 'File, or directory to write the archive into (default: the current directory, named <site>_export_<date>.<format>)',
                'dry-run'         => 'Show what would be exported, write nothing',
                'list-sets'       => 'List the node sets',
                'list-formats'    => 'List the archive formats and whether this server can write them',
                'list-classes'    => 'List the classes with how many objects each has below the nodes',
                'user'            => 'Export with the read access of this login (default: admin)',
                'progress-file'   => 'Write {"done":n,"total":m,"phase":"<class>"} to this path after every batch (for a background job)',
                'changed-since'   => 'Only objects modified after this Unix time or date (replaces --since/--date): a delta run',
                'lenient'         => 'Skip nodes and classes that no longer exist with a WARNING line instead of failing; exit code 3 when nothing is left',
                'no-manifest'     => 'Do not write <archive>.manifest.json next to the archive (the manifests inside it are always written)',
                'schedule'        => 'The schedule this run belongs to (recorded in the manifest)',
                'run-mode'        => 'full or delta (recorded in the manifest)',
            )
        );

        $fail = function ( $message ) use ( $cli, $script ): never
        {
            $cli->error( $message );
            $script->shutdown( 1 );
            exit( 1 ); // shutdown() with an exit code exits; this only states it
        };
        $warnings = array();
        $lenient = (bool)$options['lenient'];
        $warn = function ( $message ) use ( $cli, &$warnings )
        {
            $warnings[] = $message;
            $cli->output( 'WARNING: ' . $message );
        };
        $skipRun = function ( $message ) use ( $cli, $script, $warn )
        {
            $warn( $message );
            $cli->output( 'Nothing left to export; skipped.' );
            $script->shutdown( 3 );
        };
        $changedSince = false;
        if ( $options['changed-since'] )
        {
            $changedSince = ctype_digit( (string)$options['changed-since'] ) ? (int)$options['changed-since'] : \XrowExtractFilters::timestamp( $options['changed-since'] );
            if ( !$changedSince )
                $fail( "Cannot read the date in --changed-since: {$options['changed-since']}" );
        }

        $login = $options['user'] ? $options['user'] : 'admin';
        $user = \eZUser::fetchByName( $login );
        if ( !$user instanceof \eZUser )
            $fail( "No user with login $login (--user)." );
        $user->loginCurrent();
        if ( $options['password-hashes'] && !\XrowExtractColumns::allowPasswordHash() )
            $fail( "$login may not export password hashes (policy xrowextract/password_hash, or csv.ini AllowPasswordHashExport=disabled)." );

        $sets = \XrowExtractArchive::nodeSets();
        $formats = \XrowExtractArchive::formats();

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
            $nodeIDs = array_filter( array_map( 'XrowExtractColumns::dbID', explode( ',', $options['nodes'] ) ) );
        }
        else
        {
            $setID = $options['set'] ? $options['set'] : 'sites';
            if ( !isset( $sets[$setID] ) )
                $fail( "Unknown set $setID (--set). Sets: " . implode( ', ', array_keys( $sets ) ) . '.' );
            $nodeIDs = $sets[$setID]['nodes'];
        }
        $resolved = \XrowExtractArchive::resolveNodes( $nodeIDs );
        foreach ( $resolved as $index => $item )
        {
            if ( !$item['node'] )
            {
                if ( $lenient )
                {
                    $warn( "Node {$item['id']} was skipped: it no longer exists or $login may not read it." );
                    unset( $resolved[$index] );
                    continue;
                }
                $fail( "Node {$item['id']} does not exist or $login may not read it." );
            }
        }
        $resolved = array_values( $resolved );
        if ( !$resolved )
            $skipRun( 'None of the nodes exist any more.' );
        $roots = \XrowExtractArchive::exportRoots( $resolved );
        // Filters that work for every class
        $filterValues = array( 'date_field' => $options['date-field'] === 'published' ? 'published' : 'modified' );
        if ( $options['date-field'] && !in_array( $options['date-field'], array( 'published', 'modified' ), true ) )
            $fail( '--date-field is modified or published.' );
        foreach ( array( 'since', 'before' ) as $dateOption )
            if ( $options[$dateOption] && \XrowExtractFilters::timestamp( $options[$dateOption] ) === false )
                $fail( "Cannot read the date in --$dateOption: {$options[$dateOption]}" );
        $filterValues['date_mode'] = $options['since'] && $options['before'] ? 'between' : ( $options['since'] ? 'since' : ( $options['before'] ? 'before' : 'any' ) );
        if ( $options['date'] )
        {
            if ( !in_array( $options['date'], array( 'today', '7', '30', '90', '365', 'past' ), true ) )
                $fail( '--date is today, 7, 30, 90, 365 or past.' );
            $filterValues['date_mode'] = $options['date'];
        }
        // Both are readable dates here: the loop above refused one that is not
        $filterValues['date_from'] = $options['since'] ? date( 'Y-m-d H:i:s', (int)\XrowExtractFilters::timestamp( $options['since'] ) ) : '';
        $filterValues['date_to'] = $options['before'] ? date( 'Y-m-d H:i:s', (int)\XrowExtractFilters::timestamp( $options['before'], true ) ) : '';
        if ( $options['section'] )
        {
            $section = ctype_digit( (string)$options['section'] ) ? \eZSection::fetch( \XrowExtractColumns::dbID( $options['section'] ) ) : \eZSection::fetchByIdentifier( $options['section'] );
            if ( !$section )
                $fail( "No section {$options['section']} (--section)." );
            $filterValues['section'] = (int)$section->attribute( 'id' );
        }
        if ( $options['visibility'] && !in_array( $options['visibility'], array( 'visible', 'hidden' ), true ) )
            $fail( '--visibility is visible or hidden.' );
        $filterValues['visibility'] = $options['visibility'] ? $options['visibility'] : 'any';
        $filterValues['name'] = (string)$options['name'];
        if ( $changedSince )
        {
            // A delta run: only what changed since the last successful run (the one date filter the fetch has)
            if ( $filterValues['date_mode'] !== 'any' )
                $warn( 'The delta run replaces the date filter (' . $filterValues['date_mode'] . ').' );
            $filterValues['date_mode'] = 'since';
            $filterValues['date_from'] = (string)$changedSince;
            $filterValues['date_to'] = '';
            $filterValues['date_field'] = 'modified';
        }
        $archiveFilters = new \XrowExtractFilters( $filterValues );
        \XrowExtractArchive::$attributeFilter = $archiveFilters->attributeFilter( false );
        $contentLanguages = array_keys( \XrowExtractColumns::contentLanguages() );
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
        if ( !array_key_exists( $columnChoice, \XrowExtractArchive::columnChoices() ) )
            $fail( "Unknown column choice $columnChoice (--columns). Choices: " . implode( ', ', array_keys( \XrowExtractArchive::columnChoices() ) ) . '.' );
        $files = $options['files'] ? $options['files'] : 'csv';
        if ( !\XrowExtractWriter::isRowFormat( $files ) )
            $fail( "Unknown file format $files (--files). Formats: " . implode( ', ', array_keys( \XrowExtractWriter::rowFormats() ) ) . '.' );
        $counts = \XrowExtractArchive::classCounts( $roots, $languages );

        // Classes
        $classID = function ( $value ) use ( $fail, $lenient, $warn )
        {
            $class = ctype_digit( $value ) ? \eZContentClass::fetch( \XrowExtractColumns::dbID( $value ) ) : \eZContentClass::fetchByIdentifier( $value );
            if ( !$class instanceof \eZContentClass )
            {
                if ( $lenient )
                {
                    $warn( "The class $value was skipped: it no longer exists." );
                    return 0;
                }
                $fail( "No class $value." );
            }
            return (int)$class->attribute( 'id' );
        };
        $list = function ( $option ) use ( $classID )
        {
            return array_values( array_filter( array_map( $classID, array_filter( array_map( 'trim', explode( ',', (string)$option ) ) ) ) ) );
        };
        $selected = $options['classes'] ? array_values( array_intersect( $list( $options['classes'] ), array_keys( $counts ) ) ) : array_keys( $counts );
        if ( $options['exclude-classes'] )
            $selected = array_values( array_diff( $selected, $list( $options['exclude-classes'] ) ) );

        if ( $options['list-classes'] || $options['dry-run'] )
        {
            foreach ( $resolved as $item )
            {
                if ( !$item['node'] ) // left out above
                    continue;
                $cli->output( sprintf( '  node %-6d %-30s %s', $item['id'], $item['node']->attribute( 'name' ),
                                       $item['covered_by'] ? 'inside ' . $item['covered_by']->attribute( 'name' ) . ', read with it'
                                                           : \XrowExtractArchive::subtreeCount( $item['node'] ) . ' objects' ) );
            }
            $rows = 0;
            foreach ( $counts as $id => $count )
            {
                $class = \eZContentClass::fetch( $id );
                $on = in_array( $id, $selected, true );
                $rows += $on ? $count : 0;
                $cli->output( sprintf( '  %s %5d  %-32s %6d rows', $on ? '[x]' : '[ ]', $id, $class->attribute( 'identifier' ) . '.csv', $count ) );
            }
            $cli->output( sprintf( '%d files, %d rows, format %s, languages %s, columns %s', count( $selected ), $rows, $options['format'] ? $options['format'] : 'zip',
                                   implode( '+', $languages ), $columnChoice ) );
            $script->shutdown( 0 );
        }
        if ( !$selected )
        {
            if ( $lenient )
                $skipRun( 'No classes left to export.' );
            $fail( 'No classes to export (--classes / --exclude-classes).' );
        }

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

        // A running total across every selected class: build()'s progress callback only reports the rows
        // written so far within the class it is on, so the classes already finished need to be added in.
        $buildOptions = array( 'languages' => $languages, 'columns' => $columnChoice, 'plain_text' => (bool)$options['plain-text'], 'output' => $files,
                               'filters' => $filterValues, 'warnings' => $warnings,
                               'schedule' => $options['schedule'] ? (int)$options['schedule'] : null,
                               'run_mode' => $options['run-mode'] ? $options['run-mode'] : ( $changedSince ? 'delta' : 'full' ) );
        if ( $options['progress-file'] )
        {
            $progressFile = (string)$options['progress-file'];
            $progressTotal = 0;
            foreach ( $selected as $id )
                $progressTotal += isset( $counts[$id] ) ? $counts[$id] : 0;
            $progressBase = array();
            $acc = 0;
            foreach ( $selected as $id )
            {
                $identifier = \eZContentClass::fetch( $id )->attribute( 'identifier' );
                $progressBase[$identifier] = $acc;
                $acc += isset( $counts[$id] ) ? $counts[$id] : 0;
            }
            $buildOptions['progress'] = function ( $classIdentifier, $rowsInClass ) use ( $progressFile, $progressBase, $progressTotal )
            {
                $done = ( isset( $progressBase[$classIdentifier] ) ? $progressBase[$classIdentifier] : 0 ) + $rowsInClass;
                \XrowExtractJob::writeProgress( $progressFile, $done, $progressTotal, $classIdentifier );
            };
        }

        $started = microtime( true );
        try
        {
            $result = \XrowExtractArchive::build( $roots, $selected, $format, $separator, !$options['unquoted'], $lines[$lineKey], (bool)$options['password-hashes'],
                                                  $buildOptions );
        }
        catch ( \Throwable $e )
        {
            $fail( 'The archive could not be written: ' . $e->getMessage() );
        }
        $target = $options['output'] ? $options['output'] : '.';
        if ( is_dir( $target ) )
            $target = rtrim( $target, '/' ) . '/' . $result['name'];
        if ( !@copy( $result['path'], $target ) )
        {
            \XrowExtractArchive::removeWork( $result['work'] );
            $fail( "Cannot write $target (--output)." );
        }
        \XrowExtractArchive::removeWork( $result['work'] );
        $cli->output( sprintf( 'Wrote %s: %d files, %d rows, %.1f KB, %.1f s (read access of %s%s)', $target, count( $result['manifest']['classes'] ),
                               $result['manifest']['rows'], filesize( $target ) / 1024, microtime( true ) - $started, $login,
                               $result['manifest']['password_hashes'] ? ', with password hashes' : '' ) );
        if ( !$options['no-manifest'] )
        {
            // The archive's own manifest next to it, with the archive's checksum (what a delivery or a receiving
            // system checks before unpacking); the manifests inside cover each class file
            $archiveManifest = $result['manifest'];
            $archiveManifest['counts'] = array( 'rows' => (int)$result['manifest']['rows'], 'files' => count( $result['manifest']['classes'] ) );
            $archiveManifest['file'] = array( 'name' => basename( $target ), 'bytes' => (int)filesize( $target ), 'sha256' => hash_file( 'sha256', $target ) );
            $archiveManifest['warnings'] = $warnings;
            $sidecar = \XrowExtractManifest::writeSidecar( $target, $archiveManifest );
            if ( $sidecar )
                $cli->output( 'Manifest: ' . $sidecar );
        }
        $script->shutdown( 0 );
    }
}

}
