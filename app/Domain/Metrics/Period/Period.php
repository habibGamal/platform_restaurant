<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Period;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class Period
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public ?string $cutoffTime;

    public function __construct(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $cutoffTime = null,
    ) {
        $fromNormalized = $from->startOfDay();
        $toNormalized = $to->startOfDay();

        if ($fromNormalized > $toNormalized) {
            throw new InvalidArgumentException(
                sprintf('From date (%s) cannot be after to date (%s).', $fromNormalized->toDateString(), $toNormalized->toDateString())
            );
        }

        $this->from = $fromNormalized;
        $this->to = $toNormalized;
        $this->cutoffTime = $cutoffTime;
    }

    public function days(): int
    {
        $fromUtc = CarbonImmutable::parse($this->from->toDateString(), 'UTC');
        $toUtc = CarbonImmutable::parse($this->to->toDateString(), 'UTC');

        return (int) $fromUtc->diffInDays($toUtc) + 1;
    }

    public function contains(CarbonImmutable $date): bool
    {
        $targetDate = $date->setTimezone($this->from->timezone ?? 'Africa/Cairo')->toDateString();

        return $targetDate >= $this->from->toDateString() && $targetDate <= $this->to->toDateString();
    }

    /**
     * @return array<CarbonImmutable>
     */
    public function dates(): array
    {
        $dates = [];
        $current = $this->from;

        while ($current <= $this->to) {
            $dates[] = $current;
            $current = $current->addDay()->startOfDay();
        }

        return $dates;
    }

    public function shift(int $days): self
    {
        return new self(
            $this->from->addDays($days)->startOfDay(),
            $this->to->addDays($days)->startOfDay(),
            $this->cutoffTime,
        );
    }

    public function equals(self $other): bool
    {
        return $this->from->toDateString() === $other->from->toDateString()
            && $this->to->toDateString() === $other->to->toDateString()
            && $this->cutoffTime === $other->cutoffTime;
    }

    /**
     * @return array{from: string, to: string, cutoff: ?string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'cutoff' => $this->cutoffTime,
        ];
    }
}
