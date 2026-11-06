import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-7a payroll setup (TEAM.md decision 43; HR-PLAN 14.2 and 14.6, Daniel's early look folded in).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    page: 'resources/js/pages/HR/Payroll.vue',
    profile: 'resources/js/components/hr/payroll/ProfileSheet.vue',
    table: 'resources/js/components/hr/payroll/TableSheet.vue',
    rm: 'resources/js/components/hr/payroll/RmInput.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const types = read('resources/js/components/hr/payroll/types.ts');
const template = (key) => parse(source[key]).descriptor.template.content;

test('the new page and components compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('every payroll page carries the accountant line, and nothing is red', () => {
    assert.match(
        source.page,
        /To confirm with the clinic's accountant before real use\. Synthetic data only\./,
    );
    assert.match(template('page'), /<SyntheticNote :text="ACCOUNTANT"/);
    assert.match(
        types,
        /status === 'confirmed' \|\| status === 'checked' \? 'success' : 'neutral'/,
    );

    for (const key of Object.keys(files)) {
        assert.doesNotMatch(
            source[key],
            /tone="danger"|'danger'|text-red|bg-red|destructive\s*$/m,
            files[key],
        );
    }
});

test('money is shown with the shared myr() and typed with a fixed RM prefix', () => {
    assert.match(source.page, /import \{ myr \} from '@\/types\/billing';/);
    assert.match(template('rm'), /aria-hidden="true"\s*>RM<\/span/);
    assert.match(template('rm'), /inputmode="decimal"/);
    assert.match(template('profile'), /In RM\. For example 2,500\.00/);
    assert.match(types, /day: 'numeric',\s*month: 'short',\s*year: 'numeric'/);
});

test('the table sheet names each band, keeps its error under the row and ships no figure', () => {
    const t = template('table');
    assert.match(t, /:aria-label="`Remove band \$\{i \+ 1\}`"/);
    assert.match(
        t,
        /errors\.value\[`rows\.\$\{i\}\.\$\{field\}`\]|rowError\(i, 'from'\)/,
    );
    assert.match(t, /rowError\(i, 'employee'\)/);
    assert.match(t, /Leave\s+"To" empty on the last band for "and above"\./);
    assert.doesNotMatch(source.table, /\b(11|13|0\.5|0\.2)\s*%/);
});

test('decisions are outline buttons; the person who entered it sees why they cannot decide', () => {
    const page = template('page');

    for (const test of [
        'profile-confirm',
        'profile-reject',
        'table-check',
        'table-reject',
    ]) {
        assert.match(
            page,
            new RegExp(`variant="outline"[\\s\\S]{0,160}data-test="${test}"`),
            test,
        );
    }

    assert.match(page, /\{\{ person\.waiting\.decideRefusal \}\}/);
    assert.match(page, /\{\{ t\.decideRefusal \}\}/);
    assert.match(
        page,
        /\(now\s+\{\{\s*money\(person\.current\.rateSen\)\s*\}\}\s*\)/,
    );
});
