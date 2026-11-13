import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch(); const p=await (await b.newContext({viewport:{width:1440,height:900}})).newPage();
for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.director@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
await p.goto('http://127.0.0.1:8091/consultation-settings'); await p.waitForLoadState('networkidle');
const d=p.locator('[role=dialog]');
await p.getByRole('button',{name:/Add diagnosis/}).first().click(); await p.waitForTimeout(300);
await d.locator('input[type=text],input:not([type])').first().fill('Test diagnosis one'); await d.getByRole('button',{name:'Add',exact:true}).click(); await p.waitForTimeout(1800);
await p.getByRole('button',{name:/Add diagnosis/}).first().click(); await p.waitForTimeout(300);
await d.locator('input[type=text],input:not([type])').first().fill('Test diagnosis two'); await d.getByRole('button',{name:'Add',exact:true}).click(); await p.waitForTimeout(1800);
const menus=p.locator('[data-slot=v2-row-menu]'); const items=()=>p.getByRole('menuitem').allInnerTexts();
console.log('row menus',await menus.count());
await menus.first().click(); await p.waitForTimeout(250); const l=await items(); console.log('1 opens:',l);
const pick=l.find(t=>/Archive|Deactivate|Activate|Restore/.test(t)); await p.getByRole('menuitem',{name:pick.trim()}).click(); await p.waitForTimeout(500);
const dlg=await p.locator('[role=dialog]').count(); if(dlg){ await p.locator('[role=dialog] button').filter({hasText:/Archive|Deactivate|Activate|Restore|Confirm/}).last().click(); } await p.waitForTimeout(1800);
console.log('2 action done (dialog was',dlg,')');
await menus.first().click(); await p.waitForTimeout(300); console.log('3 same row menu opens again after busy:',await items());
await p.keyboard.press('Escape'); await p.waitForTimeout(200); if(await menus.count()>1){ await menus.nth(1).click(); await p.waitForTimeout(300); console.log('4 another row opens:',await items()); }
await b.close();
