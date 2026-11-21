import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// OPT-1 (first server deploy, 21 Nov 2026): `php artisan optimize` failed at its views step with "Unable to locate a
// class or view for component [inertia::app]". CI now runs optimize and optimize:clear, and the `inertia` component
// namespace is registered on the Blade compiler instance, never only through the Blade facade.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the inertia component namespace is registered on the Blade compiler instance', () => {
    const provider = read('app/Providers/AppServiceProvider.php');

    assert.match(provider, /\$this->registerInertiaBladeComponents\(\);/);
    assert.match(
        provider,
        /callAfterResolving\('blade\.compiler', function \(BladeCompiler \$blade\): void \{\s*\$blade->componentNamespace\('Inertia\\\\View\\\\Components', 'inertia'\);/,
    );
    assert.doesNotMatch(provider, /Blade::componentNamespace/);
});

test('the page shell still uses the inertia components', () => {
    const shell = read('resources/views/app.blade.php');

    assert.match(shell, /<x-inertia::head>/);
    assert.match(shell, /<x-inertia::app \/>/);
});

test('CI runs optimize and then optimize:clear, without a database', () => {
    const workflow = read('.github/workflows/tests.yml');
    const step = workflow.slice(
        workflow.indexOf('- name: Laravel optimize and optimize:clear'),
    );

    assert.ok(step.length > 0);
    assert.match(
        step,
        /env:\s*CACHE_STORE: array\s*run: \|\s*php artisan optimize --ansi\s*php artisan optimize:clear --ansi/,
    );
});
