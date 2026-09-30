<?php

use PHPUnit\Framework\TestCase;

/** Names and ids taken from a request or a file: package names, preset ids, upload ids. */
class NamesAndIdsTest extends TestCase
{
    public function testPackageNamesAreWhatTheKernelImports(): void
    {
        $cases = array(
            'xrowextract_export_Websites_2' => 'xrowextract_export_websites_2',
            'My Package!' => 'my_package',
            '__x__' => 'x',
            '' => 'package',
            '!!!' => 'package',
            'already_fine_1' => 'already_fine_1',
        );
        foreach ( $cases as $in => $want )
        {
            $name = XrowExtractPackage::validPackageName( (string)$in );
            $this->assertSame( $want, $name, var_export( $in, true ) );
            $this->assertTrue( eZPackage::isValidName( $name ), $name );
        }
        $this->assertSame( 'package', XrowExtractPackage::validPackageName( null ) );
    }

    public function testPresetIds(): void
    {
        $id = XrowExtractPreset::newID();
        $this->assertTrue( XrowExtractPreset::isValidUserID( $id ) );
        foreach ( array( '', 'x', strtoupper( $id ), $id . '0', '../' . substr( $id, 3 ), null, 12 ) as $bad )
            $this->assertFalse( XrowExtractPreset::isValidUserID( $bad ), var_export( $bad, true ) );
    }

    public function testDatabaseIds(): void
    {
        foreach ( array( 1 => 1, '42' => 42, ' 7 ' => 7, '2147483647' => 2147483647 ) as $in => $want )
            $this->assertSame( $want, XrowExtractColumns::dbID( is_int( $in ) ? $in : (string)$in ), var_export( $in, true ) );
        foreach ( array( 0, -1, '0', '-5', '2147483648', '99999999999999999999', '1e3', '12abc', '', null, false, true, 1.5, array( 1 ), PHP_INT_MAX ) as $bad )
            $this->assertSame( 0, XrowExtractColumns::dbID( $bad ), var_export( $bad, true ) );
    }

    public function testUploadIds(): void
    {
        $this->assertTrue( XrowExtractUpload::isValidID( bin2hex( random_bytes( 16 ) ) ) );
        foreach ( array( '', '../../etc/passwd', 'a/b', null, array() ) as $bad )
            $this->assertFalse( XrowExtractUpload::isValidID( $bad ), var_export( $bad, true ) );
    }
}
