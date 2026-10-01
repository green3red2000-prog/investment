const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

function arg(name, def = null) {
  const i = process.argv.indexOf(name);
  return i >= 0 && i + 1 < process.argv.length ? process.argv[i + 1] : def;
}
const root = path.resolve(arg('--root', process.cwd()));
const oneCode = arg('--code', '');
const serial = arg('--serial', '001');
const cdpUrl = arg('--cdp-url', 'http://127.0.0.1:9333');
const promptFile = path.join(root, 'AIプロンプト.txt');
const listFile = path.join(root, '調査企業リスト.txt');
const stateDir = path.join(root, 'state');
const stateFile = path.join(stateDir, 'chatgpt_automation_state.json');
const resultRoot = path.join(root, 'result');
const runMapFile = path.join(root, 'state', 'current_research_run.json');
fs.mkdirSync(stateDir, { recursive: true });
fs.mkdirSync(resultRoot, { recursive: true });
const loginOnly = process.argv.includes('--login-only');
const retryErrors = process.argv.includes('--retry-errors');

const sleep = ms => new Promise(r => setTimeout(r, ms));
function log(s) { console.log(`[${new Date().toLocaleString('ja-JP')}] ${s}`); }
function readText(file) { return fs.readFileSync(file, 'utf8').replace(/^\uFEFF/, ''); }
function loadState() {
  try { return JSON.parse(readText(stateFile)); } catch { return { stocks: {} }; }
}
function saveState(state) { fs.writeFileSync(stateFile, JSON.stringify(state, null, 2), 'utf8'); }
function codes() {
  if (oneCode) {
    if (!/^\d{4}$/.test(oneCode)) throw new Error(`不正な証券コード: ${oneCode}`);
    return [oneCode];
  }

  const result = [];
  for (const raw of readText(listFile).split(/\r?\n/)) {
    const x = raw.trim();
    if (!x || x.startsWith('#')) continue;

    const m = x.match(/^(\d{4})(?:\s+.*)?$/);
    if (!m) throw new Error(`調査企業リスト.txt に不正な行: ${x}`);
    result.push(m[1]);
  }

  if (!result.length) {
    throw new Error('調査企業リスト.txt に実行対象の証券コードがありません。');
  }
  return result;
}

function runDirectory(code) {
  if (!fs.existsSync(runMapFile)) {
    throw new Error(`current_research_run.json がありません: ${runMapFile}`);
  }
  const map = JSON.parse(readText(runMapFile));
  const runName = map?.runs?.[code];
  if (!runName) {
    throw new Error(`current_research_run.json に ${code} の実行フォルダがありません。`);
  }
  return path.join(resultRoot, code, runName);
}

async function getChatGPTPage(context) {
  let pages = context.pages();
  let page = pages.find(p => /chatgpt\.com/i.test(p.url()));
  if (!page) {
    page = pages[0] || await context.newPage();
    await page.goto('https://chatgpt.com/', { waitUntil: 'domcontentloaded', timeout: 120000 });
  }
  await page.bringToFront();
  return page;
}

async function isLoggedIn(page) {
  const loginUi = page.getByRole('button', { name: /^(ログイン|Log in)$/i }).first();
  if (await loginUi.count() && await loginUi.isVisible().catch(() => false)) return false;

  const composer = page.locator('#prompt-textarea, textarea[placeholder], div[contenteditable="true"]').first();
  return (await composer.count()) > 0 && await composer.isVisible().catch(() => false);
}

async function waitForLogin(page) {
  const deadline = Date.now() + 60000;
  while (Date.now() < deadline) {
    if (await isLoggedIn(page)) return true;
    await sleep(2000);
  }
  return false;
}

