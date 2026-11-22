<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Filament\Support\RawJs;
use Livewire\Livewire;
use Tests\TestCase;

class PercentageChartRenderingTest extends TestCase
{
    public function test_percentage_chart_options_remain_inside_the_chart_attribute(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $html = Livewire::test(PercentageChartFixture::class)->html();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($document);
        $charts = $xpath->query('//*[@data-chart-type="bar"]');

        $this->assertCount(1, $charts);
        $chart = $charts->item(0);
        $this->assertStringContainsString('options.scales["y"].ticks.callback', $chart->getAttribute('x-data'));
        $this->assertStringContainsString('options.plugins.tooltip.callbacks.label', $chart->getAttribute('x-data'));
        $this->assertCount(1, $xpath->query('.//canvas', $chart));
        $this->assertStringNotContainsString('options.plugins.tooltip', $document->textContent);
    }

    public function test_scatter_chart_keeps_its_percentage_tooltip_inside_the_attribute(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $html = Livewire::test(PercentageScatterChartFixture::class)->html();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $chart = (new DOMXPath($document))->query('//*[@data-chart-type="scatter"]')->item(0);

        $this->assertNotNull($chart);
        $this->assertStringContainsString('context.parsed.y', $chart->getAttribute('x-data'));
        $this->assertStringNotContainsString('context.parsed.y', $document->textContent);
    }
}

class PercentageChartFixture extends BaseReportChartWidget
{
    protected static bool $isLazy = false;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return ['labels' => ['A'], 'datasets' => [['label' => 'Sales', 'data' => [42]]]];
    }

    protected function getOptions(): RawJs
    {
        return $this->percentageOptions(['scales' => ['y' => ['max' => 100]]]);
    }
}

class PercentageScatterChartFixture extends PercentageChartFixture
{
    protected function getType(): string
    {
        return 'scatter';
    }

    protected function getOptions(): RawJs
    {
        return $this->percentageOptions([], 'y', true);
    }
}
