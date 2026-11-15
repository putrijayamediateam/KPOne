import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch();
async function run(reduced, theme){ const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,reducedMotion:reduced?'reduce':'no-preference'}); const p=await ctx.newPage();
 for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.director@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
 const measure=()=>p.evaluate(()=>{const l=document.querySelector('[role=tablist]'); const t=l.querySelector('[aria-selected=true]'); const lr=l.getBoundingClientRect(), tr=t.getBoundingClientRect(); return {label:t.textContent.trim().slice(0,20),scrollLeft:Math.round(l.scrollLeft),max:l.scrollWidth-l.clientWidth,tabLeft:Math.round(tr.left-lr.left),tabRight:Math.round(lr.right-tr.right),pageScrollY:Math.round(scrollY),fadeClear: lr.right-tr.right>=40 || l.scrollLeft>=l.scrollWidth-l.clientWidth-1}});
 // on load with a later tab active
 await p.goto('http://127.0.0.1:8091/registration?tab=billing'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(reduced?200:1200);
 console.log(reduced?'reduced':'normal','on load (5th tab):',JSON.stringify(await measure()));
 if(theme==='dark') await p.evaluate(()=>document.documentElement.classList.add('dark'));
 await p.screenshot({path:`/tmp/tabs-load-${reduced?'reduced':'smooth'}-${theme}.png`,clip:{x:0,y:0,width:390,height:420}});
 // on change: click the last visible tab, then another
 for (const name of [/^Completed/,/^Cancelled/,/^All$/]) { const t=p.getByRole('tab',{name}).first(); if(!await t.count()) continue; await t.scrollIntoViewIfNeeded(); await t.click(); await p.waitForTimeout(reduced?200:1200); console.log('  click',String(name),JSON.stringify(await measure())); }
 await ctx.close(); }
await run(false,'light'); await run(true,'light'); await run(false,'dark');
await b.close();
