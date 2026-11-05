import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// CAT-3 (owner decision 2026-11-05): a read-only price history per charge, with Daniel's design review points
// and the CAT-2 follow-ups (Daniel's fixed column widths, Kumar's "—" for an inactive charge) pinned.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Pricing/PriceHistory.vue');
const template = page.slice(page.indexOf('<template>'));
const check = read('resources/js/pages/Pricing/PriceCheck.vue');
const checkTemplate = check.slice(check.indexOf('<template>'));
const pricing = read('resources/js/pages/Pricing/Index.vue');
const service = read(
    'app/Domain/Visit/Billing/Services/PriceHistoryService.php',
);
const checkService = read(
    'app/Domain/Visit/Billing/Services/PriceListCheckService.php',
);

test('the page is read-only and the service writes nothing', () => {
    assert.doesNotMatch(page, /router\.(post|patch|put|delete)\(/);
    assert.doesNotMatch(page, /useForm\(/);
    assert.match(
        page,
        /router\.get\(\s*`\/pricing\/charges\/\$\{props\.result\.charge\.publicId\}\/history`/,
    );
    assert.doesNotMatch(
        service,
        /->create\(|->save\(|->update\(|->delete\(|lockForUpdate/,
    );
});

test('one header with the back link in its actions, from a fixed list of targets', () => {
    assert.equal((template.match(/<PageHeader/g) ?? []).length, 1);
    assert.doesNotMatch(template, /<h1/);
    const header = template.slice(
        template.indexOf('<PageHeader'),
        template.indexOf('</PageHeader>'),
    );
    assert.match(header, /<template #actions>[\s\S]*\{\{ back\.label \}\}/);
    assert.match(
        page,
        /href: '\/pricing\/price-check',\s*label: 'Back to price list check'/,
    );
    assert.match(page, /href: '\/pricing',\s*label: 'Back to Pricing'/);
    assert.match(page, /from: 'price-check' \| null;/);
    const controller = read('app/Http/Controllers/PricingController.php');
    assert.match(
        controller,
        /'from' => \['nullable', Rule::in\(\['price-check'\]\)\]/,
    );
});

test('the price book filter has a visible connected label and inactive books say so', () => {
    assert.ok(template.includes('id="price-history-book"'));
    assert.ok(template.includes('for="price-history-book"'));
    assert.match(template, /labelledby="price-history-book-label"/);
    assert.match(page, /`\$\{option\.label\} \(inactive\)`/);
});

test('"In use" and "Scheduled" are badges, "Replaced" is muted text', () => {
    assert.equal(
        (template.match(/v-if="row\.status !== 'replaced'"/g) ?? []).length,
        2,
    );
    assert.equal(
        (
            template.match(
                /class="text-xs text-muted-foreground"\s*>Replaced<\/span/g,
            ) ?? []
        ).length,
        2,
    );
});

test('"Free (RM 0.00)" is flagged only on a version in use or scheduled', () => {
    assert.match(
        page,
        /const flagFree = \(row: Row\) => row\.amountSen === 0 && row\.status !== 'replaced';/,
    );
    assert.equal((template.match(/v-if="flagFree\(row\)"/g) ?? []).length, 2);
});

test('cards below 1024 px, a fixed-width table from 1024 px, tabular money', () => {
    assert.match(
        template,
        /class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden"/,
    );
    assert.match(
        template,
        /class="grid min-w-0 grid-cols-1 gap-2 rounded-xl border bg-card p-4"/,
    );
    assert.match(template, /<div class="hidden min-w-0 lg:block">/);
    assert.match(
        template,
        /label="Price history"\s+class="\[&_table\]:table-fixed"/,
    );
    assert.ok((template.match(/tabular-nums/g) ?? []).length >= 5);
});

test('empty and filtered-empty wording', () => {
    assert.match(page, /'No prices in this price book yet\.'/);
    assert.match(page, /'No prices published for this charge yet\.'/);
    assert.match(page, /'Publish a price on the Pricing page\.'/);
    assert.match(template, /<ErrorState/);
    assert.match(template, /Reload/);
});

test('In use and Scheduled have worded statuses with a tone', () => {
    const presentation = read('resources/js/lib/presentation.ts');
    const js = ts.transpileModule(presentation, {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const { formatStatusLabel, statusTone } = runInNewContext(
        `${js.replaceAll('export ', '')}\n({ formatStatusLabel, statusTone })`,
        {},
    );
    assert.equal(formatStatusLabel('price_in_use'), 'In use');
    assert.equal(statusTone('price_in_use'), 'success');
    assert.equal(formatStatusLabel('price_scheduled'), 'Scheduled');
    assert.equal(statusTone('price_scheduled'), 'neutral');
});

test('Pricing opens the history from each card and row', () => {
    assert.equal(
        (pricing.match(/data-action="price-history"/g) ?? []).length,
        2,
    );
    assert.equal(
        (
            pricing.match(
                /:href="`\/pricing\/charges\/\$\{charge\.publicId\}\/history`"/g,
            ) ?? []
        ).length,
        2,
    );
    assert.equal(
        (
            pricing.match(
                /:aria-label="`Price history for \$\{charge\.displayName\}`"/g,
            ) ?? []
        ).length,
        2,
    );
});

test('on the price list check the item name is the history link; no charge stays plain text', () => {
    assert.match(
        check,
        /`\/pricing\/charges\/\$\{row\.chargePublicId\}\/history\?from=price-check`/,
    );
    assert.equal(
        (checkTemplate.match(/v-if="row\.chargePublicId"/g) ?? []).length,
        2,
    );
    assert.equal(
        (checkTemplate.match(/data-testid="history-link"/g) ?? []).length,
        2,
    );
    assert.match(
        checkTemplate,
        /class="inline-flex min-h-11 items-center break-words text-brand-strong underline underline-offset-2"/,
    );
    assert.equal(
        (
            checkTemplate.match(
                /<template v-else>\{\{\s*row\.displayName\s*\}\}<\/template>/g,
            ) ?? []
        ).length,
        2,
    );
});

test('CAT-2 follow-ups: fixed column widths and no price for an inactive charge', () => {
    assert.match(
        checkTemplate,
        /label="Price list"\s+class="\[&_table\]:table-fixed"/,
    );

    for (const width of ['w-[28%]', 'w-[14%]', 'w-[18%]', 'w-[26%]']) {
        assert.ok(checkTemplate.includes(width), `missing ${width}`);
    }

    assert.match(
        checkService,
        /\$billable = \$charge !== null && \$charge->is_active;/,
    );
    assert.match(
        checkService,
        /'selfPaySen' => \$billable \? \$selfPay : null,/,
    );
    assert.match(
        checkService,
        /'panelPrices' => \$billable \? \$panelPrices : \[\],/,
    );
});
