<?php

namespace Tests\Feature\Agents;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Contract section 7 (owner decision 2026-11-05, changed 2026-11-24 for the chatbox only): KPOne calls no AI service except through
 * the one chatbox adapter in app/Domain/Agents/Chat/Providers (with config/ai_chat.php). This fails if application
 * code, configuration, routes, frontend code or the environment example names an AI provider endpoint
 * or setting, or if an AI SDK package is required or locked.
 */
class NoAiServiceGuardTest extends TestCase
{
    private const SCANNED = ['app', 'bootstrap', 'config', 'database', 'routes', 'resources/js', 'resources/views'];

    private const FORBIDDEN_TEXT = [
        'anthropic', 'openai', 'api.anthropic.com', 'ANTHROPIC_', 'api.openai.com', 'OPENAI_', 'generativelanguage.googleapis.com', 'GEMINI_API',
        'api.mistral.ai', 'api.cohere', 'bedrock-runtime', 'api.groq.com', 'huggingface.co', 'aiplatform.googleapis.com',
    ];

    private const FORBIDDEN_PACKAGES = [
        'anthropic-ai/', '@anthropic-ai/', 'anthropic/', 'openai-php/', 'openai', '@openai/', 'google-gemini-php/', '@google/generative-ai',
        '@google/genai', 'langchain', '@langchain/', 'prism-php/', 'echolabsdev/prism', 'mistralai', '@mistralai/', 'cohere', 'llphant/', 'theodo-group/llphant',
        'ai', '@ai-sdk/', 'ollama', 'groq-sdk', '@huggingface/', '@google-cloud/vertexai', 'cohere-ai',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_no_ai_endpoint_or_setting_appears_in_application_code(): void
    {
        $hits = [];
        $files = [$this->root().'/.env.example'];
        foreach (self::SCANNED as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root().'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && preg_match('/\.(php|ts|tsx|js|jsx|mjs|cjs|vue|json|neon|ya?ml|example)$/', $file->getFilename())) {
                    $files[] = $file->getPathname();
                }
            }
        }
        foreach ($files as $path) {
            // CHAT-1 (owner decision 2026-11-24): the provider may be named only by its one adapter folder and its settings file.
            $relative = str_replace($this->root().'/', '', $path);
            if (str_starts_with($relative, 'app/Domain/Agents/Chat/Providers/') || $relative === 'config/ai_chat.php') {
                continue;
            }
            $text = (string) file_get_contents($path);
            foreach (self::FORBIDDEN_TEXT as $needle) {
                if (stripos($text, $needle) !== false) {
                    $hits[] = str_replace($this->root().'/', '', $path).' mentions '.$needle;
                }
            }
        }
        $this->assertGreaterThan(100, count($files), 'The scan must cover the application.');
        $this->assertSame([], $hits);
    }

    public function test_no_ai_sdk_package_is_required_or_locked(): void
    {
        $composer = json_decode((string) file_get_contents($this->root().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $npm = json_decode((string) file_get_contents($this->root().'/package.json'), true, flags: JSON_THROW_ON_ERROR);
        $names = [
            ...array_keys($composer['require'] ?? []), ...array_keys($composer['require-dev'] ?? []),
            ...array_keys($npm['dependencies'] ?? []), ...array_keys($npm['devDependencies'] ?? []),
        ];
        $lock = json_decode((string) file_get_contents($this->root().'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
            $names[] = $package['name'];
        }
        $npmLock = json_decode((string) file_get_contents($this->root().'/package-lock.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_keys($npmLock['packages'] ?? []) as $path) {
            if ($path !== '') {
                $names[] = preg_replace('#^.*node_modules/#', '', $path);
            }
        }
        $this->assertGreaterThan(100, count($names));
        $hits = array_values(array_filter(array_unique($names), fn (string $name): bool => $this->forbidden(strtolower($name))));
        $this->assertSame([], $hits);
    }

    /** CHAT-1: only the chatbox's own controller, middleware, command, provider binding and routes may use the chat classes. */
    public function test_no_clinical_patient_billing_or_inventory_code_uses_the_chat_classes(): void
    {
        $allowed = [
            'app/Domain/Agents/Chat/', 'app/Http/Controllers/AgentChatController.php', 'app/Http/Middleware/ChatEnabled.php',
            'app/Console/Commands/PurgeAiChatHistory.php', 'app/Providers/AppServiceProvider.php', 'routes/web.php', 'routes/console.php',
        ];
        $hits = [];
        $count = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root().'/app', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $count++;
            $relative = str_replace($this->root().'/', '', $file->getPathname());
            foreach ($allowed as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'Domain\\Agents\\Chat')) {
                $hits[] = $relative;
            }
        }
        $this->assertGreaterThan(500, $count);
        $this->assertSame([], $hits);
    }

    public function test_the_guard_recognises_ai_packages(): void
    {
        foreach (['anthropic-ai/sdk', '@anthropic-ai/sdk', 'openai-php/client', 'openai', '@langchain/core', 'prism-php/prism', 'ai', '@ai-sdk/openai', 'ollama'] as $name) {
            $this->assertTrue($this->forbidden($name), $name);
        }
        foreach (['laravel/framework', 'vue', '@inertiajs/vue3', 'aimeos/x', 'airbnb'] as $name) {
            $this->assertFalse($this->forbidden($name), $name);
        }
    }

    private function forbidden(string $name): bool
    {
        foreach (self::FORBIDDEN_PACKAGES as $prefix) {
            if (str_ends_with($prefix, '/') ? str_starts_with($name, $prefix) : ($name === $prefix || str_starts_with($name, $prefix.'/'))) {
                return true;
            }
        }

        return false;
    }
}
