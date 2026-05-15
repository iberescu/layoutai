import { chromium } from 'playwright';
import fs from 'fs/promises';

// Capture a list of public (unauthenticated) routes at a fixed viewport
// so output stays well below the 2000px image limit.
// usage: node shoot-public.js <outDir> <width> <height> <path1> [path2 ...]
const [,, outDir, w, h, ...paths] = process.argv;
const width = Number(w || 1440);
const height = Number(h || 1800);

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1 });
await ctx.route('**/localhost:8088/**', route => {
    const u = new URL(route.request().url());
    u.host = 'nginx'; u.port = '';
    route.continue({ url: u.toString() });
});
const page = await ctx.newPage();
page.on('pageerror', e => console.error('PAGEERROR:', e.message));

for (const p of paths) {
    const url = 'http://nginx' + p;
    try {
        await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
    } catch (e) {
        console.error('GOTO_FAIL', p, e.message);
        continue;
    }
    await page.evaluate(() => {
        document.querySelectorAll('img').forEach(img => {
            if (img.src.includes('localhost:8088')) img.src = img.src.replace('localhost:8088', 'nginx');
        });
    });
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(400);
    const safe = p.replace(/^\//, '').replace(/[\/\?=&]/g, '_') || 'root';
    const out = `${outDir}/${safe}.png`;
    // Viewport-only screenshot — never exceeds the configured height.
    const buf = await page.screenshot({ type: 'png', fullPage: false });
    await fs.writeFile(out, buf);
    console.error('saved', out, buf.length, 'bytes', `${width}x${height}`);
}
await browser.close();
