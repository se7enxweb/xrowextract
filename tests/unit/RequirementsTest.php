<?php

use PHPUnit\Framework\TestCase;

/** XrowExtractRequirements: what each feature needs, which ones are required, and the notices per page. */
class RequirementsTest extends TestCase
{
    /** @return array<string, array{ok: bool, detail: string}> Every requirement there. */
    private function allThere(): array
    {
        $results = array();
        foreach ( array_keys( XrowExtractRequirements::requirements() ) as $id )
            $results[$id] = array( 'ok' => true, 'detail' => '' );
        return $results;
    }

    public function testEveryFeatureNamesKnownRequirementsAndPages(): void
    {
        $requirements = XrowExtractRequirements::requirements();
        $pages = array( 'csv', 'archive', 'import', 'package', 'jobs', 'schedules', 'destinations', 'history' );
        $this->assertArrayHasKey( 'core', XrowExtractRequirements::features() );
        foreach ( XrowExtractRequirements::features() as $id => $feature )
        {
            $this->assertNotSame( '', $feature['name'], $id );
            $this->assertNotEmpty( $feature['requires'], $id );
            foreach ( $feature['requires'] as $requirement )
                $this->assertArrayHasKey( $requirement, $requirements, $id . ' needs ' . $requirement );
            foreach ( $feature['pages'] as $page )
                $this->assertContains( $page, $pages, $id . ' on ' . $page );
        }
        foreach ( $requirements as $id => $requirement )
        {
            $this->assertNotSame( '', $requirement['label'], $id );
            $this->assertNotSame( '', $requirement['hint'], $id );
        }
    }

    public function testEverythingThere(): void
    {
        $report = XrowExtractRequirements::evaluate( $this->allThere() );
        $this->assertTrue( $report['ok'] );
        foreach ( $report['features'] as $id => $feature )
            $this->assertTrue( $feature['available'], $id );
        foreach ( array( 'csv', 'archive', 'import', 'package', 'jobs', 'schedules', 'destinations', 'history' ) as $page )
            $this->assertSame( array(), XrowExtractRequirements::notices( $page, $report ), $page );
        $lines = XrowExtractRequirements::reportLines( $report );
        $this->assertStringStartsWith( 'PASS requirements:', end( $lines ) );
        $this->assertSame( array(), preg_grep( '/^(FAIL|WARN) /', $lines ) );
    }

    public function testAnOptionalRequirementOnlyTakesItsFeaturesAway(): void
    {
        $results = $this->allThere();
        $results['sodium'] = array( 'ok' => false, 'detail' => '' );
        $report = XrowExtractRequirements::evaluate( $results );
        $this->assertTrue( $report['ok'], 'sodium is not required' );
        $this->assertFalse( $report['requirements']['sodium']['required'] );
        $this->assertSame( array( 'secrets' ), $report['requirements']['sodium']['features'] );
        $this->assertFalse( $report['features']['secrets']['available'] );
        $this->assertSame( array( 'sodium' ), $report['features']['secrets']['missing'] );
        $this->assertTrue( $report['features']['transfer_curl']['available'] );

        // Only the page of the feature shows it
        $notices = XrowExtractRequirements::notices( 'destinations', $report );
        $this->assertCount( 1, $notices );
        $this->assertSame( 'secrets', $notices[0]['feature'] );
        $this->assertFalse( $notices[0]['required'] );
        $this->assertStringContainsString( 'sodium', $notices[0]['missing'] );
        foreach ( array( 'csv', 'archive', 'import', 'package', 'jobs', 'schedules', 'history' ) as $page )
            $this->assertSame( array(), XrowExtractRequirements::notices( $page, $report ), $page );

        $lines = XrowExtractRequirements::reportLines( $report );
        $this->assertCount( 1, preg_grep( '/^WARN sodium: .*needed by: Passwords and keys of destinations/', $lines ) );
        $this->assertSame( array(), preg_grep( '/^FAIL /', $lines ) );
        $this->assertStringStartsWith( 'PASS requirements:', end( $lines ) );
    }

    public function testDisabledProcessFunctionsTakeJobsSchedulesAndPackagesAway(): void
    {
        $results = $this->allThere();
        $results['exec'] = array( 'ok' => false, 'detail' => 'disabled (disable_functions)' );
        $results['proc_open'] = array( 'ok' => false, 'detail' => 'disabled (disable_functions)' );
        $report = XrowExtractRequirements::evaluate( $results );
        $this->assertTrue( $report['ok'] );
        foreach ( array( 'jobs', 'schedules', 'package', 'transfer_sftp' ) as $feature )
            $this->assertFalse( $report['features'][$feature]['available'], $feature );
        foreach ( array( 'core', 'archive', 'import', 'secrets', 'transfer_curl', 'csv_manifest' ) as $feature )
            $this->assertTrue( $report['features'][$feature]['available'], $feature );
        $this->assertSame( array( 'jobs' ), array_column( XrowExtractRequirements::notices( 'jobs', $report ), 'feature' ) );
        $this->assertSame( array( 'package' ), array_column( XrowExtractRequirements::notices( 'import', $report ), 'feature' ) );
        $this->assertSame( array( 'transfer_sftp' ), array_column( XrowExtractRequirements::notices( 'destinations', $report ), 'feature' ) );
        $this->assertSame( array(), XrowExtractRequirements::notices( 'csv', $report ) );
    }

    public function testARequiredRequirementFailsTheCheckAndShowsEverywhere(): void
    {
        $results = $this->allThere();
        $results['mbstring'] = array( 'ok' => false, 'detail' => '' );
        unset( $results['var_dir'] ); // not probed counts as missing
        $report = XrowExtractRequirements::evaluate( $results );
        $this->assertFalse( $report['ok'] );
        $this->assertTrue( $report['requirements']['mbstring']['required'] );
        $this->assertSame( array( 'mbstring', 'var_dir' ), $report['features']['core']['missing'] );
        foreach ( array( 'csv', 'archive', 'import', 'package', 'jobs', 'schedules', 'destinations', 'history' ) as $page )
        {
            $notices = XrowExtractRequirements::notices( $page, $report );
            $this->assertCount( 1, $notices, $page );
            $this->assertTrue( $notices[0]['required'] );
        }
        $lines = XrowExtractRequirements::reportLines( $report );
        $this->assertCount( 2, preg_grep( '/^FAIL (mbstring|var_dir): .*: missing.*, required\./', $lines ) );
        $this->assertStringStartsWith( 'FAIL requirements: 2 required', end( $lines ) );
    }

    public function testProbeOfThisServer(): void
    {
        XrowExtractRequirements::clearCache();
        $results = XrowExtractRequirements::probe();
        $this->assertSame( array_keys( XrowExtractRequirements::requirements() ), array_keys( $results ) );
        // What the tests themselves run on
        $this->assertTrue( $results['php']['ok'] );
        $this->assertTrue( $results['json']['ok'] );
        $this->assertTrue( $results['ctype']['ok'] );
        $this->assertSame( class_exists( 'ZipArchive' ), $results['zip']['ok'] );
        $this->assertSame( function_exists( 'curl_init' ), $results['curl']['ok'] );
        $this->assertSame( $results, XrowExtractRequirements::probe(), 'probed once per request' );
        $this->assertSame( XrowExtractRequirements::evaluate( $results ), XrowExtractRequirements::check() );
        $this->assertSame( XrowExtractRequirements::check()['features']['jobs']['available'], XrowExtractRequirements::available( 'jobs' ) );
        $this->assertFalse( XrowExtractRequirements::available( 'no-such-feature' ) );
    }
}
