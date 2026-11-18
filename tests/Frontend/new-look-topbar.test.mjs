import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const shell = read('resources/js/components/workspace/V2AppShell.vue');
const css = read('resources/css/app.css');

test('New look step D: Ctrl K / Cmd K opens the existing jump dialog, only under the look, and the hint follows the platform', () => {
    assert.match(shell, /window\.addEventListener\('keydown', onShortcut\)/);
    assert.match(shell, /window\.removeEventListener\('keydown', onShortcut\)/);
    assert.match(
        shell,
        /newLook\.value &&\s*\(event\.metaKey \|\| event\.ctrlKey\)/,
    );
    assert.match(shell, /event\.key\.toLowerCase\(\) === 'k'/);
    assert.match(shell, /jumpOpen\.value = true/);
    assert.match(shell, /isApple\.value \? '⌘K' : 'Ctrl K'/);
    // The visible, focusable search button stays (the shortcut is never the only way in).
    assert.match(shell, /data-test="v2-jump-open"/);
    assert.match(
        shell,
        /:aria-keyshortcuts="\s*newLook \? 'Control\+K Meta\+K' : undefined/,
    );
    assert.match(
        shell,
        /v-if="newLook"\s+class="ml-auto hidden shrink-0[^"]*"\s+aria-hidden="true"/,
    );
});

test('New look step D: the bar is translucent with a solid fallback, keeps its 4 rem height, and focused fields clear it', () => {
    assert.match(
        css,
        /html\[data-look='new'\] \[data-test='v2-shell-top'\] \{\s*background-color: var\(--background\);/,
    );
    assert.match(css, /@supports \(backdrop-filter: blur\(1px\)\)/);
    assert.match(css, /backdrop-filter: saturate\(160%\) blur\(14px\)/);
    assert.match(css, /@media \(prefers-reduced-transparency: reduce\)/);
    assert.match(css, /scroll-padding-top: 5rem/);
    // The height is unchanged, so `--v2-topbar-h` and every sticky page header keep their offset.
    assert.match(shell, /'--v2-topbar-h': focusMode \? '0px' : '4rem'/);
    assert.match(shell, /sticky top-0 z-40 flex h-16/);
});
