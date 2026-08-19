/**
 * Smoke-test the real resources/js/rich-text-editor.js Alpine factory.
 * Run: node tests/js/rich-text-editor-component-smoke.mjs
 */
import { createServer } from 'node:http';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execSync } from 'node:child_process';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '../..');
const outDir = join(__dirname, '.smoke-dist');
const bundlePath = join(outDir, 'component-harness.js');

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

function ensureDeps() {
    if (!existsSync(join(root, 'node_modules/alpinejs/package.json'))) {
        execSync('npm install --no-save alpinejs', { cwd: root, stdio: 'inherit' });
    }
}

async function loadPlaywright() {
    try {
        const mod = await import('playwright');
        if (!mod.chromium) {
            throw new Error('playwright.chromium missing');
        }
        return mod;
    } catch {
        execSync('npm install --no-save playwright', { cwd: root, stdio: 'inherit' });
        execSync('npx playwright install chromium', { cwd: root, stdio: 'inherit' });
        return await import('playwright');
    }
}

function bundle() {
    mkdirSync(outDir, { recursive: true });
    execSync(
        'npx --yes esbuild tests/js/rich-text-editor-component-smoke-harness.js --bundle --format=iife --outfile=tests/js/.smoke-dist/component-harness.js',
        { cwd: root, stdio: 'inherit' },
    );
}

function pageHtml() {
    return `<!doctype html>
<html><head><meta charset="utf-8" /></head>
<body>
  <div id="root" x-data="appEditor()">
    <button type="button" id="bold" @mousedown.prevent @click="runBold()">Bold</button>
    <button type="button" id="table" @mousedown.prevent @click="runTable()">Table</button>
    <button type="button" id="paste" @mousedown.prevent @click="runPasteInsert()">Paste</button>
    <div x-ref="editor"></div>
    <pre id="html" x-text="html"></pre>
    <pre id="result" x-text="result"></pre>
  </div>
  <script src="/harness.js"></script>
</body></html>`;
}

function startServer() {
    const html = pageHtml();
    const harness = readFileSync(bundlePath);
    return new Promise((resolve) => {
        const server = createServer((req, res) => {
            if (req.url === '/' ) {
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end(html);
                return;
            }
            if (req.url === '/harness.js') {
                res.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8' });
                res.end(harness);
                return;
            }
            res.writeHead(404);
            res.end('no');
        });
        server.listen(0, '127.0.0.1', () => resolve({ server, port: server.address().port }));
    });
}

async function main() {
    ensureDeps();
    bundle();
    const { chromium } = await loadPlaywright();
    const { server, port } = await startServer();
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    const pageErrors = [];
    page.on('pageerror', (err) => pageErrors.push(String(err)));

    try {
        await page.goto(`http://127.0.0.1:${port}/`, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => window.__componentSmokeReady === true, { timeout: 15000 });

        await page.click('#bold');
        let result = (await page.locator('#result').innerText()).trim();
        let html = (await page.locator('#html').innerText()).trim();
        console.log('component bold:', result, html);
        assert(result === 'ok', `Bold failed: ${result}`);

        await page.click('#table');
        result = (await page.locator('#result').innerText()).trim();
        html = (await page.locator('#html').innerText()).trim();
        console.log('component table:', result, html.slice(0, 160));
        assert(result === 'ok-table', `Table failed: ${result}`);

        await page.click('#paste');
        result = (await page.locator('#result').innerText()).trim();
        html = (await page.locator('#html').innerText()).trim();
        console.log('component paste:', result, html);
        assert(result === 'ok-paste', `Paste/insert failed: ${result}`);

        assert(pageErrors.length === 0, `Page errors: ${pageErrors.join(' | ')}`);
        console.log('\nCOMPONENT SMOKE PASS: real richTextEditor factory works under Alpine.');
    } finally {
        await browser.close();
        server.close();
    }
}

main().catch((err) => {
    console.error('\nCOMPONENT SMOKE FAIL:', err.message);
    process.exit(1);
});
