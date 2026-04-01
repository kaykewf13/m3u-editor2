<?php

namespace App\Enums;

enum TranscodeMode: string
{
    case Direct = 'direct';
    case Server = 'server';
    case Local = 'local';

    public function getLabel(): string
    {
        return match ($this) {
            self::Direct => __('Direct'),
            self::Server => __('Server'),
            self::Local => __('Local'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Direct => 'success',
            self::Server => 'info',
            self::Local => 'warning',
        };
    }
}
