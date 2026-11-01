<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

enum Channel: string
{
    case Chat = 'chat';
    case WhatsApp = 'whatsapp';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'المحادثة',
            self::WhatsApp => 'واتساب',
            self::Scheduled => 'مجدول',
        };
    }
}
