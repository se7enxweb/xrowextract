<?php
/**
 * The code of extension/xrowextract/modules/xrowextract/archive.php, moved into a class (#207 stage 1). The file extension/xrowextract/modules/xrowextract/archive.php is one call to it.
 * Guide: doc/bc/6.0/cli_cronjob_view_abstractions.md
 */
/*
 * The original header of extension/xrowextract/modules/xrowextract/archive.php:
 *
 *
 * xrowextract/archive: the content of many classes below many nodes as one
 * archive, one CSV file per class. The selection (nodes, classes, format)
 * is kept in the session; every button also works without JavaScript.
 *
 */

namespace Exponential\View\Extension\Xrowextract\Xrowextract
{

class Archive extends \Exponential\Runnable\ModuleView
{
    public function run( array $scope )
    {
        // the including function's variables ($Params, $Module, $cli, ...)
        foreach ( array_keys( $scope ) as $__name )
            if ( $__name !== 'this' && $__name !== 'scope' )
                ${$__name} = &$scope[$__name];
        unset( $__name );

        $module = $Params['Module'];
        $http = \eZHTTPTool::instance();
        $tpl = \eZTemplate::factory();
        $contentINI = \eZINI::instance( 'content.ini' );
        // A posted choice as a string, or null: an array posted in its place would be used as an array key (an Error)
        $postString = function ( $name ) use ( $http )
        {
            if ( !$http->hasPostVariable( $name ) )
                return null;
            $value = $http->postVariable( $name );
            return is_string( $value ) ? $value : null;
        };

        $state = $http->hasSessionVariable( 'eZExtractArchive' ) ? $http->sessionVariable( 'eZExtractArchive' ) : null;
        if ( !is_array( $state ) )
        {
            $sets = \XrowExtractArchive::nodeSets();
            $state = array( 'nodes' => $sets['sites']['nodes'], 'excluded' => array(), 'format' => 'zip',
                            'separator' => ',', 'escape' => true, 'line' => 'win32' );
        }
        $nodeSets = \XrowExtractArchive::nodeSets();
        $formats = \XrowExtractArchive::formats();
        $lineSeparators = array( 'win32' => "\r\n", 'unix' => "\n", 'mac' => "\r" );
        $error = false;

        // Nodes: a ready-made set, one added or removed, all removed, or chosen in the browse page
        if ( isset( $nodeSets[(string)$postString( 'UseNodeSet' )] ) )
            $state['nodes'] = $nodeSets[$postString( 'UseNodeSet' )]['nodes'];
        if ( $http->hasPostVariable( 'AddNodeID' ) && is_array( $http->postVariable( 'AddNodeID' ) ) )
        {
            foreach ( array_keys( $http->postVariable( 'AddNodeID' ) ) as $nodeID )
                $state['nodes'][] = (int)$nodeID;
        }
        if ( $http->hasPostVariable( 'RemoveNodeID' ) && is_array( $http->postVariable( 'RemoveNodeID' ) ) )
        {
            $remove = array_map( 'intval', array_keys( $http->postVariable( 'RemoveNodeID' ) ) );
            $state['nodes'] = array_values( array_diff( $state['nodes'], $remove ) );
        }
        if ( $http->hasPostVariable( 'ClearNodes' ) )
            $state['nodes'] = array();
        if ( $http->hasPostVariable( 'SelectedNodeIDArray' ) && $http->hasPostVariable( 'BrowseActionName' )
             && $http->postVariable( 'BrowseActionName' ) === 'ExtractionArchiveNode' )
        {
            foreach ( (array)$http->postVariable( 'SelectedNodeIDArray' ) as $nodeID )
                $state['nodes'][] = (int)$nodeID;
        }
        $state['nodes'] = array_values( array_unique( array_filter( array_map( 'intval', $state['nodes'] ) ) ) );

        // Format
        if ( isset( $formats[(string)$postString( 'ArchiveFormat' )] ) )
            $state['format'] = $postString( 'ArchiveFormat' );
        if ( $http->hasPostVariable( 'Separator' ) )
        {
            $separator = (string)$postString( 'Separator' );
            if ( $separator === '\t' )
                $separator = "\t";
            $state['separator'] = ( strlen( $separator ) === 1 && strpbrk( $separator, "\"\r\n" ) === false ) ? $separator : ',';
        }
        if ( $http->hasPostVariable( 'Escape' ) )
            $state['escape'] = (bool)$http->postVariable( 'Escape' );
        // The checkbox is only on the form for users allowed to export password hashes
        $allowHashes = \XrowExtractColumns::allowPasswordHash();
        if ( $http->hasPostVariable( 'ClassSelection' ) )
            $state['password_hashes'] = $allowHashes && $http->hasPostVariable( 'IncludePasswordHashes' );
        if ( !$allowHashes || !isset( $state['password_hashes'] ) )
            $state['password_hashes'] = false;
        if ( isset( $lineSeparators[(string)$postString( 'LineSeparator' )] ) )
            $state['line'] = $postString( 'LineSeparator' );
        if ( !isset( $formats[$state['format']] ) || !$formats[$state['format']]['available'] )
            $state['format'] = 'zip';

        // Languages (every content language by default) and columns
        $contentLanguages = \XrowExtractColumns::contentLanguages();
        $allLocales = array_keys( $contentLanguages );
        if ( !isset( $state['languages'] ) || !is_array( $state['languages'] ) )
            $state['languages'] = $allLocales;
        if ( $http->hasPostVariable( 'SelectAllLanguages' ) )
            $state['languages'] = $allLocales;
        elseif ( $http->hasPostVariable( 'SelectNoLanguages' ) )
            $state['languages'] = array();
        elseif ( $http->hasPostVariable( 'LanguageSelection' ) )
            $state['languages'] = (array)( $http->hasPostVariable( 'Languages' ) ? $http->postVariable( 'Languages' ) : array() );
        $state['languages'] = array_values( array_intersect( $allLocales, $state['languages'] ) );
        $columnChoices = \XrowExtractArchive::columnChoices();
        if ( isset( $columnChoices[(string)$postString( 'ArchiveColumns' )] ) )
            $state['columns'] = $postString( 'ArchiveColumns' );
        if ( !isset( $state['columns'] ) || !isset( $columnChoices[$state['columns']] ) )
            $state['columns'] = 'standard';
        if ( $http->hasPostVariable( 'OutputFormat' ) && \XrowExtractWriter::isRowFormat( $http->postVariable( 'OutputFormat' ) ) )
            $state['output'] = $http->postVariable( 'OutputFormat' );
        if ( !isset( $state['output'] ) || !\XrowExtractWriter::isRowFormat( $state['output'] ) )
            $state['output'] = 'csv';
        if ( $http->hasPostVariable( 'LanguageSelection' ) )
            $state['plain_text'] = $http->hasPostVariable( 'PlainText' );
        if ( !isset( $state['plain_text'] ) )
            $state['plain_text'] = false;

        // Filters that work for every class; applied to every count and to the build
        if ( $http->hasPostVariable( 'ClearFilters' ) )
            $state['filters'] = array();
        elseif ( $http->hasPostVariable( 'FilterSelection' ) )
            $state['filters'] = (array)( $http->hasPostVariable( 'Filter' ) ? $http->postVariable( 'Filter' ) : array() );
        $archiveFilters = new \XrowExtractFilters( isset( $state['filters'] ) ? (array)$state['filters'] : array() );
        if ( !in_array( $archiveFilters->values['date_field'], array( 'published', 'modified' ), true ) )
            $archiveFilters->values['date_field'] = 'modified';
        if ( in_array( $archiveFilters->values['date_mode'], array( 'future', 'since_last' ), true ) )
            $archiveFilters->values['date_mode'] = 'any';
        $archiveFilters->values['where_attribute'] = '';
        $state['filters'] = $archiveFilters->values;
        \XrowExtractArchive::$attributeFilter = $archiveFilters->attributeFilter( false );

        // What the selection holds: rows per class in the chosen languages
        $resolved = \XrowExtractArchive::resolveNodes( $state['nodes'] );
        $roots = \XrowExtractArchive::exportRoots( $resolved );
        $counts = $state['languages'] ? \XrowExtractArchive::classCounts( $roots, $state['languages'] ) : array();
        $languageCounts = \XrowExtractArchive::languageCounts( $roots );

        // Classes: every class with objects is exported unless it was switched off; the form posts the ones kept
        if ( $http->hasPostVariable( 'ClassSelection' ) )
        {
            $kept = array_map( 'intval', (array)( $http->hasPostVariable( 'ClassIDs' ) ? $http->postVariable( 'ClassIDs' ) : array() ) );
            $shown = array_map( 'intval', explode( ',', (string)$http->postVariable( 'ClassSelection' ) ) );
            $state['excluded'] = array_values( array_unique( array_merge(
                array_diff( $state['excluded'], $shown ),       // switched off earlier and not on the form now
                array_diff( $shown, $kept ) ) ) );              // on the form and not ticked
        }
        if ( $http->hasPostVariable( 'SelectAllClasses' ) )
            $state['excluded'] = array();
        if ( $http->hasPostVariable( 'SelectNoClasses' ) )
            $state['excluded'] = array_keys( $counts );
        $state['excluded'] = array_map( 'intval', $state['excluded'] );

        $classes = array();
        $selectedClassIDs = array();
        foreach ( \eZContentClass::fetchList( \eZContentClass::VERSION_STATUS_DEFINED, true, false, array( 'name' => 'asc' ) ) as $class )
        {
            $id = (int)$class->attribute( 'id' );
            if ( !isset( $counts[$id] ) )
                continue;
            $included = !in_array( $id, $state['excluded'], true );
            if ( $included )
                $selectedClassIDs[] = $id;
            $classes[] = array( 'id' => $id, 'name' => $class->attribute( 'name' ), 'identifier' => $class->attribute( 'identifier' ),
                                'count' => $counts[$id], 'included' => $included,
                                'columns' => count( \XrowExtractColumns::identityColumns() ) + count( $class->dataMap() ) );
        }

        $http->setSessionVariable( 'eZExtractArchive', $state );

        // Browse for nodes to add
        if ( $http->hasPostVariable( 'BrowseArchiveNode' ) )
        {
            \eZContentBrowse::browse( array( 'action_name' => 'ExtractionArchiveNode',
                                            'description_template' => 'design:xrowextract/browse_archive_node.tpl',
                                            'from_page' => '/xrowextract/archive' ), $module );
            return $this->viewResult( isset( $Result ) ? $Result : null, null );
        }

        // Download
        if ( $http->hasPostVariable( 'DownloadArchive' ) )
        {
            if ( !$roots || !$selectedClassIDs )
            {
                $error = \ezpI18n::tr( 'design/standard/extract', 'Choose at least one node and one class.' );
            }
            else
            {
                // Only the build is caught: cleanExit() may end the request by throwing, and must not be taken for an error
                $result = false;
                try
                {
                    $result = \XrowExtractArchive::build( $roots, $selectedClassIDs, $state['format'], $state['separator'], $state['escape'], $lineSeparators[$state['line']], $state['password_hashes'],
                                                          array( 'languages' => $state['languages'], 'columns' => $state['columns'], 'plain_text' => $state['plain_text'], 'output' => $state['output'] ) );
                }
                catch ( \Throwable $e )
                {
                    \eZDebug::writeError( $e->getMessage(), 'xrowextract/archive' );
                    $error = \ezpI18n::tr( 'design/standard/extract', 'The archive could not be written: %reason', null, array( '%reason' => $e->getMessage() ) );
                }
                if ( $result )
                {
                    $types = array( 'zip' => 'application/zip', 'tar.gz' => 'application/gzip', 'tar.bz2' => 'application/x-bzip2',
                                    'tar.xz' => 'application/x-xz', '7z' => 'application/x-7z-compressed', 'rar' => 'application/vnd.rar' );
                    $size = filesize( $result['path'] );
                    // Every download is a row of the export history (no file is kept for it)
                    \XrowExtractHistory::record( array(
                        'owner_login' => \eZUser::currentUser()->attribute( 'login' ), 'kind' => 'archive', 'trigger_type' => 'download', 'run_mode' => 'full',
                        'what' => \ezpI18n::tr( 'design/standard/extract', 'Site archive' ) . ': ' . implode( ', ', array_map( function ( $root ) { return $root->attribute( 'name' ); }, $roots ) ),
                        'output_format' => $state['format'], 'started_at' => time() - (int)ceil( $result['manifest']['seconds'] ), 'ended_at' => time(),
                        'run_state' => 'done', 'row_count' => (int)$result['manifest']['rows'], 'byte_size' => $size,
                        'checksum' => hash_file( 'sha256', $result['path'] ), 'file_name' => $result['name'],
                    ) );
                    header( 'Cache-Control: private, no-store, max-age=0' );
                    header( 'Pragma: no-cache' );
                    header( 'X-Content-Type-Options: nosniff' );
                    header( 'Content-Type: ' . $types[$state['format']] );
                    header( 'Content-Length: ' . $size );
                    header( 'Content-Disposition: attachment; filename="' . $result['name'] . '"' );
                    while ( @ob_end_clean() );
                    $fh = fopen( $result['path'], 'rb' );
                    while ( $fh && !feof( $fh ) )
                        echo fread( $fh, 1048576 );
                    if ( $fh )
                        fclose( $fh );
                    \XrowExtractArchive::removeWork( $result['work'] );
                    \eZExecution::cleanExit();
                }
            }
        }

        // Velocity ends a request after 30s, too short for a large archive: "Run in the background" builds
        // the same command line as bin/php/archive.php from this view's already-validated state (never from
        // raw request strings) and starts it detached, so the export keeps going after the page returns.
        $tpl->setVariable( 'BackgroundAvailable', \XrowExtractJob::available() );
        $tpl->setVariable( 'RunningJobsCount', \XrowExtractJob::countRunning( \eZUser::currentUser()->attribute( 'login' ), \XrowExtractJob::allowAllJobs() ) );

        if ( $http->hasPostVariable( 'RunInBackground' ) )
        {
            if ( !$roots || !$selectedClassIDs )
            {
                $error = \ezpI18n::tr( 'design/standard/extract', 'Choose at least one node and one class.' );
            }
            elseif ( !\XrowExtractJob::available() )
            {
                $error = \ezpI18n::tr( 'design/standard/extract', 'Background exports are not available on this server (no PHP command line binary was found, or exec() is disabled).' );
            }
            elseif ( !$state['languages'] )
            {
                $error = \ezpI18n::tr( 'design/standard/extract', 'Choose at least one language first.' );
            }
            else
            {
                // The current siteaccess, so a URL alias in the export (below a multi-site root, several
                // siteaccesses can see the same node under a different path) comes out exactly as this
                // Download would have written it
                $currentAccess = \eZSiteAccess::current();
                $jobArgs = array();
                if ( $currentAccess && !empty( $currentAccess['name'] ) )
                    $jobArgs[] = '--siteaccess=' . $currentAccess['name'];
                $jobArgs[] = '--nodes=' . implode( ',', $state['nodes'] );
                $jobArgs[] = '--classes=' . implode( ',', $selectedClassIDs );
                $jobArgs[] = '--format=' . $state['format'];
                $jobArgs[] = '--separator=' . $state['separator'];
                $jobArgs[] = '--line-endings=' . $state['line'];
                if ( !$state['escape'] )
                    $jobArgs[] = '--unquoted';
                if ( $state['password_hashes'] && $allowHashes )
                    $jobArgs[] = '--password-hashes';
                $jobArgs[] = '--languages=' . implode( ',', $state['languages'] );
                $jobArgs[] = '--columns=' . $state['columns'];
                if ( $state['plain_text'] )
                    $jobArgs[] = '--plain-text';
                $jobArgs[] = '--files=' . $state['output'];
                foreach ( $archiveFilters->cliArgs( 0, false ) as $filterArg )
                    $jobArgs[] = $filterArg;

                $rootNames = array();
                foreach ( $roots as $root )
                    $rootNames[] = $root->attribute( 'name' );
                $what = implode( ', ', array_slice( $rootNames, 0, 4 ) ) . ( count( $rootNames ) > 4 ? ' …' : '' )
                      . ' — ' . count( $selectedClassIDs ) . ' ' . \ezpI18n::tr( 'design/standard/extract', 'classes' );

                $jobID = \XrowExtractJob::create( array(
                    'type' => 'archive',
                    'owner' => \eZUser::currentUser()->attribute( 'login' ),
                    'what' => $what,
                    'format' => $state['format'],
                    // The archive's exact name carries a timestamp decided inside build(); bin/php/job.php
                    // fills this in once the run is done
                    'output_file' => null,
                    'args' => $jobArgs,
                ) );
                if ( !\XrowExtractJob::start( $jobID ) )
                {
                    \XrowExtractJob::update( $jobID, array(
                        'state' => 'failed', 'ended' => time(),
                        'error' => \ezpI18n::tr( 'design/standard/extract', 'Could not start the background process.' ),
                    ) );
                }
                $http->setSessionVariable( 'eZExtractJobStarted', $jobID );
                return $this->viewResult( isset( $Result ) ? $Result : null,  $module->redirectTo( 'xrowextract/jobs' ) );
            }
        }

        // Export as package (.ezpkg): the selected nodes (each with its whole subtree), as one real content
        // package - every class found under them, not narrowed to the class selection above (a package has
        // no per-class filter across several nodes; "One class" export does narrow to one class, see csv.php).
        // Always a background job, the same reasons as "Run in the background" above.
        if ( $http->hasPostVariable( 'ExportAsPackage' ) )
        {
            if ( !$roots )
            {
                $error = \ezpI18n::tr( 'design/standard/extract', 'Choose at least one node.' );
            }
            elseif ( !\XrowExtractJob::available() )
            {
                $error = \ezpI18n::tr( 'design/standard/extract', 'Background exports are not available on this server (no PHP command line binary was found, or exec() is disabled).' );
            }
            else
            {
                $exportArgs = array( '--export', '--nodes=' . implode( ',', $state['nodes'] ), '--subtree' );
                $rootNames = array();
                foreach ( $roots as $root )
                    $rootNames[] = $root->attribute( 'name' );
                $exportWhat = implode( ', ', array_slice( $rootNames, 0, 4 ) ) . ( count( $rootNames ) > 4 ? ' …' : '' );
                $exportJobID = \XrowExtractJob::create( array(
                    'type' => 'package', 'owner' => \eZUser::currentUser()->attribute( 'login' ),
                    'what' => 'Export as package: ' . $exportWhat,
                    'format' => 'ezpkg', 'output_file' => 'export.ezpkg', 'args' => $exportArgs,
                ) );
                if ( !\XrowExtractJob::start( $exportJobID ) )
                {
                    \XrowExtractJob::update( $exportJobID, array(
                        'state' => 'failed', 'ended' => time(),
                        'error' => \ezpI18n::tr( 'design/standard/extract', 'Could not start the background process.' ),
                    ) );
                }
                $http->setSessionVariable( 'eZExtractJobStarted', $exportJobID );
                return $this->viewResult( isset( $Result ) ? $Result : null,  $module->redirectTo( 'xrowextract/jobs' ) );
            }
        }

        // Nodes to pick from: the roots of the installation and the first levels of the content and media trees
        $suggestions = array();
        $selectedIDs = array_flip( $state['nodes'] );
        $addSuggestion = function ( $node, $level ) use ( &$suggestions, $selectedIDs )
        {
            if ( !$node instanceof \eZContentObjectTreeNode || !$node->canRead() || isset( $suggestions[$node->attribute( 'node_id' )] ) || count( $suggestions ) >= 150 )
                return;
            $suggestions[$node->attribute( 'node_id' )] = array(
                'node_id' => (int)$node->attribute( 'node_id' ), 'name' => $node->attribute( 'name' ), 'class_name' => $node->attribute( 'class_name' ),
                'class_identifier' => $node->attribute( 'class_identifier' ), 'path' => $node->attribute( 'path_identification_string' ),
                'level' => $level, 'count' => \XrowExtractArchive::subtreeCount( $node ), 'selected' => isset( $selectedIDs[$node->attribute( 'node_id' )] ),
            );
        };
        foreach ( array( 'RootNode' => 2, 'MediaRootNode' => 1, 'UserRootNode' => 1 ) as $setting => $depth )
        {
            $top = \eZContentObjectTreeNode::fetch( (int)$contentINI->variable( 'NodeSettings', $setting ) );
            if ( !$top )
                continue;
            $addSuggestion( $top, 0 );
            foreach ( (array)$top->subTree( array( 'Depth' => 1, 'SortBy' => $top->sortArray(), 'Limit' => 60 ) ) as $child )
            {
                $addSuggestion( $child, 1 );
                if ( $depth > 1 && $child->attribute( 'children_count' ) > 0 )
                {
                    foreach ( (array)$child->subTree( array( 'Depth' => 1, 'SortBy' => $child->sortArray(), 'Limit' => 30 ) ) as $grandChild )
                        $addSuggestion( $grandChild, 2 );
                }
            }
        }

        // The selected nodes for the list
        $nodes = array();
        foreach ( $resolved as $item )
        {
            $nodes[] = array(
                'node_id' => $item['id'],
                'node' => $item['node'],
                'covered_by' => $item['covered_by'] ? $item['covered_by']->attribute( 'name' ) : false,
                'count' => ( $item['node'] && !$item['covered_by'] ) ? \XrowExtractArchive::subtreeCount( $item['node'] ) : 0,
            );
        }
        $activeSet = false;
        foreach ( $nodeSets as $id => $set )
        {
            $sorted = $set['nodes']; sort( $sorted );
            $current = $state['nodes']; sort( $current );
            if ( $sorted === $current )
                $activeSet = $id;
        }

        $totalRows = 0;
        foreach ( $classes as $class )
            $totalRows += $class['included'] ? $class['count'] : 0;

        $tpl->setVariable( 'state', $state );
        $tpl->setVariable( 'separator_display', $state['separator'] === "\t" ? '\t' : $state['separator'] );
        $tpl->setVariable( 'node_sets', $nodeSets );
        $siteList = \XrowExtractArchive::siteList();
        foreach ( $siteList as $i => $siteNode )
            $siteList[$i]['selected'] = in_array( $siteNode['node_id'], $state['nodes'], true );
        $tpl->setVariable( 'site_list', $siteList );
        $tpl->setVariable( 'active_set', $activeSet );
        $tpl->setVariable( 'nodes', $nodes );
        $tpl->setVariable( 'suggestions', array_values( $suggestions ) );
        $tpl->setVariable( 'classes', $classes );
        $tpl->setVariable( 'class_ids_shown', implode( ',', array_map( function ( $c ) { return $c['id']; }, $classes ) ) );
        $tpl->setVariable( 'selected_class_count', count( $selectedClassIDs ) );
        $tpl->setVariable( 'total_rows', $totalRows );
        $tpl->setVariable( 'formats', $formats );
        $tpl->setVariable( 'error', $error );
        $tpl->setVariable( 'allow_password_hashes', $allowHashes );
        $languageChoices = array();
        foreach ( $contentLanguages as $locale => $language )
            $languageChoices[] = array_merge( $language, array( 'count' => $languageCounts[$locale], 'selected' => in_array( $locale, $state['languages'], true ) ) );
        $tpl->setVariable( 'language_choices', $languageChoices );
        $choices = array();
        foreach ( $columnChoices as $id => $choice )
            $choices[] = array( 'id' => $id, 'name' => $choice[0], 'description' => $choice[1] );
        $tpl->setVariable( 'column_choices', $choices );
        $tpl->setVariable( 'output_formats', array_values( \XrowExtractWriter::rowFormats() ) );
        $tpl->setVariable( 'filters', $archiveFilters->values );
        $tpl->setVariable( 'filter_count', $archiveFilters->activeCount() );
        $archiveDateModes = \XrowExtractFilters::dateModes();
        unset( $archiveDateModes['future'], $archiveDateModes['since_last'] );
        $tpl->setVariable( 'filter_date_modes', $archiveDateModes );
        $tpl->setVariable( 'filter_sections', \eZSection::fetchList() );
        $tpl->setVariable( 'TabNotation', '\t' );
        $scriptFile = dirname( $this->scriptFile() ) . '/../../design/standard/javascript/xrowextract.js';
        $scriptHash = is_file( $scriptFile ) ? md5_file( $scriptFile ) : false;
        $tpl->setVariable( 'ScriptVersion', $scriptHash !== false ? substr( $scriptHash, 0, 12 ) : '0' );

        $Result = array();
        $Result['content'] = $tpl->fetch( 'design:xrowextract/archive.tpl' );
        $Result['path'] = array(
            array( 'url' => false, 'text' => \ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
            array( 'url' => false, 'text' => \ezpI18n::tr( 'design/standard/extract', 'Site archive' ) ),
        );
        $Result['left_menu'] = 'design:xrowextract/menu_archive.tpl';

        return $this->viewResult( isset( $Result ) ? $Result : null, null );
    }
}

}