async function newChat(page) {
  await page.goto('https://chatgpt.com/', { waitUntil: 'domcontentloaded', timeout: 120000 });
  await page.locator('#prompt-textarea, div[contenteditable="true"]').first()
    .waitFor({ state: 'visible', timeout: 120000 });
}
async function uploadFiles(page, files) {
  let input = page.locator('input[type="file"]').first();
  if (!(await input.count())) {
    const attach = page.getByRole('button', { name: /attach|添付|ファイル|追加/i }).first();
    if (await attach.count()) await attach.click();
    await page.locator('input[type="file"]').first().waitFor({ state: 'attached', timeout: 15000 });
    input = page.locator('input[type="file"]').first();
  }

  await input.setInputFiles(files);
  log(`アップロード指定: ${files.length}ファイル`);
}
async function fillPrompt(page, text) {
  const box = page.locator('#prompt-textarea, div[contenteditable="true"]').first();
  await box.waitFor({ state: 'visible', timeout: 60000 });
  await box.click();
  try { await box.fill(text); }
  catch { await page.keyboard.insertText(text); }
}
async function sendPrompt(page) {
  // Wait in three 60-second stages (maximum 180 seconds).
  // After clicking Send, confirm submission by UI state rather than by counting
  // user-message DOM nodes, because ChatGPT's current UI does not reliably expose
  // those nodes with data-message-author-role="user".
  for (let round = 1; round <= 3; round++) {
    log(`[UPLOAD] ${round}/3: 60秒待機します...`);
    await sleep(60000);
    log(`[UPLOAD] ${round}/3: 送信可能状態を確認します...`);

    const send = page.locator(
      'button[data-testid="send-button"], button[aria-label*="送信"], button[aria-label*="Send"]'
    ).first();

    const exists = (await send.count()) > 0;
    const visible = exists && await send.isVisible().catch(() => false);
    const enabled = visible && await send.isEnabled().catch(() => false);

    if (!enabled) {
      log(`[UPLOAD] ${round}/3: まだ送信できません。`);
      continue;
    }

    log(`[UPLOAD] ${round}/3: 送信可能です。送信します...`);
    await send.click();

    // A successful submission normally causes one or more of these:
    //  1) the composer becomes empty,
    //  2) a Stop button appears while ChatGPT is generating,
    //  3) an assistant message appears.
    // Check all three so minor UI changes do not cause a false failure.
    const box = page.locator('#prompt-textarea, div[contenteditable="true"]').first();
    const stop = page.locator(
      'button[data-testid="stop-button"], button[aria-label*="停止"], button[aria-label*="Stop"]'
    ).first();

    for (let i = 0; i < 30; i++) {
      await sleep(1000);

      const composerText = await box.innerText().catch(async () => {
        return await box.inputValue().catch(() => '');
      });
      const composerEmpty = !String(composerText || '').trim();

      const stopVisible =
        (await stop.count()) > 0 &&
        await stop.isVisible().catch(() => false);

      const assistantCount =
        await page.locator('[data-message-author-role="assistant"]').count().catch(() => 0);

      if (composerEmpty || stopVisible || assistantCount > 0) {
        log(
          `プロンプトの送信を確認しました。` +
          ` (入力欄=${composerEmpty ? '空' : '残存'}, ` +
          `生成中=${stopVisible ? 'YES' : 'NO'}, ` +
          `assistant=${assistantCount})`
        );
        return;
      }
    }

    log(`[UPLOAD] ${round}/3: クリック後30秒以内に送信確認できません。次の60秒待機へ進みます。`);
  }

  throw new Error('最大180秒の待機後もプロンプトの送信を確認できませんでした。');
}
async function waitForCompletion(page, code) {
  const wanted = `TR_${code}_${serial}.txt`;
  log('ChatGPTの回答生成開始を確認しています...');

  const stop = page.locator(
    'button[data-testid="stop-button"], button[aria-label*="停止"], button[aria-label*="Stop"]'
  ).first();

  // sendPrompt() already saw generation start in the normal case, but re-check here.
  // Do NOT use the filename as a completion signal because the user's own prompt
  // contains TR_xxxx_xxx.txt from the beginning.
  const startDeadline = Date.now() + 120000;
  let generationSeen = false;
  while (Date.now() < startDeadline) {
    if ((await stop.count()) > 0 && await stop.isVisible().catch(() => false)) {
      generationSeen = true;
      log('ChatGPTの回答生成開始を確認しました。');
      break;
    }
    await sleep(1000);
  }

  if (!generationSeen) {
    log('生成中ボタンは確認できませんでしたが、回答終了監視を続行します。');
  }

  log('ChatGPTの回答完了を待っています...');
  const deadline = Date.now() + 60 * 60 * 1000;
  let noStopStable = 0;
  let lastAnnounce = 0;

  while (Date.now() < deadline) {
    const stopVisible =
      (await stop.count()) > 0 &&
      await stop.isVisible().catch(() => false);

    if (!stopVisible) noStopStable++;
    else noStopStable = 0;

    // Require the generation control to remain absent for 15 seconds.
    if (noStopStable >= 5) {
      log('回答生成の終了を確認しました。');
      await sleep(5000);

      // Only after generation has ended do we accept the last visible occurrence
      // of the expected filename as the generated result card.
      const matches = page.getByText(wanted, { exact: true });
      const count = await matches.count();
      let visibleCount = 0;
      for (let i = 0; i < count; i++) {
        if (await matches.nth(i).isVisible().catch(() => false)) visibleCount++;
      }

      if (visibleCount >= 1) {
        log(`回答完了後に ${wanted} の表示を確認しました。候補数=${visibleCount}`);
        return;
      }

      // If the answer has finished but the card is still rendering, continue.
      noStopStable = 0;
      log(`回答は終了しましたが ${wanted} の結果カード待ちです...`);
    }

    if (Date.now() - lastAnnounce >= 30000) {
      log(`回答処理中... ${wanted} の完成待ち`);
      lastAnnounce = Date.now();
    }
    await sleep(3000);
  }

  throw new Error(`回答完了後の ${wanted} が60分以内に確認できませんでした。`);
}
async function saveResultFile(page, code, stockDir) {
  const wanted = `TR_${code}_${serial}.txt`;
  const out = path.join(stockDir, `TR_${code}_${serial}_AI.txt`);
  log(`回答側の完成ファイルを開きます: ${wanted}`);

  const matches = page.getByText(wanted, { exact: true });
  const count = await matches.count();
  if (!count) {
    throw new Error(`回答完了後の ${wanted} が見つかりません。`);
  }

  // The generated result is the last visible occurrence.
  let node = null;
  for (let i = count - 1; i >= 0; i--) {
    const n = matches.nth(i);
    if (await n.isVisible().catch(() => false)) {
      node = n;
      break;
    }
  }
  if (!node) {
    throw new Error(`回答完了後の ${wanted} はありますが、表示中の要素を取得できません。`);
  }

  // Current ChatGPT behavior:
  // clicking the generated .txt card opens a right-side preview.
  // Click ONLY ONCE here. Repeated candidate clicks caused the previous
  // up/down scrolling/flickering.
  // The filename <span> itself is covered by ChatGPT's transparent preview button.
  // Click that exact overlay button instead of the text node.
  const previewButton = page.locator(
    `button[aria-label="${wanted} のプレビューを開く"]`
  ).last();

  if (await previewButton.count() && await previewButton.isVisible().catch(() => false)) {
    log(`プレビューボタンを検出しました: ${wanted}`);
    await previewButton.click({ timeout: 10000 });
  } else {
    // Fallback for a future wording change: find the nearest card and its overlay button.
    const card = node.locator('xpath=ancestor::*[.//button][1]');
    const overlay = card.locator('button').first();
    if (await overlay.count() && await overlay.isVisible().catch(() => false)) {
      log('ファイルカード内のプレビューボタンを検出しました。');
      await overlay.click({ timeout: 10000 });
    } else {
      throw new Error(`${wanted} のプレビューを開くボタンを特定できませんでした。`);
    }
  }

  log('完成ファイルのプレビューを開きました。右上のダウンロードボタンを待ちます...');
  await sleep(3000);

  async function tryDownloadButton(locator, description) {
    const c = await locator.count().catch(() => 0);
    for (let i = 0; i < c; i++) {
      const b = locator.nth(i);
      if (!(await b.isVisible().catch(() => false))) continue;

      const downloadPromise = page.waitForEvent('download', { timeout: 15000 })
        .then(d => ({ ok: true, download: d }))
        .catch(e => ({ ok: false, error: e }));

      try {
        await b.click({ timeout: 5000 });
      } catch {
        await downloadPromise;
        continue;
      }

      const result = await downloadPromise;
      if (result.ok) {
        const suggested = result.download.suggestedFilename();
        if (suggested !== wanted) {
          log(`ダウンロード対象不一致: expected=${wanted}, actual=${suggested}。このファイルは保存せず、別のダウンロードボタンを試します。`);
          await result.download.delete().catch(() => {});
          continue;
        }

        const tempOut = `${out}.download`;
        await result.download.saveAs(tempOut);

        // Never accept HTML/PDF/other attachment bytes as the completed TR,
        // even if the UI accidentally fires a download from another preview.
        const stat = fs.statSync(tempOut);
        const head = fs.readFileSync(tempOut, { encoding: 'utf8' }).slice(0, 4096);
        const looksLikeHtml = /^\s*<!doctype\s+html/i.test(head) || /^\s*<html[\s>]/i.test(head);
        const looksLikePdf = head.startsWith('%PDF-');
        const looksLikeTr = head.includes('■No ') || head.includes('調査結果:') || head.includes('調査結果：');

        if (stat.size <= 0 || looksLikeHtml || looksLikePdf || !looksLikeTr) {
          fs.rmSync(tempOut, { force: true });
          throw new Error(
            `ダウンロードした ${wanted} の内容検証に失敗しました。` +
            ` size=${stat.size}, html=${looksLikeHtml}, pdf=${looksLikePdf}, tr=${looksLikeTr}`
          );
        }

        fs.renameSync(tempOut, out);
        log(`調査済TRを保存・内容検証OK: ${out} (${stat.size} bytes)`);
        return true;
      }
    }
    return false;
  }

  // First use semantic labels/titles if ChatGPT exposes them.
  const semantic = page.locator([
    'button[aria-label*="ダウンロード" i]',
    'button[aria-label*="download" i]',
    'button[title*="ダウンロード" i]',
    'button[title*="download" i]',
    '[role="button"][aria-label*="ダウンロード" i]',
    '[role="button"][aria-label*="download" i]'
  ].join(','));

  if (await tryDownloadButton(semantic, 'semantic')) {
    return out;
  }

  // Fallback for the current preview UI shown in the screenshot:
  // the preview has three round controls at the upper-right:
  // Download / Expand / Close. Download is the LEFTMOST of those controls.
  const viewport = page.viewportSize();
  const width = viewport ? viewport.width : await page.evaluate(() => window.innerWidth);

  const visibleButtons = page.locator('button');
  const topRight = [];
  const buttonCount = await visibleButtons.count();

  for (let i = 0; i < buttonCount; i++) {
    const b = visibleButtons.nth(i);
    if (!(await b.isVisible().catch(() => false))) continue;
    const box = await b.boundingBox().catch(() => null);
    if (!box) continue;

    // Limit to the preview toolbar area at the top-right of the viewport.
    if (box.x > width * 0.72 && box.y >= 0 && box.y < 120) {
      topRight.push({ locator: b, x: box.x, y: box.y });
    }
  }

  topRight.sort((a, b) => a.x - b.x);

  if (topRight.length) {
    log(`プレビュー右上の操作ボタンを ${topRight.length} 個検出しました。`);
    // Screenshot/UI order: Download is the leftmost button.
    const downloadButton = topRight[0].locator;
    if (await tryDownloadButton(downloadButton, 'top-right-leftmost')) {
      return out;
    }
  }

  // Diagnostic output for the next run if the UI changes again.
  const diagnostics = [];
  for (let i = 0; i < buttonCount; i++) {
    const b = visibleButtons.nth(i);
    if (!(await b.isVisible().catch(() => false))) continue;
    const box = await b.boundingBox().catch(() => null);
    if (!box || box.y >= 140) continue;
    diagnostics.push({
      x: Math.round(box.x),
      y: Math.round(box.y),
      aria: await b.getAttribute('aria-label').catch(() => null),
      title: await b.getAttribute('title').catch(() => null),
      testid: await b.getAttribute('data-testid').catch(() => null)
    });
  }
  log(`上部ボタン診断: ${JSON.stringify(diagnostics)}`);

  throw new Error(
    `${wanted} のプレビューは開けましたが、右上のダウンロードボタンから保存できませんでした。`
  );
}

