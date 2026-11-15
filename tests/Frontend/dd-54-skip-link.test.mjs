import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const shell = readFileSync(
    new URL('../../resources/js/components/AppShell.vue', import.meta.url),
    'utf8',
);

test('DD-54: the staff shell starts with a skip link that focuses the main landmark', () => {
    assert.match(shell, /Skip to main content/);
    assert.match(shell, /sr-only focus:not-sr-only/);
    assert.match(
        shell,
        /querySelector<HTMLElement>\('main, \[role="main"\]'\)/,
    );
    assert.match(shell, /main\.focus\(\)/);
    assert.ok(
        shell.indexOf('Skip to main content') < shell.indexOf('<slot />'),
    );
});
