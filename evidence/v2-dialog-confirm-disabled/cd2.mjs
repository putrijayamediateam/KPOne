import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch(); const p=await b.newPage({viewport:{width:390,height:700},deviceScaleFactor:2});
await p.goto('http://127.0.0.1:8781/index.html'); await p.waitForTimeout(400);
const btn=p.getByRole('button',{name:'Save receipt'});
console.log('1 aria-disabled:',await btn.getAttribute('aria-disabled'),'| native disabled:',await btn.isDisabled()&&await btn.evaluate(e=>e.hasAttribute('disabled')));
for(let i=0;i<4;i++){ await p.keyboard.press('Tab'); if(await btn.evaluate(e=>e===document.activeElement)) { console.log('  focusable by Tab after',i+1,'presses'); break; } }
await p.keyboard.press('Enter'); await btn.click({force:true}); console.log('  pressed (Enter and click): confirm events:',await p.evaluate(()=>window.__hits??0));
console.log('2 status region present, role:',await p.locator('#v2-dialog-confirm-reason').getAttribute('role'),'| text:',await p.locator('#v2-dialog-confirm-reason').innerText(),'| describedby:',await btn.getAttribute('aria-describedby'));
await p.evaluate(()=>window.__set(false)); await p.waitForTimeout(200);
console.log('3 enabled: aria-disabled',await btn.getAttribute('aria-disabled'),'| describedby',await btn.getAttribute('aria-describedby'),'| region still in DOM:',await p.locator('#v2-dialog-confirm-reason').count(),'| text:',JSON.stringify(await p.locator('#v2-dialog-confirm-reason').innerText()));
await btn.click(); console.log('  press now confirms:',await p.evaluate(()=>window.__hits));
await p.close();
const d=await b.newPage({viewport:{width:390,height:700},deviceScaleFactor:2}); await d.goto('http://127.0.0.1:8781/index.html?theme=dark'); await d.waitForTimeout(400); await d.screenshot({path:'/tmp/cd-dark-390.png'});
await b.close();
