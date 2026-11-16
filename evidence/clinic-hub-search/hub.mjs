import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
import fs from 'fs'; fs.mkdirSync('/tmp/hubout',{recursive:true});
const b=await chromium.launch();
const email=process.argv[2]||'demo.ca_supervisor@kpone.test';
async function s(w,theme){ const ctx=await b.newContext({viewport:{width:w,height:w===390?844:900},deviceScaleFactor:2}); const p=await ctx.newPage();
 for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill(email); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
 await p.goto('http://127.0.0.1:8091/clinic-management'); await p.waitForLoadState('networkidle');
 if(theme==='dark') await p.evaluate(()=>document.documentElement.classList.add('dark'));
 const vis=()=>p.evaluate(()=>({cards:[...document.querySelectorAll('[data-testid=clinic-management-cards] > section')].filter(e=>e.offsetParent!==null).length,links:[...document.querySelectorAll('[data-testid=clinic-management-cards] li')].filter(e=>e.offsetParent!==null).length,totalInDom:document.querySelectorAll('[data-testid=clinic-management-cards] li').length,empty:!!document.querySelector('[data-testid=hub-empty]'),status:document.getElementById('hub-find-result').textContent.trim(),ui:document.documentElement.dataset.ui||'classic'}));
 const input=p.locator('#hub-find');
 console.log(w,theme,email,'start',JSON.stringify(await vis()));
 for(const q of ['panel','stock','daily cash','refund','zzz','']){ await input.fill(q); await p.waitForTimeout(250); console.log(w,theme,JSON.stringify(q),JSON.stringify(await vis())); if(q==='panel') await p.screenshot({path:`/tmp/hubout/hub-panel-${w}-${theme}.png`}); if(q==='zzz') await p.screenshot({path:`/tmp/hubout/hub-empty-${w}-${theme}.png`}); }
 await ctx.close(); }
await s(1440,'light'); await s(1440,'dark'); await s(390,'light'); await s(390,'dark'); await b.close();
