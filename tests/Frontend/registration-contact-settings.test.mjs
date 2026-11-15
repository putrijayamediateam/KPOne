import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
const read = (p) => readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const source = read('resources/js/lib/public-intake-form.ts');
const js = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;
const { validateIntakeStep, reviewRows, intakeSubmission } = runInNewContext(`${js.replaceAll('export ', '')}\n({validateIntakeStep,reviewRows,intakeSubmission})`);
const options = { minorAge:18, today:new Date('2026-10-16'), collectContactDetails:true, requireEmail:true, requireAddress:true };
const form = { submission_type:'patient', full_name:'Synthetic Patient', sex:'unknown', date_of_birth:'1990-01-01', mobile_phone:'+60123456789', identifier_type:'passport', identifier_value:'SYN-SET04B', identifier_issuing_country_code:'MY', visit_kind:'otc', chief_complaint:'', complaint_duration:'', email:'person@set04b.test', address_line_1:'42 Synthetic Street', address_line_2:'', city:'Synthetic City', state:'Synthetic State', postcode:'50000', country_code:'MY' };

test('QR per-step checks require every address component except line 2 and validate optional supplied values', () => {
    assert.deepEqual(Object.keys(validateIntakeStep(2,form,options)), []);

    for (const field of ['email','address_line_1','city','state','postcode','country_code']) {
        assert.ok(validateIntakeStep(2,{...form,[field]:''},options)[field], field);
        assert.equal(validateIntakeStep(2,{...form,[field]:''},{...options,requireEmail:false,requireAddress:false})[field],undefined);
    }

    for (const [field,value] of [['email','bad'], ['email','a'.repeat(256)], ['address_line_1','a'.repeat(256)], ['address_line_2','a'.repeat(256)], ['city','a'.repeat(101)], ['state','a'.repeat(101)], ['postcode','a'.repeat(21)], ['country_code','12']]) {
        assert.ok(validateIntakeStep(2,{...form,[field]:value},{...options,requireEmail:false,requireAddress:false})[field]);
    }

    assert.equal(validateIntakeStep(2,{...form,email:''},{...options,collectContactDetails:false}).email,undefined);
    assert.ok(reviewRows(form,[],true).some((r)=>r.label==='E-mel' && r.value===form.email));
    assert.equal(reviewRows(form,[],false).some((r)=>r.label==='E-mel'),false);
});

test('all new contact fields compile with labels, limits, required indicators and linked errors', () => {
    const path='resources/js/components/patient/RegistrationContactFields.vue';
    const s=read(path); const descriptor=parse(s).descriptor;
    assert.deepEqual(compileTemplate({id:'contacts',source:descriptor.template.content,filename:path}).errors,[]);
    assert.ok(compileScript(descriptor,{id:'contacts'}));
    assert.match(s, /v-model="fields\[field\]"/);
    assert.match(s, /:aria-describedby=/);
    assert.match(s, /field !== 'address_line_2' && props.requireAddress/);

    for (const field of ['email','address_line_1','address_line_2','city','state','postcode','country_code']) {
assert.ok(s.includes(field));
}

    const quick=read('resources/js/pages/Registration/Create.vue');
    assert.match(quick, /:require-email="requirements.requireEmail"/);
    assert.match(quick, /:require-address="requirements.requireAddress"/);
    const patient=read('resources/js/components/patient/PatientFormFields.vue');
    assert.match(patient, /newPatient && requirements\?\.requireEmail/);
    assert.match(patient, /newPatient && requirements\?\.requireAddress/);
});

test('public collection is session-bound, old forms omit new fields and both consent surfaces remain explicitly draft', () => {
    const page=read('resources/js/pages/PublicCheckIn/Show.vue');
    assert.match(page,/v-if="collectContactDetails"/);
    assert.match(page,/form.privacy_notice_version = exchanged.privacyNoticeVersion/);
    assert.match(page,/collectContactDetails.value = exchanged.collectContactDetails/);
    assert.equal((page.match(/Notis privasi — draf UAT/g)||[]).length,2);
    assert.match(page,/E-mel dan alamat/);
    assert.match(page.replace(/\s+/g, ' '),/rekod pesakit tidak dipadam selepas 30 hari/);
    assert.match(read('resources/js/pages/RegistrationReview/Show.vue'), /v-if="intake.collectContactDetails"/);
    assert.match(read('config/public-intake.php'), /uat-draft-set04b-email-address-v2/);
});


test('country is a real MY default and legacy QR submissions omit contact defaults', () => {
    for (const path of ['Registration/Create.vue', 'PublicCheckIn/Show.vue']) {
        assert.match(read(`resources/js/pages/${path}`), /country_code: 'MY'/);
    }

    assert.equal(intakeSubmission(form,true).country_code,'MY');
    const legacy=intakeSubmission(form,false);

    for (const field of ['email','address_line_1','address_line_2','city','state','postcode','country_code']) {
        assert.equal(Object.hasOwn(legacy,field),false);
    }

    assert.equal(legacy.full_name,form.full_name);
    assert.equal(form.country_code,'MY');
    assert.match(read('resources/js/pages/PublicCheckIn/Show.vue'), /intakeSubmission\(form, collectContactDetails.value\)/);
});
