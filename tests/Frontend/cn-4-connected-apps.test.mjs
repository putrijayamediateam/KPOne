// CN-4: the Connected apps page.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Connected apps disconnects through its own route behind a destructive confirm that says Claude may still list KPOne', () => {
    const page = read('resources/js/pages/Agents/ConnectedApps.vue');

    assert.match(page, /router\.delete\(/);
    assert.match(
        page,
        /\/agents\/connected-apps\/\$\{encodeURIComponent\(app\.clientId\)\}/,
    );
    assert.match(page, /OperationalConfirmDialog/);
    assert.match(page, /destructive\b/);
    assert.match(page, /may still list KPOne as connected until it next asks/);
});

test('Connected apps shows the empty state, the switched-off note, "Not asked yet" and a closed questions list', () => {
    const page = read('resources/js/pages/Agents/ConnectedApps.vue');

    assert.match(page, /then allow it on the KPOne screen that\s+opens/);
    assert.match(page, /The connector is switched off on this server\./);
    assert.match(page, /Not asked yet/);
    assert.match(page, /<details class=/);
    assert.doesNotMatch(page, /<details[^>]* open/);
});

test('Connected apps returns focus to the page heading after a disconnect and uses 44 px targets', () => {
    const page = read('resources/js/pages/Agents/ConnectedApps.vue');

    assert.match(page, /heading\.value\?\.focus\(\)/);
    assert.match(page, /min-h-11/);
});

test('the Agents page links to Connected apps only when the person may connect, and the consent page points to it', () => {
    assert.match(
        read('resources/js/pages/Agents/Index.vue'),
        /v-if="canConnect"[\s\S]*\/agents\/connected-apps/,
    );
    assert.match(
        read('resources/views/connector/authorize.blade.php'),
        /agents\.connected-apps[\s\S]*Connected apps<\/a> in KPOne, or disconnect KPOne in the app/,
    );
});

test('while the connector is switched off the empty state does not invite connecting, and the Agents link is a header action button', () => {
    const page = read('resources/js/pages/Agents/ConnectedApps.vue');
    const agents = read('resources/js/pages/Agents/Index.vue');

    assert.match(
        page,
        /Connecting is not available until the connector is switched on\./,
    );
    assert.match(page, /v-if="connectorEnabled"/);
    assert.match(
        agents,
        /<template v-if="canConnect" #actions>[\s\S]*variant="outline"[\s\S]*\/agents\/connected-apps/,
    );
});
