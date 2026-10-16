<?php

use PHPUnit\Framework\TestCase;

/**
 * The extension's pages follow the administration's styling rules (Exponential's doc/bc/6.0/admin4-styling.md):
 * no style="" attribute and no <style> block in a template or in markup a script writes, and colours only from the
 * --a4-* tokens of the administration's components.css, which have a light and a dark value, so every page follows
 * admin4's dark mode.
 */
class StylingRulesTest extends TestCase
{
    private static function root(): string
    {
        return dirname( __DIR__, 2 );
    }

    /** @return list<string> */
    private static function files( string $dir, string $suffix ): array
    {
        $files = array();
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::root() . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file )
        {
            if ( substr( $file->getFilename(), -strlen( $suffix ) ) === $suffix )
                $files[] = $file->getPathname();
        }
        sort( $files );
        return $files;
    }

    public function testTemplatesHaveNoInlineCss(): void
    {
        $found = array();
        foreach ( self::files( 'design', '.tpl' ) as $path )
        {
            // template comments are not output
            $source = preg_replace( '/\{\*.*?\*\}/s', '', (string)file_get_contents( $path ) );
            if ( preg_match_all( '/<style\b|\sstyle\s*=/i', $source, $m ) )
                $found[] = basename( $path ) . ' (' . count( $m[0] ) . ')';
        }
        $this->assertSame( array(), $found, 'style="" or <style> in templates' );
    }

    public function testScriptsWriteNoInlineSizesOrColours(): void
    {
        $found = array();
        foreach ( self::files( 'design', '.js' ) as $path )
        {
            foreach ( file( $path ) as $n => $line )
            {
                if ( preg_match( '/\.style\.(width|height|background|color)|style\s*=\s*["\']|<style/', $line ) )
                    $found[] = basename( $path ) . ':' . ( $n + 1 );
            }
        }
        $this->assertSame( array(), $found, 'a script sets a width or a colour inline' );
    }

    public function testStylesheetsTakeTheirColoursFromTheTokens(): void
    {
        $found = array();
        foreach ( self::files( 'design', '.css' ) as $path )
        {
            $source = preg_replace( '#/\*.*?\*/#s', '', (string)file_get_contents( $path ) );
            foreach ( explode( "\n", $source ) as $n => $line )
            {
                if ( preg_match( '/#[0-9a-f]{3,8}\b|\b(rgba?|hsla?)\(|:\s*(white|black)\b/i', $line ) )
                    $found[] = basename( $path ) . ':' . ( $n + 1 ) . ' ' . trim( $line );
                if ( preg_match( '/var\(--(?!a4-|xe-cell-width)[a-z0-9-]+/i', $line ) )
                    $found[] = basename( $path ) . ':' . ( $n + 1 ) . ' local custom property ' . trim( $line );
            }
        }
        $this->assertSame( array(), $found, 'colour literals or local colour properties in the stylesheets' );
    }

    public function testTemplatesCloseTheFramesTheyOpen(): void
    {
        // a template that closes more <div> than it opens ends the administration's page frame early, which puts the
        // footer outside the page (the Package tab closed two too many up to 2.5.14)
        $found = array();
        foreach ( self::files( 'design', '.tpl' ) as $path )
        {
            $source = preg_replace( '/\{\*.*?\*\}/s', '', (string)file_get_contents( $path ) );
            $open = preg_match_all( '/<div[\s>]/i', $source );
            $close = preg_match_all( '/<\/div>/i', $source );
            if ( $open !== $close )
                $found[] = basename( $path ) . " ($open open, $close closed)";
        }
        $this->assertSame( array(), $found, 'templates whose <div> elements do not balance' );
    }
}
