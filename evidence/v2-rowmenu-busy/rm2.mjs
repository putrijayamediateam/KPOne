import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch(); const p=await (await b.newContext({viewport:{width:1440,height:900}})).newPage();
for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.director@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
await p.goto('http://127.0.0.1:8091/payment-methods'); await p.waitForLoadState('networkidle');
for (const [code,name] of [['CASH','Cash'],['CARD','Card']]) { await p.getByRole('button',{name:/Add payment method/}).first().click(); await p.waitForTimeout(300); const d=p.locator('[role=dialog]'); await d.getByLabel(/^Code/).fill(code); await d.getByLabel(/^Name/).fill(name); await d.getByRole('button',{name:'Add payment method'}).click(); await p.waitForTimeout(1500); }
const menus=p.locator('[data-slot=v2-row-menu]'); console.log('data-ui',await p.evaluate(()=>document.documentElement.dataset.ui),'| row menus',await menus.count());
const items=()=>p.getByRole('menuitem').allInnerTexts();
await menus.first().click(); await p.waitForTimeout(250); console.log('1 first row opens:',await items());
// do a status action (busy true then false): choose Deactivate/Activate
const list=await items(); const act=list.find(t=>/^(Activate|Publish|Deactivate)/.test(t.trim()));
await p.getByRole('menuitem',{name:new RegExp('^'+act.trim().split(/\s/)[0])}).first().click(); await p.waitForTimeout(400);
const confirm=p.locator('[role=dialog] button',{hasText:/Deactivate|Publish|Activate/}).last(); if(await p.locator('[role=dialog]').count()){ await confirm.click(); } await p.waitForTimeout(1800);
console.log('2 status action done, dialogs',await p.locator('[role=dialog]').count());
await menus.first().click(); await p.waitForTimeout(300); console.log('3 SAME row menu opens again after busy:',await items());
await p.keyboard.press('Escape'); await p.waitForTimeout(200);
await menus.nth(1).click(); await p.waitForTimeout(300); console.log('4 another row opens:',await items());
await b.close();
