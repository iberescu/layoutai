import { chromium } from 'playwright';
import fs from 'fs/promises';

// usage: node screenshot-auth.js <email> <password> <path1,path2,...> <outdir> <width> <height>
const [,, email, password, pathList, outDir, w, h] = process.argv;
const width = Number(w || 1440);
const height = Number(h || 1200);
const paths = pathList.split(',');

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await browser.newContext({
    viewport: { width, height },
    deviceScaleFactor: 1,
});

// Rewrite all localhost:8088 requests to nginx hostname
await ctx.route('**/localhost:8088/**', route => {
    const u = new URL(route.request().url());
    u.host = 'nginx'; u.port = '';
    route.continue({ url: u.toString() });
});

const page = await ctx.newPage();
page.on('pageerror', e => console.error('PAGEERROR:', e.message));

// Login
await page.goto('http://nginx/login', { waitUntil: 'networkidle' });
await page.fill('input[name=email]', email);
await page.fill('input[name=password]', password);
await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], button:has-text("Log in")'),
]);
console.error('logged in, url=', page.url());

for (const p of paths) {
    const url = 'http://nginx' + p;
    await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
    await page.evaluate(() => {
        document.querySelectorAll('img').forEach(img => {
            if (img.src.includes('localhost:8088')) img.src = img.src.replace('localhost:8088', 'nginx');
        });
    });
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(500);
    const safeName = p.replace(/^\//, '').replace(/[\/]/g, '_') || 'root';
    const out = `${outDir}/${safeName}.png`;
    const buf = await page.screenshot({ type: 'png', fullPage: true });
    await fs.writeFile(out, buf);
    console.error('saved', out, buf.length, 'bytes');
}
await browser.close();
