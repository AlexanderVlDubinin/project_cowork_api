<?php

namespace App\Enum;

enum BookingStatus: string
{
    // use Symfony Workflow ???

    case PENDING = 'pending'; // waiting for payment
    case EXPIRED = 'expired'; // time limit for payment expired
    case CONFIRMED = 'confirmed'; // payment completed
    case CANCELLED = 'cancelled'; // booking canceled (by client or admin)
    case CHECKED_IN = 'checked_in'; // coworking/room is in use
    case COMPLETED = 'completed'; // booking completed
    case NO_SHOW = 'no_show'; // client didn't show up
}
