import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
import fs from 'fs'; fs.mkdirSync('/tmp/refout',{recursive:true});
const b=await chromium.launch();
const HTML=(c,t)=>`<!doctype html><html><head><title>${c}</title></head><body><h1>${c}</h1><p>${t}</p></body></html>`;
async function login(p){ for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.director@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) return; await p.waitForTimeout(20000);} }
const w=Number(process.argv[2]); const theme=process.argv[3]; const only=process.argv[4]; // 'v2' or 'classic'
const ctx=await b.newContext({viewport:{width:w,height:w===390?844:900},deviceScaleFactor:2}); const p=await ctx.newPage(); await login(p);
let code=403; await p.route(/\/staff\/13($|\/roles$)/,async r=>{ if(r.request().method()==='GET') return r.continue(); await r.fulfill({status:code,contentType:'text/html',body:HTML(code,code===403?'Forbidden':'Page Expired')}); });
const setTheme=()=>p.evaluate(t=>document.documentElement.classList.toggle('dark',t==='dark'),theme);
const out=[];
for (const c of [403,419]) { code=c;
 // form: Staff edit save
 await p.goto('http://127.0.0.1:8091/staff/13/edit'); await p.waitForLoadState('networkidle'); await setTheme();
 await p.getByLabel('Job title').fill('Edited '+c); await p.getByRole('button',{name:'Save profile'}).click(); await p.waitForTimeout(1200);
 const notice=p.locator('[data-slot=v2-refused-notice]'); const modal=await p.locator('#inertia-error-dialog').count();
 out.push(`${w} ${theme} form ${c}: notice ${await notice.count()} | text ${JSON.stringify(await notice.first().innerText().catch(()=>null))} | reload button ${await notice.getByRole('button',{name:'Reload page'}).count()} | raw modal ${modal} | typed kept ${await p.getByLabel('Job title').inputValue()}`);
 await p.screenshot({path:`/tmp/refout/form-${c}-${w}-${theme}.png`});
 // dialog: Manage roles
 await p.goto('http://127.0.0.1:8091/staff/13'); await p.waitForLoadState('networkidle'); await setTheme();
 await p.getByRole('button',{name:'Manage roles'}).first().click(); await p.waitForTimeout(500); await p.getByRole('button',{name:'Save roles'}).click(); await p.waitForTimeout(1200);
 const dn=p.locator('[role=dialog] [data-slot=v2-refused-notice]'); const pageNotice=await p.locator('main [data-slot=v2-refused-notice], [data-test] ~ [data-slot=v2-refused-notice]').count();
 out.push(`${w} ${theme} dialog ${c}: in-dialog notice ${await dn.count()} | text ${JSON.stringify(await dn.first().innerText().catch(()=>null))} | reload ${await dn.getByRole('button',{name:'Reload page'}).count()} | notices on page total ${await p.locator('[data-slot=v2-refused-notice]').count()} | dialog still open ${await p.locator('[role=dialog]').count()} | raw modal ${await p.locator('#inertia-error-dialog').count()}`);
 await p.screenshot({path:`/tmp/refout/dialog-${c}-${w}-${theme}.png`});
}
console.log(out.join('\n')); await b.close();
