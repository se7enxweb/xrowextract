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

$FunctionList = array();
$FunctionList['csv'] = array();
// Export the password hash and hash type of user accounts (special columns, site archive option)
$FunctionList['password_hash'] = array();
// See, download and delete background export jobs (xrowextract/jobs, job_status, job_download)
$FunctionList['jobs'] = array();
// Without it a user only sees, downloads and deletes their own jobs; with it, everyone's
$FunctionList['all_jobs'] = array();

?>