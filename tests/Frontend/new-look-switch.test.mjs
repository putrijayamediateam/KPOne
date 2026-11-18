import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const menu = read('resources/js/components/UserMenuContent.vue');
const blade = read('resources/views/app.blade.php');

test('New look (preview): a menu row beside the layout switch, only while the new layout is on', () => {
    assert.match(
        menu,
        /v-if="page\.props\.workspace\?\.spaces\?\.enabled"[\s\S]*?data-test="new-look-row"[\s\S]*?switchLook\(!newLook\)/,
    );
    assert.match(menu, /'Back to the current look' : 'New look \(preview\)'/);
    assert.match(
        menu,
        /router\.put\('\/spaces\/preference', \{\s*enabled: true,\s*new_look: enabled,/,
    );
});

test('New look (preview): the page gets data-look="new" only in Version 2 and only when switched on', () => {
    assert.match(
        blade,
        /\$uiVersion === 'v2' && \(\$page\['props'\]\['workspace'\]\['newLook'\] \?\? false\) === true\) data-look="new"/,
    );
});
