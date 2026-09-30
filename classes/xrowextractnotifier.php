<?php

/**
 * What a schedule's owner (and whoever else it names) hears about a run:
 *  - e-mail on failure (a failed or skipped run, or a failed delivery): always to the owner, plus the
 *    schedule's failure addresses;
 *  - e-mail on success, when switched on, to the owner and the success addresses (warnings included);
 *  - the admin notice: failures count on the Jobs tab badge and show as a notice (XrowExtractHistory::alertCount());
 *  - a webhook: POST of the run as JSON (with the same HMAC header as an HTTP destination when
 *    xrowextract.ini [Notifications] WebhookSecret is set).
 * Mail goes through the kernel's eZMail / eZMailTransport, so site.ini [MailSettings] decides how.
 */
class XrowExtractNotifier
{
    /** The e-mail address of a login, or ''. */
    public static function userEmail( $login )
    {
        $user = $login !== '' ? eZUser::fetchByName( $login ) : null;
        return $user instanceof eZUser ? (string)$user->attribute( 'email' ) : '';
    }

    /** Whether a run counts as a failure for notifications. */
    public static function isFailure( array $run )
    {
        return in_array( $run['run_state'], array( 'failed', 'skipped' ), true ) || ( isset( $run['delivery_state'] ) && in_array( $run['delivery_state'], array( 'failed', 'partial' ), true ) );
    }

    /**
     * Sends what the schedule asks for about $run (a history row's fields, warnings and delivery as
     * arrays). Returns array( 'emails' => addresses mailed, 'webhook' => result or null ).
     */
    public static function notify( XrowExtractSchedule $schedule, array $run )
    {
        $notify = $schedule->notifyArray();
        $failure = self::isFailure( $run );
        $sent = array( 'emails' => array(), 'webhook' => null );
        $recipients = array();
        if ( $failure )
        {
            $recipients[] = self::userEmail( $schedule->attribute( 'owner_login' ) );
            $recipients = array_merge( $recipients, preg_split( '/[\s,;]+/', (string)$notify['failure_emails'], -1, PREG_SPLIT_NO_EMPTY ) );
        }
        elseif ( !empty( $notify['success'] ) )
        {
            $recipients[] = self::userEmail( $schedule->attribute( 'owner_login' ) );
            $recipients = array_merge( $recipients, preg_split( '/[\s,;]+/', (string)$notify['success_emails'], -1, PREG_SPLIT_NO_EMPTY ) );
        }
        $recipients = array_values( array_unique( array_filter( $recipients, function ( $a ) { return $a !== '' && eZMail::validate( $a ); } ) ) );
        if ( $recipients )
        {
            if ( self::mail( $recipients, self::subject( $schedule, $run, $failure ), self::body( $schedule, $run ) ) )
                $sent['emails'] = $recipients;
        }
        if ( !empty( $notify['webhook_url'] ) )
            $sent['webhook'] = self::webhook( $notify['webhook_url'], self::payload( $schedule, $run ) );
        return $sent;
    }

    public static function subject( XrowExtractSchedule $schedule, array $run, $failure )
    {
        $site = XrowExtractManifest::source();
        $state = $run['run_state'] === 'done' && !empty( $run['warnings'] ) ? 'done with warnings' : $run['run_state'];
        if ( $failure && in_array( $run['run_state'], array( 'done', 'warning' ), true ) )
            $state = 'delivery ' . $run['delivery_state'];
        return '[' . ( $site['site'] !== '' ? $site['site'] : 'Exponential' ) . '] Scheduled export "' . $schedule->attribute( 'name' ) . '": ' . $state;
    }

    public static function body( XrowExtractSchedule $schedule, array $run )
    {
        $lines = array(
            'Schedule: ' . $schedule->attribute( 'name' ) . ' (#' . (int)$schedule->attribute( 'id' ) . ', ' . $schedule->summary() . ')',
            'Run:      ' . $run['run_state'] . ( !empty( $run['run_mode'] ) ? ', ' . $run['run_mode'] : '' ),
            'Started:  ' . ( !empty( $run['started_at'] ) ? date( 'Y-m-d H:i:s', $run['started_at'] ) : '-' ),
            'Ended:    ' . ( !empty( $run['ended_at'] ) ? date( 'Y-m-d H:i:s', $run['ended_at'] ) : '-' ),
        );
        if ( isset( $run['row_count'] ) )
            $lines[] = 'Rows:     ' . (int)$run['row_count'];
        if ( !empty( $run['file_name'] ) )
            $lines[] = 'File:     ' . $run['file_name'] . ( !empty( $run['byte_size'] ) ? ' (' . (int)$run['byte_size'] . ' bytes, sha256 ' . $run['checksum'] . ')' : '' );
        if ( !empty( $run['error_text'] ) )
            $lines[] = 'Error:    ' . $run['error_text'];
        if ( !empty( $run['warnings'] ) )
        {
            $lines[] = '';
            $lines[] = 'Warnings:';
            foreach ( (array)$run['warnings'] as $warning )
                $lines[] = '  - ' . $warning;
        }
        if ( !empty( $run['delivery'] ) )
        {
            $lines[] = '';
            $lines[] = 'Delivery:';
            foreach ( (array)$run['delivery'] as $delivery )
                $lines[] = '  - ' . $delivery['destination'] . ': ' . ( $delivery['ok'] ? 'ok' : 'FAILED' ) . ' after ' . $delivery['attempts'] . ' attempt(s) - ' . $delivery['message'];
        }
        $lines[] = '';
        $lines[] = 'History: ' . self::adminURL( 'xrowextract/history' . ( $schedule->attribute( 'id' ) ? '?schedule=' . (int)$schedule->attribute( 'id' ) : '' ) );
        return implode( "\n", $lines ) . "\n";
    }

