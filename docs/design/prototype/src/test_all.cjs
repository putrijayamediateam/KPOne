const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const p = await b.newPage({viewport:{width:1600,height:950}});
  let errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,200))); p.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,200))});
  await p.goto('file://'+process.argv[2]); await p.waitForTimeout(1500);
  const ids = await p.evaluate(()=>JSON.parse(document.getElementById('meta-data').textContent).map(m=>m.id));
  const bad=[]; const fr=p.frameLocator('#f');
  for (const id of ids){ errs=[]; await p.evaluate(h=>location.hash=h,id); await p.waitForTimeout(650);
    let txt=''; try{ txt=(await fr.locator('body').innerText({timeout:3000})).trim(); }catch(e){}
    const holes=(txt.match(/\{\{/g)||[]).length; const errText=/\bundefined\b|\bNaN\b|\[object/.test(txt);
    const realErr=errs.filter(e=>!/fonts\.g|ERR_NAME|net::/.test(e));
    if (txt.length<80||holes||realErr.length||errText) bad.push({id,len:txt.length,holes,errText:errText?(txt.match(/.{0,40}(error|failed|undefined|NaN|\[object).{0,40}/i)||[''])[0]:'',errs:realErr.slice(0,2)}); }
  console.log('screens',ids.length,'problems',bad.length); console.log(JSON.stringify(bad,null,1).slice(0,4000));
  await b.close();
})();
