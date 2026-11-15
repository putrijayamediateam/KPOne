import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch();
async function run(reduced){ const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,reducedMotion:reduced?'reduce':'no-preference'}); const p=await ctx.newPage();
for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.panel_officer@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
const m=()=>p.evaluate(()=>{const l=document.querySelector('[role=tablist]'); const t=l.querySelector('[aria-selected=true]'); const lr=l.getBoundingClientRect(), tr=t.getBoundingClientRect(); return {data:document.documentElement.dataset.ui,label:t.textContent.trim().slice(0,14),scrollLeft:Math.round(l.scrollLeft),max:l.scrollWidth-l.clientWidth,tabLeft:Math.round(tr.left-lr.left),tabRight:Math.round(lr.right-tr.right),pageY:Math.round(scrollY)}});
await p.goto('http://127.0.0.1:8091/panel-claims?tab=rejected'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(reduced?200:1500);
console.log(reduced?'reduced':'smooth','on load 5th tab:',JSON.stringify(await m()));
await p.screenshot({path:`/tmp/tabs-load-${reduced?'reduced':'smooth'}.png`,clip:{x:0,y:0,width:390,height:420}});
await p.getByRole('tab',{name:/^Received/}).click(); await p.waitForTimeout(reduced?300:1800); console.log('  after change to last tab:',JSON.stringify(await m()));
await ctx.close(); }
await run(false); await run(true); await b.close();
