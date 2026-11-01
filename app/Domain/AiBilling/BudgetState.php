<?php

declare(strict_types=1);

namespace App\Domain\AiBilling;

enum BudgetState: string
{
    case OK = 'ok';
    case WARNING = 'warning';
    case EXHAUSTED = 'exhausted';
}
