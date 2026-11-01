<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Domain\Assistant\NumericGroundingChecker;
use PHPUnit\Framework\TestCase;

class NumericGroundingCheckerTest extends TestCase
{
    private NumericGroundingChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new NumericGroundingChecker;
    }

    public function test_extracts_western_and_arabic_indic_digits(): void
    {
        $westernText = 'إجمالي المبيعات 12780 ج.م';
        $arabicText = 'إجمالي المبيعات ١٢٧٨٠ ج.م';

        $this->assertEquals([12780], $this->checker->extract($westernText));
        $this->assertEquals([12780], $this->checker->extract($arabicText));
    }

    public function test_extracts_separators_correctly(): void
    {
        // Western commas as thousands
        $this->assertEquals([48210], $this->checker->extract('المبيعات 48,210 ج.م'));

        // Arabic thousands separator (٬)
        $this->assertEquals([48210], $this->checker->extract('المبيعات ٤٨٬٢١٠ ج.م'));

        // Arabic decimal separator (٫)
        $this->assertEquals([48.2], $this->checker->extract('المبيعات ٤٨٫٢ ج.م'));

        // Decimal comma
        $this->assertEquals([12.3], $this->checker->extract('النسبة 12,3%'));
    }

    public function test_extracts_negative_numbers(): void
    {
        $this->assertEquals([-490], $this->checker->extract('صافي الربح -490 ج.م'));
        $this->assertEquals([-490], $this->checker->extract('صافي الربح 490- ج.م'));
        $this->assertEquals([-490], $this->checker->extract('صافي الربح −٤٩٠ ج.م'));
    }

    public function test_extracts_multipliers(): void
    {
        // ألف (thousand)
        $this->assertEquals([48200], $this->checker->extract('المبيعات بلغت 48.2 ألف ج.م'));
        $this->assertEquals([48200], $this->checker->extract('المبيعات بلغت ٤٨٫٢ ألف ج.م'));
        $this->assertEquals([48000], $this->checker->extract('المبيعات 48 ألف'));

        // مليون (million)
        $this->assertEquals([1500000], $this->checker->extract('الإيرادات 1.5 مليون'));
    }

    public function test_ignores_dates_and_line_ordinals(): void
    {
        $text = <<<'TXT'
1. في الفترة من 2026-10-03 إلى 2026-10-16:
بلغت المبيعات 12,780 ج.م.
2. في يوم 03/10 بلغت المبيعات 920 ج.م.
٣- في شهر أكتوبر 2026 حققنا 5,950 ج.م أرباحاً.
TXT;

        $numbers = $this->checker->extract($text);

        // Ordinals 1., 2., ٣- should be ignored.
        // Dates 2026-10-03, 2026-10-16, 03/10, أكتوبر 2026 should be ignored.
        // Only 12780, 920, 5950 should remain.
        $this->assertEquals([12780, 920, 5950], $numbers);
    }

    public function test_ignores_numbers_present_in_question(): void
    {
        $question = 'ما هي مبيعات آخر 14 يوم لفرع أ؟';
        $answer = 'خلال الـ 14 يوم الماضية، بلغت المبيعات 12,780 ج.م';

        $numbers = $this->checker->extract($answer, $question);

        $this->assertEquals([12780], $numbers);
    }

    public function test_grounding_matches_rounding_and_precision(): void
    {
        $toolResults = [
            ['metrics' => ['K01' => ['value' => 48210.37]]],
        ];

        // Shown as 48,210 (rounded)
        $res1 = $this->checker->check('المبيعات بلغت 48,210 ج.م', $toolResults);
        $this->assertTrue($res1->grounded);
        $this->assertEmpty($res1->ungrounded);
        $this->assertContains(48210, $res1->matched);

        // Shown as 48.2 ألف
        $res2 = $this->checker->check('المبيعات بلغت 48.2 ألف ج.م', $toolResults);
        $this->assertTrue($res2->grounded);
        $this->assertEmpty($res2->ungrounded);
        $this->assertContains(48200, $res2->matched);
    }

    public function test_grounding_matches_ratio_to_percent_and_signless_growth(): void
    {
        $toolResults = [
            ['growth' => -0.052, 'margin' => 0.123],
        ];

        // 12.3% matches margin 0.123
        $res1 = $this->checker->check('هامش الربح 12.3%', $toolResults);
        $this->assertTrue($res1->grounded);
        $this->assertEmpty($res1->ungrounded);

        // 5.2% sign-less matches negative growth -0.052
        $res2 = $this->checker->check('انخفض النمو بنسبة 5.2%', $toolResults);
        $this->assertTrue($res2->grounded);
        $this->assertEmpty($res2->ungrounded);
    }

    public function test_grounding_recursively_walks_json_strings(): void
    {
        $rawJson = json_encode([
            'metrics' => [
                'K01' => ['value' => 12780.0],
                'K02' => ['value' => 84],
            ],
        ], JSON_THROW_ON_ERROR);

        $toolResults = [$rawJson];

        $res = $this->checker->check('المبيعات 12,780 ج.م عبر 84 طلباً', $toolResults);
        $this->assertTrue($res->grounded);
        $this->assertEmpty($res->ungrounded);
        $this->assertEquals([12780, 84], $res->matched);
    }

    public function test_grounding_flags_invented_number(): void
    {
        $toolResults = [
            ['metrics' => ['K01' => ['value' => 12780.0]]],
        ];

        $question = 'ما هي مبيعات آخر 14 يوم؟';
        $answer = 'خلال 14 يوماً بلغت المبيعات 12,780 ج.م وسجلنا 99999 زائر.';

        $res = $this->checker->check($answer, $toolResults, $question);

        $this->assertFalse($res->grounded);
        $this->assertContains(99999, $res->ungrounded);
        $this->assertContains(12780, $res->matched);
        $this->assertNotContains(14, $res->ungrounded);
    }
}
