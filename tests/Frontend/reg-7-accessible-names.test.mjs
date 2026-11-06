import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// REG-7: names that screen readers can rely on. The QR back buttons carry their own name (the icon is hidden), and
// every OperationalSelect's open option list is named like its trigger.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('each public QR back button is named "Kembali" on the button, with the arrow icon hidden', () => {
    const page = read('resources/js/pages/PublicCheckIn/Show.vue');
    const backButtons = [
        ...page.matchAll(
            /<button\b[^>]*@click="back"[^>]*>([\s\S]*?)<\/button/g,
        ),
    ];
    assert.equal(backButtons.length, 4);

    for (const [whole, inner] of backButtons) {
        const open = whole.slice(0, whole.indexOf('>') + 1);
        assert.match(open, /aria-label="Kembali"/);
        assert.match(inner, /<ArrowLeft[\s\S]*?aria-hidden="true"/);
        assert.doesNotMatch(inner, /aria-label=/);
    }
});

test('the shared select names its open option list the same way as its trigger', () => {
    const select = read(
        'resources/js/components/ui/select/OperationalSelect.vue',
    );
    // The trigger uses aria-labelledby when a caller passes labelledby, otherwise aria-label; the list matches it.
    assert.match(
        select,
        /:aria-label="labelledby \? undefined : label"\s+:aria-labelledby="labelledby"/,
    );
    const content = select.match(/<SelectContent[\s\S]*?>/)?.[0] ?? '';
    assert.match(content, /:aria-label="labelledby \? undefined : label"/);
    assert.match(content, /:aria-labelledby="labelledby"/);
});
