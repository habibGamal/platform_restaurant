<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

final class NumericGroundingChecker
{
    /**
     * Arabic-Indic digits (٠-٩) and Eastern Arabic-Indic digits (۰-۹).
     */
    private const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const EASTERN_INDIC_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ASCII_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /**
     * Extract a list of numbers found in the text, ignoring non-metric numbers
     * such as dates, line ordinals, and optionally numbers in the user question.
     *
     * @return list<int|float>
     */
    public function extract(string $text, ?string $question = null): array
    {
        $items = $this->extractDetailed($text, $question);

        $results = [];
        foreach ($items as $item) {
            if (! $item['from_question']) {
                $results[] = $item['value'];
            }
        }

        return $results;
    }

    /**
     * Check whether all numbers in the answer are grounded against the tool results.
     *
     * @param  array<mixed>  $toolResults
     */
    public function check(string $answer, array $toolResults, ?string $question = null): GroundingResult
    {
        $toolNumbers = $this->collectToolNumbers($toolResults);
        $extractedItems = $this->extractDetailed($answer, $question);

        $matched = [];
        $ungrounded = [];

        foreach ($extractedItems as $item) {
            $isMatch = false;

            foreach ($toolNumbers as $toolNumber) {
                if ($this->matchesToolNumber($item, $toolNumber)) {
                    $isMatch = true;
                    break;
                }
            }

            if ($isMatch) {
                $matched[] = $item['value'];
            } elseif ($item['from_question']) {
                // If it came from the question, it's ignored and not flagged as ungrounded
                continue;
            } else {
                $ungrounded[] = $item['value'];
            }
        }

        /** @var list<int|float> $uniqueUngrounded */
        $uniqueUngrounded = array_values(array_unique($ungrounded));

        /** @var list<int|float> $uniqueMatched */
        $uniqueMatched = array_values(array_unique($matched));

        return new GroundingResult(
            grounded: empty($uniqueUngrounded),
            ungrounded: $uniqueUngrounded,
            matched: $uniqueMatched,
        );
    }

