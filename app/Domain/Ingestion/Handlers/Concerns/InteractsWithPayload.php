<?php

namespace App\Domain\Ingestion\Handlers\Concerns;

use Carbon\Carbon;

trait InteractsWithPayload
{
    protected function parseTimestamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateTimeString();
    }

    protected function toDecimal(mixed $value, int $scale = 2, string $default = '0.00'): string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return number_format((float) $value, $scale, '.', '');
    }

    /**
     * @return numeric-string
     */
    protected function asNumeric(mixed $value, string $default = '0'): string
    {
        $str = is_scalar($value) ? (string) $value : $default;

        if (is_numeric($str)) {
            return $str;
        }

        return '0';
    }

    /**
     * @return numeric-string
     */
    protected function bcMultiply(mixed $a, mixed $b, int $scale = 4): string
    {
        $numA = $this->asNumeric($a);
        $numB = $this->asNumeric($b);

        return bcmul($numA, $numB, $scale);
    }

    /**
     * @return numeric-string
     */
    protected function bcAdd(mixed $a, mixed $b, int $scale = 4): string
    {
        $numA = $this->asNumeric($a);
        $numB = $this->asNumeric($b);

        return bcadd($numA, $numB, $scale);
    }

    /**
     * @return numeric-string
     */
    protected function bcSubtract(mixed $a, mixed $b, int $scale = 4): string
    {
        $numA = $this->asNumeric($a);
        $numB = $this->asNumeric($b);

        return bcsub($numA, $numB, $scale);
    }
}
