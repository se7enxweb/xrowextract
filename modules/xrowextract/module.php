<?php

$Module = array('name' => 'xrowextract');

$ViewList = array();
$ViewList['csv'] = array( 'script' => 'csv.php',
                          'functions' => array( 'csv' ),
            			  'default_navigation_part' => 'ezextractnavigationpart',
            			  'post_actions' => array( 'Download', 'BrowseSubtree', 'AddAttribute', 'Remove', 'RemoveData' ),
            			  'params' => array() );

$ViewList['archive'] = array( 'script' => 'archive.php',
                              'functions' => array( 'csv' ),
                              'default_navigation_part' => 'ezextractnavigationpart',
                              'params' => array() );

$FunctionList = array();
$FunctionList['csv'] = array();
// Export the password hash and hash type of user accounts (special columns, site archive option)
$FunctionList['password_hash'] = array();

?>