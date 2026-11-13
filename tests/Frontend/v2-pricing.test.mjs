import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Pricing: look only. It must send what Classic sends, keep Classic's rules, and add no second filled button.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Pricing/Index.vue');
const v2 = read('resources/js/pages/Pricing/V2/Index.vue');
const publish = read('resources/js/composables/pricing/usePricingPublish.ts');
const registry = read('config/v2-pages.php');

test('Pricing/Index is registered for Version 2 and the V2 file exists', () => {
    assert.match(registry, /'Pricing\/Index'/);
    assert.ok(v2.includes('<V2WorkLayout'));
    assert.ok(v2.includes('<PageFrame'));
});

test('V2 posts to the same routes as Classic', () => {
    for (const route of [
        '/pricing/price-books',
        '/pricing/charges',
        '/pricing/charges/${charge.publicId}/publish',
    ]) {
        assert.ok(classic.includes(route) || publish.includes(route), route);
        assert.ok(v2.includes(route) || publish.includes(route), route);
    }

    assert.ok(
        classic.includes("charge.isActive ? 'deactivate' : 'activate'") &&
            v2.includes("charge.isActive ? 'deactivate' : 'activate'"),
    );
    assert.ok(v2.includes('/pricing/charges/${charge.publicId}/history'));
});

test('the publish rules and payload match Classic (CAT-1b)', () => {
    const message = "'Enter an amount. Type 0.00 if this is free.'";

    assert.ok(classic.includes(`const emptyAmountMessage = ${message};`));
    assert.match(
        publish,
        new RegExp(
            `EMPTY_AMOUNT_MESSAGE =\\s*${message.replace(/[.]/g, '\\.')}`,
        ),
    );
    assert.match(publish, /const amountSen = rmToSen\(form\.amount\);/);
    assert.match(
        publish,
        /if \(amountSen === null\) \{\s*form\.error = EMPTY_AMOUNT_MESSAGE;\s*return;\s*\}/,
    );

    for (const key of [
        'price_book_public_id',
        'amount_sen',
        'expected_version',
        'expected_branch_id',
    ]) {
        assert.ok(classic.includes(key) && publish.includes(key), key);
    }

    assert.match(
        publish,
        /expected_version: charge\.currentPrice\?\.version \?\? 0/,
    );
    // The check runs before anything is sent.
    assert.ok(
        publish.indexOf('amountSen === null') < publish.indexOf('router.post('),
    );
});

test('the new charge and price book forms send the same fields as Classic', () => {
    for (const key of [
        'medicine_public_id',
        'service_public_id',
        'display_name',
        "currency: 'MYR'",
    ]) {
        assert.ok(classic.includes(key) && v2.includes(key), key);
    }

    assert.ok(v2.includes('maxlength="500"'));
    assert.ok(v2.includes('maxlength="150"'));
});

test('V2 keeps the publish permission and the scheduled-price line', () => {
    assert.match(v2, /v-if="canPublish"/);
    assert.match(
        v2,
        /Next: RM \$\{[^}]*\} from \$\{charge\.scheduledPrice\.effectiveLabel/,
    );
    assert.ok(v2.includes('Publish price'));
});

test('V2 has one filled page button, native controls are not used, and dialogs are V2Dialog', () => {
    assert.equal((v2.match(/filledButton,/g) ?? []).length, 1);
    assert.doesNotMatch(v2, /<select[\s>]/);
    assert.doesNotMatch(v2, /type="file"/);
    assert.equal((v2.match(/<V2Dialog/g) ?? []).length, 5);
    assert.ok(v2.includes('tone="danger"'));
    assert.ok(v2.includes('cards="xl"'));
    assert.ok(v2.includes('data-actions'));
});

test('Classic keeps its own page and does not import V2 pieces', () => {
    assert.doesNotMatch(classic, /components\/v2|composables\/pricing/);
});

