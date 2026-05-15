import { chromium } from 'playwright';
import fs from 'fs/promises';

const [,, url, width, height, out] = process.argv;
if (!url || !out) {
    console.error('usage: node screenshot.js <url> <width> <height> <out>');
    process.exit(2);
}

const b = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await b.newContext({
    viewport: { width: Number(width || 1280), height: Number(height || 800) },
    deviceScaleFactor: 1,
});
const page = await ctx.newPage();
page.on('console', m => console.error('PAGE:', m.text()));
page.on('pageerror', e => console.error('PAGEERROR:', e.message));
// Renderer sits inside the docker network and can't reach the host's
// localhost:8088 - rewrite img src that come back with the public URL.
await page.route('**/localhost:8088/**', route => {
    const u = new URL(route.request().url());
    u.host = 'nginx';
    u.port = '';
    route.continue({ url: u.toString() });
});
await page.goto(url.replace('localhost:8088', 'nginx'), { waitUntil: 'networkidle', timeout: 30000 });
// Rewrite already-parsed asset URLs that the browser wants to load.
await page.evaluate(() => {
    document.querySelectorAll('img').forEach(img => {
        if (img.src.includes('localhost:8088')) img.src = img.src.replace('localhost:8088', 'nginx');
    });
});
await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
await page.waitForTimeout(800);
const buf = await page.screenshot({ type: 'png', fullPage: true });
await fs.writeFile(out, buf);
console.error('saved', out, buf.length, 'bytes');
await b.close();
