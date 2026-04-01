<?php

namespace App\Enums;

enum EpgSourceType: string
{
    case URL = 'url';
    case SCHEDULES_DIRECT = 'schedules_direct';

    public function getLabel(): string
    {
        return match ($this) {
            self::URL => __('URL/XML File'),
            self::SCHEDULES_DIRECT => __('SchedulesDirect'),
        };
    }

    /** @deprecated Use getLabel() instead */
    public function label(): string
    {
        return $this->getLabel();
    }
}
