import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:390,height:520},deviceScaleFactor:2}); const p=await ctx.newPage();
for(let i=0;i<6;i++){ await p.goto('http://127.0.0.1:8091/login'); await p.getByLabel(/email/i).fill('demo.director@kpone.test'); await p.getByLabel(/password/i).first().fill('KPOne-Presentation-Only-2026!'); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); if(!p.url().includes('/login')) break; await p.waitForTimeout(20000);}
await p.route(/\/staff\/13$/,async r=>{ if(r.request().method()==='GET') return r.continue(); await r.fulfill({status:419,contentType:'text/html',body:'<html><body>419</body></html>'}); });
await p.goto('http://127.0.0.1:8091/staff/13/edit'); await p.waitForLoadState('networkidle');
await p.getByLabel('Job title').fill('Edited long form');
const save=p.getByRole('button',{name:'Save profile'}); await save.scrollIntoViewIfNeeded(); await p.evaluate(()=>window.scrollTo(0,document.body.scrollHeight)); await p.waitForTimeout(300);
const before=await p.evaluate(()=>scrollY); await save.focus(); const fb=await p.evaluate(()=>document.activeElement?.tagName);
await save.click(); await p.waitForTimeout(1500);
const r=await p.evaluate(()=>{const n=document.querySelector('[data-slot=v2-refused-notice]').getBoundingClientRect(); return {top:Math.round(n.top),bottom:Math.round(n.bottom),vh:innerHeight,scrollY:Math.round(scrollY),active:document.activeElement?.tagName+'#'+(document.activeElement?.id||''), docH:document.documentElement.scrollHeight}});
console.log('before scrollY',before,'| after',JSON.stringify(r),'| notice in view:',r.top>=0&&r.bottom<=r.vh,'| focus before',fb,'-> after',r.active);
await p.screenshot({path:'/tmp/refout/form-419-390-scrolled.png'});
// reduced motion: instant
const ctx2=await b.newContext({viewport:{width:390,height:520},deviceScaleFactor:2,reducedMotion:'reduce'}); await ctx2.close();
await b.close();
