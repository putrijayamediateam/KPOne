import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Completed Dispensary page (owner, 2026-11-15). Look only: same props and the Classic returns section.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Dispensary/Completed.vue');
const v2 = read('resources/js/pages/Dispensary/V2/Completed.vue');
const props = (source) =>
    source.slice(
        source.indexOf('defineProps<{'),
        source.indexOf('}>();', source.indexOf('defineProps<{')),
    );
const template = (source) => source.slice(source.indexOf('<template>'));

test('V2 Completed is registered and Classic does not import anything from V2', () => {
    assert.match(read('config/v2-pages.php'), /'Dispensary\/Completed',/);
    assert.ok(!/\/V2\//.test(classic));
});

test("V2 takes exactly Classic's props, and keeps the same links and the returns section", () => {
    assert.equal(props(v2), props(classic));

    for (const marker of [
        'href="/registration"',
        ':href="dispensary.billingUrl"',
        ':href="dispensary.labelUrl"',
        'target="_blank"',
        '<MedicineReturnsSection',
        'v-if="returns && returns.lines.length"',
    ]) {
        assert.ok(template(classic).includes(marker), `Classic ${marker}`);
        assert.ok(template(v2).includes(marker), `V2 ${marker}`);
    }

    assert.match(
        v2,
        /from '@\/pages\/Dispensary\/V2\/Partials\/MedicineReturnsSection\.vue'/,
    );
});

test('one filled button: Continue to Billing; Print current labels and Return to Registration are outline', () => {
    assert.equal(
        (template(v2).match(/:class="filledButton"/g) ?? []).length,
        1,
    );
    assert.match(
        template(v2),
        /:href="dispensary\.billingUrl"\s+:class="filledButton"/,
    );
    assert.equal(
        (template(v2).match(/:class="outlineButton"/g) ?? []).length,
        2,
    );
});

test('the page says it is read only, and shows the patient and when it was completed', () => {
    assert.match(template(v2), /This case is read only\./);
    assert.match(template(v2), /data-testid="completed-patient"/);
    assert.match(template(v2), /Completed \{\{ dispensary\.completedAt \}\}/);
    assert.match(v2, /\{ title: 'Dispensary completed' \}/);
});

test("the reused returns section keeps Classic's 44 px controls below 1024 (Hakim)", () => {
    const rule =
        'max-lg:[&_[data-slot=button]]:min-h-11 max-lg:[&_[data-slot=select-trigger]]:min-h-11 max-lg:[&_input:not([type=radio]):not([type=checkbox])]:min-h-11 max-lg:[&_select]:min-h-11 max-lg:[&_textarea]:min-h-11';
    assert.ok(classic.includes(rule), 'Classic carries the rule');
    assert.ok(v2.includes(`class="${rule}"`), 'V2 wraps the section in it');
});

// Every top-level `const` of a script, by name, with its full text (up to the next top-level statement).
const consts = (source) => {
    const lines = source.slice(0, source.indexOf('</script>')).split('\n');
    const found = new Map();
    let name = null;
    let body = [];
    const flush = () => {
        if (name) {
            found.set(name, body.join('\n').trimEnd());
        }
    };

    for (const line of lines) {
        const start = /^const ([A-Za-z_$][\w$]*)/.exec(line);
        const otherTopLevel = /^(?:[a-z]|\/\/|type |\}|<)/.test(line) && !start;

        if (start) {
            flush();
            name = start[1];
            body = [line];
        } else if (otherTopLevel && !line.startsWith('}') && name) {
            flush();
            name = null;
            body = [];
        } else if (name) {
            body.push(line);
        }
    }

    flush();

    return found;
};

for (const [file, replaced] of [
    ['MedicineReturnsSection', new Set()],
    // The V2 sheet is the confirm step: its own title, labels and footer (sheetText) and its own confirm (confirmSheet).
    ['ReturnCheckPanel', new Set(['dialog', 'openConfirm', 'amount'])],
]) {
    test(`V2 ${file} keeps every Classic const unchanged (rules, routes, payloads, retry keys)`, () => {
        const classicConsts = consts(
            read(`resources/js/pages/Dispensary/Partials/${file}.vue`),
        );
        const v2Consts = consts(
            read(`resources/js/pages/Dispensary/V2/Partials/${file}.vue`),
        );
        assert.ok(classicConsts.size > 10, 'the parser found Classic consts');

        for (const [name, body] of classicConsts) {
            if (replaced.has(name)) {
                assert.equal(v2Consts.has(name), false, name);
                continue;
            }

            assert.ok(v2Consts.has(name), `V2 keeps ${name}`);
            assert.equal(v2Consts.get(name), body, `V2's ${name} is Classic's`);
        }
    });
}

