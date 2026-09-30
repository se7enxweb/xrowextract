<?php

/**
 * xrowextract/compare/<PackageName>[/<OtherName>]: what differs.
 *   - With two packages (the second as a URL segment, or ?with=<name> from the picker): the classes and
 *     objects only in the first, only in the second, or in both with differences - matched by remote id,
 *     read from the packages' own XML (XrowExtractPackage::cachedComparison()).
 *   - With one: the package against this site - per class and object what installing it would create or
 *     change, down to the fields that differ, straight from the cached dry run the Package tab shows
 *     (XrowExtractPackage::cachedInspection(); no second inspect()).
 * Filters (what changes, class, a part of the name or remote id) and pages, all on the cached result;
 * links are query-only (href="?..."), so they keep the path and every filter.
 */

$module = $Params['Module'];
$http = eZHTTPTool::instance();
$tpl = eZTemplate::factory();

$packageName = isset( $Params['PackageName'] ) ? (string)$Params['PackageName'] : '';
$otherName = isset( $Params['OtherName'] ) && $Params['OtherName'] ? (string)$Params['OtherName']
           : ( isset( $_GET['with'] ) ? trim( (string)$_GET['with'] ) : '' );
$package = $packageName !== '' ? eZPackage::fetch( $packageName ) : false;
$other = $otherName !== '' ? eZPackage::fetch( $otherName ) : false;

$path = array(
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/xrowextract', 'Extract' ) ),
    array( 'url' => 'xrowextract/package', 'text' => ezpI18n::tr( 'design/standard/extract', 'Package' ) ),
    array( 'url' => false, 'text' => ezpI18n::tr( 'design/standard/extract', 'Compare' ) ),
);
if ( !$package instanceof eZPackage || ( $otherName !== '' && !$other instanceof eZPackage ) )
{
    $Result = array();
    $Result['content'] = ezpI18n::tr( 'design/standard/extract', 'No package %name in the repository.', false,
                                      array( '%name' => !$package instanceof eZPackage ? $packageName : $otherName ) );
    $Result['path'] = $path;
    return $Result;
}
// A ?with= choice is turned into the plain URL, so the page's address names both packages
if ( $otherName !== '' && !( isset( $Params['OtherName'] ) && $Params['OtherName'] ) )
    return $module->redirectTo( 'xrowextract/compare/' . $packageName . '/' . $otherName );

$refresh = $http->hasPostVariable( 'RecheckComparison' );
$mode = $other instanceof eZPackage ? 'packages' : 'site';

$perPageChoices = array( '25', '50', '100', '250', 'all' );
$perPage = isset( $_GET['per_page'] ) && in_array( (string)$_GET['per_page'], $perPageChoices, true ) ? (string)$_GET['per_page'] : '';
if ( $perPage !== '' )
    eZPreferences::setValue( 'admin_xrowextract_compare_per_page', $perPage );
else
    $perPage = in_array( (string)eZPreferences::value( 'admin_xrowextract_compare_per_page' ), $perPageChoices, true ) ? (string)eZPreferences::value( 'admin_xrowextract_compare_per_page' ) : '50';

$filterClass = isset( $_GET['class'] ) && preg_match( '/^[A-Za-z0-9_]{1,100}$/', (string)$_GET['class'] ) ? (string)$_GET['class'] : '';
$filterText = isset( $_GET['q'] ) ? mb_substr( trim( (string)$_GET['q'] ), 0, 100 ) : '';

