<?php
/**
 * The code of extension/xrowextract/bin/php/history.php, moved into a class (#207 stage 1). The file extension/xrowextract/bin/php/history.php is one call to it.
 * Guide: doc/bc/6.0/cli_cronjob_view_abstractions.md
 */
/*
 * The original header of extension/xrowextract/bin/php/history.php:
 *
 *
 * The export history from the command line (./console ext:xrowextract:history runs it too): every
 * background job, scheduled run and direct download, newest first, filtered and paged like the History page.
 *
 *   php extension/xrowextract/bin/php/history.php
 *   php extension/xrowextract/bin/php/history.php --state=failed --schedule=3 --from=2026-09-01 --limit=50 --offset=50
 *   php extension/xrowextract/bin/php/history.php --show=120 [--json]
 *   php extension/xrowextract/bin/php/history.php --clean                    (retention, as the cronjob part does it)
 *
 */

namespace Exponential\Command\Extension\Xrowextract
{

class History extends \Exponential\Runnable\Command
{
    public function run()
    {
        // the script's variables were globals; functions of the script read them with "global"
        foreach ( array( 'admin', 'cli', 'counts', 'filter', 'h', 'limit', 'offset', 'options', 'row', 'rowArray', 'rows', 'script', 'total' ) as $__name )
            ${$__name} = &$GLOBALS[$__name];
        unset( $__name );

        $cli = \eZCLI::instance();
        $script = \eZScript::instance( array(
            'description'    => "Queries the xrowextract export history.",
            'use-session'    => false,
            'use-modules'    => true,
            'use-extensions' => true,
        ) );
        $script->startup();
        $options = $script->getOptions(
            '[state:][kind:][schedule:][owner:][trigger:][delivery:][from:][to:][text:][limit:][offset:][show:][json][clean]',
            '',
            array(
                'state'    => 'done, warning, skipped or failed',
                'kind'     => 'csv, archive, package, import',
                'schedule' => 'A schedule id',
                'owner'    => 'A login',
                'trigger'  => 'manual, schedule, cli, system_cron, download',
                'delivery' => 'ok, partial or failed',
                'from'     => 'From this day (YYYY-MM-DD)',
                'to'       => 'Up to this day (YYYY-MM-DD)',
                'text'     => 'Part of the description',
                'limit'    => 'Rows per page (default 25, at most 200)',
                'offset'   => 'Rows to skip',
                'show'     => 'One history row in full',
                'json'     => 'Machine readable',
                'clean'    => 'Remove history rows past their retention now',
            )
        );
        $script->initialize();

        $admin = \eZUser::fetchByName( 'admin' );
        if ( $admin instanceof \eZUser )
            $admin->loginCurrent();

        if ( $options['clean'] )
        {
            $cli->output( 'Removed ' . \XrowExtractHistory::clean() . ' history row(s) past their retention (default ' . \XrowExtractHistory::retentionDays() . ' days).' );
            $script->shutdown( 0 );
        }

        $rowArray = function ( \XrowExtractHistory $h )
        {
            $data = array();
            foreach ( array_keys( \XrowExtractHistory::definition()['fields'] ) as $field )
                $data[$field] = $h->attribute( (string)$field );
            $data['warnings'] = $h->warningList();
            $data['delivery'] = $h->deliveryList();
            return $data;
        };

        if ( $options['show'] )
        {
            $row = \eZPersistentObject::fetchObject( \XrowExtractHistory::definition(), null, array( 'id' => \XrowExtractColumns::dbID( $options['show'] ) ) );
            if ( !$row instanceof \XrowExtractHistory )
            {
                $cli->error( 'No history row ' . (int)$options['show'] . '.' );
                $script->shutdown( 1 );
                exit( 1 );
            }
            $cli->output( json_encode( $rowArray( $row ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            $script->shutdown( 0 );
        }

        $filter = array(
            'state' => $options['state'], 'kind' => $options['kind'], 'schedule_id' => \XrowExtractColumns::dbID( $options['schedule'] ), 'owner' => $options['owner'],
            'trigger' => $options['trigger'], 'delivery' => $options['delivery'], 'from' => $options['from'], 'to' => $options['to'], 'text' => $options['text'],
        );
        $limit = $options['limit'] ? (int)$options['limit'] : 25;
        $offset = (int)$options['offset'];
        $total = \XrowExtractHistory::countFor( $filter, 'admin', true );
        $rows = \XrowExtractHistory::fetchPage( $filter, 'admin', true, $offset, $limit );
        if ( $options['json'] )
        {
            $cli->output( json_encode( array( 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'rows' => array_map( $rowArray, $rows ) ),
                                       JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            $script->shutdown( 0 );
        }
        $counts = \XrowExtractHistory::stateCounts( $filter, 'admin', true );
        $cli->output( sprintf( '%d run(s): %d done, %d with warnings, %d skipped, %d failed. Showing %d-%d.', $counts['total'], $counts['done'], $counts['warning'],
                               $counts['skipped'], $counts['failed'], $rows ? $offset + 1 : 0, $offset + count( $rows ) ) );
        foreach ( $rows as $h )
        {
            $cli->output( sprintf( '  %5d %-16s %-8s %-8s %-11s %-12s %7s rows %8s KB %-8s %s%s', $h->attribute( 'id' ), date( 'Y-m-d H:i', $h->attribute( 'created' ) ),
                                   $h->attribute( 'run_state' ), $h->attribute( 'kind' ), $h->attribute( 'trigger_type' ), $h->attribute( 'owner_login' ),
                                   $h->attribute( 'row_count' ), $h->sizeKB(), $h->attribute( 'delivery_state' ) ?: '-', mb_substr( $h->attribute( 'what' ), 0, 60 ),
                                   $h->attribute( 'warning_count' ) ? '  (' . $h->attribute( 'warning_count' ) . ' warning(s))' : '' ) );
        }
        $script->shutdown( 0 );
    }
}

}
