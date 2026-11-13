import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,hasTouch:true,isMobile:true}); const p=await ctx.newPage();
for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.director@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
await p.goto('http://127.0.0.1:8091/staff/13'); await p.waitForLoadState('networkidle');
if(await p.evaluate(()=>document.documentElement.dataset.ui)!=='v2'){ const t=p.getByText(/Try the new layout/); console.log('switching to V2, link count',await t.count()); await t.first().click(); await p.waitForTimeout(2500); await p.goto('http://127.0.0.1:8091/staff/13'); await p.waitForLoadState('networkidle'); }
console.log('data-ui',await p.evaluate(()=>document.documentElement.dataset.ui));
await p.getByRole('button',{name:'Add assignment'}).first().tap(); await p.waitForTimeout(500);
// park the pointer well away from the footer, then measure
await p.mouse.move(5,5); await p.waitForTimeout(300);
const cancel=p.locator('[role=dialog]').getByRole('button',{name:'Cancel'});
console.log('hovered:',await cancel.evaluate(e=>e.matches(':hover')),'| bg:',await cancel.evaluate(e=>getComputedStyle(e).backgroundColor),'| card bg:',await p.locator('[role=dialog]').evaluate(e=>getComputedStyle(e).backgroundColor));
await p.screenshot({path:'/tmp/cancel-nohover-390.png'});
await cancel.hover(); await p.waitForTimeout(300);
console.log('hovered:',await cancel.evaluate(e=>e.matches(':hover')),'| bg:',await cancel.evaluate(e=>getComputedStyle(e).backgroundColor));
await b.close();
