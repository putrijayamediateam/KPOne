import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1440,height:900}}); const p=await ctx.newPage();
for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.director@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
const U=()=>p.url().replace(/.*8091/,''); const dlg=()=>p.locator('[role=dialog],[role=alertdialog]').count();
await p.goto('http://127.0.0.1:8091/staff/13'); await p.waitForLoadState('networkidle');
await p.evaluate(()=>{window.__push=0; const o=history.pushState.bind(history); history.pushState=(...a)=>{window.__push++; return o(...a)};});
await p.getByRole('link',{name:'Directory'}).click(); await p.waitForURL(/\/staff$/); await p.waitForLoadState('networkidle');
await p.evaluate(()=>window.__push=0); await p.goBack(); await p.waitForTimeout(600); await p.goForward(); await p.waitForTimeout(600);
console.log('A page WITHOUT a guard (Show, Directory): Back/Forward ->',U(),'| guard pushState:',await p.evaluate(()=>window.__push),'| dialogs',await dlg());
// Forward while dirty
await p.goto('http://127.0.0.1:8091/staff/13'); await p.waitForLoadState('networkidle');
await p.getByRole('link',{name:'Edit profile'}).click(); await p.waitForURL(/edit$/); await p.waitForLoadState('networkidle');
await p.getByRole('link',{name:'Cancel'}).click(); await p.waitForURL(/\/staff\/13$/); await p.waitForLoadState('networkidle');
await p.goBack(); await p.waitForURL(/edit$/); await p.waitForTimeout(600); // now on edit with a forward entry
await p.getByLabel('Job title').fill('Typed before Forward'); await p.goForward(); await p.waitForTimeout(700);
console.log('B Forward while dirty: dialogs',await dlg(),'| url',U(),'| value',await p.getByLabel('Job title').inputValue());
await p.getByRole('button',{name:'Discard changes'}).click(); await p.waitForTimeout(1200);
console.log('B2 Discard after Forward ->',U(),'(Forward was heading to /staff/13)');
await b.close();
