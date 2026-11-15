import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch();
for(const w of [390,1440]) for(const theme of ['light','dark']) for(const style of ['outline','filled']){
 const p=await b.newPage({viewport:{width:w,height:700},deviceScaleFactor:2}); await p.goto(`http://127.0.0.1:8784/index.html?theme=${theme}&style=${style}`); await p.waitForTimeout(400);
 const btn=p.locator('[role=dialog] button').filter({hasText:'Close'}); 
 const r=await btn.evaluate(e=>{const c=getComputedStyle(e),b=e.getBoundingClientRect(); return {bg:c.backgroundColor,border:c.borderTopWidth,h:Math.round(b.height)}});
 console.log(w,theme,style,JSON.stringify(r)); if(style==='outline') await p.screenshot({path:`/tmp/sa/single-${w}-${theme}.png`}); await p.close(); }
// keyboard
const p=await b.newPage({viewport:{width:900,height:600}}); await p.goto('http://127.0.0.1:8784/index.html?style=outline'); await p.waitForTimeout(400);
const btn=p.locator('[role=dialog] button').filter({hasText:'Close'});
for(let i=0;i<4;i++){ await p.keyboard.press('Tab'); if(await btn.evaluate(e=>e===document.activeElement)) break; }
console.log('focus on button:',await btn.evaluate(e=>e===document.activeElement),'| focus ring:',await btn.evaluate(e=>getComputedStyle(e).boxShadow!=='none'||getComputedStyle(e).outlineStyle!=='none'));
await p.keyboard.press('Enter'); await p.waitForTimeout(300); console.log('Enter: confirm events',await p.evaluate(()=>window.__hits),'| dialog open',await p.locator('[role=dialog]').count());
await p.evaluate(()=>window.__open()); await p.waitForTimeout(300); await p.keyboard.press('Escape'); await p.waitForTimeout(300); console.log('Esc: dialog open',await p.locator('[role=dialog]').count(),'| confirm events still',await p.evaluate(()=>window.__hits));
await b.close();
