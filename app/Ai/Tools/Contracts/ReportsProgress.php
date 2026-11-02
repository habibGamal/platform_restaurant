<?php

declare(strict_types=1);

namespace App\Ai\Tools\Contracts;

/**
 * A tool that can describe what it is doing while it runs, shown to the user as a progress line.
 */
interface ReportsProgress
{
    /** A short colloquial Arabic "doing" label, e.g. 'بحسب هوامش وأداء الأصناف…'. */
    public function progressLabel(): string;
}
