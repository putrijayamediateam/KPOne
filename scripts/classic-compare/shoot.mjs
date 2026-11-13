// Classic look check: shoot the main pages (and a dialog and a sticky bar) with the clock frozen, so two
// builds can be compared pixel by pixel. See README.md. Usage:
//   BASE=http://127.0.0.1:8732 PLAYWRIGHT=/path/to/node_modules/playwright/index.mjs node shoot.mjs <tag> [outDir]
// Needs a task-owned database with the demo accounts (never a real database).
import fs from 'node:fs';

// PLAYWRIGHT can point at a Playwright install that is not in this project's node_modules.
const { chromium } = await import(process.env.PLAYWRIGHT || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8732';
const PASSWORD = process.env.DEMO_PASSWORD || 'KPOne-Presentation-Only-2026!';
const tag = process.argv[2] || 'run';
const out = `${process.argv[3] || '.'}/shots-${tag}`;
fs.mkdirSync(out, { recursive: true });

const ONLY = process.env.ONLY_PAGES ? process.env.ONLY_PAGES.split(',') : null; // re-shoot a few pages only
const PAGES = ONLY || ['/workspace', '/registration', '/queue', '/pricing', '/registration/create', '/financial-work'];

// Live counters ("Waiting 12 min") come from the server's clock, which a browser clock cannot freeze: print them as N.
const fixCounters = (page) =>
    page.evaluate(() => {
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);

        for (let n = walker.nextNode(); n; n = walker.nextNode()) {
            n.nodeValue = n.nodeValue.replace(/\b\d+ ?(hr|hrs|hours?)\b/gi, 'N hr').replace(/\b\d+ ?(min|mins|minutes?)\b/gi, 'N min');
        }
    });
const USERS = ['demo.ca', 'demo.director'];
const SIZES = [[1440, 900], [390, 844]];
const THEMES = ['light', 'dark'];
const FROZEN = new Date('2026-11-12T10:00:00+08:00');

(async () => {
    const browser = await chromium.launch();

    for (const user of USERS) {
        let state = null;

        for (const [w, h] of SIZES) {
            for (const theme of THEMES) {
                const ctx = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: theme, ...(state ? { storageState: state } : {}) });
                await ctx.addInitScript((t) => {
 try {
 localStorage.setItem('appearance', t); 
} catch {} 
}, theme);
                // A blinking caret in a focused field can differ between two shots: hide it on every document.
                await ctx.addInitScript(() => {
                    document.addEventListener('DOMContentLoaded', () => {
                        const style = document.createElement('style');

                        style.textContent = '* { caret-color: transparent !important; }';
                        document.head.appendChild(style);
                    });
                });
                await ctx.clock.setFixedTime(FROZEN);
                const page = await ctx.newPage();

                if (!state) {
                    await page.goto(`${BASE}/login`);
                    await page.fill('input[name=email]', `${user}@kpone.test`);
                    await page.fill('input[name=password]', PASSWORD);
                    await page.click('button[type=submit]');
                    await page.waitForLoadState('networkidle');
                    state = await ctx.storageState();
                }

                const name = (s) => `${out}/${user.split('.')[1]}-${s}-${w}-${theme}.png`;

                for (const url of PAGES) {
                    await page.goto(BASE + url);
                    await page.waitForLoadState('networkidle');
                    await page.waitForTimeout(700);
                    await fixCounters(page);
                    await page.screenshot({ path: name(url.replace(/\W/g, '_')) });
                }

                if (ONLY) {
                    await ctx.close();
                    continue;
                }

                // A row menu (CA) and a confirm dialog (Director: Deactivate on a staff page; the dialog is only opened).
                if (user === 'demo.ca') {
                    await page.goto(`${BASE}/registration`);
                    await page.waitForLoadState('networkidle');
                    await page.waitForTimeout(700);
                    const menu = page.locator('tbody tr button[aria-haspopup=menu], article button[aria-haspopup=menu]').first();

                    if (await menu.count()) {
                        await menu.click();
                        await page.waitForTimeout(300);
                        await page.screenshot({ path: name('menu-open') });
                    }
                } else {
                    await page.goto(`${BASE}${process.env.DIALOG_URL || '/staff/14'}`);
                    await page.waitForLoadState('networkidle');
                    await page.waitForTimeout(600);
                    const trigger = page.getByRole('button', { name: /deactivate/i }).first();

                    if (await trigger.count()) {
                        await trigger.click();
                        await page.waitForTimeout(500);
                        await page.screenshot({ path: name('dialog-deactivate') });
                    }
                }

                // A sticky bar: Register Visit scrolled to the bottom.
                await page.goto(`${BASE}/registration/create`);
                await page.waitForLoadState('networkidle');
                await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
                await page.waitForTimeout(400);
                await page.screenshot({ path: name('sticky-register-visit') });
                await ctx.close();
            }
        }
    }

    await browser.close();
})();
