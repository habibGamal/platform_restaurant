<?php

declare(strict_types=1);

namespace App\Ai\Tools\Context;

use App\Ai\Tools\Concerns\CachesToolResults;
use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Domain\Tenancy\AccessibleBranches;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class FindBranches implements Tool
{
    use CachesToolResults, FormatsToolResult;

    public function __construct(
        public readonly User $user,
    ) {}

    public function name(): string
    {
        return 'find_branches';
    }

    public function description(): string
    {
        return 'Find accessible branches by fuzzy Arabic-normalized name search (normalizes alef, taa marbuta, yaa, and strips tashkeel).';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Branch name or search term in Arabic or English.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            return $this->cached($this->name(), $request->all(), function () use ($request): string {
                return $this->execute($request);
            });
        } catch (AuthorizationException $e) {
            return $this->formatError('forbidden_branch', 'ليس لديك صلاحية للوصول إلى هذا الفرع أو البيانات المطلوبة.');
        } catch (ValidationException $e) {
            return $this->formatError('validation_error', 'بيانات غير صالحة.', ['errors' => $e->errors()]);
        } catch (Throwable $e) {
            report($e);

            return $this->formatError('internal_error', 'حدث خطأ غير متوقع أثناء تجهيز البيانات.');
        }
    }

    protected function execute(Request $request): string
    {
        /** @var array{query: string} $validated */
        $validated = $request->validate([
            'query' => ['required', 'string'],
        ]);

        $searchTerm = $validated['query'];
        $normalizedQuery = $this->normalizeArabic($searchTerm);

        $accessibleBranches = AccessibleBranches::query($this->user)->get();

        $matches = [];
        foreach ($accessibleBranches as $branch) {
            $branchName = (string) $branch->name;
            $normalizedBranchName = $this->normalizeArabic($branchName);

            // Exact, substring, or all-tokens match
            if (
                $normalizedQuery !== '' &&
                (str_contains($normalizedBranchName, $normalizedQuery) ||
                 str_contains($normalizedQuery, $normalizedBranchName) ||
                 $this->matchesAllTokens($normalizedQuery, $normalizedBranchName))
            ) {
                $status = (string) $branch->getRawOriginal('status');

                $matches[] = [
                    'id' => (int) $branch->id,
                    'name' => $branchName,
                    'restaurant_id' => (int) $branch->restaurant_id,
                    'status' => $status,
                ];
            }
        }

        $payload = [
            'query' => $searchTerm,
            'normalized_query' => $normalizedQuery,
            'count' => count($matches),
            'matches' => $matches,
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Normalize Arabic text:
     * - Strip tashkeel (diacritics: Fatha, Damma, Kasra, Sukun, Shadda, Tanween, Dagger Alif)
     * - Strip tatweel (kashida)
     * - Normalize Alef variants (أ / إ / آ / ٱ -> ا)
     * - Normalize Taa Marbuta (ة -> ه)
     * - Normalize Yaa / Alif Maqsura (ى -> ي)
     * - Normalize whitespace and lowercase
     */
    public function normalizeArabic(string $text): string
    {
        // 1. Strip tashkeel (Unicode range \x{064B} to \x{0652} plus \x{0670})
        $text = (string) preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $text);

        // 2. Strip tatweel (kashida: \x{0640})
        $text = (string) preg_replace('/[\x{0640}]/u', '', $text);

        // 3. Normalize Alef forms (أ, إ, آ, ٱ -> ا)
        $text = (string) preg_replace('/[أإآٱ]/u', 'ا', $text);

        // 4. Normalize Taa Marbuta (ة -> ه)
        $text = (string) preg_replace('/ة/u', 'ه', $text);

        // 5. Normalize Alif Maqsura (ى -> ي)
        $text = (string) preg_replace('/ى/u', 'ي', $text);

        // 6. Normalize whitespace and case
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strtolower(trim($text), 'UTF-8');
    }

    /**
     * Check if all non-empty tokens of the query match within the branch name.
     */
    protected function matchesAllTokens(string $query, string $target): bool
    {
        $queryTokens = array_filter(explode(' ', $query));
        if (empty($queryTokens)) {
            return false;
        }

        foreach ($queryTokens as $qToken) {
            if (! str_contains($target, $qToken)) {
                return false;
            }
        }

        return true;
    }
}
