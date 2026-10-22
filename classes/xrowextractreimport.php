<?php

/**
 * "Re-import this file" on the Jobs and History pages: the file of a finished class export (a CSV, JSON or XML job
 * of the One class view, the command line or a schedule) opened in the Import page as if it had been uploaded, its
 * typed column manifest with it when the job wrote one, so the mapping is exact. Nothing is imported until the user
 * confirms the import's steps.
 *
 * A file qualifies only when the user may see the job (XrowExtractJob::canSee(), the same rule as its download),
 * the job is a finished export of type csv, and its file is CSV, JSON or XML and still on disk. Content packages
 * (.ezpkg) have "Open the package" instead; archives are many files and go through the Import page's upload.
 */
class XrowExtractReimport
{
    /** The file formats a job's file can be imported again in */
    const FORMATS = array( 'csv', 'json', 'xml' );

    /**
     * Whether a job's file can be imported again, without asking who looks (the Jobs and History rows; the view
     * checks the user again).
     *
     * @param array|null $job as XrowExtractJob::load() returns it
     */
    public static function isReimportable( $job ): bool
    {
        if ( !is_array( $job ) || ( $job['type'] ?? '' ) !== 'csv' || ( $job['state'] ?? '' ) !== 'done' )
            return false;
        $file = (string)( $job['output_file'] ?? '' );
        if ( $file === '' || basename( $file ) !== $file )
            return false;
        $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
        return in_array( $ext, self::FORMATS, true );
    }

    /**
     * The file of a job the user may import again, or null.
     *
     * @param array|null $job
     * @param string $login the current user's login
     * @param bool $allJobs whether the user may see every user's jobs (xrowextract/all_jobs)
     * @param string|null $directory the job's directory (XrowExtractJob::path() when null; tests give one)
     * @return array|null array( 'path' => data file, 'name' => its file name, 'manifest' => sidecar path or null )
     */
    public static function source( $job, $login, $allJobs, $directory = null ): ?array
    {
        if ( !self::isReimportable( $job ) || !XrowExtractJob::canSee( $job, $login, $allJobs ) )
            return null;
        $directory = $directory !== null ? $directory : XrowExtractJob::path( (string)$job['id'] );
        $path = rtrim( $directory, '/' ) . '/' . $job['output_file'];
        if ( !is_file( $path ) )
            return null;
        $manifest = XrowExtractManifest::sidecarPath( $path );
        return array( 'path' => $path, 'name' => (string)$job['output_file'], 'manifest' => is_file( $manifest ) ? $manifest : null );
    }
}
