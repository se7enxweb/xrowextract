<?php

/**
 * When a schedule runs: the simple choices of the Schedules page (hourly at minute M, daily at HH:MM,
 * weekly on a day at HH:MM, monthly on a day at HH:MM) are stored as a small array and turned into the
 * standard 5-field cron expression, which is also what an admin types for "advanced". One code path
 * (nextRun()) answers "when next" for both, in the server's time zone, minute by minute from the fields'
 * own value sets - no day/week/month arithmetic of its own, and no end date that could silently switch
 * a schedule off.
 *
 * Fields: minute (0-59) hour (0-23) day of month (1-31) month (1-12) day of week (0-7, 0 and 7 Sunday).
 * Each field: "*", a number, a range a-b, a list a,b,c, and a step ("*" or a range, then "/n"). Names (mon, jan) are
 * accepted for day of week and month. As in cron, when both day of month and day of week are
 * restricted, a day matching either one is a match.
 */
class XrowExtractCron
{
    const RANGES = array( array( 0, 59 ), array( 0, 23 ), array( 1, 31 ), array( 1, 12 ), array( 0, 7 ) );

    /**
     * The simple frequencies.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return array( 'hourly', 'daily', 'weekly', 'monthly', 'cron' );
    }

    /**
     * A frequency array ('kind' => hourly|daily|weekly|monthly|cron, 'minute', 'time' => 'HH:MM',
     * 'weekday' => 0-6, 'monthday' => 1-31, 'expression') as a cron expression, or false when invalid.
     *
     * @param array<string, mixed> $frequency
     */
    public static function expressionFor( array $frequency ): string|false
    {
        $kind = isset( $frequency['kind'] ) ? $frequency['kind'] : 'daily';
        $time = isset( $frequency['time'] ) ? (string)$frequency['time'] : '02:00';
        if ( !preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m ) )
            $m = array( '', '2', '00' );
        $hour = (int)$m[1];
        $minute = (int)$m[2];
        switch ( $kind )
        {
            case 'hourly':
                $atMinute = isset( $frequency['minute'] ) ? (int)$frequency['minute'] : 0;
                return ( $atMinute >= 0 && $atMinute <= 59 ? $atMinute : 0 ) . ' * * * *';
            case 'daily':
                return "$minute $hour * * *";
            case 'weekly':
                $weekday = isset( $frequency['weekday'] ) ? (int)$frequency['weekday'] : 1;
                return "$minute $hour * * " . ( $weekday >= 0 && $weekday <= 6 ? $weekday : 1 );
            case 'monthly':
                $monthday = isset( $frequency['monthday'] ) ? (int)$frequency['monthday'] : 1;
                return "$minute $hour " . ( $monthday >= 1 && $monthday <= 31 ? $monthday : 1 ) . ' * *';
            case 'cron':
                $expression = trim( preg_replace( '/\s+/', ' ', isset( $frequency['expression'] ) ? (string)$frequency['expression'] : '' ) );
                return self::parse( $expression ) ? $expression : false;
        }
        return false;
    }

    /**
     * The five fields as sets of allowed values, or false when the expression is not valid.
     *
     * @param mixed $expression
     * @return array{minute: array<int, bool>, hour: array<int, bool>, day: array<int, bool>, month: array<int, bool>, weekday: array<int, bool>, day_any: bool, weekday_any: bool}|false
     */
    public static function parse( $expression ): array|false
    {
        $expression = strtolower( trim( (string)$expression ) );
        $aliases = array( '@hourly' => '0 * * * *', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@weekly' => '0 0 * * 0',
                          '@monthly' => '0 0 1 * *', '@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *' );
        if ( isset( $aliases[$expression] ) )
            $expression = $aliases[$expression];
        $parts = preg_split( '/\s+/', $expression );
        if ( count( $parts ) !== 5 )
            return false;
        $names = array(
            3 => array( 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 ),
            4 => array( 'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6 ),
        );
        $sets = array();
        foreach ( $parts as $index => $part )
        {
            if ( isset( $names[$index] ) )
                $part = strtr( $part, $names[$index] );
            list( $low, $high ) = self::RANGES[$index];
            $values = array();
            foreach ( explode( ',', $part ) as $item )
            {
                if ( !preg_match( '#^(\*|\d+(?:-\d+)?)(?:/(\d+))?$#', $item, $m ) )
                    return false;
                $step = isset( $m[2] ) ? (int)$m[2] : 1;
                if ( $step < 1 )
                    return false;
                if ( $m[1] === '*' )
                {
                    $from = $low;
                    $to = $high;
                }
                elseif ( strpos( $m[1], '-' ) !== false )
                {
                    list( $from, $to ) = array_map( 'intval', explode( '-', $m[1] ) );
                }
                else
                {
                    $from = (int)$m[1];
                    $to = isset( $m[2] ) ? $high : $from;
                }
                if ( $from < $low || $to > $high || $from > $to )
                    return false;
                for ( $v = $from; $v <= $to; $v += $step )
                    $values[$v] = true;
            }
            if ( $index === 4 && isset( $values[7] ) )
            {
                unset( $values[7] );
                $values[0] = true;
            }
            $sets[$index] = $values;
        }
        return array(
            'minute' => $sets[0], 'hour' => $sets[1], 'day' => $sets[2], 'month' => $sets[3], 'weekday' => $sets[4],
            'day_any' => $parts[2] === '*' || strpos( $parts[2], '*' ) === 0 && strpos( $parts[2], '/' ) === false,
            'weekday_any' => $parts[4] === '*' || strpos( $parts[4], '*' ) === 0 && strpos( $parts[4], '/' ) === false,
        );
    }

    /** @param mixed $expression */
    public static function isValid( $expression ): bool
    {
        return self::parse( $expression ) !== false;
    }

    /**
     * Whether a Unix time's minute matches the expression.
     *
     * @param array{minute: array<int, bool>, hour: array<int, bool>, day: array<int, bool>, month: array<int, bool>, weekday: array<int, bool>, day_any: bool, weekday_any: bool}|string $expression an expression, or what parse() made of one
     * @param int $time
     */
    public static function matches( $expression, $time ): bool
    {
        $p = is_array( $expression ) ? $expression : self::parse( $expression );
        if ( !$p )
            return false;
        list( $minute, $hour, $day, $month, $weekday ) = array_map( 'intval', explode( ' ', date( 'i G j n w', $time ) ) );
        if ( !isset( $p['minute'][$minute] ) || !isset( $p['hour'][$hour] ) || !isset( $p['month'][$month] ) )
            return false;
        $dayOk = isset( $p['day'][$day] );
        $weekdayOk = isset( $p['weekday'][$weekday] );
        if ( $p['day_any'] && $p['weekday_any'] )
            return true;
        if ( $p['day_any'] )
            return $weekdayOk;
        if ( $p['weekday_any'] )
            return $dayOk;
        return $dayOk || $weekdayOk;
    }

    /**
     * The first minute strictly after $after that matches, or false (an expression such as "0 0 31 2 *"
     * never matches; the search stops after five years). Walks days first, then hours, then minutes,
     * so a yearly expression costs a few thousand steps at most.
     *
     * @param array{minute: array<int, bool>, hour: array<int, bool>, day: array<int, bool>, month: array<int, bool>, weekday: array<int, bool>, day_any: bool, weekday_any: bool}|string $expression an expression, or what parse() made of one
     * @param int|string|null $after
     */
    public static function nextRun( $expression, $after = null ): int|false
    {
        $p = is_array( $expression ) ? $expression : self::parse( $expression );
        if ( !$p )
            return false;
        $after = $after === null ? time() : (int)$after;
        $t = $after - ( $after % 60 ) + 60; // the next whole minute
        $limit = $after + 5 * 366 * 86400;
        while ( $t <= $limit )
        {
            list( $hour, $day, $month, $weekday ) = array_map( 'intval', explode( ' ', date( 'G j n w', $t ) ) );
            if ( !isset( $p['month'][$month] ) || !self::dayMatches( $p, $day, $weekday ) )
            {
                $t = mktime( 0, 0, 0, (int)date( 'n', $t ), (int)date( 'j', $t ) + 1, (int)date( 'Y', $t ) );
                continue;
            }
            if ( !isset( $p['hour'][$hour] ) )
            {
                $t = mktime( $hour + 1, 0, 0, (int)date( 'n', $t ), (int)date( 'j', $t ), (int)date( 'Y', $t ) );
                continue;
            }
            if ( self::matches( $p, $t ) )
                return $t;
            $t += 60;
        }
        return false;
    }

    /**
     * @param array{minute: array<int, bool>, hour: array<int, bool>, day: array<int, bool>, month: array<int, bool>, weekday: array<int, bool>, day_any: bool, weekday_any: bool} $p
     * @param int $day
     * @param int $weekday
     */
    protected static function dayMatches( array $p, $day, $weekday ): bool
    {
        $dayOk = isset( $p['day'][$day] );
        $weekdayOk = isset( $p['weekday'][$weekday] );
        if ( $p['day_any'] && $p['weekday_any'] )
            return true;
        if ( $p['day_any'] )
            return $weekdayOk;
        if ( $p['weekday_any'] )
            return $dayOk;
        return $dayOk || $weekdayOk;
    }

    /**
     * A frequency in words, for the Schedules page and the CLI.
     *
     * @param array<string, mixed> $frequency
     * @param string $expression
     */
    public static function describe( array $frequency, $expression ): string
    {
        $t = function ( $text, $args = array() ) { return ezpI18n::tr( 'design/standard/extract', $text, null, $args ); };
        $days = array( $t( 'Sunday' ), $t( 'Monday' ), $t( 'Tuesday' ), $t( 'Wednesday' ), $t( 'Thursday' ), $t( 'Friday' ), $t( 'Saturday' ) );
        $kind = isset( $frequency['kind'] ) ? $frequency['kind'] : 'cron';
        $time = isset( $frequency['time'] ) ? $frequency['time'] : '';
        switch ( $kind )
        {
            case 'hourly':
                return $t( 'Every hour at minute %minute', array( '%minute' => isset( $frequency['minute'] ) ? (int)$frequency['minute'] : 0 ) );
            case 'daily':
                return $t( 'Every day at %time', array( '%time' => $time ) );
            case 'weekly':
                $weekday = isset( $frequency['weekday'] ) ? (int)$frequency['weekday'] : 1;
                return $t( 'Every %day at %time', array( '%day' => isset( $days[$weekday] ) ? $days[$weekday] : $weekday, '%time' => $time ) );
            case 'monthly':
                return $t( 'Every month on day %day at %time', array( '%day' => isset( $frequency['monthday'] ) ? (int)$frequency['monthday'] : 1, '%time' => $time ) );
        }
        return $t( 'Cron expression %expression', array( '%expression' => $expression ) );
    }
}

?>
