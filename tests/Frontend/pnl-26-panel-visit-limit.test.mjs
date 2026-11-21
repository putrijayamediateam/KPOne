import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-26 (item 16a): an optional per-Panel limit per visit in Checkout settings. It only ever warns; it never blocks.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const form = read('resources/js/pages/PanelSettings/Form.vue');
const service = read('app/Domain/Visit/Services/PanelSettingsService.php');

test('the Panel form has an optional ringgit field that says it only warns', () => {
    assert.match(form, /Limit per visit \(RM\)/);
    assert.match(form, /v-model="form\.visit_limit"/);
    assert.match(form, /placeholder="No limit"/);
    assert.match(form, /never blocks a\s+visit, a bill or a claim/);
    assert.match(form, /form\.errors\.visit_limit/);
    // Saved sen are shown back in ringgit, and an empty box is sent as nothing.
    assert.match(form, /props\.panel\.visitLimitSen \/ 100\)\.toFixed\(2\)/);
    assert.match(form, /visit_limit: blankToNull\(data\.visit_limit\)/);
});

test('the service reads the limit as exact ringgit and keeps its value out of the audit trail', () => {
    assert.match(
        service,
        /'visit_limit' => \['nullable', 'string', 'max:20'\]/,
    );
    assert.match(service, /private static function limitSen/);
    assert.match(service, /'visit_limit_sen' => self::limitSen/);
    assert.match(service, /'visitLimitSen' => \$panel->visit_limit_sen/);
    // Audit still holds field names only.
    assert.match(service, /'changed_fields' => \$changed/);
});