test('the publish dialog shows the current and scheduled price and the stale-price message (Daniel)', () => {
    assert.match(v2, /data-test="publish-current"/);
    assert.match(v2, /\{\{ bookInfo \}\}/);
    assert.match(v2, /\{\{ bookScheduled \}\}/);
    assert.ok(
        publish.includes(
            "'Someone published a newer price. Reload to see it.'",
        ),
    );
    // The server refuses a changed price with a `price` error key.
    assert.ok(
        read(
            'app/Domain/Visit/Billing/Services/PricePublicationService.php',
        ).includes("withMessages(['price' =>"),
    );
    assert.match(publish, /form\.stale = 'price' in errors/);
    assert.match(v2, /data-action="reload-prices"/);
});

test('phones get one "Filters (n)" sheet and the deactivate confirm uses plain words (Daniel)', () => {
    assert.match(v2, /data-action="open-filters"/);
    assert.match(
        v2,
        /Filters\{\{\s*filterCount \? ` \(\$\{filterCount\}\)` : ''/,
    );
    assert.match(v2, /applied/);
    assert.match(v2, /confirm-label="Apply"/);
    assert.match(v2, /data-action="reset-filters"/);
    assert.ok(
        v2.includes(
            'It will not be offered on new invoices. Existing invoices are not changed. You can activate it again.',
        ),
    );
});

test('money safety (Daniel): no guessed price book, an empty amount at the start, a red border on invalid fields', () => {
    // More than one active book: nothing is preselected.
    assert.match(
        v2,
        /activeBooks\.value\.length === 1 \? activeBooks\.value\[0\]\.publicId : ''/,
    );
    assert.match(publish, /form\.priceBook = defaultPriceBook;/);
    assert.match(publish, /form\.amount = '';/);
    assert.doesNotMatch(publish, /toFixed\(2\)/);
    assert.ok(v2.includes("label: 'Choose a price book'"));
    assert.ok(v2.includes('Current price in ${book.name}'));
    assert.match(v2, /:class="fieldClass\(invalid\)"/);
    assert.ok(
        v2.includes("inputClass.replace('border-input', 'border-destructive')"),
    );

    // The payload is unchanged.
    for (const key of [
        'price_book_public_id',
        'amount_sen',
        'expected_version',
        'expected_branch_id',
    ]) {
        assert.ok(publish.includes(key), key);
    }
});

test('phone header: New charge full width, the other two as a text-link row; cards fold Type and Status (Daniel)', () => {
    assert.match(v2, /data-test="page-links"/);
    assert.match(v2, /max-sm:hidden/);
    assert.match(v2, /max-xl:hidden/);
});

test('a stale price shows in its own alert, never on the amount field; Reload keeps the typed amount and never publishes (Daniel)', () => {
    assert.match(v2, /data-test="publish-stale"/);
    assert.match(v2, /publish\.form\.priceBook && !publish\.form\.stale/);
    // Reload re-reads the charge from the fresh props and keeps the book and amount; it never posts a price.
    const reload = publish.slice(publish.indexOf('const reload = () => {'));
    assert.match(reload, /router\.reload\(/);
    assert.match(reload, /target\.value = fresh;/);
    assert.doesNotMatch(reload, /router\.post|submit\(/);
    assert.doesNotMatch(reload, /form\.amount =|form\.priceBook =/);
});

test("Daniel's last four: reload status line, 'No price' in words, Next line on its own row, a reason when the person cannot publish", () => {
    assert.match(v2, /data-test="publish-reloaded"/);
    assert.match(v2, /role="status"/);
    assert.ok(v2.includes('Prices reloaded.'));
    assert.match(publish, /form\.reloaded = true;/);
    assert.match(v2, /data-test="no-price"/);
    assert.ok(v2.includes('>No price</span'));
    assert.match(v2, /data-test="next-price-row"/);
    assert.ok(v2.includes('\\u00A0$1'));
    assert.ok(v2.includes("id: 'publish-disabled'"));
    assert.ok(v2.includes('allowed: false'));
    assert.ok(v2.includes("You can't publish prices with your role."));
    assert.match(v2, /data-test="cannot-publish-note"/);
});
