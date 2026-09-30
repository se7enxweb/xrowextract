<?php

// Template fetch functions of the xrowextract module:
//   fetch( 'xrowextract', 'schedule_alerts' )  failed or skipped scheduled runs (and failed deliveries)
//                                              the current user has not acknowledged yet: the Jobs tab badge
//   fetch( 'xrowextract', 'requirement_notices', hash( 'page', 'csv' ) )
//                                              what the server misses for the features of that page
//                                              (XrowExtractRequirements; requirements_notice.tpl shows them)
$FunctionList = array();
$FunctionList['schedule_alerts'] = array(
    'name' => 'schedule_alerts',
    'operation_types' => array( 'read' ),
    'call_method' => array( 'class' => 'XrowExtractFunctionCollection', 'method' => 'fetchScheduleAlerts' ),
    'parameter_type' => 'standard',
    'parameters' => array(),
);
$FunctionList['requirement_notices'] = array(
    'name' => 'requirement_notices',
    'operation_types' => array( 'read' ),
    'call_method' => array( 'class' => 'XrowExtractFunctionCollection', 'method' => 'fetchRequirementNotices' ),
    'parameter_type' => 'standard',
    'parameters' => array( array( 'name' => 'page', 'type' => 'string', 'required' => true ) ),
);

?>
