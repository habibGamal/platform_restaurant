<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Support\BranchColor;
use Tests\TestCase;

class BranchColorTest extends TestCase
{
    public function test_for_returns_valid_hex_color(): void
    {
        $color = BranchColor::for(1);

        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color);
    }

    public function test_for_is_stable_and_deterministic(): void
    {
        $this->assertSame(BranchColor::for(42), BranchColor::for(42));
        $this->assertSame(BranchColor::for(100), BranchColor::for(100));

        // Testing across 10 distinct ids covers the palette
        $colors = [];
        for ($i = 0; $i < 10; $i++) {
            $colors[] = BranchColor::for($i);
        }

        $this->assertCount(10, array_unique($colors));
    }

    public function test_palette_contains_ten_accessible_colors(): void
    {
        $this->assertCount(10, BranchColor::palette());
    }
}
