import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Billing/PayLaterCollections.vue');
const service = read(
    'app/Domain/Visit/Billing/Services/PayLaterCollectionService.php',
);
const cards = page
    .split('data-testid="pay-later-card"')[1]
    .split('</article>')[0];
const table = page.split('>Still owed</th>')[1];

test('the server flags bills on hold for correction or with out-of-date source, beside the rows', () => {
    assert.ok(
        service.includes(
            "->selectRaw('(i.correction_hold or i.source_stale) as review_required')",
        ),
    );
    assert.match(service, /'reviewRequired' => \$rows->filter/);
    assert.ok(page.includes('reviewRequired?: string[];'));
    assert.match(
        page,
        /const needsReview = \(row: \{ visitNumber: string \}\) =>\s+props\.collections\.reviewRequired\?\.includes\(row\.visitNumber\) \?\? false;/,
    );
});

test('the card and the table row show a neutral "Review required" tag beside Still owed', () => {
    for (const [name, part, before] of [
        ['card', cards, 'Still owed</p>'],
        ['table', table, '{{ myr(owedOf(row)) }}'],
    ]) {
        const tag = part.indexOf('data-testid="bill-review-required"');
        assert.ok(tag > 0, `${name} has the tag`);
        assert.ok(
            part.includes('v-if="needsReview(row)"'),
            `${name} shows it only when flagged`,
        );
        const badge = part.slice(part.lastIndexOf('<StatusBadge', tag), tag);
        assert.ok(badge.includes('label="Review required"'), `${name} label`);
        assert.ok(
            badge.includes('tone="neutral"'),
            `${name} is neutral, not amber`,
        );
        assert.match(
            part,
            /class="sr-only"\s*>: the amount may change; open billing\s+before\s+collecting\.<\/span/,
        );

        if (name === 'card') {
            assert.ok(tag < part.indexOf(before), 'card: above Still owed');
        } else {
            assert.ok(tag > part.indexOf(before), 'table: under the amount');
        }
    }
});

test('the page explains the tag only when a bill on the page has it, and amounts are not muted', () => {
    const flat = page.replace(/\s+/g, ' ');
    assert.ok(
        flat.includes(
            '<template v-if="collections.reviewRequired?.length"> Review required: the invoice is being corrected or its items changed, so the amount may change. Open billing before asking the patient to pay.</template >',
        ),
    );
    assert.ok(!/opacity|line-through/.test(page));
});
