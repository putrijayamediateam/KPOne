// usage: node shoot.cjs <mock> [outdir]  -> 1440 light, 1440 dark, 390 light
const { chromium } = require('playwright');
(async () => {
  const [mock, out = '/tmp/shots'] = process.argv.slice(2);
  require('fs').mkdirSync(out, { recursive: true });
  const b = await chromium.launch();
  for (const [tag, w, h, dark] of [['1440-light',1440,900,false],['1440-dark',1440,900,true],['390-light',390,844,false]]) {
    const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, colorScheme: dark ? 'dark' : 'light' });
    if (dark) await ctx.addInitScript(() => localStorage.setItem('appearance', 'dark'));
    const p = await ctx.newPage();
    const errs = [];
    p.on('pageerror', (e) => errs.push(String(e).slice(0, 200)));
    await p.goto(`http://127.0.0.1:5199/h/${mock}.html`, { waitUntil: 'commit', timeout: 60000 });
    await p.waitForSelector('h1', { timeout: 120000 }).catch(() => console.log('no h1')); await p.waitForTimeout(1500);
    await p.screenshot({ path: `${out}/${mock}-${tag}.png`, fullPage: true, timeout: 90000, animations: 'disabled' });
    const sx = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
    console.log(tag, 'sideways:', sx, errs.join(' | '));
    await ctx.close();
  }
  await b.close();
})();
