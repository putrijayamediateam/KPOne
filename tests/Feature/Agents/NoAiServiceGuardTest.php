<?php

namespace Tests\Feature\Agents;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Contract section 7 (owner decision 2026-11-05): KPOne calls no AI service. This fails if application
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
