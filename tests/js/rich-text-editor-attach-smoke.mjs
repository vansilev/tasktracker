/**
 * Confirms HTTP inline upload + applyIncoming only mirrors clears.
 * Run: node tests/js/rich-text-editor-attach-smoke.mjs
 */
import { createServer } from 'node:http';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execSync } from 'node:child_process';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '../..');
const outDir = join(__dirname, '.smoke-dist');
const bundlePath = join(outDir, 'attach-harness.js');

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
    const mod = await import('playwright');
    if (!mod.chromium) {
        execSync('npm install --no-save playwright', { cwd: root, stdio: 'inherit' });
        execSync('npx playwright install chromium', { cwd: root, stdio: 'inherit' });
        return await import('playwright');
    }
    return mod;
}

function bundle() {
    mkdirSync(outDir, { recursive: true });
    execSync(
        'npx --yes esbuild tests/js/rich-text-editor-attach-smoke-harness.js --bundle --format=iife --outfile=tests/js/.smoke-dist/attach-harness.js',
        { cwd: root, stdio: 'inherit' },
    );
}

function pageHtml() {
    return `<!doctype html><html><head><meta charset="utf-8" /><meta name="csrf-token" content="test" /></head>
<body>
  <div id="policy" x-data="attachEditor()">
    <button type="button" id="confirm-policy" @click="runConfirmClearOnly()">Policy</button>
    <div x-ref="editor"></div>
    <pre id="policy-result" x-text="result"></pre>
  </div>
  <div id="attach" x-data="attachEditor()">
    <button type="button" id="do-attach" @click="runAttach()">Attach</button>
    <div x-ref="editor"></div>
    <pre id="attach-html" x-text="html"></pre>
    <pre id="attach-result" x-text="result"></pre>
  </div>
  <script src="/harness.js"></script>
</body></html>`;
}

function startServer() {
    const html = pageHtml();
    const harness = readFileSync(bundlePath);
    return new Promise((resolve) => {
        const server = createServer((req, res) => {
            if (req.url === '/') {
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
        await page.waitForFunction(() => window.__attachSmokeReady === true, { timeout: 15000 });

        await page.click('#confirm-policy');
        await page.waitForFunction(() => {
            const t = document.querySelector('#policy-result')?.textContent?.trim();
            return t && t !== 'pending';
        }, { timeout: 5000 });
        const policy = (await page.locator('#policy-result').innerText()).trim();
        console.log('incoming policy:', policy);
        assert(policy === 'clear-only-ok', `applyIncoming policy failed: ${policy}`);

        await page.click('#do-attach');
        await page.waitForFunction(() => {
            const t = document.querySelector('#attach-result')?.textContent?.trim();
            return t && t !== 'pending';
        }, { timeout: 10000 });

        const result = (await page.locator('#attach-result').innerText()).trim();
        const html = (await page.locator('#attach-html').innerText()).trim();
        console.log('attach result:', result);
        console.log('attach html:', html);

        assert(result === 'ok-attach', `HTTP attach path failed: ${result}`);
        assert(pageErrors.length === 0, `Page errors: ${pageErrors.join(' | ')}`);
        console.log('\nATTACH SMOKE PASS: HTTP upload inserts; stale non-empty echo ignored; clear works.');
    } finally {
        await browser.close();
        server.close();
    }
}

main().catch((err) => {
    console.error('\nATTACH SMOKE FAIL:', err.message);
    process.exit(1);
});
