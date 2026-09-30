<?php

/**
 * What the extension needs from PHP and the server, in one place: each requirement (a PHP version, a PHP
 * extension, a function a hosting may disable, a program, a writable folder) is probed once, and each
 * feature lists the requirements it cannot work without.
 *
 * - check() probes everything and says, per requirement, whether it is there, and per feature whether it
 *   is available. A requirement of the "core" feature is required: without it the extension does not work
 *   at all. Every other one only takes the features that need it away.
 * - notices( $page ) gives the lines an admin page shows for the features of that page that are not
 *   available (nothing for a feature of another page), translated.
 * - bin/php/requirements.php (ext:xrowextract:requirements) prints the report with PASS/FAIL per
 *   requirement and exits 1 when a required one is missing; bin/check.sh runs it.
 *
 * evaluate() is the pure part (results in, features and notices out), so it is tested without having to
 * take anything away from the PHP that runs the tests.
 */
class XrowExtractRequirements
{
    const MIN_PHP_VERSION = '8.1.0';

    /** @var array<string, array{ok: bool, detail: string}>|null The probes of this request (they do not change within one). */
    protected static ?array $probed = null;

    /**
     * The requirements: id => label (what is needed) and hint (what to do about it). English, as the command line
     * prints them; notices() translates.
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function requirements(): array
    {
        return array(
            'php'           => array( 'label' => 'PHP 8.1 or later',                                    'hint' => 'Run the installation with a newer PHP.' ),
            'mbstring'      => array( 'label' => 'The PHP mbstring extension',                          'hint' => 'Install or enable mbstring (php-mbstring).' ),
            'ctype'         => array( 'label' => 'The PHP ctype extension',                             'hint' => 'Install or enable ctype.' ),
            'json'          => array( 'label' => 'The PHP json extension',                              'hint' => 'Install or enable json.' ),
            'var_dir'       => array( 'label' => 'A writable var directory',                            'hint' => 'Give the web server user write access to the installation\'s var directory.' ),
            'cache_dir'     => array( 'label' => 'A writable cache directory',                          'hint' => 'Give the web server user write access to the cache directory below var.' ),
            'storage_dir'   => array( 'label' => 'A writable storage directory',                        'hint' => 'Give the web server user write access to var/<site>/storage (packages are kept there).' ),
            'dom'           => array( 'label' => 'The PHP dom extension',                               'hint' => 'Install or enable dom (php-xml).' ),
            'xmlreader'     => array( 'label' => 'The PHP xmlreader extension',                         'hint' => 'Install or enable xmlreader (php-xml).' ),
            'zlib'          => array( 'label' => 'The PHP zlib extension',                              'hint' => 'Install or enable zlib.' ),
            'zip'           => array( 'label' => 'The PHP zip extension',                               'hint' => 'Install or enable zip (php-zip).' ),
            'sodium'        => array( 'label' => 'The PHP sodium extension',                            'hint' => 'Install or enable sodium (php-sodium).' ),
            'curl'          => array( 'label' => 'The PHP curl extension',                              'hint' => 'Install or enable curl (php-curl).' ),
            'proc_open'     => array( 'label' => 'The PHP function proc_open()',                        'hint' => 'Remove proc_open from disable_functions in php.ini.' ),
            'exec'          => array( 'label' => 'The PHP function exec()',                             'hint' => 'Remove exec from disable_functions in php.ini.' ),
            'php_cli'       => array( 'label' => 'A PHP command line binary',                           'hint' => 'Install the PHP command line binary, or name it in csv.ini [Jobs] PhpCli.' ),
            'tar'           => array( 'label' => 'The tar and gzip programs',                           'hint' => 'Install tar and gzip on the server.' ),
            'archive_format' => array( 'label' => 'At least one archive format (PHP zip, or tar with gzip)', 'hint' => 'Install or enable the PHP zip extension, or tar and gzip.' ),
            'sftp'          => array( 'label' => 'The OpenSSH client programs (sftp, ssh-keyscan, ssh-keygen)', 'hint' => 'Install the OpenSSH client, or name its folder in xrowextract.ini [Destinations] SshBinaryDir.' ),
        );
    }

    /**
     * The features: id => name, the requirements it cannot work without, and the admin pages that offer it (a
     * page shows a notice only for its own features). "core" is the extension itself: its requirements are the
     * required ones.
     *
     * @return array<string, array{name: string, requires: list<string>, pages: list<string>}>
     */
    public static function features(): array
    {
        $all = array( 'csv', 'archive', 'import', 'package', 'jobs', 'schedules', 'destinations', 'history' );
        return array(
            'core'         => array( 'name' => 'Every xrowextract page and command',            'requires' => array( 'php', 'mbstring', 'ctype', 'json', 'var_dir', 'cache_dir' ), 'pages' => $all ),
            'csv_manifest' => array( 'name' => 'A CSV file with its manifest (ZIP download)',   'requires' => array( 'zip' ),                                   'pages' => array( 'csv' ) ),
            'archive'      => array( 'name' => 'Multi class of content export',                 'requires' => array( 'archive_format' ),                        'pages' => array( 'archive' ) ),
            'archive_zip'  => array( 'name' => 'ZIP archives',                                  'requires' => array( 'zip' ),                                   'pages' => array( 'archive' ) ),
            'import'       => array( 'name' => 'Import content file',                           'requires' => array( 'dom', 'xmlreader' ),                      'pages' => array( 'import' ) ),
            'import_zip'   => array( 'name' => 'Import of a file with its manifest (ZIP)',      'requires' => array( 'zip' ),                                   'pages' => array( 'import' ) ),
            'package'      => array( 'name' => 'Content packages (.ezpkg)',                     'requires' => array( 'dom', 'zlib', 'proc_open', 'tar', 'storage_dir' ), 'pages' => array( 'import', 'package' ) ),
            'jobs'         => array( 'name' => 'Background jobs',                               'requires' => array( 'proc_open', 'exec', 'php_cli' ),          'pages' => array( 'jobs' ) ),
            'schedules'    => array( 'name' => 'Schedules',                                     'requires' => array( 'proc_open', 'exec', 'php_cli' ),          'pages' => array( 'schedules' ) ),
            'secrets'      => array( 'name' => 'Passwords and keys of destinations',            'requires' => array( 'sodium' ),                                'pages' => array( 'destinations' ) ),
            'transfer_curl' => array( 'name' => 'HTTP, S3, WebDAV and FTP destinations',        'requires' => array( 'curl' ),                                  'pages' => array( 'destinations' ) ),
            'transfer_sftp' => array( 'name' => 'SFTP destinations',                            'requires' => array( 'proc_open', 'sftp' ),                     'pages' => array( 'destinations' ) ),
        );
    }

