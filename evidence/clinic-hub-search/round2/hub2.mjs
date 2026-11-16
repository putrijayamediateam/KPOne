import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
import fs from 'fs'; fs.mkdirSync('/tmp/hubout2',{recursive:true});
const b=await chromium.launch();
const lum=(c)=>{const [r,g,bl]=c.match(/\d+(\.\d+)?/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4)});return 0.2126*r+0.7152*g+0.0722*bl};
const ratio=(a,b)=>{const [x,y]=[lum(a),lum(b)].sort((p,q)=>q-p);return ((x+0.05)/(y+0.05)).toFixed(2)};
async function s(w,theme){ const ctx=await b.newContext({viewport:{width:w,height:w===390?844:900},deviceScaleFactor:2}); const p=await ctx.newPage();
 for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.finance_officer@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
 await p.goto('http://127.0.0.1:8091/clinic-management'); await p.waitForLoadState('networkidle');
 if(theme==='dark') await p.evaluate(()=>document.documentElement.classList.add('dark'));
 const input=p.locator('#hub-find');
 await input.fill('panel'); await p.waitForTimeout(250);
 const st=await p.locator('[role=status]').innerText(); const clear=await p.locator('[data-testid=hub-clear]').boundingBox(); const col=await p.locator('[data-testid=hub-clear] svg').evaluate(e=>getComputedStyle(e).color);
 console.log(w,theme,'panel: status',JSON.stringify(st),'| clear',Math.round(clear.width)+'x'+Math.round(clear.height),'| icon colour',col,'| describedby',await input.getAttribute('aria-describedby'));
 await p.screenshot({path:`/tmp/hubout2/hub-panel-${w}-${theme}.png`});
 await input.fill('zzz'); await p.waitForTimeout(250);
 const e=await p.evaluate(()=>{const el=document.querySelector('[data-testid=hub-empty]'); const g=document.querySelector('[data-testid=clinic-management-cards]'); const cs=getComputedStyle(el); const bg=(()=>{let n=el;while(n){const c=getComputedStyle(n).backgroundColor; if(c&&c!=='rgba(0, 0, 0, 0)')return c; n=n.parentElement;}return 'rgb(255,255,255)'})(); return {text:el.innerText,color:cs.color,bg,gridDisplay:getComputedStyle(g).display,gridH:Math.round(g.getBoundingClientRect().height),statusText:document.querySelector('[role=status]').innerText.replace(/\n+/g,' | ')}});
 console.log(w,theme,'empty:',JSON.stringify(e),'| contrast',ratio(e.color,e.bg));
 await p.screenshot({path:`/tmp/hubout2/hub-empty-${w}-${theme}.png`});
 // clear button works and returns focus
 await p.locator('[data-testid=hub-clear]').click(); await p.waitForTimeout(200); console.log(w,theme,'after Clear: value',JSON.stringify(await input.inputValue()),'| focus on input',await input.evaluate(e=>e===document.activeElement),'| status',JSON.stringify(await p.locator('[role=status]').innerText()),'| clear button gone',await p.locator('[data-testid=hub-clear]').count()===0);
 await ctx.close(); }
for(const [w,t] of [[1440,'light'],[1440,'dark'],[390,'light'],[390,'dark']]) await s(w,t);
await b.close();
