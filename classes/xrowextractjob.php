<?php

/**
 * A background export job: one folder per job below the var directory
 * (var/<site>/xrowextract-jobs/<random hex id>/), holding job.json (state),
 * progress.json (written by the running export, read by the jobs page),
 * job.log (what the child process printed) and the finished output file.
 *
 * The web request that starts a job may run as a different user than the
 * process that carries it out: PHP-FPM (:443) is user alpha, Velocity
 * (:8080) and a command line invocation may be root. Whenever this class
 * writes a job folder or file while running as root, it hands it back to
 * the owner of the var directory, so the other side can always read,
 * download or delete it.
 */
class XrowExtractJob
{
    const ID_PATTERN = '/^[0-9a-f]{32}$/';
    const JOB_FILE = 'job.json';
    const LOG_FILE = 'job.log';
    const PROGRESS_FILE = 'progress.json';
    /** A package install's watch list (remote ids + start time), counted by XrowExtractPackage::installProgress() */
    const INSTALL_WATCH_FILE = 'install-watch.json';

    /** The folder all jobs live in, created (and handed to the var directory's owner) if missing. */
    public static function baseDir()
    {
        $dir = eZSys::varDirectory() . '/xrowextract-jobs';
        if ( !is_dir( $dir ) )
        {
            $umask = umask( 0077 );
            @mkdir( $dir, 0700, true );
            umask( $umask );
            self::fixOwnership( $dir );
        }
        return $dir;
    }

    public static function isValidID( $id )
    {
        return is_string( $id ) && preg_match( self::ID_PATTERN, $id ) === 1;
    }

    public static function path( $id )
    {
        return self::baseDir() . '/' . $id;
    }

    public static function exists( $id )
    {
        return self::isValidID( $id ) && is_dir( self::path( $id ) ) && is_file( self::path( $id ) . '/' . self::JOB_FILE );
    }

    /**
     * A new job. $data holds 'type' (csv|archive), 'owner' (login),
     * 'what' (human text: class/nodes), 'format' (csv, zip, tar.gz ...),
     * 'output_file' (download name, or null when the export decides it,
     * as the archive's timestamped name does), 'args' (the validated CLI
     * arguments for bin/php/<type>.php, without --output, --progress-file
     * or --user: those are added when the job runs).
     * Returns the new job id.
     */
    public static function create( array $data )
    {
        $id = bin2hex( random_bytes( 16 ) );
        $dir = self::path( $id );
        $umask = umask( 0077 );
        $made = @mkdir( $dir, 0700, true );
        umask( $umask );
        if ( !$made )
            throw new RuntimeException( 'Cannot create the job folder' );
        $job = array_merge( array(
            'id' => $id,
            'type' => 'csv',
            'owner' => '',
            'what' => '',
            'format' => 'csv',
            'output_file' => null,
            'args' => array(),
            'state' => 'queued',
            'created' => time(),
            'started' => null,
            'ended' => null,
            'pid' => null,
            'rows' => null,
            'size' => null,
            'error' => null,
        ), $data, array( 'id' => $id ) );
        self::save( $id, $job );
        self::fixOwnership( $dir );
        return $id;
    }

    /** The job's data, or null when the id is not one of ours. */
    public static function load( $id )
    {
        if ( !self::exists( $id ) )
            return null;
        $raw = @file_get_contents( self::path( $id ) . '/' . self::JOB_FILE );
        $job = $raw !== false ? json_decode( $raw, true ) : null;
        return is_array( $job ) ? $job : null;
    }

