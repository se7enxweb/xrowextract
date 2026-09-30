<?php

/** The xrowextract module's template fetch functions (modules/xrowextract/function_definition.php). */
class XrowExtractFunctionCollection
{
    /** The eZ preference that holds when the user last acknowledged the schedule alerts. */
    const ALERTS_SEEN_PREFERENCE = 'admin_xrowextract_alerts_seen';

    /**
     * How many failed or skipped scheduled runs, and failed deliveries, the current user may see and has
     * not acknowledged on the History page yet. 0 without the policy xrowextract/history, and before any
     * schedule table exists (never creates one).
     *
     * @return array{result: int}
     */
    public static function fetchScheduleAlerts(): array
    {
        if ( !XrowExtractSchema::exists() || !XrowExtractHistory::canView() )
            return array( 'result' => 0 );
        $user = eZUser::currentUser();
        $since = (int)eZPreferences::value( self::ALERTS_SEEN_PREFERENCE, $user );
        return array( 'result' => XrowExtractHistory::alertCount( $since, $user->attribute( 'login' ), XrowExtractJob::allowAllJobs() ) );
    }

    /**
     * The notices of one admin page about what this server cannot offer there (XrowExtractRequirements::notices()):
     * empty when every feature of the page works. $page is not one of the pages: no notices.
     *
     * @param mixed $page the page, as the template passes it
     * @return array{result: list<array{feature: string, name: string, missing: string, hint: string, required: bool}>}
     */
    public static function fetchRequirementNotices( $page ): array
    {
        return array( 'result' => is_string( $page ) ? XrowExtractRequirements::notices( $page ) : array() );
    }
}

?>
