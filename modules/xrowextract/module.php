<?php

$Module = array('name' => 'xrowextract');

$ViewList = array();
$ViewList['csv'] = array( 'script' => 'csv.php',
                          'functions' => array( 'csv' ),
            			  'default_navigation_part' => 'ezextractnavigationpart',
            			  'post_actions' => array( 'Download', 'BrowseSubtree', 'AddAttribute', 'Remove', 'RemoveData', 'RunInBackground' ),
            			  'params' => array() );

$ViewList['archive'] = array( 'script' => 'archive.php',
                              'functions' => array( 'csv' ),
                              'default_navigation_part' => 'ezextractnavigationpart',
                              'post_actions' => array( 'DownloadArchive', 'BrowseArchiveNode', 'RunInBackground' ),
                              'params' => array() );

// Background export jobs: the jobs page, its JSON poll and its download, one class attribute
// identifier of the module's own function -- all three share the same policy
$ViewList['jobs'] = array( 'script' => 'jobs.php',
                           'functions' => array( 'jobs' ),
                           'default_navigation_part' => 'ezextractnavigationpart',
                           'post_actions' => array( 'DeleteJobID' ),
                           'params' => array() );

$ViewList['job_status'] = array( 'script' => 'job_status.php',
                                 'functions' => array( 'jobs' ),
                                 'params' => array( 'JobID' ) );

$ViewList['job_download'] = array( 'script' => 'job_download.php',
                                   'functions' => array( 'jobs' ),
                                   'params' => array( 'JobID' ) );
$ViewList['import'] = array( 'script' => 'import.php',
                             'functions' => array( 'import' ),
                             'default_navigation_part' => 'ezextractnavigationpart',
                             'post_actions' => array( 'Upload', 'RemoveFile', 'NewImport', 'BrowseParent', 'Preview', 'Apply' ),
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

$FunctionList = array();
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