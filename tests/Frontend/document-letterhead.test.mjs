import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('existing invoice and receipt print use escaped current letterhead, optional lines and A4 CSS', () => {
    const source=read('resources/js/pages/Billing/Print.vue');
    const descriptor=parse(source).descriptor;
    assert.deepEqual(compileTemplate({source:descriptor.template.content, filename:'Print.vue',id:'doc01'}).errors,[]);
    assert.ok(compileScript(descriptor,{id:'doc01'}));
    assert.match(source,/v-if="letterhead.logo"/);
    assert.match(source,/v-if="letterhead.details.phone"/);
    assert.match(source,/v-if="letterhead.details.email"/);
    assert.match(source,/v-if="letterhead.footerText"/);
    assert.match(source,/\{\{ letterhead.footerText \}\}/);
    assert.doesNotMatch(source,/v-html|innerHTML/);
    assert.match(source,/size: A4/);
    assert.match(source,/table-header-group/);
    assert.match(source,/window.print\(\)/);
    assert.match(source,/Recording reversed — this is not a refund receipt/);
    assert.match(source,/myr\(receipt.amountSen\)/);
    assert.match(source,/billing.invoice.state.total/);
});
