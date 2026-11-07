#!/usr/bin/env node
/**
 * End-to-end student flow for the Lecture Evaluations EM, run against the synthetic perf fixture
 * (tests/fixture/perf-fixture.php). It does what a student does, in a real browser:
 *
 *   1. opens the student page for a few fixture students (plus an unknown and a malformed hash),
 *   2. opens N "Evaluate" links in parallel tabs (the 2026-11-06 incident pattern),
 *   3. reloads the student page, then submits K evaluations one after another and follows the
 *      module's post-survey redirect back to the student page.
 *
 * While it runs it samples the web container's memory with `docker stats`. Every page it lands on is
 * saved under e2e/out/<label>/ so two runs (before/after a change, from the same fresh seed) can be
 * diffed. Desktop by default; --mobile uses an iPhone 13 viewport.
 *
 *   node e2e/student-flow.js --fixture fixture.json --label baseline [--tabs 20] [--submit 3] [--mobile] [--bad-links]
 *
 * --bad-links (after the change only) opens view/evaluation with unknown and malformed hash/lid
 * values and expects the "not valid" message; tests/fixture/dump-new-evaluations.sh then shows
 * that no record was created for them.
 */
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { chromium, devices } = require('playwright');

function arg(name, def) {
  const i = process.argv.indexOf('--' + name);
  if (i === -1) return def;
  const v = process.argv[i + 1];
  return v === undefined || v.startsWith('--') ? true : v;
}

const fixture = JSON.parse(fs.readFileSync(arg('fixture'), 'utf8'));
const label = arg('label', 'run');
const TABS = parseInt(arg('tabs', '20'), 10);
const SUBMIT = parseInt(arg('submit', '3'), 10);
const MOBILE = !!arg('mobile', false);
const CONTAINER = arg('container', 'redcap_2023_1_web');
const OUT = path.join(__dirname, 'out', label + (MOBILE ? '-mobile' : ''));
fs.mkdirSync(OUT, { recursive: true });

const results = { label, mobile: MOBILE, steps: [], errors: [], container_mem_mib: [] };

