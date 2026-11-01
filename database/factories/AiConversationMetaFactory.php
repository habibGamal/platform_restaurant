<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assistant\Channel;
use App\Models\AiConversationMeta;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiConversationMeta>
 */
class AiConversationMetaFactory extends Factory
{
    protected $model = AiConversationMeta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => (string) Str::uuid7(),
            'user_id' => User::factory(),
            'organization_id' => function (array $attributes) {
                if (isset($attributes['user_id']) && is_scalar($attributes['user_id'])) {
                    $user = User::find($attributes['user_id']);
                    if ($user instanceof User && $user->organization_id !== null) {
                        return $user->organization_id;
                    }
                }

                return Organization::factory();
            },
            'channel' => Channel::Chat,
            'title' => fake()->sentence(3),
            'pinned' => false,
            'last_activity_at' => now(),
            'archived_at' => null,
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['pinned' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }

    public function forChannel(Channel $channel): static
    {
        return $this->state(fn () => ['channel' => $channel]);
    }
}
