<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Support\Format;
use Tests\TestCase;

class FormatTest extends TestCase
{
    public function test_money_formats_amounts_with_currency_and_thousands_separator(): void
    {
        $this->assertSame('48,210 ج.م', Format::money(48210));
        $this->assertSame('0 ج.م', Format::money(0));
        $this->assertSame('1,234.50 ج.م', Format::money(1234.5, 2));
    }

    public function test_money_returns_em_dash_when_null(): void
    {
        $this->assertSame('—', Format::money(null));
    }

    public function test_percent_formats_ratios_with_one_decimal_by_default(): void
    {
        $this->assertSame('12.5%', Format::percent(0.125));
        $this->assertSame('100.0%', Format::percent(1.0));
        $this->assertSame('0.0%', Format::percent(0.0));
        $this->assertSame('12.55%', Format::percent(0.1255, 2));
    }

    public function test_percent_returns_em_dash_when_null(): void
    {
        $this->assertSame('—', Format::percent(null));
    }

    public function test_number_formats_with_thousands_separator(): void
    {
        $this->assertSame('48,210', Format::number(48210));
        $this->assertSame('1,234.5', Format::number(1234.5, 1));
        $this->assertSame('—', Format::number(null));
    }

    public function test_growth_formats_with_sign_and_arrow(): void
    {
        $this->assertSame('↑ +12.5%', Format::growth(0.125));
        $this->assertSame('↓ -5.2%', Format::growth(-0.052));
        $this->assertSame('0.0%', Format::growth(0.0));
        $this->assertSame('—', Format::growth(null));
    }
}
