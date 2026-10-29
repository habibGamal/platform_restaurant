<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports;

use Filament\Widgets\Widget;

class ComingSoonWidget extends Widget
{
    protected string $view = 'filament.app.widgets.reports.coming-soon-widget';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;
}
