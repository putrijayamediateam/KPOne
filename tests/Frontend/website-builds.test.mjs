import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
const read = (file) => readFileSync(new URL(`../../${file}`,import.meta.url),'utf8');
test('Website builds expose only approved private build controls and a plain manifest', () => {
    const source=read('resources/js/components/website/WebsiteBuilds.vue');
    const descriptor=parse(source).descriptor;
    const script=compileScript(descriptor,{id:'website-builds'});
    assert.deepEqual(compileTemplate({id:'website-builds',filename:'WebsiteBuilds.vue',source:descriptor.template.content,compilerOptions:{bindingMetadata:script.bindings}}).errors,[]);

    for(const value of ['Review build only. Nothing is public. Preview and approval come later.','!builds.available','!plan','form.processing','props.builds.releaseHash','plan.value.hash','crypto.randomUUID()','/branch-context','blocked.value','File manifest (not for submission)','file.path','file.sha256.slice(0, 12)']) {
assert.ok(source.replace(/\s+/g, ' ').includes(value),value);
}

    assert.doesNotMatch(source,/href=|v-html|iframe|window\.open|fetch\(|download|type="file"/);
    const routes=read('routes/web.php');
    assert.match(routes,/website\/releases\/\{release\}\/builds.*permission:website.releases.prepare.organisation/);
    const publicScript=read('resources/website-static/site.js');
    assert.doesNotMatch(publicScript,/innerHTML|\beval\s*\(|localStorage|document\.cookie/);
    assert.match(publicScript,/textContent/);
    assert.match(publicScript,/mode: ["']same-origin["']/);
});
