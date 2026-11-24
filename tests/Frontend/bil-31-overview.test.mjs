import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-31: the Director and Finance overview tab (owner answers 1A and 8A).

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );

for (const page of ['Today', 'Report']) {
    test(`BIL-31: the ${page} page shows the Overview tab only with the permission prop, after Today`, () => {
        const source = read(`resources/js/pages/Insights/${page}.vue`);
        assert.match(source, /canOverview\?: boolean;/);
        const today = source.indexOf("{ id: 'today', title: 'Today'");
        const overview = source.indexOf(
            "? [{ id: 'overview', title: 'Overview', href: '/insights/overview' }]",
        );
        assert.ok(today > 0 && overview > today, 'Overview after Today');
        assert.match(source, /\.\.\.\(props\.canOverview/);
    });
}

test('BIL-31: the overview shows counts and amounts only, with no red or green', () => {
    const page = read('resources/js/pages/Insights/Overview.vue');
    assert.doesNotMatch(page, /text-(red|green|destructive|success)/);
    assert.doesNotMatch(page, /patientName|patient_id|invoiceNumber|visitNumber|staffName/);
    assert.match(page, /Nothing is waiting\./);
    assert.match(
        page,
        /All Panel bills to date\. The Panel summary report covers at most 31 days\./,
    );
});
