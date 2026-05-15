import express from 'express';
import { chromium } from 'playwright';

const app = express();
app.use(express.json({ limit: '20mb' }));

let browser;
async function getBrowser() {
    if (!browser) {
        browser = await chromium.launch({ args: ['--no-sandbox'] });
    }
    return browser;
}

app.get('/health', (_req, res) => res.json({ ok: true }));

app.post('/render', async (req, res) => {
    const { html, width, height, format = 'png', quality = 85 } = req.body || {};
    if (!html || !width || !height) {
        return res.status(400).json({ error: 'html, width and height are required' });
    }
    let context;
    let page;
    try {
        const b = await getBrowser();
        context = await b.newContext({
            viewport: { width: Number(width), height: Number(height) },
            deviceScaleFactor: 2,
        });
        page = await context.newPage();
        await page.setContent(html, { waitUntil: 'networkidle' });
        const buf = await page.screenshot({
            type: format === 'jpg' ? 'jpeg' : 'png',
            quality: format === 'jpg' ? Number(quality) : undefined,
            fullPage: false,
            omitBackground: false,
            clip: { x: 0, y: 0, width: Number(width), height: Number(height) },
        });
        res.set('Content-Type', format === 'jpg' ? 'image/jpeg' : 'image/png');
        res.send(buf);
    } catch (err) {
        console.error(err);
        res.status(500).json({ error: err.message });
    } finally {
        if (page) await page.close().catch(() => {});
        if (context) await context.close().catch(() => {});
    }
});

const PORT = Number(process.env.PORT || 3000);
app.listen(PORT, () => {
    console.log(`renderer listening on ${PORT}`);
});
