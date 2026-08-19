/**
 * Browser smoke test for TipTap + Alpine proxy bug.
 *
 * Confirms:
 *  1) Storing Editor on Alpine reactive data → "mismatched transaction" (root cause)
 *  2) Official TipTap pattern (editor in closure) → bold / table / text insert work
 *
 * Run: node tests/js/rich-text-editor-alpine-smoke.mjs
 */
import { createServer } from 'node:http';
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execSync } from 'node:child_process';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '../..');
const outDir = join(__dirname, '.smoke-dist');
const bundlePath = join(outDir, 'harness.js');

function ensureDeps() {
    const alpine = join(root, 'node_modules/alpinejs/package.json');
    if (!existsSync(alpine)) {
        console.log('Installing alpinejs for smoke test...');
        execSync('npm install --no-save alpinejs', { cwd: root, stdio: 'inherit' });
    }
}

async function loadPlaywright() {
    try {
        return await import('playwright');
    } catch {
        console.log('Installing playwright (chromium) for smoke test...');
        execSync('npm install --no-save playwright', { cwd: root, stdio: 'inherit' });
        execSync('npx playwright install chromium', { cwd: root, stdio: 'inherit' });
        return await import('playwright');
    }
}

function bundleHarness() {
    mkdirSync(outDir, { recursive: true });
    console.log('Bundling smoke harness with esbuild...');
    execSync(
        'npx --yes esbuild tests/js/rich-text-editor-alpine-smoke-harness.js --bundle --format=iife --outfile=tests/js/.smoke-dist/harness.js',
        { cwd: root, stdio: 'inherit' },
    );
    assert(existsSync(bundlePath), 'Harness bundle was not created');
}

function pageHtml() {
    return `<!doctype html>
<html>
<head><meta charset="utf-8" /></head>
<body>
  <section id="broken">
    <div x-data="brokenEditor()">
      <button type="button" id="broken-bold" @mousedown.prevent @click="runBold()">Bold</button>
      <div x-ref="mount"></div>
      <pre id="broken-result" x-text="result"></pre>
    </div>
  </section>
  <section id="fixed">
    <div x-data="fixedEditor()">
      <button type="button" id="fixed-bold" @mousedown.prevent @click="runBold()">Bold</button>
      <button type="button" id="fixed-table" @mousedown.prevent @click="runTable()">Table</button>
      <div x-ref="mount"></div>
      <pre id="fixed-html" x-text="html"></pre>
      <pre id="fixed-result" x-text="result"></pre>
    </div>
  </section>
  <script src="/harness.js"></script>
</body>
</html>`;
}

function startServer() {
    const html = pageHtml();
    const harness = readFileSync(bundlePath);

    return new Promise((resolve) => {
        const server = createServer((req, res) => {
            if (req.url === '/' || req.url?.startsWith('/index')) {
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
            res.end('not found');
        });

        server.listen(0, '127.0.0.1', () => {
            resolve({ server, port: server.address().port });
        });
    });
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function main() {
    ensureDeps();
    bundleHarness();

    const { chromium } = await loadPlaywright();
    const { server, port } = await startServer();
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    const pageErrors = [];
    page.on('pageerror', (err) => pageErrors.push(String(err)));

    try {
        await page.goto(`http://127.0.0.1:${port}/`, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => window.__smokeReady === true, null, { timeout: 15000 });

        await page.click('#broken-bold');
        const broken = (await page.locator('#broken-result').innerText()).trim();
        console.log('broken result:', broken);
        assert(
            broken.includes('mismatched transaction') || broken.includes('RangeError'),
            `Expected Alpine-proxied editor to throw mismatched transaction, got: ${broken}`,
        );

        await page.click('#fixed-bold');
        let fixed = (await page.locator('#fixed-result').innerText()).trim();
        let html = (await page.locator('#fixed-html').innerText()).trim();
        console.log('fixed bold:', fixed, html);
        assert(fixed === 'ok', `Fixed bold failed: ${fixed}`);
        assert(html.includes('<strong>') || html.includes('<b>'), `Bold markup missing: ${html}`);

        await page.click('#fixed-table');
        fixed = (await page.locator('#fixed-result').innerText()).trim();
        html = (await page.locator('#fixed-html').innerText()).trim();
        console.log('fixed table:', fixed, html.slice(0, 180));
        assert(fixed === 'ok-table', `Fixed table failed: ${fixed}`);

        await page.evaluate(() => {
            const rootEl = document.querySelector('#fixed > div');
            Alpine.$data(rootEl).pasteText();
        });
        fixed = (await page.locator('#fixed-result').innerText()).trim();
        html = (await page.locator('#fixed-html').innerText()).trim();
        console.log('fixed paste/insert:', fixed, html);
        assert(fixed === 'ok-paste', `Insert/paste path failed: ${fixed}`);

        // The broken case is expected to throw; ignore that specific error only.
        const unexpected = pageErrors.filter((e) => !e.includes('mismatched transaction'));
        assert(unexpected.length === 0, `Unexpected page errors: ${unexpected.join(' | ')}`);

        writeFileSync(join(outDir, 'last-pass.txt'), new Date().toISOString());
        console.log('\nSMOKE PASS: root cause confirmed + closure fix works (bold, table, insert).');
    } finally {
        await browser.close();
        server.close();
    }
}

main().catch((err) => {
    console.error('\nSMOKE FAIL:', err.message);
    process.exit(1);
});
