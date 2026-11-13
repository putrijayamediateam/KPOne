import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch(); const p=await (await b.newContext({viewport:{width:1440,height:900}})).newPage();
for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.director@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
const U=()=>p.url().replace(/.*8091/,''); const dlg=()=>p.locator('[role=dialog],[role=alertdialog]').count();
await p.goto('http://127.0.0.1:8091/staff/13'); await p.waitForLoadState('networkidle');
if(await p.getByText(/Back to Classic/).count()){await p.getByText(/Back to Classic/).first().click(); await p.waitForTimeout(2500);} await p.goto('http://127.0.0.1:8091/staff/13'); await p.waitForLoadState('networkidle');
console.log('data-ui:',await p.evaluate(()=>document.documentElement.dataset.ui||'(classic)'));
const link=p.getByRole('link',{name:/Edit/}).first(); await link.click(); await p.waitForURL(/edit$/); await p.waitForLoadState('networkidle');
console.log('Classic edit page, V2LeaveGuard present:',await p.locator('[data-slot=v2-dialog-discard]').count());
await p.evaluate(()=>{window.__push=0; window.__probe=0; const o=history.pushState.bind(history); history.pushState=(...a)=>{window.__push++; return o(...a)}; window.addEventListener('popstate',()=>window.__probe++,true);});
await p.goBack(); await p.waitForTimeout(800);
console.log('clean Back ->',U(),'| later listener received popstate (not stopped):',await p.evaluate(()=>window.__probe),'| guard pushState:',await p.evaluate(()=>window.__push),'| dialogs',await dlg());
await p.goForward(); await p.waitForURL(/edit$/); await p.waitForLoadState('networkidle'); await p.waitForTimeout(500);
await p.evaluate(()=>{window.__push=0; window.__probe=0;});
const f=p.getByLabel(/Job title/); await f.fill('Typed in Classic'); await p.waitForTimeout(200);
await p.goBack(); await p.waitForTimeout(800);
console.log('dirty Back ->',U(),'| later listener received popstate:',await p.evaluate(()=>window.__probe),'| guard pushState:',await p.evaluate(()=>window.__push),'| dialogs',await dlg());
await b.close();