    /**
     * Extract detailed number occurrences from text.
     *
     * @return list<array{
     *     value: int|float,
     *     raw_number: float,
     *     multiplier: int,
     *     precision: int,
     *     is_percent: bool,
     *     from_question: bool
     * }>
     */
    public function extractDetailed(string $text, ?string $question = null): array
    {
        $questionNumbers = [];
        if ($question !== null && trim($question) !== '') {
            $qItems = $this->extractDetailed($question);
            foreach ($qItems as $qi) {
                $questionNumbers[] = (float) $qi['value'];
                $questionNumbers[] = (float) $qi['raw_number'];
            }
        }

        // 1. Strip list ordinals at line start ("1." / "١-" / "2)" etc.)
        $cleanText = preg_replace('/(?m)^\s*[0-9٠-٩۰-۹]+[\.\-\)\:]\s+/u', ' ', $text) ?? $text;

        // 2. Mask dates to ignore them:
        // 2a. Full ISO or slash dates (e.g. 2026-10-03, 2026/10/03, 03-10-2026, 03/10/2026)
        $cleanText = preg_replace('/[0-9٠-٩۰-۹]{4}[-\/][0-9٠-٩۰-۹]{1,2}[-\/][0-9٠-٩۰-۹]{1,2}/u', ' ', $cleanText) ?? $cleanText;
        $cleanText = preg_replace('/[0-9٠-٩۰-۹]{1,2}[-\/][0-9٠-٩۰-۹]{1,2}[-\/][0-9٠-٩۰-۹]{4}/u', ' ', $cleanText) ?? $cleanText;

        // 2b. Day/month dates (e.g. 03/10, 16/10, 3/10, 03-10)
        $cleanText = preg_replace('/(?<![\d٠-٩۰-۹٫٬,\.])[0-9٠-٩۰-۹]{1,2}[-\/][0-9٠-٩۰-۹]{1,2}(?![\d٠-٩۰-۹٫٬,\.])/u', ' ', $cleanText) ?? $cleanText;

        // 2c. Arabic month names with optional day and year
        $monthsPattern = '(?:يناير|فبراير|مارس|أبريل|ابريل|مايو|يونيو|يوليو|أغسطس|اغسطس|سبتمبر|أكتوبر|اكتوبر|نوفمبر|ديسمبر)';
        $cleanText = preg_replace('/[0-9٠-٩۰-۹]+\s*(?:من\s*)?'.$monthsPattern.'(?:\s*[0-9٠-٩۰-۹]+)?/u', ' ', $cleanText) ?? $cleanText;
        $cleanText = preg_replace('/'.$monthsPattern.'\s*[0-9٠-٩۰-۹]+/u', ' ', $cleanText) ?? $cleanText;

        // 2d. Standalone years 2000–2100 in date context (without currency, percent, or multiplier)
        $cleanText = preg_replace('/(?<![\d٠-٩۰-۹])(?:عام\s+|سنة\s+|في\s+)?([2٢][0-9٠-٩۰-۹]{3})(?!\s*(?:ج\.م|جنيه|جنية|EGP|LE|L\.E\.|%|٪|ألف|مليون|مليار))(?![\d٠-٩۰-۹])/u', ' ', $cleanText) ?? $cleanText;

        // 3. Match numbers with negative signs, Arabic separators, multipliers, and suffixes
        $pattern = '/(?<prefix_curr>ج\.م|جنيه|جنية|EGP|LE|L\.E\.)?\s*'
            .'(?<neg1>[\-−–—])?\s*'
            .'(?<digits>[0-9٠-٩۰-۹][0-9٠-٩۰-۹٫٬,\.]*)\s*'
            .'(?<neg2>[\-−–—])?\s*'
            .'(?<mult>ألف|الف|آلاف|الاف|مليون|ملايين|مليار|مليارات)?\s*'
            .'(?<suffix>%|٪|ج\.م|جنيه|جنية|EGP|LE|L\.E\.)?\s*'
            .'(?<neg3>[\-−–—])?/u';

        preg_match_all($pattern, $cleanText, $matches, PREG_SET_ORDER);

        $items = [];

        foreach ($matches as $match) {
            $digitsRaw = $match['digits'];

            // Normalize digits
            $str = str_replace(self::ARABIC_INDIC_DIGITS, self::ASCII_DIGITS, $digitsRaw);
            $str = str_replace(self::EASTERN_INDIC_DIGITS, self::ASCII_DIGITS, $str);

            // Arabic separators:
            // ٬ is thousands separator
            $str = str_replace('٬', '', $str);
            // ٫ is decimal separator
            $str = str_replace('٫', '.', $str);

            // Handle ASCII commas
            if (str_contains($str, ',')) {
                if (str_contains($str, '.')) {
                    // e.g. 1,234.56
                    $str = str_replace(',', '', $str);
                } else {
                    // Check if comma is followed by exactly 3 digits
                    if (preg_match('/,\d{3}(?:,|$)/', $str)) {
                        $str = str_replace(',', '', $str);
                    } else {
                        // e.g. 12,3 used as decimal
                        $str = str_replace(',', '.', $str);
                    }
                }
            }

            $str = rtrim($str, '.');

            if (! is_numeric($str)) {
                continue;
            }

            $baseVal = (float) $str;

            // Precision calculation
            $precision = 0;
            if (str_contains($str, '.')) {
                $precision = strlen(substr($str, strrpos($str, '.') + 1));
            }

            // Negative sign
            $isNegative = ! empty($match['neg1']) || ! empty($match['neg2']) || ! empty($match['neg3']);
            if ($isNegative) {
                $baseVal = -abs($baseVal);
            }

            // Multiplier
            $multiplier = 1;
            if (! empty($match['mult'])) {
                $mult = $match['mult'];
                if (in_array($mult, ['ألف', 'الف', 'آلاف', 'الاف'], true)) {
                    $multiplier = 1000;
                } elseif (in_array($mult, ['مليون', 'ملايين'], true)) {
                    $multiplier = 1000000;
                } elseif (in_array($mult, ['مليار', 'مليارات'], true)) {
                    $multiplier = 1000000000;
                }
            }

            $isPercent = (! empty($match['suffix']) && in_array($match['suffix'], ['%', '٪'], true));

            $finalVal = $baseVal * $multiplier;
            $normalizedVal = (abs($finalVal - round($finalVal)) < 1e-6)
                ? (int) round($finalVal)
                : round($finalVal, 4);

            // Check if present in question
            $fromQuestion = false;
            foreach ($questionNumbers as $qNum) {
                if (abs($finalVal - $qNum) < 1e-4 || abs($baseVal - $qNum) < 1e-4) {
                    $fromQuestion = true;
                    break;
                }
            }

            $items[] = [
                'value' => $normalizedVal,
                'raw_number' => $baseVal,
                'multiplier' => $multiplier,
                'precision' => $precision,
                'is_percent' => $isPercent,
                'from_question' => $fromQuestion,
            ];
        }

        return $items;
    }

