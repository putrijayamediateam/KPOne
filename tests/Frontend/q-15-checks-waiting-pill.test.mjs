import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Q-15 (owner decision 21 Nov 2026; Daniel's note): "N to check" after the doctor's Consultation Completed tab label, only when
// N is above 0: a warning-token pill, visible text aria-hidden plus an sr-only sentence, not interactive.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const tabs = read('resources/js/components/ui/tabs/OperationalTabs.vue');
const queue = read('resources/js/pages/Queue/Index.vue');
const types = read('resources/js/types/queue.ts');
const service = read('app/Domain/Queue/Services/QueueDirectoryService.php');
const template = tabs.slice(tabs.indexOf('<template>'));

test('the shared tabs render the pill only when a tab has a badge, after the count, with an sr-only sentence', () => {
    assert.match(tabs, /badge\?: \{ text: string; srLabel: string \}/);
    const pill = template.slice(template.indexOf('v-if="tab.badge"'));
    assert.ok(
        template.indexOf('v-if="tab.count !== undefined"') <
            template.indexOf('v-if="tab.badge"'),
    );
    assert.match(pill, /border border-warning\/25 bg-warning\/10/);
    assert.match(pill, /text-warning-strong/);
    assert.match(
        pill,
        /<span aria-hidden="true">\{\{ tab\.badge\.text \}\}<\/span>/,
    );
    assert.match(
        pill,
        /<span class="sr-only">\{\{ tab\.badge\.srLabel \}\}<\/span>/,
    );
    // not a second control: no button, link, handler or live region inside the pill
    const body = pill.slice(0, pill.indexOf('</button>'));

    for (const bad of [
        '<button',
        '<a ',
        '@click',
        'aria-live',
        'role="status"',
        'tabindex',
    ]) {
        assert.ok(!body.includes(bad), `${bad} must not be in the pill`);
    }

    // the existing tab classes the other tests pin stay as they are
    assert.match(
        tabs,
        /class="relative flex h-10 shrink-0 max-lg:h-11 max-sm:px-2\.5/,
    );
    assert.match(tabs, /after:bg-brand/);
});

test('the Queue page gives the Consultation Completed tab a badge only when checksWaiting is above 0', () => {
    // inline in the tabs computed: tab-counts-1.test.mjs evaluates that computed on its own, so it cannot call a helper
    assert.match(
        queue,
        /badge:\s*\(live\.value\.checksWaiting \?\? 0\) > 0\s*\?\s*\{\s*text: `\$\{live\.value\.checksWaiting\} to check`/,
    );
    assert.match(
        queue,
        /srLabel: `\$\{live\.value\.checksWaiting\} medicine \$\{live\.value\.checksWaiting === 1 \? 'check' : 'checks'\} waiting for you`/,
    );
    const removed = queue.slice(
        queue.indexOf("label: 'Consultation Completed'"),
    );
    assert.ok(
        removed.indexOf('badge:') > 0 &&
            removed.indexOf('badge:') < removed.indexOf('\n]);'),
        'only that tab has the badge',
    );
    assert.equal((queue.match(/badge:\s*\(live/g) ?? []).length, 1);
    assert.ok(!queue.includes('toCheckBadge'));
});

test('the count rides the poll as its own top-level key and tabCounts keeps its shape', () => {
    assert.match(types, /checksWaiting\?: number;/);
    // the payload key is the value worked out above it: reused from the flags already read when the Completed tab shows every
    // removed entry, otherwise one aggregate count for the active branch
    assert.match(service, /'checksWaiting' => \$checksWaiting,/);
    assert.match(
        service,
        /\$checksWaiting = \$showsAllRemoved\s*\?\s*count\(\$checkWaiting\)\s*:\s*\$this->dispensaryAttention->checksWaitingCount\(\$actor, \$branch\);/,
    );
    assert.match(
        service,
        /\$showsAllRemoved = \$status === QueueEntry::STATUS_REMOVED/,
    );
    assert.match(service, /! filled\(\$criteria\['priority'\] \?\? null\)/);
    assert.match(
        service,
        /\$removedEntries->count\(\) >= \$counts\['tabs'\]\['removed'\]/,
    );
    // tabCounts keeps its shape: the key is separate from it
    assert.match(service, /'tabCounts' => \$counts\['tabs'\],/);
    const dynamic = service.slice(
        service.indexOf("'tabCounts' => $counts['tabs']"),
    );
    assert.ok(
        dynamic.indexOf("'checksWaiting'") <
            dynamic.indexOf("'waiting' => ['data'"),
    );
});