if ( $mode === 'packages' )
{
    $comparison = XrowExtractPackage::cachedComparison( $package, $other, $refresh );
    $changes = array( 'added', 'removed', 'changed' );
    $filterChange = isset( $_GET['change'] ) && in_array( (string)$_GET['change'], $changes, true ) ? (string)$_GET['change'] : '';
    $classRows = XrowExtractPackage::filterComparisonRows( $comparison['classes'], $filterChange, '', $filterText );
    $objectRows = XrowExtractPackage::filterComparisonRows( $comparison['objects'], $filterChange, $filterClass, $filterText );
    $classChoices = XrowExtractPackage::inspectionObjectClasses( $comparison['objects'] );
    $checkedAt = $comparison['checked_at'];
    $cached = !empty( $comparison['cached'] );
    $tpl->setVariable( 'Comparison', array( 'from' => $comparison['from'], 'to' => $comparison['to'], 'counts' => $comparison['counts'] ) );
}
else
{
    // The package against the site: the Package tab's own cached dry run, for the parent chosen there
    // (the default content root when none was: where a new top-level object would land)
    $parentNodeID = isset( $_GET['parent'] ) && ctype_digit( (string)$_GET['parent'] ) ? (int)$_GET['parent'] : 0;
    if ( !$parentNodeID )
    {
        $publicContentINI = eZSiteAccess::getIni( eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ), 'content.ini' );
        $parentNodeID = (int)$publicContentINI->variable( 'NodeSettings', 'RootNode' );
    }
    $inspection = XrowExtractPackage::cachedInspection( $package, $parentNodeID, $refresh );
    $changes = array( 'create', 'update', 'unchanged', 'class_missing' );
    // Default: only what an install would change (create, update, class missing); ?change=all for everything
    $changeParam = isset( $_GET['change'] ) ? (string)$_GET['change'] : '';
    $filterChange = in_array( $changeParam, $changes, true ) || $changeParam === 'all' ? $changeParam : '';
    $classRows = array();
    foreach ( $inspection['classes'] as $classRow )
    {
        $state = $classRow['state'];
        if ( $filterChange !== '' && $filterChange !== 'all' && $filterChange !== $state )
            continue;
        if ( $filterText !== '' && mb_stripos( $classRow['identifier'] . ' ' . $classRow['name'] . ' ' . $classRow['remote_id'], $filterText ) === false )
            continue;
        if ( $filterChange === '' && $state === 'update' && ( !$classRow['diff'] || !$classRow['diff']['has_changes'] ) )
            continue; // the same as the site's: nothing an install would change
        $classRows[] = $classRow;
    }
    $objectRows = array();
    foreach ( $inspection['objects'] as $objectRow )
    {
        if ( $filterChange === '' && $objectRow['state'] === 'unchanged' )
            continue;
        if ( $filterChange !== '' && $filterChange !== 'all' && $objectRow['state'] !== $filterChange )
            continue;
        $objectRows[] = $objectRow;
    }
    $objectRows = XrowExtractPackage::filterInspectionObjects( $objectRows, '', $filterClass, $filterText );
    $classChoices = XrowExtractPackage::inspectionObjectClasses( $inspection['objects'] );
    $checkedAt = $inspection['checked_at'];
    $cached = !empty( $inspection['cached'] );
    $tpl->setVariable( 'SiteCounts', $inspection['counts'] );
    $tpl->setVariable( 'MissingDatatypes', isset( $inspection['missing_datatypes'] ) ? $inspection['missing_datatypes'] : array() );
    $tpl->setVariable( 'ParentNodeID', $parentNodeID );
}

$objectTotal = count( $objectRows );
$pageSize = $perPage === 'all' ? max( 1, $objectTotal ) : (int)$perPage;
$pageCount = max( 1, (int)ceil( $objectTotal / $pageSize ) );
$pageNumber = isset( $_GET['page'] ) && ctype_digit( (string)$_GET['page'] ) ? min( $pageCount, max( 1, (int)$_GET['page'] ) ) : 1;
$filterQuery = array();
foreach ( array( 'change' => $filterChange, 'class' => $filterClass, 'q' => $filterText ) as $key => $value )
    if ( $value !== '' )
        $filterQuery[$key] = $value;
if ( $mode === 'site' && isset( $_GET['parent'] ) && ctype_digit( (string)$_GET['parent'] ) )
    $filterQuery['parent'] = (int)$_GET['parent'];

$tpl->setVariable( 'Mode', $mode );
$tpl->setVariable( 'PackageName', $packageName );
$tpl->setVariable( 'OtherName', $mode === 'packages' ? $otherName : '' );
$tpl->setVariable( 'ClassRows', $classRows );
$tpl->setVariable( 'ObjectRows', array_slice( $objectRows, ( $pageNumber - 1 ) * $pageSize, $pageSize ) );
$tpl->setVariable( 'CompareFilter', array(
    'change' => $filterChange, 'changes' => $changes, 'class' => $filterClass, 'q' => $filterText,
    'classes' => $classChoices, 'active' => (bool)array_diff_key( $filterQuery, array( 'parent' => true ) ),
    'query' => $filterQuery ? '&' . http_build_query( $filterQuery ) : '',
) );
$tpl->setVariable( 'ComparePager', array(
    'per_page' => $perPage, 'choices' => $perPageChoices, 'page' => $pageNumber, 'pages' => $pageCount, 'total' => $objectTotal,
    'from' => $objectTotal ? ( $pageNumber - 1 ) * $pageSize + 1 : 0, 'to' => min( $objectTotal, $pageNumber * $pageSize ),
    'prev' => max( 1, $pageNumber - 1 ), 'next' => min( $pageCount, $pageNumber + 1 ),
) );
$tpl->setVariable( 'CheckedAt', $checkedAt );
$tpl->setVariable( 'Cached', $cached );
// The other packages to compare with (the picker on this page)
$others = array();
foreach ( XrowExtractPackage::repositoryPackages() as $repositoryPackage )
    if ( $repositoryPackage['name'] !== $packageName )
        $others[] = $repositoryPackage;
$tpl->setVariable( 'OtherPackages', $others );
$scriptFile = dirname( __FILE__ ) . '/../../design/standard/javascript/xrowextract.js';
$tpl->setVariable( 'ScriptVersion', is_file( $scriptFile ) ? substr( md5_file( $scriptFile ), 0, 12 ) : '0' );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:xrowextract/compare.tpl' );
$Result['path'] = $path;
$Result['left_menu'] = 'design:xrowextract/menu_compare.tpl';

?>
