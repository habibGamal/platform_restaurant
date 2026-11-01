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

    public function test_openai_mapping(): void
    {
        $options = ProviderOptions::for('openai', 'high');

        $this->assertArrayHasKey('reasoning', $options);
        $this->assertIsArray($options['reasoning']);
        $this->assertSame('high', $options['reasoning']['effort']);
    }

    public function test_gemini_mapping(): void
    {
        $optionsLow = ProviderOptions::for('gemini', 'low');
        $this->assertArrayHasKey('thinking_config', $optionsLow);
        $this->assertIsArray($optionsLow['thinking_config']);
        $this->assertSame(1024, $optionsLow['thinking_config']['thinking_budget']);

        $optionsHigh = ProviderOptions::for('gemini', 'high');
        $this->assertSame(8192, $optionsHigh['thinking_config']['thinking_budget']);

        $optionsMedium = ProviderOptions::for('gemini', 'medium');
        $this->assertSame(4096, $optionsMedium['thinking_config']['thinking_budget']);
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
