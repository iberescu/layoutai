import { chromium } from 'playwright';
import fs from 'fs/promises';

// Capture a single CSS-selector element clipped to its bounding box.
// usage: node shoot-element.js <url> <selector> <outFile> [width] [height]
const [,, url, selector, out, w, h] = process.argv;
const width = Number(w || 1440);
const height = Number(h || 1800);

const b = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport: { width, height }, deviceScaleFactor: 1 });
await ctx.route('**/localhost:8088/**', r => {
    const u = new URL(r.request().url());
    u.host = 'nginx'; u.port = '';
    r.continue({ url: u.toString() });
});
const page = await ctx.newPage();
await page.goto(url.replace('localhost:8088', 'nginx'), { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
await page.waitForTimeout(800);
const el = await page.$(selector);
if (!el) {
    console.error('SELECTOR NOT FOUND:', selector);
    process.exit(1);
}
const buf = await el.screenshot({ type: 'png' });
await fs.writeFile(out, buf);
console.error('saved', out, buf.length, 'bytes');
await b.close();
