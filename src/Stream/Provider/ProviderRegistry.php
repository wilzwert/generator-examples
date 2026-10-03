<?php

namespace App\Stream\Provider;

final readonly class ProviderRegistry
{
    /**
     * @var array<string, Provider> keyed by provider name
     */
    private array $providers;

    public function __construct()
    {
        $this->providers = [
            'demo-1' => new DemoProvider(0),
            'demo-2' => new DemoProvider(1),
            'demo-3' => new DemoProvider(2),
            'demo-4' => new DemoProvider(3),
            'open-router-gpt-4o' => new OpenRouterProvider('openai/gpt-4o', (string) getenv('OPENROUTER_API_KEY')),
            'anthropic-opus' => new AnthropicProvider('claude-opus-5-5', (string) getenv('ANTHROPIC_API_KEY')),
        ];
    }

    /**
     * @return array<string, Provider>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
}
