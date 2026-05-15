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
await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(800);
const buf = await page.screenshot({ type: 'png', fullPage: true });
await fs.writeFile(out, buf);
console.error('saved', out, buf.length, 'bytes');
await b.close();
