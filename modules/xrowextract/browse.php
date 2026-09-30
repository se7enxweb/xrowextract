<?php

/**
 * xrowextract/browse/<PackageName>/<Offset>/<ViewIndex>: the package contents
 * browser (#26). Every file a package carries, paginated (no limit on the
 * list itself - only how much of it one page shows), each one viewable: an
 * .xml item pretty-printed, an image shown inline, a small text file shown
 * as text, anything else only offered as a download (xrowextract/browse_file).
 *
 * <Offset> and <ViewIndex> are both plain integers, like every other
 * xrowextract URL param (JobID aside, everything here is a name or a
 * number) - never the file's own path. A relative path can carry slashes of
 * its own ("ezcontentobject/abc123.xml"), which a bare URL segment cannot
 * hold safely without an encoding step this codebase does not otherwise use
 * anywhere (every other link here is concat(...)|ezurl over plain names and
 * ids). <ViewIndex> is instead the file's position in allPackageFiles()'s
 * own sorted list - deterministic and stable for as long as the package's
 * files do not change, which for an installed or repository package is for
 * as long as it exists. xrowextract/browse_file uses the same scheme.
 *
 * Reachable from three places, all through the same data
 * (XrowExtractPackage::allPackageFiles()) and the same row markup
 * (design:xrowextract/package_files_rows.tpl):
 *   - this page itself, the full paginated browser;
 *   - a short preview embedded on the Import page and the Package tab
 *     (design:xrowextract/package_files_preview.tpl), "Browse all N files"
 *     linking here for the rest;
 *   - a link added to the kernel's own package/view/full/<name>
 *     (extension/xrowextract/design/standard/override/templates/package/view/full.tpl).
 */

$module = $Params['Module'];
$tpl = eZTemplate::factory();

// A plain variable, not a class/file-level const: this script runs again for every request a
// long-running Velocity worker serves in the same process, and a top-level `const` (or a bare
// `function`) declared here would fatal ("cannot redeclare") on the second one.
$pageSize = 50;

$packageName = isset( $Params['PackageName'] ) ? (string)$Params['PackageName'] : '';
$package = $packageName !== '' ? eZPackage::fetch( $packageName ) : false;
if ( !$package instanceof eZPackage )
{
    $Result = array();
    $Result['content'] = ezpI18n::tr( 'design/standard/extract', 'No package %name in the repository.', false, array( '%name' => $packageName ) );
    $Result['path'] = array(
        array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
        array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Package contents' ) ),
    );
    return $Result;
}

$allFiles = XrowExtractPackage::allPackageFiles( $package );
$total = count( $allFiles );
$offset = isset( $Params['Offset'] ) && ctype_digit( (string)$Params['Offset'] ) ? (int)$Params['Offset'] : 0;
if ( $offset >= $total && $total > 0 )
    $offset = (int)( floor( ( $total - 1 ) / $pageSize ) * $pageSize );
$pageFiles = array_slice( $allFiles, $offset, $pageSize );

// Viewing one file's contents: found by its index in the whole list (not only this page - a direct
// link, or a browser back/forward, may land on a file whose page differs from the one currently
// shown), and only ever read once its kind and existence are confirmed the safe way, through
// packageFilePath() (no path traversal, no symlink escape).
$viewIndex = isset( $Params['ViewIndex'] ) && ctype_digit( (string)$Params['ViewIndex'] ) ? (int)$Params['ViewIndex'] : -1;
$viewedFile = null;
$viewedContent = null;
if ( $viewIndex >= 0 && isset( $allFiles[$viewIndex] ) )
{
    $viewedFile = $allFiles[$viewIndex];
    $viewedFile['index'] = $viewIndex;
    if ( $viewedFile['kind'] !== 'image' )
    {
        $realPath = XrowExtractPackage::packageFilePath( $package, $viewedFile['path'] );
        if ( $realPath !== false )
        {
            $bytes = (string)@file_get_contents( $realPath );
            $viewedContent = $viewedFile['kind'] === 'xml' ? XrowExtractPackage::prettyPrintXML( $bytes ) : $bytes;
        }
        else
        {
            $viewedFile = null; // packageFilePath() refused it; behave as if nothing was found
        }
    }
}
// Every row needs its own index (into the full list, not the page slice) for its View/Download
// links, so this is computed here rather than asking the template to add $offset + a loop counter
foreach ( $pageFiles as $i => &$fileRow )
    $fileRow['index'] = $offset + $i;
unset( $fileRow );

$tpl->setVariable( 'PackageName', $packageName );
$tpl->setVariable( 'Package', $package );
$tpl->setVariable( 'Files', $pageFiles );
$tpl->setVariable( 'FilesTotal', $total );
$tpl->setVariable( 'FilesOffset', $offset );
$tpl->setVariable( 'FilesPageSize', $pageSize );
$tpl->setVariable( 'ViewedFile', $viewedFile );
$tpl->setVariable( 'ViewedContent', $viewedContent );
// eZ TPL has no arithmetic operators of its own here; every number the template needs beyond the
// plain values above (the "N to M of T" line, the prev/next page offsets) is worked out in PHP
// instead of trying to compute it in the template.
$tpl->setVariable( 'FilesShownFrom', $pageFiles ? $offset + 1 : 0 );
$tpl->setVariable( 'FilesShownTo', $offset + count( $pageFiles ) );
$tpl->setVariable( 'FilesHasPrevious', $offset > 0 );
$tpl->setVariable( 'FilesPreviousOffset', max( 0, $offset - $pageSize ) );
$tpl->setVariable( 'FilesHasNext', $offset + $pageSize < $total );
$tpl->setVariable( 'FilesNextOffset', $offset + $pageSize );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/browse.tpl' );
$Result['path'] = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => 'xrowextract/package', 'text' => ezpI18n::tr( 'design/standard/extract', 'Package' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Package contents' ) ),
);
$Result['left_menu'] = 'design:xrowextract/menu_package.tpl';

?>
