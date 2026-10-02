<?php
/**
 * The code of extension/xrowextract/bin/php/requirements.php, moved into a class (#207 stage 1). The file extension/xrowextract/bin/php/requirements.php is one call to it.
 * Guide: doc/bc/6.0/cli_cronjob_view_abstractions.md
 */
/*
 * The original header of extension/xrowextract/bin/php/requirements.php:
 *
 *
 * What xrowextract needs from PHP and the server, checked on this one (./console ext:xrowextract:requirements
 * runs it too): PASS or FAIL per requirement (WARN for one that is missing but not required: only the features
 * it names are unavailable), the features, and a summary line.
 *
 *   php extension/xrowextract/bin/php/requirements.php
 *   php extension/xrowextract/bin/php/requirements.php --feature=package,jobs   (exit 1 unless both are available)
 *   php extension/xrowextract/bin/php/requirements.php --strict                 (exit 1 when anything is missing)
 *   php extension/xrowextract/bin/php/requirements.php --json
 *
 * Exit code 0 when every required requirement is there (and, with --feature or --strict, what those ask for),
 * 1 otherwise, 2 for an unknown feature. Run it as the user the web server runs as: writable folders and
 * disabled functions depend on the user and on the PHP configuration (the command line one can differ from
 * the web server's).
 *
 */

namespace Exponential\Command\Extension\Xrowextract
{

class Requirements extends \Exponential\Runnable\Command
{
    public function run()
    {
        // the script's variables were globals; functions of the script read them with "global"
        foreach ( array( 'cli', 'exitCode', 'feature', 'line', 'options', 'report', 'requirement', 'script', 'wanted' ) as $__name )
            ${$__name} = &$GLOBALS[$__name];
        unset( $__name );

        $cli = \eZCLI::instance();
        $script = \eZScript::instance( array(
            'description'    => "Checks what xrowextract needs from PHP and the server.",
            'use-session'    => false,
            'use-modules'    => false,
            'use-extensions' => true,
        ) );
        $script->startup();
        $options = $script->getOptions(
            '[feature:][strict][json]',
            '',
            array(
                'feature' => 'Also fail when one of these features (comma separated ids) is not available',
                'strict'  => 'Fail when any requirement is missing, required or not',
                'json'    => 'Machine readable',
            )
        );
        $script->initialize();

        $report = \XrowExtractRequirements::check();
        $exitCode = $report['ok'] ? 0 : 1;

        $wanted = array_values( array_filter( array_map( 'trim', explode( ',', is_string( $options['feature'] ) ? $options['feature'] : '' ) ) ) );
        foreach ( $wanted as $feature )
        {
            if ( !isset( $report['features'][$feature] ) )
            {
                $cli->error( 'No feature ' . $feature . '. Features: ' . implode( ', ', array_keys( $report['features'] ) ) . '.' );
                $script->shutdown( 2 );
                exit( 2 );
            }
            if ( !$report['features'][$feature]['available'] )
                $exitCode = 1;
        }
        if ( $options['strict'] )
        {
            foreach ( $report['requirements'] as $requirement )
            {
                if ( !$requirement['ok'] )
                    $exitCode = 1;
            }
        }

        if ( $options['json'] )
        {
            $cli->output( (string)json_encode( $report + array( 'exit_code' => $exitCode ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }
        else
        {
            foreach ( \XrowExtractRequirements::reportLines( $report ) as $line )
                $cli->output( $line );
            foreach ( $wanted as $feature )
                $cli->output( ( $report['features'][$feature]['available'] ? 'PASS' : 'FAIL' ) . ' feature ' . $feature . ' asked for with --feature' );
        }

        $script->shutdown( $exitCode );
        exit( $exitCode );
    }
}

}