    /** Writes job.json (atomically: a temp file, then a rename). */
    public static function save( $id, array $job )
    {
        $file = self::path( $id ) . '/' . self::JOB_FILE;
        $tmp = $file . '.tmp-' . getmypid();
        file_put_contents( $tmp, json_encode( $job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
        @chmod( $tmp, 0600 );
        rename( $tmp, $file );
        self::fixOwnership( $file );
    }

    /** Loads, merges $patch over the stored job, saves. Returns the merged job, or null if it does not exist. */
    public static function update( $id, array $patch )
    {
        $job = self::load( $id );
        if ( $job === null )
            return null;
        $job = array_merge( $job, $patch );
        self::save( $id, $job );
        return $job;
    }

    /**
     * Marks a job that is still queued or running as failed, with $message as its error (and as the
     * last line of its log, where the Jobs page shows it). A job in any other state (done, skipped,
     * already failed or cancelled) is left alone. True when it was marked.
     */
    public static function markFailed( $id, $message )
    {
        if ( !self::isValidID( $id ) )
            return false;
        $job = self::load( $id );
        if ( !$job || !in_array( $job['state'], array( 'queued', 'running' ), true ) || !empty( $job['cancelled'] ) )
            return false;
        $message = trim( (string)preg_replace( '/\s+/', ' ', (string)$message ) );
        $job['state'] = 'failed';
        $job['ended'] = time();
        $job['error'] = mb_substr( $message !== '' ? $message : 'The job stopped.', 0, 1000 );
        self::save( $id, $job );
        $log = @fopen( self::path( $id ) . '/' . self::LOG_FILE, 'a' );
        if ( $log )
        {
            fwrite( $log, '[' . date( 'c' ) . '] ' . $job['error'] . "\n" );
            fclose( $log );
        }
        return true;
    }

    /** Every job, newest first. */
    public static function listAll()
    {
        $jobs = array();
        foreach ( (array)@scandir( self::baseDir() ) as $entry )
        {
            if ( !self::isValidID( $entry ) )
                continue;
            $job = self::load( $entry );
            if ( $job !== null )
                $jobs[] = $job;
        }
        usort( $jobs, function ( $a, $b ) { return $b['created'] - $a['created']; } );
        return $jobs;
    }

    /** The jobs a viewer may see: everyone's with $allJobs, else only their own. */
    public static function forViewer( $login, $allJobs )
    {
        $jobs = self::listAll();
        if ( $allJobs )
            return $jobs;
        return array_values( array_filter( $jobs, function ( $job ) use ( $login ) {
            return isset( $job['owner'] ) && $job['owner'] === $login;
        } ) );
    }

    public static function canSee( array $job, $login, $allJobs )
    {
        return $allJobs || ( isset( $job['owner'] ) && $job['owner'] === $login );
    }

    /** How many of the viewer's jobs are queued or running (for the Jobs tab badge). */
    public static function countRunning( $login, $allJobs )
    {
        $count = 0;
        foreach ( self::forViewer( $login, $allJobs ) as $job )
        {
            if ( isset( $job['state'] ) && ( $job['state'] === 'queued' || $job['state'] === 'running' ) )
                $count++;
        }
        return $count;
    }

    /**
     * Cancels a queued or running job: stops the job's runner (bin/php/job.php) and every process below it
     * (the export/import/install script, and anything that started, e.g. tar), and records it as failed with
     * "Cancelled by <login>". Whatever the job already wrote stays (an install's objects are not rolled back).
     * A process started by another system user (a job started from :8080 runs as root, from :443 as the
     * web user) may not be stoppable from here; that is reported, and the job is still marked cancelled so
     * its runner does not overwrite the state when it ends.
     * @return array{0: bool, 1: string} ok, message
     */
    public static function cancel( $id, $login )
    {
        $job = self::load( $id );
        if ( !$job || !in_array( $job['state'], array( 'queued', 'running' ), true ) )
            return array( false, 'The job is not queued or running.' );
        $pid = isset( $job['pid'] ) ? (int)$job['pid'] : 0;
        $notStopped = array();
        if ( $pid > 1 )
        {
            // The whole tree first, while the runner still holds it together, then stop it from the top down
            $tree = array( $pid );
            for ( $i = 0; $i < count( $tree ) && $i < 64; $i++ )
            {
                $children = array();
                @exec( 'pgrep -P ' . (int)$tree[$i], $children );
                foreach ( $children as $child )
                    if ( ctype_digit( trim( $child ) ) )
                        $tree[] = (int)trim( $child );
            }
            foreach ( $tree as $process )
            {
                $stopped = function_exists( 'posix_kill' ) ? @posix_kill( $process, 15 ) : false;
                if ( !$stopped )
                {
                    $output = array();
                    @exec( 'kill -TERM ' . (int)$process . ' 2>&1', $output, $code );
                    $stopped = $code === 0;
                }
                if ( !$stopped && file_exists( '/proc/' . (int)$process ) )
                    $notStopped[] = $process;
            }
        }
        self::update( $id, array(
            'state' => 'failed', 'ended' => time(), 'cancelled' => true,
            'error' => 'Cancelled by ' . $login . ( $notStopped ? ' (process ' . implode( ', ', $notStopped ) . ' could not be stopped from this server user; it may finish on its own)' : '' ),
        ) );
        return array( !$notStopped, $notStopped ? 'The job was marked cancelled, but its process could not be stopped from here.' : 'The job was cancelled.' );
    }

    /** Removes a job's folder (its files, then itself). */
    public static function delete( $id )
    {
        if ( !self::isValidID( $id ) )
            return false;
        $dir = self::path( $id );
        if ( !is_dir( $dir ) )
            return false;
        foreach ( (array)@scandir( $dir ) as $entry )
        {
            if ( $entry === '.' || $entry === '..' )
                continue;
            $full = $dir . '/' . $entry;
            if ( is_file( $full ) )
                @unlink( $full );
        }
        return @rmdir( $dir );
    }

    /** Jobs older (by their end time, or their start time when they never finished) than the retention: removed, count returned. */
    public static function clean()
    {
        $cutoff = time() - self::retentionDays() * 86400;
        $removed = 0;
        foreach ( self::listAll() as $job )
        {
            $when = !empty( $job['ended'] ) ? $job['ended'] : $job['created'];
            if ( $when < $cutoff )
            {
                self::delete( $job['id'] );
                $removed++;
            }
        }
        return $removed;
    }

    public static function retentionDays()
    {
        $ini = eZINI::instance( 'csv.ini' );
        if ( $ini->hasVariable( 'Jobs', 'RetentionDays' ) )
        {
            $days = (int)$ini->variable( 'Jobs', 'RetentionDays' );
            if ( $days > 0 )
                return $days;
        }
        return 7;
    }

    /** Whether the current user may see and act on every user's jobs (policy xrowextract/all_jobs). */
    public static function allowAllJobs()
    {
        $access = eZUser::currentUser()->hasAccessTo( 'xrowextract', 'all_jobs' );
        return $access['accessWord'] !== 'no';
    }

    /**
     * Who a login is, for the Jobs page's and the Presets card's user bubble: the login resolved once to
     * the user's name, initials, a colour that stays the same for that login, and the user's node in the
     * admin (false when the account no longer exists).
     */
    public static function ownerInfo( $ownerLogin )
    {
        static $owners = array();
        if ( isset( $owners[$ownerLogin] ) )
            return $owners[$ownerLogin];
        $name = $ownerLogin;
        $nodeID = false;
        $user = $ownerLogin !== '' ? eZUser::fetchByName( $ownerLogin ) : null;
        if ( $user instanceof eZUser )
        {
            $object = $user->attribute( 'contentobject' );
            if ( $object instanceof eZContentObject )
            {
                if ( trim( (string)$object->attribute( 'name' ) ) !== '' )
                    $name = $object->attribute( 'name' );
                $nodeID = (int)$object->attribute( 'main_node_id' ) ?: false;
            }
        }
        $initials = '';
        foreach ( preg_split( '/[\s._@-]+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ) as $part )
        {
            $initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
            if ( mb_strlen( $initials ) >= 2 )
                break;
        }
        return $owners[$ownerLogin] = array(
            'login' => $ownerLogin,
            'name' => $name,
            'initials' => $initials !== '' ? $initials : '?',
            'hue' => hexdec( substr( md5( $ownerLogin ), 0, 4 ) ) % 360,
            'node_id' => $nodeID,
        );
    }

    /**
     * Writes progress.json (or another path) atomically. $phase is free text:
     * a class identifier (archive) or a locale (csv), whatever is being
     * written when the progress was last reported.
     */
    public static function writeProgress( $path, $done, $total, $phase = '' )
    {
        $tmp = $path . '.tmp-' . getmypid();
        $written = @file_put_contents( $tmp, json_encode( array(
            'done' => (int)$done, 'total' => (int)$total, 'phase' => (string)$phase, 'updated' => time(),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
        if ( $written === false )
            return;
        @chmod( $tmp, 0600 );
        @rename( $tmp, $path );
        self::fixOwnership( $path );
    }

    public static function readProgress( $path )
    {
        if ( !is_file( $path ) )
            return null;
        $raw = @file_get_contents( $path );
        $data = $raw !== false && $raw !== '' ? json_decode( $raw, true ) : null;
        return is_array( $data ) ? $data : null;
    }

    /**
     * A job log as a person reads it: terminal colour codes removed (eZCLI writes them, the kernel's
     * progress bars included) and carriage-return redraws flattened. A progress bar redraws itself after
     * every item ("Installing content objects | 12.6% (548/4339) | elapsed ... | end @ 16:15"), thousands of
     * near-identical lines that would bury the steps, warnings and errors; of those only a short timeline is
     * kept: the first line of each phase, then one line each time the phase passes another 10 %.
     * $state carries the phase and 10 % step already written between calls (the Jobs page appends a running
     * log in pieces), so the timeline does not start over with every piece.
     */
    public static function cleanLog( $text, ?array &$state = null )
    {
        if ( !is_array( $state ) )
            $state = array( 'phase' => '', 'step' => -1 );
        $text = preg_replace( '/\x1b\[[0-9;?]*[A-Za-z]/', '', (string)$text );
        $text = preg_replace( '/(?<![\x1b])\[[0-9;]{1,12}m/', '', $text ); // codes whose ESC byte got lost
        $text = str_replace( "\r\n", "\n", $text );
        $lines = array();
        foreach ( preg_split( '/[\n\r]/', $text ) as $line )
        {
            $progress = self::parseProgressLine( $line );
            if ( $progress )
            {
                $phase = $progress['phase'] !== '' ? $progress['phase'] : 'Working';
                $step = (int)floor( $progress['percent'] / 10 );
                if ( $phase === $state['phase'] && $step <= $state['step'] )
                    continue;
                $state = array( 'phase' => $phase, 'step' => $step );
                $lines[] = sprintf( '%s · %s%% (%d/%d)%s%s', $phase, rtrim( rtrim( number_format( $progress['percent'], 1 ), '0' ), '.' ),
                                    $progress['done'], $progress['total'],
                                    $progress['elapsed'] !== '' ? ' · elapsed ' . $progress['elapsed'] : '',
                                    $progress['end_at'] !== '' ? ' · expected end ' . $progress['end_at'] : '' );
                continue;
            }
            // Blank lines: never two in a row, and none at all around the progress timeline (they are the
            // gaps the bar's redraws leave, not paragraphs)
            if ( trim( $line ) === '' && ( !$lines || trim( end( $lines ) ) === '' || $state['step'] >= 0 ) )
                continue;
            $lines[] = rtrim( $line );
        }
        return implode( "\n", $lines );
    }

    /**
     * A whole job log, cleaned (cleanLog()), read line by line so a long log costs no memory: the text, the
     * timeline state reached at its end (for the page to continue from) and the byte size read.
     * Beyond $maxBytes (default 8 MB) only the last $maxBytes are read, after a marker line.
     */
    public static function cleanLogFile( $path, $maxBytes = 8388608 )
    {
        $state = null;
        $size = is_file( $path ) ? (int)@filesize( $path ) : 0;
        $out = '';
        $fp = $size ? @fopen( $path, 'rb' ) : false;
        if ( $fp )
        {
            if ( $size > $maxBytes )
            {
                fseek( $fp, $size - $maxBytes );
                fgets( $fp ); // the line the cut runs through
                $out .= "…\n";
            }
            $batch = '';
            while ( ( $line = fgets( $fp ) ) !== false )
            {
                $batch .= $line;
                if ( strlen( $batch ) > 262144 )
                {
                    $out .= self::cleanLog( $batch, $state ) . "\n";
                    $batch = '';
                }
            }
            if ( $batch !== '' )
                $out .= self::cleanLog( $batch, $state );
            fclose( $fp );
        }
        return array( 'text' => trim( $out, "\n" ), 'state' => $state ? $state : array( 'phase' => '', 'step' => -1 ), 'size' => $size );
    }

    /** One eZCLI progress bar line, as array( phase, percent, done, total, elapsed, end_at ), or null. */
    public static function parseProgressLine( $line )
    {
        $line = preg_replace( '/\x1b?\[[0-9;?]*m/', '', (string)$line );
        if ( !preg_match( '/^\s*(?:(.*?)\s*\|\s*)?([\d.]+)%\s*\((\d+)\/(\d+)\)(?:.*?elapsed\s*([\d:]+))?(?:.*?end\s*@\s*([\d:]+))?/', $line, $m ) )
            return null;
        return array( 'phase' => trim( (string)$m[1] ), 'percent' => (float)$m[2], 'done' => (int)$m[3], 'total' => (int)$m[4],
                      'elapsed' => isset( $m[5] ) ? $m[5] : '', 'end_at' => isset( $m[6] ) ? $m[6] : '' );
    }

    /**
     * The latest progress a job's own output reported (the kernel's installers draw eZCLI progress bars:
     * "Installing content objects | 40% (1736/4339) | elapsed 00:10:13 | end @ 16:16"), read from the end of
     * its log: the exact count, a percentage, the time spent and the expected end. Null when there is none.
     */
    public static function logProgress( $logPath )
    {
        if ( !is_file( $logPath ) )
            return null;
        $size = (int)@filesize( $logPath );
        $tail = (string)@file_get_contents( $logPath, false, null, max( 0, $size - 16384 ) );
        $found = null;
        foreach ( preg_split( '/[\n\r]/', $tail ) as $line )
        {
            $parsed = self::parseProgressLine( $line );
            if ( $parsed )
                $found = $parsed;
        }
        if ( !$found )
            return null;
        $phase = $found['phase'] !== '' ? $found['phase'] : 'working';
        $details = array( $phase, rtrim( rtrim( number_format( $found['percent'], 1 ), '0' ), '.' ) . '%' );
        if ( $found['elapsed'] !== '' )
            $details[] = 'elapsed ' . $found['elapsed'];
        if ( $found['end_at'] !== '' )
            $details[] = 'expected end ' . $found['end_at'];
        return array( 'done' => $found['done'], 'total' => $found['total'], 'phase' => implode( ' · ', $details ) );
    }

    /** bin/php/csv.php, archive.php or package.php, the scripts a job runs. */
    public static function scriptFor( $type )
    {
        $files = array( 'archive' => 'archive.php', 'import' => 'import.php', 'package' => 'package.php' );
        $file = isset( $files[$type] ) ? $files[$type] : 'csv.php';
        $path = realpath( dirname( __FILE__ ) . '/../bin/php/' . $file );
        return $path !== false ? $path : dirname( __FILE__ ) . '/../bin/php/' . $file;
    }

    /** bin/php/job.php itself, to launch a job's run. */
    public static function runnerScript()
    {
        $path = realpath( dirname( __FILE__ ) . '/../bin/php/job.php' );
        return $path !== false ? $path : dirname( __FILE__ ) . '/../bin/php/job.php';
    }

    /**
     * The PHP command line binary to run a job with: csv.ini [Jobs] PhpCli
     * first, else PHP_BINARY -- which under PHP-FPM is the FPM binary, so a
     * Plesk-style .../sbin/php-fpm is mapped to .../bin/php -- else whatever
     * "php" resolves to on the PATH. False when none of these is executable.
     */
    public static function phpCliBinary()
    {
        $ini = eZINI::instance( 'csv.ini' );
        if ( $ini->hasVariable( 'Jobs', 'PhpCli' ) )
        {
            $configured = trim( (string)$ini->variable( 'Jobs', 'PhpCli' ) );
            if ( $configured !== '' && is_executable( $configured ) )
                return $configured;
        }
        $candidates = array();
        if ( defined( 'PHP_BINARY' ) && PHP_BINARY !== '' )
        {
            $candidates[] = PHP_BINARY;
            // Plesk and the common FHS layout keep the CLI binary in bin/ next to sbin/php-fpm or sbin/php-cgi
            $candidates[] = preg_replace( '#/sbin/php-fpm[0-9.]*$#', '/bin/php', PHP_BINARY );
            $candidates[] = preg_replace( '#/sbin/php-cgi[0-9.]*$#', '/bin/php', PHP_BINARY );
        }
        foreach ( $candidates as $candidate )
        {
            $base = basename( $candidate );
            if ( $candidate && is_executable( $candidate ) && strpos( $base, 'fpm' ) === false && strpos( $base, 'cgi' ) === false )
                return $candidate;
        }
        $found = trim( (string)@shell_exec( 'command -v php 2>/dev/null' ) );
        if ( $found !== '' && is_executable( $found ) )
            return $found;
        return false;
    }

    /** Whether this process may launch a detached child (exec() available and not disabled). */
    public static function canRunDetached()
    {
        if ( !function_exists( 'exec' ) || !function_exists( 'proc_open' ) )
            return false;
        $disabled = array_map( 'trim', explode( ',', (string)ini_get( 'disable_functions' ) ) );
        return !in_array( 'exec', $disabled, true ) && !in_array( 'proc_open', $disabled, true );
    }

    /** Whether a background export can be offered at all on this server. */
    public static function available()
    {
        return self::canRunDetached() && self::phpCliBinary() !== false;
    }

    /**
     * Launches "<php cli> bin/php/job.php --run=<id>" detached: the request
     * does not wait for it. bin/php/job.php itself carries the job out and
     * updates job.json as it goes.
     */
    public static function start( $id )
    {
        if ( !self::available() )
            return false;
        $php = self::phpCliBinary();
        $log = self::path( $id ) . '/' . self::LOG_FILE;
        $command = 'nohup ' . escapeshellarg( $php ) . ' ' . escapeshellarg( self::runnerScript() )
                 . ' --run=' . escapeshellarg( $id )
                 . ' >> ' . escapeshellarg( $log ) . ' 2>&1 < /dev/null & echo $!';
        $output = array();
        @exec( $command, $output );
        $pid = isset( $output[0] ) ? (int)trim( $output[0] ) : 0;
        if ( $pid > 0 )
            self::update( $id, array( 'pid' => $pid ) );
        return true;
    }

    /**
     * Chowns a job path to the owner of the var directory, when this
     * process is running as root (Velocity, or a root command line): a
     * folder or file it creates must stay usable by the alpha user PHP-FPM
     * runs as. A no-op for anything else, and errors are swallowed: a
     * failed chown must never break the export itself.
     */
    public static function fixOwnership( $path )
    {
        if ( !self::runningAsRoot() )
            return;
        $owner = self::varOwner();
        if ( !$owner )
            return;
        @chown( $path, $owner[0] );
        @chgrp( $path, $owner[1] );
    }

    /** Whether this process is running as root (Velocity, or a root command line). */
    public static function runningAsRoot()
    {
        if ( function_exists( 'posix_getuid' ) )
            return posix_getuid() === 0;
        return trim( (string)@shell_exec( 'id -u 2>/dev/null' ) ) === '0';
    }

    /** array( uid, gid ) of the var directory's owner, or false when it cannot be read. */
    protected static function varOwner()
    {
        static $owner = null;
        if ( $owner === null )
        {
            $dir = eZSys::varDirectory();
            $owner = is_dir( $dir ) ? array( fileowner( $dir ), filegroup( $dir ) ) : false;
        }
        return $owner;
    }
}
