<?php

declare(strict_types=1);

namespace Solo\Logger\Rotation;

/** Calendar boundaries for rotation, in the timezone of the writer's clock. */
enum Schedule
{
    case Hourly;
    case Daily;
    /** ISO weeks, which start on Monday. */
    case Weekly;
    case Monthly;
}