    /**
     * Probes every requirement on this server, once per request (clearCache() forgets it).
     *
     * @return array<string, array{ok: bool, detail: string}>
     */
    public static function probe(): array
    {
        if ( self::$probed !== null )
            return self::$probed;
        $php = version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '>=' );
        $tar = self::program( 'tar' );
        $gzip = self::program( 'gzip' );
        $zip = class_exists( 'ZipArchive' );
        $cli = self::phpCli();
        $sftp = class_exists( 'XrowExtractTransportSftp' ) ? XrowExtractTransportSftp::unavailableReason() : 'the SFTP transport is not installed';
        $results = array(
            'php'         => self::result( $php, 'PHP ' . PHP_VERSION, 'this is PHP ' . PHP_VERSION ),
            'mbstring'    => self::result( extension_loaded( 'mbstring' ) ),
            'ctype'       => self::result( function_exists( 'ctype_digit' ) ),
            'json'        => self::result( function_exists( 'json_encode' ) ),
            'var_dir'     => self::writable( eZSys::varDirectory() ),
            'cache_dir'   => self::writable( eZSys::cacheDirectory() ),
            'storage_dir' => self::writable( eZSys::storageDirectory() ),
            'dom'         => self::result( class_exists( 'DOMDocument' ) ),
            'xmlreader'   => self::result( class_exists( 'XMLReader' ) ),
            'zlib'        => self::result( extension_loaded( 'zlib' ) ),
            'zip'         => self::result( $zip ),
            'sodium'      => self::result( XrowExtractSecrets::available() ),
            'curl'        => self::result( function_exists( 'curl_init' ) ),
            'proc_open'   => self::result( self::functionUsable( 'proc_open' ), '', 'disabled (disable_functions)' ),
            'exec'        => self::result( self::functionUsable( 'exec' ), '', 'disabled (disable_functions)' ),
            'php_cli'     => self::result( $cli !== '', $cli, 'none found' ),
            'tar'         => self::result( $tar !== '' && $gzip !== '', trim( $tar . ' ' . $gzip ), ( $tar === '' ? 'no tar' : 'no gzip' ) . ' in PATH' ),
            'archive_format' => self::result( self::anyArchiveFormat(), '', 'no archive format can be written here' ),
            'sftp'        => self::result( $sftp === '', '', $sftp ),
        );
        return self::$probed = $results;
    }

    /** Forgets the probes of this request (a test changes what is there, or a long process checks again). */
    public static function clearCache(): void
    {
        self::$probed = null;
    }

    /**
     * The report for these probe results: every requirement with whether it is there, whether it is required
     * (a requirement of "core") and the features it takes away; every feature with whether it is available and
     * what it misses; and ok (no required requirement missing).
     *
     * @param array<string, array{ok: bool, detail: string}> $results
     * @return array{ok: bool, requirements: array<string, array{id: string, label: string, hint: string, ok: bool, detail: string, required: bool, features: list<string>}>, features: array<string, array{id: string, name: string, available: bool, missing: list<string>, pages: list<string>}>}
     */
    public static function evaluate( array $results ): array
    {
        $features = self::features();
        $requirements = array();
        foreach ( self::requirements() as $id => $requirement )
        {
            $result = $results[$id] ?? array( 'ok' => false, 'detail' => 'not checked' );
            $usedBy = array();
            foreach ( $features as $featureID => $feature )
            {
                if ( in_array( $id, $feature['requires'], true ) )
                    $usedBy[] = $featureID;
            }
            $requirements[$id] = array(
                'id'       => $id,
                'label'    => $requirement['label'],
                'hint'     => $requirement['hint'],
                'ok'       => (bool)$result['ok'],
                'detail'   => (string)$result['detail'],
                'required' => in_array( $id, $features['core']['requires'], true ),
                'features' => $usedBy,
            );
        }
        $featureReport = array();
        $ok = true;
        foreach ( $features as $featureID => $feature )
        {
            $missing = array();
            foreach ( $feature['requires'] as $id )
            {
                if ( !isset( $requirements[$id] ) || !$requirements[$id]['ok'] )
                    $missing[] = $id;
            }
            $featureReport[$featureID] = array(
                'id'        => $featureID,
                'name'      => $feature['name'],
                'available' => !$missing,
                'missing'   => $missing,
                'pages'     => $feature['pages'],
            );
            if ( $featureID === 'core' && $missing )
                $ok = false;
        }
        return array( 'ok' => $ok, 'requirements' => $requirements, 'features' => $featureReport );
    }

    /**
     * The report of this server (evaluate() of probe()).
     *
     * @return array{ok: bool, requirements: array<string, array{id: string, label: string, hint: string, ok: bool, detail: string, required: bool, features: list<string>}>, features: array<string, array{id: string, name: string, available: bool, missing: list<string>, pages: list<string>}>}
     */
    public static function check(): array
    {
        return self::evaluate( self::probe() );
    }

    /** Whether a feature (an id of features()) is available on this server. */
    public static function available( string $feature ): bool
    {
        $report = self::check();
        return isset( $report['features'][$feature] ) && $report['features'][$feature]['available'];
    }

    /**
     * The notices an admin page shows: one per feature of that page that is not available, naming what it
     * misses, translated. Empty when everything the page offers works.
     *
     * @param array{ok: bool, requirements: array<string, array{id: string, label: string, hint: string, ok: bool, detail: string, required: bool, features: list<string>}>, features: array<string, array{id: string, name: string, available: bool, missing: list<string>, pages: list<string>}>}|null $report
     *        the report to use (check() when null)
     * @return list<array{feature: string, name: string, missing: string, hint: string, required: bool}>
     */
    public static function notices( string $page, ?array $report = null ): array
    {
        $report = $report ?? self::check();
        $notices = array();
        foreach ( $report['features'] as $featureID => $feature )
        {
            if ( $feature['available'] || !in_array( $page, $feature['pages'], true ) )
                continue;
            $labels = array();
            $hints = array();
            foreach ( $feature['missing'] as $id )
            {
                $labels[] = self::translate( $report['requirements'][$id]['label'] );
                $hints[] = self::translate( $report['requirements'][$id]['hint'] );
            }
            $notices[] = array(
                'feature'  => $featureID,
                'name'     => self::translate( $feature['name'] ),
                'missing'  => implode( ', ', $labels ),
                'hint'     => implode( ' ', array_unique( $hints ) ),
                'required' => $featureID === 'core',
            );
        }
        return $notices;
    }

    /**
     * The report as text lines for the command line: PASS or FAIL per requirement (WARN for a missing one that is
     * not required: only the features it names are unavailable), then the features, then the summary.
     *
     * @param array{ok: bool, requirements: array<string, array{id: string, label: string, hint: string, ok: bool, detail: string, required: bool, features: list<string>}>, features: array<string, array{id: string, name: string, available: bool, missing: list<string>, pages: list<string>}>} $report
     * @return list<string>
     */
    public static function reportLines( array $report ): array
    {
        $lines = array();
        $missingRequired = 0;
        $missingOptional = 0;
        foreach ( $report['requirements'] as $id => $requirement )
        {
            if ( $requirement['ok'] )
            {
                $lines[] = 'PASS ' . $id . ': ' . $requirement['label'] . ( $requirement['detail'] !== '' ? ' (' . $requirement['detail'] . ')' : '' );
                continue;
            }
            $names = array();
            foreach ( $requirement['features'] as $featureID )
                $names[] = $report['features'][$featureID]['name'];
            if ( $requirement['required'] )
                $missingRequired++;
            else
                $missingOptional++;
            $lines[] = ( $requirement['required'] ? 'FAIL ' : 'WARN ' ) . $id . ': ' . $requirement['label'] . ': missing'
                     . ( $requirement['detail'] !== '' ? ' (' . $requirement['detail'] . ')' : '' )
                     . ( $requirement['required'] ? ', required' : ', needed by: ' . implode( '; ', $names ) )
                     . '. ' . $requirement['hint'];
        }
        foreach ( $report['features'] as $featureID => $feature )
        {
            $lines[] = 'INFO feature ' . $featureID . ': ' . $feature['name'] . ': '
                     . ( $feature['available'] ? 'available' : 'not available (missing ' . implode( ', ', $feature['missing'] ) . ')' );
        }
        $count = count( $report['requirements'] );
        if ( $missingRequired > 0 )
            $lines[] = 'FAIL requirements: ' . $missingRequired . ' required of ' . $count . ' missing' . ( $missingOptional ? ', ' . $missingOptional . ' optional missing' : '' );
        else
            $lines[] = 'PASS requirements: every required one of ' . $count . ' is there' . ( $missingOptional ? ', ' . $missingOptional . ' optional missing (see WARN)' : '' );
        return $lines;
    }

    /** @return array{ok: bool, detail: string} */
    protected static function result( bool $ok, string $detail = '', string $missingDetail = '' ): array
    {
        return array( 'ok' => $ok, 'detail' => $ok ? $detail : $missingDetail );
    }

    /**
     * A folder that exists and is writable, or that does not exist yet but can be created (its nearest existing
     * parent is writable: the kernel and this extension create their folders on first use).
     *
     * @return array{ok: bool, detail: string}
     */
    protected static function writable( string $dir ): array
    {
        if ( $dir === '' )
            return self::result( false, '', 'not configured' );
        $path = $dir[0] === '/' ? $dir : eZSys::rootDir() . '/' . $dir;
        if ( is_dir( $path ) )
            return self::result( is_writable( $path ), $dir, $dir . ' is not writable by this user' );
        $parent = dirname( $path );
        while ( $parent !== '/' && $parent !== '.' && !is_dir( $parent ) )
            $parent = dirname( $parent );
        return self::result( is_dir( $parent ) && is_writable( $parent ), $dir . ' (created on first use)', $dir . ' does not exist and cannot be created' );
    }

    /** A function that exists (a function in disable_functions does not exist in PHP 8) and is not listed as disabled. */
    protected static function functionUsable( string $name ): bool
    {
        if ( !function_exists( $name ) )
            return false;
        $disabled = array_map( 'trim', explode( ',', (string)ini_get( 'disable_functions' ) ) );
        return !in_array( $name, $disabled, true );
    }

    /** The path of a program in PATH or the usual system folders, '' when there is none. */
    protected static function program( string $name ): string
    {
        $dirs = array_filter( explode( PATH_SEPARATOR, (string)getenv( 'PATH' ) ) );
        foreach ( array_merge( $dirs, array( '/usr/bin', '/usr/local/bin', '/bin', '/opt/homebrew/bin' ) ) as $dir )
        {
            if ( @is_executable( $dir . '/' . $name ) && !is_dir( $dir . '/' . $name ) )
                return $dir . '/' . $name;
        }
        return '';
    }

    protected static function phpCli(): string
    {
        $binary = XrowExtractJob::phpCliBinary();
        return is_string( $binary ) ? $binary : '';
    }

    protected static function anyArchiveFormat(): bool
    {
        foreach ( XrowExtractArchive::formats() as $format )
        {
            if ( $format['available'] )
                return true;
        }
        return false;
    }

    protected static function translate( string $text ): string
    {
        return ezpI18n::tr( 'design/standard/extract', $text );
    }
}

?>
