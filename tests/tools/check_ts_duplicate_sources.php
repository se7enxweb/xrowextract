<?php
/**
 * Reports every <source> that appears more than once inside the same <context>
 * of a Qt .ts translation file: the sign of a merge that kept a message from
 * both sides instead of once. The translation system uses the first one and
 * silently ignores the other, so an edit to the second never shows.
 *
 * Usage: php tests/tools/check_ts_duplicate_sources.php translations/*\/translation.ts
 * Prints FAIL lines for each duplicate and a PASS/FAIL summary; exit code 1 on
 * any duplicate or unreadable file.
 */

if ( PHP_SAPI !== 'cli' )
{
    exit( 1 );
}

$files = array_slice( $argv, 1 );
if ( !$files )
{
    fwrite( STDERR, "usage: php " . $argv[0] . " <file.ts> [...]\n" );
    exit( 2 );
}

$duplicates = 0;
$broken = 0;
foreach ( $files as $file )
{
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors( true );
    $loaded = is_file( $file ) && $doc->load( $file, LIBXML_NONET );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );
    if ( !$loaded )
    {
        echo "FAIL $file: not a readable XML file\n";
        $broken++;
        continue;
    }
    foreach ( $doc->getElementsByTagName( 'context' ) as $context )
    {
        $name = '?';
        $seen = array();
        foreach ( $context->childNodes as $child )
        {
            if ( !$child instanceof DOMElement )
            {
                continue;
            }
            if ( $child->nodeName === 'name' )
            {
                $name = $child->textContent;
            }
            elseif ( $child->nodeName === 'message' )
            {
                foreach ( $child->childNodes as $part )
                {
                    if ( $part instanceof DOMElement && $part->nodeName === 'source' )
                    {
                        $source = $part->textContent;
                        $seen[$source] = isset( $seen[$source] ) ? $seen[$source] + 1 : 1;
                    }
                }
            }
        }
        foreach ( $seen as $source => $count )
        {
            if ( $count > 1 )
            {
                echo "FAIL $file [$name]: x$count " . mb_substr( $source, 0, 100 ) . "\n";
                $duplicates++;
            }
        }
    }
}

if ( $duplicates || $broken )
{
    echo "FAIL $duplicates duplicate <source>(s), $broken unreadable file(s)\n";
    exit( 1 );
}
echo "PASS no duplicate <source> within any context (" . count( $files ) . " file(s))\n";
exit( 0 );
