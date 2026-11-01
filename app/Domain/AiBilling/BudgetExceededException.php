<?php

declare(strict_types=1);

namespace App\Domain\AiBilling;

use Exception;
use Throwable;

final class BudgetExceededException extends Exception
{
    public function __construct(
        string $message = 'تم استنفاد ميزانية الذكاء الاصطناعي المحددة. يرجى التواصل مع إدارة النظام.',
        public readonly ?string $scope = null,
        public readonly ?float $budget = null,
        public readonly ?float $spend = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