test('one filled button on the page: Record a return and each check open V2 sheets (Daniel)', () => {
    const section = read(
        'resources/js/pages/Dispensary/V2/Partials/MedicineReturnsSection.vue',
    );
    const panel = read(
        'resources/js/pages/Dispensary/V2/Partials/ReturnCheckPanel.vue',
    );

    for (const source of [section, panel]) {
        const tpl = template(source);
        assert.ok(
            !/<Button(?![^>]*variant="(outline|ghost|link)")[^>]*>/.test(tpl),
            'no filled Button',
        );
        assert.match(tpl, /<V2Dialog/);
    }

    assert.ok(!/OperationalConfirmDialog/.test(panel));
    assert.match(template(section), /@confirm="submit\(line\)"/);
    assert.match(template(panel), /@confirm="confirmSheet"/);
    assert.match(panel, /if \(validate\(\)\) \{\s+submit\(\);/);
});

test("the sheets' words: summary first, footer notes, the buttons off with the reason, Dispose and Refuse red (Daniel)", () => {
    const section = read(
        'resources/js/pages/Dispensary/V2/Partials/MedicineReturnsSection.vue',
    );
    const panel = read(
        'resources/js/pages/Dispensary/V2/Partials/ReturnCheckPanel.vue',
    );
    assert.match(template(section), /data-testid="return-form-summary"/);
    assert.match(template(panel), /data-testid="return-check-summary"/);
    // The decision sentence sits in the pinned footer, beside the button.
    assert.match(
        template(section),
        /<template #footer-note>[\s\S]*?data-testid="return-form-footer"/,
    );
    assert.match(
        template(panel),
        /<template #footer-note>[\s\S]*?data-testid="return-check-footer"/,
    );
    assert.match(
        template(section),
        /Nothing goes back into stock until someone\s+else\s+checks the return\./,
    );
    assert.match(
        panel,
        /`Moves \$\{amountWords\.value\} to \$\{locationName\.value\}\./,
    );
    assert.match(panel, /No stock change/);
    // Stock actions are truly off (aria-disabled through V2Dialog) until valid, with the exact reason.
    assert.match(
        template(section),
        /:confirm-disabled="\s*recordBlockedReason\(line\) !== null\s*"/,
    );
    assert.match(
        section,
        /'Enter a quantity, a reason and a condition to continue\.'/,
    );
    assert.match(
        template(panel),
        /:confirm-disabled="sheetBlockedReason !== null"/,
    );
    assert.match(panel, /`Place all \$\{amountWords\.value\} to continue\.`/);
    assert.match(panel, /'Pick a reason to continue\.'/);
    // Dispose (irreversible) and Refuse are red; Quarantine is standard.
    assert.match(
        panel,
        /title: `Dispose \$\{amountWords\.value\}\?`,\s+confirm: `Dispose \$\{amountWords\.value\}`,\s+busy: 'Disposing…',\s+tone: 'danger' as const,/,
    );
    assert.match(
        panel,
        /confirm: 'Refuse return',\s+busy: 'Refusing…',\s+tone: 'danger' as const,/,
    );
    assert.match(
        panel,
        /confirm: 'Move to returns location',\s+busy: 'Moving…',\s+tone: 'default' as const,/,
    );
    // V2Dialog's own discard guard, judged from what was typed.
    assert.match(template(section), /:dirty="recordTyped"/);
    assert.match(template(panel), /:dirty="sheetDirty"/);
    assert.ok(
        !/form\.isDirty/.test(section.slice(section.indexOf('---- V2 only'))),
        'the retry key must not count as typing (Hakim)',
    );
    assert.match(template(section), /type="submit"\s+class="sr-only"/);
    // A cancelled check forgets its row (Hakim).
    assert.match(panel, /notifySheetClosed\(props\.row\.publicId\);/);
    assert.match(section, /provide\(RETURN_SHEET_CLOSED,/);
});

test('the split quarantine shows "Placed 0 of 18 tablets" from the V2 template (Daniel)', () => {
    const panel = read(
        'resources/js/pages/Dispensary/V2/Partials/ReturnCheckPanel.vue',
    );
    assert.match(template(panel), /\{\{ placedWords \}\}/);
    assert.ok(!/\{\{ placedText \}\}/.test(template(panel)));
    // The unit once: "Placed 1 of 1 tablet" (Daniel).
    assert.match(
        panel,
        /`Placed \$\{quantityNumber\(fromThousandths\(placed\.value\)\)\} of \$\{amountWords\.value\}`/,
    );
});

test('read-only quantities use plural unit words (Daniel)', () => {
    const words = read(
        'resources/js/pages/Dispensary/V2/Partials/quantity-words.ts',
    );
    assert.match(words, /'tablet',/);
    assert.match(
        template(
            read(
                'resources/js/pages/Dispensary/V2/Partials/MedicineReturnsSection.vue',
            ),
        ),
        /quantityWords\(\s*row\.quantity,\s*line\.unit,?\s*\)/,
    );
});

test('after a check, focus goes to the checked row even though the panel unmounts (Hakim)', () => {
    const section = read(
        'resources/js/pages/Dispensary/V2/Partials/MedicineReturnsSection.vue',
    );
    assert.match(
        template(section),
        /@click\.capture="noteCheck\(\$event, row\)"/,
    );
    assert.match(section, /if \(!row \|\| row\.canCheck \|\| !row\.check\) \{/);
    assert.match(
        section,
        /window\.setTimeout\(\(\) => \{\s+document\s+\.querySelector<HTMLElement>\(\s+`\[data-return-id="\$\{row\.publicId\}"\]`,/,
    );
});
