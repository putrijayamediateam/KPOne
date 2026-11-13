import { chromium, devices } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch();
async function session(opts){ const ctx=await b.newContext(opts); const p=await ctx.newPage();
 for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.director@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);} return p; }
const H=(p)=>p.evaluate(()=>history.length);
const hook=(p)=>p.evaluate(()=>{window.__push=0; const o=history.pushState.bind(history); history.pushState=(...a)=>{window.__push++; return o(...a)};});
const dlg=(p)=>p.locator('[role=dialog],[role=alertdialog]').count();
for (const [name,opts] of [['desktop',{viewport:{width:1440,height:900}}],['phone (Chromium mobile emulation)',{...devices['iPhone 13']}]]) {
 console.log('=====',name);
 const p=await session(opts);
 const edit=async()=>{ await p.goto('http://127.0.0.1:8091/staff/13'); await p.waitForLoadState('networkidle'); await hook(p); await p.getByRole('link',{name:'Edit profile'}).click(); await p.waitForURL(/edit$/); await p.waitForLoadState('networkidle'); };
 // 1. clean V2 page: Back / Forward are plain Inertia, no extra entries
 await edit(); const h0=await H(p); await p.evaluate(()=>window.__push=0); await p.goBack(); await p.waitForTimeout(700); await p.goForward(); await p.waitForTimeout(700);
 console.log('1 clean V2: Back+Forward ->',p.url().replace(/.*8091/,''),'| pushState by guard:',await p.evaluate(()=>window.__push),'| history.length',h0,'->',await H(p),'| dialogs',await dlg(p));
 // dirty: Back asks; scroll + value kept
 await p.getByLabel('Job title').fill('Typed once'); await p.evaluate(()=>window.scrollTo(0,200)); const sy=await p.evaluate(()=>scrollY); const hb=await H(p);
 await p.goBack(); await p.waitForTimeout(600);
 console.log('2 dirty Back: dialogs',await dlg(p),'| url',p.url().replace(/.*8091/,''));
 await p.getByRole('button',{name:'Keep editing'}).click(); await p.waitForTimeout(300);
 console.log('3 Keep editing: value',await p.getByLabel('Job title').inputValue(),'| scroll',sy,'->',await p.evaluate(()=>scrollY),'| history.length',hb,'->',await H(p));
 // several Keep editing, then Back still asks, and Discard goes to where Back was heading
 for (let i=0;i<3;i++){ await p.goBack(); await p.waitForTimeout(500); await p.getByRole('button',{name:'Keep editing'}).click(); await p.waitForTimeout(250);} console.log('4 after 3 more Keep editing: history.length',await H(p),'| url',p.url().replace(/.*8091/,''),'| value',await p.getByLabel('Job title').inputValue());
 await p.goBack(); await p.waitForTimeout(500); await p.getByRole('button',{name:'Discard changes'}).click(); await p.waitForTimeout(1200);
 console.log('5 Discard -> ',p.url().replace(/.*8091/,''),'(Back was heading to /staff/13)');
 // Forward while dirty
 await edit(); await p.goBack(); await p.waitForTimeout(700); await p.goForward(); await p.waitForTimeout(700); console.log('6 now on edit via Forward, URL',p.url().replace(/.*8091/,'')); await p.getByLabel('Job title').fill('Typed again'); await p.goBack(); await p.waitForTimeout(600); await p.getByRole('button',{name:'Keep editing'}).click(); await p.waitForTimeout(300);
 // Forward-dirty: go back (discard) then forward to edit dirty page is not reachable; instead test Forward from a dirty page with a forward entry
 await p.getByRole('button',{name:'Save profile'}).count();
 // 7 beforeunload
 const bu=await p.evaluate(()=>{const e=new Event('beforeunload',{cancelable:true}); window.dispatchEvent(e); return e.defaultPrevented;}); console.log('7 beforeunload prevented while dirty:',bu);
 await p.close();
}
await b.close();
