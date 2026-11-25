import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Inventory/CentralStore.vue');
const panel = read(
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
);
const inventory = read('resources/js/pages/Inventory/Index.vue');
const myDay = read('resources/js/pages/MyDay/Index.vue');
const template = (source) => source.slice(source.indexOf('<template>'));
// The source without comments, so a note such as "no cost" never counts as code.
const code = page.replace(/<!--[\s\S]*?-->|\/\/[^\n]*/g, '');

// STK-47 and STK-60 (design note STK-47-60-DESIGN.md revision 2 with Daniel's conditions).
test('STK-47: "This week" comes first, then the three views as tabs', () => {
    const body = template(page);
    assert.ok(body.indexOf('This week') < body.indexOf('<OperationalTabs'));

    for (const label of [
        "label: 'Store stock'",
        "label: 'Branch stock'",
        "label: 'Requests to the store'",
    ]) {
        assert.ok(page.includes(label), label);
    }

    assert.match(page, /'Not sent yet'/);
    assert.match(page, /`Sent \$\{card\.sentOn\}/);
    // The card tone comes from the server: success, neutral before the request day, warning on and after; never red.
    assert.match(page, /tone: 'success' \| 'neutral' \| 'warning';/);
});

test('STK-47: cover reads in words with a dot, and zero use says "No dispensing data"', () => {
    assert.match(page, /return 'No dispensing data';/);
    assert.match(page, /return 'Less than 1 week';/);
    assert.match(page, /status="cover"\s+:tone="row\.coverTone"/);
    assert.match(page, /tabular-nums/);
});

test('STK-47: no cost or value on the page', () => {
    // Ref `.value` aside, no field named for cost, value, price or sen is read.
    assert.doesNotMatch(
        code,
        /\.\w+(Cost|Value|Price|Sen)\b|\.(cost|price)\w*/,
    );
    assert.doesNotMatch(code, /\bRM\b|valuation/i);
});

test('STK-47: branch stock is a matrix from 1024 px with cards below, and "Below level" in words', () => {
    assert.match(page, /<div class="hidden lg:block">/);
    assert.match(page, /class="space-y-3 lg:hidden" aria-label="Branch stock"/);
    assert.match(page, /label="Below level"/);
    assert.match(
        page,
        /`\$\{trimQuantity\(cell\.usable\)\} of \$\{trimQuantity\(cell\.level\)\}`/,
    );
});

test('STK-47: requests to the store are links only, grouped by branch', () => {
    const requests = page.slice(
        page.indexOf('aria-label="Requests to the store"'),
    );
    assert.match(requests, /v-for="group in store\.requests"/);
    assert.match(requests, /variant="outline"[\s\S]*?>Open<\/ActionLink/);
    assert.doesNotMatch(page, /router\.post/);
    assert.doesNotMatch(page, /stock-requests/);
});

test('STK-60: the request-day sheet has weekday radios, the sentence and one Save', () => {
    assert.match(page, /Request day: \{\{ store\.requestDay\.name \}\}/);
    assert.match(page, /v-if="store\.canChangeDay" #actions/);
    assert.match(page, /type="radio"\s+name="request_weekday"/);
    assert.match(
        page,
        /This only reminds and warns\. It never blocks a\s+request\./,
    );
    assert.equal([...page.matchAll(/>Save<\/Button/g)].length, 1);
    assert.doesNotMatch(code, /cut-off|cutoff|type="time"/i);
    assert.match(page, /lock_version: props\.store\.settingLockVersion/);
    assert.match(page, /idempotency_key: form\.key/);
});

test('STK-60: the off-day warning is a warning wash at the top of the request form and never disables Submit', () => {
    const form = panel.slice(panel.indexOf('id="store-request"'));
    assert.ok(
        form.indexOf('data-testid="store-off-day-warning"') <
            form.indexOf('Source location'),
    );
    assert.match(panel, /border-warning\/30 bg-warning\/10/);
    assert.match(
        panel,
        /The store's request day is \$\{props\.storeDay\.dayName\}\. You can still send this; the store may pack it with next \$\{props\.storeDay\.dayName\}'s requests\./,
    );
    assert.match(
        panel,
        /<Button type="submit" :disabled="busy" class="sm:col-span-2"/,
    );
    assert.doesNotMatch(panel, /:disabled="[^"]*storeOffDayWarning/);
});

test('STK-60: the reminder shows in info tone with one outline link on Inventory and My day', () => {
    for (const source of [inventory, myDay]) {
        assert.match(source, /border-brand\/25 bg-brand\/10/);
        assert.match(source, />Start a store request<\/(ActionLink|Link)/);
        assert.match(source, /<Info/);
    }

    assert.match(inventory, /v-if="storeDay\?\.reminder"/);
    assert.match(myDay, /v-if="storeReminder"/);
});

test('STK-47: the Central store link shows only to warehouse holders', () => {
    assert.match(
        panel,
        /v-if="canViewCentralStore"\s+href="\/inventory\/central-store"/,
    );
    assert.match(panel, /:id="`stock-request-\$\{row\.publicId\}`"/);
});

test('STK-47: the 100-request cap says how many more, and store holds are explained', () => {
    assert.match(page, /v-if="store\.requestsMore > 0"/);
    assert.match(
        page,
        /Showing the 100 oldest open requests, and\s+\{\{ store\.requestsMore \}\} more\./,
    );
    assert.match(
        page,
        /A batch held at a branch still counts as usable\s+here, because branch holds don't apply at the store\./,
    );
});
