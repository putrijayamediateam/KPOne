import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch(); const p=await b.newPage({viewport:{width:900,height:600}});
await p.goto('http://127.0.0.1:8782/index.html'); await p.waitForTimeout(500);
const q=()=>p.locator('[data-slot=v2-dialog-discard]').count();
console.log('start url',p.url().replace(/.*8782/,''),'| questions',await q());
await p.goBack(); await p.waitForTimeout(500);
console.log('Back with sheet + page both dirty: questions asked:',await q(),'| question dialogs in DOM:',await p.locator('[role=alertdialog]').count(),'| page-level dialogs:',await p.locator('[role=dialog]').count());
await b.close();
