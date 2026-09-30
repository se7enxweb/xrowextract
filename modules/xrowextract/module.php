<?php

$Module = array('name' => 'xrowextract');

$ViewList = array();
$ViewList['csv'] = array( 'script' => 'csv.php',
                          'functions' => array( 'csv' ),
            			  'default_navigation_part' => 'ezextractnavigationpart',
            			  'post_actions' => array( 'Download', 'DownloadManifest', 'DownloadWithManifest', 'BrowseSubtree', 'AddAttribute', 'Remove', 'RemoveData', 'RunInBackground', 'ExportAsPackage' ),
            			  'params' => array() );

$ViewList['archive'] = array( 'script' => 'archive.php',
                              'functions' => array( 'csv' ),
                              'default_navigation_part' => 'ezextractnavigationpart',
                              'post_actions' => array( 'DownloadArchive', 'BrowseArchiveNode', 'RunInBackground', 'ExportAsPackage' ),
                              'params' => array() );

// Background export jobs: the jobs page, its JSON poll and its download, one class attribute
// identifier of the module's own function -- all three share the same policy
$ViewList['jobs'] = array( 'script' => 'jobs.php',
                           'functions' => array( 'jobs' ),
                           'default_navigation_part' => 'ezextractnavigationpart',
                           'post_actions' => array( 'DeleteJobID', 'CancelJobID', 'ExportAgainJobID', 'ExportAgainHistoryID' ),
                           'params' => array() );

$ViewList['job_status'] = array( 'script' => 'job_status.php',
                                 'functions' => array( 'jobs' ),
                                 'params' => array( 'JobID' ) );

$ViewList['job_download'] = array( 'script' => 'job_download.php',
                                   'functions' => array( 'jobs' ),
                                   'params' => array( 'JobID', 'What' ) );
$ViewList['import'] = array( 'script' => 'import.php',
                             'functions' => array( 'import' ),
                             'default_navigation_part' => 'ezextractnavigationpart',
                             'post_actions' => array( 'Upload', 'RemoveFile', 'NewImport', 'BrowseParent', 'Preview', 'Apply',
                                                      'RunInBackground', 'ResumeJobID', 'KeepSamplePackage',
                                                      'DownloadClassXML', 'DownloadObjectXML', 'OpenRepositoryPackage' ),
                             'params' => array() );
// The chunked upload endpoint XrowExtractUploadJS talks to: same policy as xrowextract/import
$ViewList['upload_chunk'] = array( 'script' => 'upload_chunk.php',
                                   'functions' => array( 'import' ),
                                   'post_actions' => array( 'Action' ),
                                   'params' => array() );

// Content packages (.ezpkg): upload or pick one from the repository, inspect it
// (a dry run: nothing written), install it, or build a sample "content + class"
// template package for a chosen class. Same policy function as import: this is
// the same feature area, structured content going in, one way or another.
$ViewList['package'] = array( 'script' => 'package.php',
                              'functions' => array( 'import' ),
                              'default_navigation_part' => 'ezextractnavigationpart',
                              'post_actions' => array( 'UploadPackage', 'ForgetPackage', 'BrowseParent', 'Install', 'BuildTemplate' ),
                              'params' => array( 'PackageName' ) );

// Scheduled exports and imports; the destinations they deliver to (and their encrypted credentials);
// the export history. Three policy functions, so a role can allow running schedules without seeing
// or changing any destination's credentials, or reading the history alone.
$ViewList['schedules'] = array( 'script' => 'schedules.php',
                                'functions' => array( 'schedule' ),
                                'default_navigation_part' => 'ezextractnavigationpart',
                                'post_actions' => array( 'SaveSchedule', 'NewSchedule', 'EditScheduleID', 'DeleteScheduleID', 'RunScheduleID',
                                                         'EnableScheduleID', 'DisableScheduleID', 'CancelEdit' ),
                                'params' => array( 'ScheduleID' ) );
$ViewList['destinations'] = array( 'script' => 'destinations.php',
                                   'functions' => array( 'destinations' ),
                                   'default_navigation_part' => 'ezextractnavigationpart',
                                   'post_actions' => array( 'SaveDestination', 'NewDestination', 'EditDestinationID', 'DeleteDestinationID',
                                                            'TestDestinationID', 'TrustHostKey', 'CancelEdit' ),
                                   'params' => array( 'DestinationID' ) );
$ViewList['history'] = array( 'script' => 'history.php',
                              'functions' => array( 'history' ),
                              'default_navigation_part' => 'ezextractnavigationpart',
                              'post_actions' => array( 'AcknowledgeAlerts' ),
                              'params' => array() );

// The package contents browser (#26): every file a package carries, paginated, each one viewable.
// Same policy as package/import - anyone who can inspect a package's classes/objects can read its
// raw files the same way. Reachable from the Import page, the Package tab and a link added to the
// kernel's own package/view/full/<name>.
$ViewList['browse'] = array( 'script' => 'browse.php',
                             'functions' => array( 'import' ),
                             'default_navigation_part' => 'ezextractnavigationpart',
                             'params' => array( 'PackageName', 'Offset', 'ViewIndex' ) );

// ViewIndex: a file's position in allPackageFiles()'s own sorted list, not its path (which can
// carry slashes of its own, "ezcontentobject/abc123.xml" - see browse.php's own comment)
// Compare a package with another package, or with this site (what an install would create or change,
// down to the fields). Same policy as package/browse: reading packages and the dry run.
$ViewList['compare'] = array( 'script' => 'compare.php',
                              'functions' => array( 'import' ),
                              'default_navigation_part' => 'ezextractnavigationpart',
                              'post_actions' => array( 'RecheckComparison' ),
                              'params' => array( 'PackageName', 'OtherName' ) );

$ViewList['browse_file'] = array( 'script' => 'browse_file.php',
                                  'functions' => array( 'import' ),
                                  'params' => array( 'PackageName', 'ViewIndex' ) );

$FunctionList = array();
// Create, change, run, enable and disable schedules (your own; with xrowextract/all_jobs everyone's)
$FunctionList['schedule'] = array();
// Manage delivery destinations and set/replace/clear their credentials
$FunctionList['destinations'] = array();
// See the export history (your own runs; with xrowextract/all_jobs everyone's)
$FunctionList['history'] = array();
$FunctionList['csv'] = array();
// Export the password hash and hash type of user accounts (special columns, site archive option)
$FunctionList['password_hash'] = array();
// See, download and delete background export jobs (xrowextract/jobs, job_status, job_download)
$FunctionList['jobs'] = array();
// Without it a user only sees, downloads and deletes their own jobs; with it, everyone's
$FunctionList['all_jobs'] = array();
// Read a CSV/JSON file back into content objects (xrowextract/import)
$FunctionList['import'] = array();

?>