function memSampler() {
  const p = spawn('docker', ['stats', '--format', '{{.MemUsage}}', CONTAINER]);
  p.stdout.on('data', (buf) => {
    // docker stats redraws with ANSI codes; pull every "123.4MiB" / "1.2GiB" that appears
    for (const m of buf.toString().matchAll(/([\d.]+)(MiB|GiB)\s*\//g)) {
      const v = parseFloat(m[1]) * (m[2] === 'GiB' ? 1024 : 1);
      results.container_mem_mib.push({ t: Date.now(), mib: Math.round(v) });
    }
  });
  return p;
}

// Strip what legitimately differs between two runs from the same seed: nothing on the student page
// should, so this only normalises whitespace at line ends.
function normalise(html) {
  return html.replace(/[ \t]+$/gm, '');
}

async function studentPage(page, url, name) {
  const t0 = Date.now();
  const resp = await page.goto(url, { waitUntil: 'load', timeout: 180000 });
  const ms = Date.now() - t0;
  const html = await page.content();
  fs.writeFileSync(path.join(OUT, name + '.html'), normalise(html));
  const rows = await page.locator('#student-table tbody tr').count();
  const summary = (await page.locator('h4').first().textContent().catch(() => '')) || '';
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  const step = { step: 'student:' + name, status: resp.status(), ms, rows, summary: summary.trim(), overflow_px: overflow };
  results.steps.push(step);
  console.log(JSON.stringify(step));
  return step;
}

async function evaluateLinks(page) {
  // DataTables paginates; read every link from the DOM rows DataTables keeps in memory.
  return page.evaluate(() => {
    const t = window.jQuery && jQuery.fn.dataTable && jQuery('#student-table').DataTable();
    const nodes = t ? t.rows().nodes().toArray() : Array.from(document.querySelectorAll('#student-table tbody tr'));
    return nodes.map((tr) => tr.querySelector('a.btn') && tr.querySelector('a.btn').href).filter(Boolean);
  });
}

async function fillAndSubmit(page) {
  const pick = async (field, value) => {
    const el = page.locator(`input[name="${field}___radio"][value="${value}"]`);
    if (await el.count() && await el.first().isVisible()) await el.first().check();
  };
  await pick('did_you_attend_this_lectur', '1');
  for (const f of ['score', 'knowledge_subject', 'lecture_organization', 'professionalism', 'communication', 'questions_rec']) {
    await pick(f, '4');
  }
  const c = page.locator('textarea[name="comments"]');
  if (await c.count() && await c.isVisible()) await c.fill('Synthetic E2E comment.');
  await Promise.all([
    page.waitForURL(/page=view%2Fstudent|page=view\/student/, { timeout: 180000 }),
    page.locator('button[name="submit-btn-saverecord"]').first().click(),
  ]);
}

(async () => {
  const sampler = memSampler();
  const browser = await chromium.launch();
  const ctx = await browser.newContext(MOBILE ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } });
  const page = await ctx.newPage();
  // JS errors are recorded with the page they happened on; they are reported, not counted as failures,
  // because REDCap's own survey page raises one on load (seen identically before and after the change).
  results.page_errors = [];
  page.on('pageerror', (e) => results.page_errors.push({ url: page.url().replace(/hash=[^&]+/, 'hash=<h>').replace(/s=[^&]+/, 's=<s>'), message: e.message }));

  const s = fixture.students;
  const base = s.S1.url.replace(/&hash=.*$/, '');
  for (const sid of Object.keys(s)) await studentPage(page, s[sid].url, 'initial-' + sid);
  await studentPage(page, base + '&hash=ZZZZ9999', 'initial-unknown-hash');
  await studentPage(page, base + '&hash=' + encodeURIComponent("x' or '1'='1"), 'initial-malformed-hash');

  // Parallel tabs -------------------------------------------------------------------------------
  await studentPage(page, s.S1.url, 'before-tabs-S1');
  const links = (await evaluateLinks(page)).slice(0, TABS);
  fs.writeFileSync(path.join(OUT, 'tab-links.json'), JSON.stringify(links, null, 1));
  const t0 = Date.now();
  const tabResults = await Promise.all(links.map(async (href, i) => {
    const p = await ctx.newPage();
    const st = Date.now();
    try {
      const r = await p.goto(href, { waitUntil: 'load', timeout: 300000 });
      const ok = /\/surveys\/\?s=/.test(p.url()) && (await p.locator('button[name="submit-btn-saverecord"]').count()) > 0;
      return { i, status: r.status(), ms: Date.now() - st, landed_on_survey: ok, body: ok ? '' : (await p.content()).slice(0, 300) };
    } catch (e) {
      return { i, error: e.message, ms: Date.now() - st };
    } finally {
      await p.close();
    }
  }));
  const tabStep = {
    step: `parallel-tabs x${links.length}`,
    wall_ms: Date.now() - t0,
    ok: tabResults.filter((r) => r.landed_on_survey).length,
    failed: tabResults.filter((r) => !r.landed_on_survey),
    max_ms: Math.max(...tabResults.map((r) => r.ms)),
  };
  results.steps.push(tabStep);
  console.log(JSON.stringify({ ...tabStep, failed: tabStep.failed.length }));
  for (const f of tabStep.failed) results.errors.push('tab failed: ' + JSON.stringify(f));

  // Submit K evaluations, each from the student page ---------------------------------------------
  await studentPage(page, s.S1.url, 'after-tabs-S1');
  for (let k = 0; k < SUBMIT; k++) {
    const href = (await evaluateLinks(page))[0];
    const st = Date.now();
    await page.goto(href, { waitUntil: 'load', timeout: 180000 });
    const openMs = Date.now() - st;
    const st2 = Date.now();
    await fillAndSubmit(page);
    await page.waitForLoadState('load');
    const step = { step: 'submit-' + (k + 1), open_ms: openMs, submit_to_student_ms: Date.now() - st2, landed: page.url().replace(/hash=[^&]+/, 'hash=<S1>') };
    fs.writeFileSync(path.join(OUT, `after-submit-${k + 1}.html`), normalise(await page.content()));
    const summary = (await page.locator('h4').first().textContent()) || '';
    step.summary = summary.trim();
    step.redirect_is_S1 = page.url() === s.S1.url;
    results.steps.push(step);
    console.log(JSON.stringify(step));
    if (!step.redirect_is_S1) results.errors.push('submit ' + (k + 1) + ' redirected to ' + step.landed);
  }

  await page.screenshot({ path: path.join(OUT, 'final-student-S1.png'), fullPage: false });

  // Invalid evaluation links: shown an error, and (checked in the DB afterwards) no record created.
  if (arg('bad-links', false)) {
    const evalBase = links[0].replace(/&hash=.*$/, '');
    const goodLid = new URL(links[0]).searchParams.get('lid');
    const bad = {
      'unknown-hash': `${evalBase}&hash=ZZZZ9999&lid=${goodLid}`,
      'malformed-hash': `${evalBase}&hash=${encodeURIComponent("x' or '1'='1")}&lid=${goodLid}`,
      'unknown-lid': `${evalBase}&hash=${s.S1.hash}&lid=L999999`,
      'malformed-lid': `${evalBase}&hash=${s.S1.hash}&lid=${encodeURIComponent("L1' or 1=1")}`,
      'no-params': evalBase,
    };
    for (const [name, url] of Object.entries(bad)) {
      const r = await page.goto(url, { waitUntil: 'load' });
      const text = (await page.locator('body').innerText()).trim();
      const step = { step: 'bad-link:' + name, status: r.status(), stayed_on_module: !/\/surveys\//.test(page.url()), text };
      results.steps.push(step);
      console.log(JSON.stringify(step));
      if (!step.stayed_on_module || text !== 'This evaluation link is not valid.') results.errors.push('bad link accepted: ' + name);
    }
    await page.screenshot({ path: path.join(OUT, 'bad-link.png'), fullPage: false });
  }

  await browser.close();
  sampler.kill();
  const mem = results.container_mem_mib.map((m) => m.mib);
  results.container_mem_summary = mem.length ? { min: Math.min(...mem), max: Math.max(...mem), samples: mem.length } : null;
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 1));
  console.log(JSON.stringify({ container_mem_mib: results.container_mem_summary, errors: results.errors.length }));
  process.exit(results.errors.length ? 2 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