    /**
     * Check if a single extracted number matches a tool number.
     *
     * @param  array{
     *     value: int|float,
     *     raw_number: float,
     *     multiplier: int,
     *     precision: int,
     *     is_percent: bool,
     *     from_question: bool
     * }  $item
     */
    private function matchesToolNumber(array $item, float $t): bool
    {
        $v = (float) $item['value'];
        $raw = (float) $item['raw_number'];
        $prec = $item['precision'];
        $mult = $item['multiplier'];

        // 1. Direct match (exact or sign-less)
        if (abs($v - $t) < 1e-4 || abs(abs($v) - abs($t)) < 1e-4) {
            return true;
        }

        // 2. Rounding to displayed precision (e.g. 48210.37 -> 48,210)
        $tAbs = abs($t);
        $vAbs = abs($v);

        for ($p = 0; $p <= max($prec, 2); $p++) {
            if (abs(round($tAbs, $p) - $vAbs) < 1e-4) {
                return true;
            }
        }

        // Also check integer rounding to tens/hundreds
        foreach ([-1, -2, -3] as $p) {
            if (abs(round($tAbs, $p) - $vAbs) < 1e-4) {
                return true;
            }
        }

        // 3. Multiplier ("ألف" / "مليون")
        if ($mult > 1) {
            $tScaled = $tAbs / $mult;
            $rawAbs = abs($raw);

            for ($p = 0; $p <= max($prec, 2); $p++) {
                if (abs(round($tScaled, $p) - $rawAbs) < 1e-4) {
                    return true;
                }
            }
        } else {
            // Check if number was rounded as thousands without multiplier keyword in token
            if (abs(round($tAbs / 1000, 1) * 1000 - $vAbs) < 1e-4) {
                return true;
            }
            if (abs(round($tAbs / 1000, 0) * 1000 - $vAbs) < 1e-4) {
                return true;
            }
            if (abs(round($tAbs / 1000000, 1) * 1000000 - $vAbs) < 1e-4) {
                return true;
            }
        }

        // 4. Ratio ↔ Percent (e.g. 0.123 ↔ 12.3%)
        // Case A: tool has ratio (e.g. 0.1234 or -0.052), displayed is percentage
        $tPct = $tAbs * 100;
        $rawAbs = abs($raw);
        for ($p = 0; $p <= max($prec, 2); $p++) {
            if (abs(round($tPct, $p) - $rawAbs) < 1e-4) {
                return true;
            }
            if (abs(round($tPct, $p) - $vAbs) < 1e-4) {
                return true;
            }
        }

        // Case B: tool has percentage (e.g. 12.34), displayed is ratio (0.123)
        $tRatio = $tAbs / 100;
        for ($p = 0; $p <= max($prec, 4); $p++) {
            if (abs(round($tRatio, $p) - $rawAbs) < 1e-4) {
                return true;
            }
            if (abs(round($tRatio, $p) - $vAbs) < 1e-4) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively walk decoded JSON or arrays to extract all numeric values.
     *
     * @return list<float>
     */
    private function collectToolNumbers(mixed $data): array
    {
        $numbers = [];

        if (is_null($data) || is_bool($data)) {
            return [];
        }

        if (is_int($data) || is_float($data)) {
            return [(float) $data];
        }

        if (is_string($data)) {
            $trimmed = trim($data);
            if ((str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
                || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))) {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $this->collectToolNumbers($decoded);
                }
            }

            if (is_numeric($trimmed)) {
                return [(float) $trimmed];
            }

            return [];
        }

        if (is_array($data)) {
            foreach ($data as $value) {
                $numbers = array_merge($numbers, $this->collectToolNumbers($value));
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $value) {
                $numbers = array_merge($numbers, $this->collectToolNumbers($value));
            }
        }

        return $numbers;
    }
}
