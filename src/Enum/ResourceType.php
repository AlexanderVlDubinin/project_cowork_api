<?php

namespace App\Enum;

enum ResourceType: string
{
    case DESK = 'desk';
    case DESK_PLUS = 'desk_plus';
    case MEETING_ROOM = 'meeting_room';
    case VIP_ROOM = 'vip_room';
}
