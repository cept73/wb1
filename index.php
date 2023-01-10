<?php /** @noinspection PhpUnused */

function main()
{
    $dt1 = $_GET['dt1'] ?? '01.01.2023';
    $dt2 = $_GET['dt2'] ?? '10.01.2023';

    try {
        $count = WeekActions::getWeekDayCount(WeekActions::DAY_TUESDAY, $dt1, $dt2);
        print "There is a $count tuesday(s) between $dt1 and $dt2";
    } catch (Exception $e) {
        print 'ERROR: ';
        print_r($e->getMessage());
    }

    print PHP_EOL;
}

class WeekActions
{
    public const DIRECTION_AHEAD = 1;
    public const DIRECTION_BACK = 2;

    public const DATE_FORMAT    = 'd.m.Y';

    public const DAY_MONDAY     = 1;
    public const DAY_TUESDAY    = 2;
    public const DAY_WEDNESDAY  = 3;
    public const DAY_THURSDAY   = 4;
    public const DAY_FRIDAY     = 5;
    public const DAY_SATURDAY   = 6;
    public const DAY_SUNDAY     = 0;

    /**
     * Move to next or previous weekIndex day
     * Not include same date
     *
     * @param $dateTime DateTime
     * @param $weekIndex int
     * @param $direction int
     * @return DateTime|false
     */
    public static function moveToWeekIndex(DateTime $dateTime, int $weekIndex, int $direction)
    {
        $baseIndex      = $direction === self::DIRECTION_AHEAD ? 7 : -7;
        $diffSign       = $direction === self::DIRECTION_AHEAD ? '+' : '-';
        $dateTime1index = (int) $dateTime->format('w');
        $diffToFirst    = (abs($baseIndex + $weekIndex - $dateTime1index) % 7) ?: 7;

        return $dateTime->modify("$diffSign$diffToFirst days");
    }

    /**
     * Get count of specified weekDays between two dates
     *
     * @param $weekDay int
     * @param $dt1 string
     * @param $dt2 string
     * @return int
     * @throws Exception
     */
    public static function getWeekDayCount(int $weekDay, string $dt1, string $dt2): int
    {
        if (!self::isCorrectDate($dt1) || !self::isCorrectDate($dt2)) {
            throw new InvalidArgumentException('Wrong date');
        }

        $dateTime1 = new DateTime($dt1);
        $dateTime2 = new DateTime($dt2);

        $dateDiff = $dateTime1->diff($dateTime2);
        if ($dateDiff->invert) {
            return 0;
        }

        $firstMatch = self::moveToWeekIndex($dateTime1, $weekDay, self::DIRECTION_AHEAD);
        $lastMatch  = self::moveToWeekIndex($dateTime2, $weekDay, self::DIRECTION_BACK);
        $dateDiff = $firstMatch->diff($lastMatch);
        if ($dateDiff->invert) {
            return 0;
        }

        return ($dateDiff->days / 7) + 1;
    }

    public static function isCorrectDate($date): bool
    {
        return (DateTime::createFromFormat(self::DATE_FORMAT, $date) !== false);
    }
}

main();
