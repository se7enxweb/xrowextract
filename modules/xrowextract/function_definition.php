<?php

// Template fetch functions of the xrowextract module:
//   fetch( 'xrowextract', 'schedule_alerts' )  failed or skipped scheduled runs (and failed deliveries)
//                                              the current user has not acknowledged yet: the Jobs tab badge
$FunctionList = array();
$FunctionList['schedule_alerts'] = array(
    'name' => 'schedule_alerts',
    'operation_types' => array( 'read' ),
    'call_method' => array( 'class' => 'XrowExtractFunctionCollection', 'method' => 'fetchScheduleAlerts' ),
    'parameter_type' => 'standard',
    'parameters' => array(),
);

?>
