<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Agent;

use App\Ai\ProviderOptions;
use App\Domain\Assistant\Channel;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

final class ProviderOptionsTest extends TestCase
{
    public function test_anthropic_mapping_never_contains_budget_tokens(): void
    {
        $options = ProviderOptions::for('anthropic', 'medium');

        $this->assertArrayNotHasKey('budget_tokens', $options);
        $this->assertArrayHasKey('thinking', $options);
        $this->assertIsArray($options['thinking']);
        $this->assertArrayNotHasKey('budget_tokens', $options['thinking']);
        $this->assertSame('adaptive', $options['thinking']['type']);

        $this->assertArrayHasKey('output_config', $options);
        $this->assertIsArray($options['output_config']);
        $this->assertSame('medium', $options['output_config']['effort']);
        $this->assertArrayNotHasKey('budget_tokens', $options['output_config']);
    }

    public function test_anthropic_mapping_with_lab_enum(): void
    {
        $options = ProviderOptions::for(Lab::Anthropic, 'high');

        $this->assertArrayNotHasKey('budget_tokens', $options);
        $this->assertIsArray($options['thinking']);
        $this->assertSame('adaptive', $options['thinking']['type']);
        $this->assertIsArray($options['output_config']);
        $this->assertSame('high', $options['output_config']['effort']);
    }

    public function test_openai_mapping_on_responses_driver(): void
    {
        config(['ai.providers.openai.driver' => 'openai']);

        $options = ProviderOptions::for('openai', 'high');

        $this->assertArrayHasKey('reasoning', $options);
        $this->assertIsArray($options['reasoning']);
        $this->assertSame('high', $options['reasoning']['effort']);
    }

    public function test_openai_mapping_on_chat_completions_driver(): void
    {
        config(['ai.providers.openai.driver' => 'openai-compatible']);

        $this->assertSame(['reasoning_effort' => 'high'], ProviderOptions::for('openai', 'high'));
    }

    public function test_byo_provider_names_map_to_their_base_provider(): void
    {
        config(['ai.providers.openai.driver' => 'openai-compatible']);

        $this->assertSame(['reasoning_effort' => 'low'], ProviderOptions::for('byo_openai_2', 'low'));
        $this->assertSame(['thinking_level' => 'high'], ProviderOptions::for('byo_gemini_15', 'high'));
        $this->assertSame([], ProviderOptions::for('byo_openai-compatible_3', 'high'));
    }

    public function test_gemini_mapping(): void
    {
        $this->assertSame(['thinking_level' => 'low'], ProviderOptions::for('gemini', 'low'));
        $this->assertSame(['thinking_level' => 'medium'], ProviderOptions::for('gemini', 'medium'));
        $this->assertSame(['thinking_level' => 'high'], ProviderOptions::for('gemini', 'high'));
    }

    public function test_other_providers_return_empty_array(): void
    {
        $options = ProviderOptions::for('ollama', 'high');
        $this->assertSame([], $options);

        $optionsGroq = ProviderOptions::for('groq', 'high');
        $this->assertSame([], $optionsGroq);
    }

    public function test_channel_defaults(): void
    {
        // WhatsApp defaults to low
        $whatsappOptions = ProviderOptions::for('anthropic', channel: Channel::WhatsApp);
        $this->assertIsArray($whatsappOptions['output_config']);
        $this->assertSame('low', $whatsappOptions['output_config']['effort']);

        // Chat defaults to medium
        $chatOptions = ProviderOptions::for('anthropic', channel: Channel::Chat);
        $this->assertIsArray($chatOptions['output_config']);
        $this->assertSame('medium', $chatOptions['output_config']['effort']);

        // Explicit reasoning level overrides channel default
        $explicitOptions = ProviderOptions::for('anthropic', reasoningLevel: 'high', channel: Channel::WhatsApp);
        $this->assertIsArray($explicitOptions['output_config']);
        $this->assertSame('high', $explicitOptions['output_config']['effort']);
    }
}