    /** An absolute URL into the admin, when site.ini [SiteSettings] SiteURL of the admin siteaccess is known; else the path. */
    protected static function adminURL( $path )
    {
        $ini = eZINI::instance();
        $url = $ini->hasVariable( 'SiteSettings', 'SiteURL' ) ? trim( (string)$ini->variable( 'SiteSettings', 'SiteURL' ) ) : '';
        return $url !== '' ? 'https://' . preg_replace( '#^https?://#', '', rtrim( $url, '/' ) ) . '/' . $path : '/' . $path;
    }

    /** The webhook body. */
    public static function payload( XrowExtractSchedule $schedule, array $run )
    {
        return array(
            'event' => 'xrowextract.run',
            'schedule' => array( 'id' => (int)$schedule->attribute( 'id' ), 'name' => $schedule->attribute( 'name' ), 'kind' => $schedule->attribute( 'kind' ) ),
            'run' => array(
                'job_id' => isset( $run['job_id'] ) ? $run['job_id'] : '',
                'state' => $run['run_state'],
                'mode' => isset( $run['run_mode'] ) ? $run['run_mode'] : '',
                'started' => !empty( $run['started_at'] ) ? date( 'c', $run['started_at'] ) : null,
                'ended' => !empty( $run['ended_at'] ) ? date( 'c', $run['ended_at'] ) : null,
                'rows' => isset( $run['row_count'] ) ? (int)$run['row_count'] : null,
                'file' => isset( $run['file_name'] ) ? $run['file_name'] : '',
                'bytes' => isset( $run['byte_size'] ) ? (int)$run['byte_size'] : 0,
                'sha256' => isset( $run['checksum'] ) ? $run['checksum'] : '',
                'error' => isset( $run['error_text'] ) ? $run['error_text'] : '',
                'warnings' => isset( $run['warnings'] ) ? array_values( (array)$run['warnings'] ) : array(),
                'delivery' => array_map( function ( $d ) {
                    return array( 'destination' => $d['destination'], 'ok' => (bool)$d['ok'], 'attempts' => $d['attempts'], 'message' => $d['message'], 'location' => $d['location'] );
                }, isset( $run['delivery'] ) ? (array)$run['delivery'] : array() ),
            ),
            'source' => XrowExtractManifest::source(),
        );
    }

    /** One e-mail through the kernel's mail transport. */
    public static function mail( array $recipients, $subject, $body )
    {
        $ini = eZINI::instance();
        $mail = new eZMail();
        $sender = $ini->variable( 'MailSettings', 'EmailSender' );
        if ( !$sender )
            $sender = $ini->variable( 'MailSettings', 'AdminEmail' );
        if ( $sender )
            $mail->setSender( $sender );
        $first = true;
        foreach ( $recipients as $address )
        {
            if ( $first )
                $mail->setReceiver( $address );
            else
                $mail->addReceiver( $address );
            $first = false;
        }
        $mail->setSubject( $subject );
        $mail->setBody( $body );
        $mail->setContentType( 'text/plain', 'utf-8' );
        return (bool)eZMailTransport::send( $mail );
    }

    /** POST $payload as JSON; returns array( ok, status, message ). */
    public static function webhook( $url, array $payload )
    {
        $transport = new XrowExtractTransportHttp( array( 'url' => $url ), array( 'hmac_key' => self::webhookSecret() ) );
        $body = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
        $result = XrowExtractNotifierHttp::post( $url, $body, $transport->signedHeaders( hash( 'sha256', $body ) ) );
        return $result;
    }

    protected static function webhookSecret()
    {
        $ini = eZINI::instance( 'xrowextract.ini' );
        return $ini->hasVariable( 'Notifications', 'WebhookSecret' ) ? (string)$ini->variable( 'Notifications', 'WebhookSecret' ) : '';
    }
}

/** The webhook's HTTP call: XrowExtractTransport's curl helper with a JSON body. */
class XrowExtractNotifierHttp extends XrowExtractTransport
{
    public function test() { return array( 'ok' => false, 'message' => '' ); }
    public function upload( $localPath, $remoteName ) { return array( 'ok' => false, 'message' => '' ); }

    public static function post( $url, $body, array $headers )
    {
        if ( !preg_match( '#^https?://#i', $url ) )
            return array( 'ok' => false, 'status' => 0, 'message' => 'not an http(s) URL' );
        $result = self::curl( $url, array( 'method' => 'POST', 'body' => $body, 'timeout' => 30,
                                           'headers' => array_merge( array( 'Content-Type: application/json' ), $headers ) ) );
        $ok = $result['ok'] && $result['status'] >= 200 && $result['status'] < 300;
        return array( 'ok' => $ok, 'status' => $result['status'], 'message' => $result['ok'] ? 'HTTP ' . $result['status'] : $result['error'] );
    }
}

?>
