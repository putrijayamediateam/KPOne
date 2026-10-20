import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/Dispensary/Labels.vue');

test('label page compiles, consumes server lines and dimensions, and never measures or truncates content', () => {
    const {descriptor} = parse(source);
    assert.ok(compileScript(descriptor, {id:'labels'}));
    assert.deepEqual(compileTemplate({id:'labels',filename:'Labels.vue',source:descriptor.template.content}).errors, []);
    assert.match(source,/labels.pageSize.width/);
    assert.match(source,/labels.pageSize.height/);
    assert.match(source,/item.lines/);
    assert.doesNotMatch(source,/getBoundingClientRect|scrollHeight|clientHeight|ResizeObserver|text-overflow|line-clamp|v-html|localStorage|sessionStorage/);
    assert.match(source,/labels.blocked.length/);
    assert.match(source,/Printing blocked/);
    assert.match(source,/background: #fff !important/);
    assert.match(source,/color: #171717 !important/);
});

const printHarness = (current = true) => {
    const {descriptor} = parse(source);
    const body = descriptor.scriptSetup.content.slice(descriptor.scriptSetup.content.indexOf('const printLabels = async'));
    const calls = {reads:0, prints:0};
    const busy = {value:false}; const changed = {value:false}; const retired = {value:false}; const error = {value:''};
    const props = {labels:{blocked:[],token:'a'.repeat(64),branchId:1}};
    const fn = new Function('props','busy','changed','retired','error','fetch','window','URL',body+';return printLabels;')(
        props,busy,changed,retired,error,
        async (url, options) => {
 calls.reads++; assert.equal(url.searchParams.get('verify'),'1'); assert.equal(options.cache,'no-store'); assert.equal(options.method,undefined);

 return {ok:current,json:async()=>({current})};
},
        {location:{href:'http://127.0.0.1/dispensary/synthetic/labels'},print:()=>calls.prints++},URL,
    );

    return {fn,calls,props,busy,changed,retired,error};
};

test('actual print action revalidates once and only opens browser print for a current authorised source', async()=>{
    const h=printHarness(); await h.fn(); assert.equal(h.calls.reads,1); assert.equal(h.calls.prints,1); assert.equal(h.busy.value,false);
    const stale=printHarness(false); await stale.fn(); assert.equal(stale.calls.prints,0); assert.equal(stale.retired.value,true); assert.match(stale.error.value,/changed/);
});

test('blocked and switched-branch labels cannot request a print', async()=>{
    const h=printHarness(); h.props.labels.blocked=[{errors:['Instruction: 385 characters; label limit 384.']}]; await h.fn(); assert.equal(h.calls.reads,0);
    h.props.labels.blocked=[]; h.changed.value=true; await h.fn(); assert.equal(h.calls.prints,0); assert.equal(h.calls.reads,0);
});

test('line editors show server budgets without lowering clinical storage maxlengths',()=>{
    const show=read('resources/js/pages/Dispensary/Show.vue');

    for(const field of ['dosage','frequency','duration','route','instruction','precaution']) {
assert.match(show,new RegExp('labelLimits\\?\\.'+field));
}

    assert.match(show,/maxlength="2000"/); assert.match(show,/maxlength="500"/);
    assert.match(show,/dispensary.canPrintLabels/);
    assert.match(read('resources/js/pages/Dispensary/Completed.vue'),/dispensary.labelUrl/);
});

 test('identity is emphasised and mixed selections expose individual previews without silently printing a subset',()=>{
 assert.match(source,/item.emphasis.includes\(n\)/);
 assert.match(source,/font-weight: 700/);
 assert.match(source,/10pt\/4mm/);
 assert.match(source,/Individually printable labels/);
 assert.match(source,/:href="item.previewUrl"/);
 assert.match(source,/v-if="!labels.blocked.length" aria-label="Medicine labels"/);
 });
