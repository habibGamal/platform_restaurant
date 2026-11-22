<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Domain\Menu\Intelligence\FoodClass;
use App\Domain\Menu\Intelligence\LabelTrust;
use Tests\TestCase;

// Config only: no database, so no RefreshDatabase.
class LabelTrustTest extends TestCase
{
    public function test_owner_is_always_trusted_and_auto_or_rule_needs_the_threshold(): void
    {
        $this->assertTrue(LabelTrust::isTrusted('owner', 0.0));
        $this->assertTrue(LabelTrust::isTrusted('auto', 0.70));
        $this->assertTrue(LabelTrust::isTrusted('rule', 0.95));
        $this->assertFalse(LabelTrust::isTrusted('auto', 0.69));
        $this->assertFalse(LabelTrust::isTrusted('rule', 0.5));
        $this->assertFalse(LabelTrust::isTrusted('signal', 1.0));
    }

    public function test_food_class_flags(): void
    {
        $this->assertFalse(FoodClass::Packaging->isFood());
        $this->assertFalse(FoodClass::Cutlery->isFood());
        $this->assertTrue(FoodClass::Sauce->isFood());
        $this->assertSame(['meat', 'chicken', 'seafood'], array_values(array_map(
            fn (FoodClass $class): string => $class->value,
            array_filter(FoodClass::cases(), fn (FoodClass $class): bool => $class->isProtein()),
        )));
    }
}
