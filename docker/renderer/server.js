import express from 'express';
import { chromium } from 'playwright';
import sharp from 'sharp';
import smartcrop from 'smartcrop-sharp';

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
        // Force the body to the exact ad dimensions so AI-authored HTML that
        // overflows the container still renders at the correct size.
        await page.addStyleTag({ content: `html,body{margin:0;padding:0;overflow:hidden!important;width:${width}px!important;height:${height}px!important;}` });
        await page.setViewportSize({ width: Number(width), height: Number(height) });
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

/**
 * Smartcrop focal-point endpoint. POST { url } -> { focal_x, focal_y } where
 * each value is 0-100 (percentage). The HTML pipeline uses these as
 * `object-position` so when an ad crops the image (object-fit: cover),
 * the subject of interest stays in frame regardless of the ad's aspect
 * ratio. Uses smartcrop-sharp (edge / skin / saturation heuristics —
 * no neural net, very fast: ~50-150ms per image).
 */
app.post('/focal', async (req, res) => {
    const { url } = req.body || {};
    if (!url) return res.status(400).json({ error: 'url required' });

    try {
        // Fetch the image — keep the body in memory (sharp + smartcrop need
        // a Buffer, not a stream).
        const r = await fetch(url, {
            headers: { 'User-Agent': 'Mozilla/5.0 (LayoutAIBot)' },
            redirect: 'follow',
        });
        if (!r.ok) return res.status(502).json({ error: `fetch ${r.status}` });
        const buf = Buffer.from(await r.arrayBuffer());

        // Quick metadata read so we can ask smartcrop for a crop that's
        // proportional to the original (smartcrop wants a target size).
        const meta = await sharp(buf).metadata();
        const w = meta.width || 0;
        const h = meta.height || 0;
        if (w < 100 || h < 100) {
            // Image too small to bother — return centre.
            return res.json({ focal_x: 50, focal_y: 50, width: w, height: h, fallback: 'too_small' });
        }

        // Request a small square (~25% of the short side) so smartcrop actually
        // SEARCHES for the best region. If we asked for side×side it would only
        // have one place to put the crop and always return centre.
        const side = Math.max(64, Math.round(Math.min(w, h) * 0.25));
        const result = await smartcrop.crop(buf, { width: side, height: side });
        const top = result.topCrop;
        if (!top || !Number.isFinite(top.x) || !Number.isFinite(top.y)) {
            return res.json({ focal_x: 50, focal_y: 50, width: w, height: h, fallback: 'no_crop' });
        }

        const focalX = ((top.x + top.width / 2) / w) * 100;
        const focalY = ((top.y + top.height / 2) / h) * 100;
        res.json({
            focal_x: +focalX.toFixed(1),
            focal_y: +focalY.toFixed(1),
            width: w,
            height: h,
        });
    } catch (err) {
        console.error('focal err:', err.message);
        res.status(500).json({ error: err.message });
    }
});

const PORT = Number(process.env.PORT || 3000);
app.listen(PORT, () => {
    console.log(`renderer listening on ${PORT}`);
});
