<?php
/**
 * The code of extension/xrowextract/modules/xrowextract/subtree_classes.php: the classes below a node for the
 * content tree's Advanced > Export > "Subtree of class" list, as JSON.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */

namespace Exponential\View\Extension\Xrowextract\Xrowextract
{

class SubtreeClasses extends \Exponential\Runnable\ModuleView
{
    /**
     * @param array<string, mixed> $scope the variables eZProcess::runFile() gives the view: $Params, the view's
     *                                    parameters with its eZModule under 'Module'
     * @return mixed the view's result
     */
    public function run( array $scope )
    {
        $Params = isset( $scope['Params'] ) && is_array( $scope['Params'] ) ? $scope['Params'] : array();

        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: application/json; charset=utf-8' );

        $nodeID = \XrowExtractEntry::positiveID( isset( $Params['NodeID'] ) && is_string( $Params['NodeID'] ) ? $Params['NodeID'] : '' );
        $node = $nodeID ? \eZContentObjectTreeNode::fetch( $nodeID ) : null;
        // An unknown node and one the user cannot read get the same answer
        if ( !$node instanceof \eZContentObjectTreeNode || !$node->canRead() )
        {
            header( 'HTTP/1.1 404 Not Found' );
            echo json_encode( array( 'error' => 'not_found' ) );
            \eZExecution::cleanExit();
        }

        // Each class the form offers (export.ini ExportClasses), counted below the node with the user's read access
        // (the default limitation of the tree count), as the form's own class list counts them; nothing when the
        // node has no children
        $exportClasses = (array)\eZINI::instance( 'export.ini' )->variable( 'ExportSettings', 'ExportClasses' );
        $classes = array();
        $counts = array();
        if ( (int)$node->attribute( 'children_count' ) > 0 )
        {
            foreach ( \eZContentClass::fetchList( \eZContentClass::VERSION_STATUS_DEFINED, true ) as $class )
            {
                $classID = (int)$class->attribute( 'id' );
                if ( !\XrowExtractEntry::classAllowed( $classID, (string)$class->attribute( 'identifier' ), $exportClasses ) )
                    continue;
                $count = (int)\eZContentObjectTreeNode::subTreeCountByNodeID(
                    array( 'ClassFilterType' => 'include', 'ClassFilterArray' => array( $classID ) ), $nodeID );
                if ( $count <= 0 )
                    continue;
                $counts[$classID] = $count;
                $classes[$classID] = array( 'identifier' => (string)$class->attribute( 'identifier' ),
                                            'name' => (string)$class->attribute( 'name' ) );
            }
        }

        echo json_encode( array( 'node' => $nodeID ) + \XrowExtractEntry::classList( $counts, $classes ), JSON_INVALID_UTF8_SUBSTITUTE );
        \eZExecution::cleanExit();
    }
}

}
