<?php
/**
 * The report mails of xrowextract (XrowExtractNotifier::mail()) are sent as text and as HTML in the kernel's mail
 * layout (design:mail/layout.tpl), as text only with a kernel without it. Needs the kernel (Exponential 6.0.15 or
 * later), no database; the mail goes to a transport of the test that keeps it, nothing is sent:
 *
 *   php vendor/bin/phpunit extension/xrowextract/tests/kernel/NotifierMailLayoutTest.php
 *
 *  NL-01 - the report text is the text part; the HTML part is the layout with the subject as its heading, escaped
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */

require_once dirname( __DIR__, 2 ) . '/classes/xrowextractfilemodes.php';
require_once dirname( __DIR__, 2 ) . '/classes/transports/xrowextracttransport.php';
require_once dirname( __DIR__, 2 ) . '/classes/xrowextractnotifier.php';

if ( class_exists( 'eZMailTransport' ) && !class_exists( 'XrowExtractNotifierMailLayoutTestTransport' ) )
{
    /** Keeps the mails it is given; never sends */
    class XrowExtractNotifierMailLayoutTestTransport extends eZMailTransport
    {
        public static $sent = array();

        function sendMail( eZMail $mail )
        {
            static::$sent[] = $mail;
            return true;
        }
    }
}

class NotifierMailLayoutTest extends PHPUnit\Framework\TestCase
{
    private $saved = array();
    private $savedGlobals = array();

    protected function setUp(): void
    {
        if ( !class_exists( 'expMailTemplateBody' ) || !method_exists( 'expMailTemplateBody', 'fromText' ) )
            $this->markTestSkipped( 'needs the kernel with the mail layout (Exponential 6.0.15 or later)' );
        foreach ( array( 'eZTemplateCompilerSettings', 'eZSiteBasics', 'eZCurrentAccess' ) as $name )
            $this->savedGlobals[$name] = array_key_exists( $name, $GLOBALS ) ? array( $GLOBALS[$name] ) : null;
        $GLOBALS['eZTemplateCompilerSettings']['compile'] = false;
        $GLOBALS['eZSiteBasics']['no-cache-adviced'] = true;
        if ( !isset( $GLOBALS['eZCurrentAccess']['name'] ) )
            $GLOBALS['eZCurrentAccess'] = array( 'name' => eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ),
                                                 'type' => eZSiteAccess::TYPE_DEFAULT );
        $ini = eZINI::instance();
        foreach ( array( 'Transport', 'TransportAlias' ) as $name )
            $this->saved[$name] = $ini->hasVariable( 'MailSettings', $name ) ? $ini->variable( 'MailSettings', $name ) : null;
        $ini->setVariable( 'MailSettings', 'Transport', 'xrowextractrecord' );
        $ini->setVariable( 'MailSettings', 'TransportAlias', array( 'xrowextractrecord' => 'XrowExtractNotifierMailLayoutTestTransport' ) );
        if ( class_exists( 'expMailGate' ) )
            expMailGate::setLogFileForTest( eZSys::rootDir() . '/var/tmp/xrowextract-notifier-mailgate-' . getmypid() . '.jsonl' );
        XrowExtractNotifierMailLayoutTestTransport::$sent = array();
    }

    protected function tearDown(): void
    {
        $ini = eZINI::instance();
        foreach ( $this->saved as $name => $value )
        {
            if ( $value === null )
                $ini->removeSetting( 'MailSettings', $name );
            else
                $ini->setVariable( 'MailSettings', $name, $value );
        }
        if ( class_exists( 'expMailGate' ) )
        {
            expMailGate::setLogFileForTest( null );
            $log = eZSys::rootDir() . '/var/tmp/xrowextract-notifier-mailgate-' . getmypid() . '.jsonl';
            if ( is_file( $log ) )
                unlink( $log );
        }
        foreach ( $this->savedGlobals as $name => $saved )
        {
            if ( $saved === null )
                unset( $GLOBALS[$name] );
            else
                $GLOBALS[$name] = $saved[0];
        }
    }

    /** NL-01 */
    public function testReportIsTextAndHtml()
    {
        $text = "Job <export> FAILED\nRows: 12\n\nDetails: https://admin.example.invalid/xrowextract/runs\n";
        $handler = set_exception_handler( null );
        restore_exception_handler();
        try
        {
            $this->assertTrue( XrowExtractNotifier::mail( array( 'ops@example.invalid' ), 'xrowextract: job <export> failed', $text ) );
        }
        finally
        {
            $current = set_exception_handler( null );
            restore_exception_handler();
            if ( $current !== $handler )
                restore_exception_handler();
        }
        $this->assertCount( 1, XrowExtractNotifierMailLayoutTestTransport::$sent );
        $mail = XrowExtractNotifierMailLayoutTestTransport::$sent[0];
        $this->assertSame( 'multipart/alternative', $mail->contentType() );
        $this->assertSame( $text, $mail->AlternativeParts['text'] );
        $this->assertStringContainsString( 'role="presentation"', $mail->AlternativeParts['html'] );
        $this->assertStringContainsString( 'xrowextract: job &lt;export&gt; failed', $mail->AlternativeParts['html'] );
        $this->assertStringContainsString( 'Job &lt;export&gt; FAILED<br />Rows: 12', $mail->AlternativeParts['html'] );
        $this->assertStringContainsString( '<a class="mx-link" href="https://admin.example.invalid/xrowextract/runs"', $mail->AlternativeParts['html'] );
    }
}
