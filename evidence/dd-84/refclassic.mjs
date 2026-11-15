import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch(); const p=await (await b.newContext({viewport:{width:1440,height:900}})).newPage();
for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.director@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
let code=403; await p.route(/\/staff\/13$/,async r=>{ if(r.request().method()==='GET') return r.continue(); await r.fulfill({status:code,contentType:'text/html',body:`<!doctype html><html><body><h1>${code}</h1></body></html>`}); });
for(const c of [403,419]){ code=c; await p.goto('http://127.0.0.1:8091/staff/13/edit'); await p.waitForLoadState('networkidle');
 console.log('data-ui:',await p.evaluate(()=>document.documentElement.dataset.ui||'(classic)'));
 await p.evaluate(()=>{window.__evt=0; document.addEventListener('inertia:httpException',e=>{window.__evt++; window.__prevented=e.defaultPrevented;});});
 await p.getByLabel(/Job title/).fill('Classic '+c); await p.getByRole('button',{name:/Save/}).first().click(); await p.waitForTimeout(1500);
 console.log(c,'Classic: Inertia raw modal',await p.locator('#inertia-error-dialog').count(),'| V2 notice',await p.locator('[data-slot=v2-refused-notice]').count(),'| event seen',await p.evaluate(()=>window.__evt),'| defaultPrevented by us',await p.evaluate(()=>window.__prevented)); }
await b.close();