(async () => {
  if (!fs.existsSync(promptFile)) throw new Error(`AIプロンプト.txt がありません: ${promptFile}`);

  log(`通常EdgeへCDP接続します: ${cdpUrl}`);
  const browser = await chromium.connectOverCDP(cdpUrl);
  const contexts = browser.contexts();
  if (!contexts.length) throw new Error('Edgeのブラウザーコンテキストを取得できませんでした。');
  const context = contexts[0];
  const page = await getChatGPTPage(context);

  const loggedIn = await waitForLogin(page);
  if (!loggedIn) {
    throw new Error('ChatGPTのログイン済み画面を確認できませんでした。-SetupLogin で専用Edgeのログイン状態を確認してください。');
  }
  log('ChatGPTログイン状態を確認しました。');

  if (loginOnly) {
    log('ログイン確認OK。');
    await browser.close();
    return;
  }

  const state = loadState();
  const basePrompt = readText(promptFile);
  let hadError = false;
  for (const code of codes()) {
    const prev = state.stocks[code];

    // Normal mode always researches every stock in the current list.
    // This is intentional: Shikiho / earnings / IR materials may have changed
    // since the previous run, so an old OK state must not suppress a new run.
    // --retry-errors remains available when explicitly requested.
    if (!oneCode && retryErrors && prev?.status !== 'ERROR') continue;

    const stockDir = runDirectory(code);
    const uploadDir = path.join(stockDir, 'gpt_upload');

    try {
      if (!fs.existsSync(uploadDir)) throw new Error(`gpt_upload がありません: ${uploadDir}`);
      const files = fs.readdirSync(uploadDir).sort().map(x => path.join(uploadDir, x)).filter(x => fs.statSync(x).isFile());
      if (!files.length) throw new Error('gpt_upload が空です。');
      if (files.length > 10) throw new Error(`gpt_upload が10ファイルを超えています: ${files.length}`);

      const prompt = basePrompt.replaceAll('★★★', code).replaceAll('■■■', serial) +
        `\n・完成したプレーンテキストのファイル名は必ず「TR_${code}_${serial}.txt」としてください。`;

      log(`===== ${code} START =====`);
      state.stocks[code] = { status: 'RUNNING', startedAt: new Date().toISOString() };
      saveState(state);

      await newChat(page);
      await uploadFiles(page, files);
      await fillPrompt(page, prompt);
      await sendPrompt(page);
      await waitForCompletion(page, code);
      const saved = await saveResultFile(page, code, stockDir);

      state.stocks[code] = { status: 'OK', finishedAt: new Date().toISOString(), result: saved, chatUrl: page.url() };
      saveState(state);
      log(`===== ${code} OK =====`);

      if (!oneCode) await sleep(10000);
    } catch (e) {
      hadError = true;
      log(`${code}: ERROR: ${e.stack || e}`);
      state.stocks[code] = { status: 'ERROR', finishedAt: new Date().toISOString(), error: String(e), chatUrl: page.url() };
      saveState(state);
      await sleep(5000);
    }
  }

  log('全処理終了。');
  await browser.close();
  if (hadError) process.exitCode = 1;
})().catch(e => { console.error(e.stack || e); process.exit(1); });
