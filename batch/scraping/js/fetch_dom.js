const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const url = process.argv[2];
  const out = process.argv[3];
  if (!url || !out) {
    console.error('Usage: node fetch_dom.js <url> <output>');
    process.exit(1);
  }
  
  const HARD_TIMEOUT_MS = 90_000;
  const hardTimeout = setTimeout(() => {
    console.error(`[FATAL] hard timeout ${HARD_TIMEOUT_MS}ms url=${url}`);
    process.exit(2);
  }, HARD_TIMEOUT_MS);

  // Proxy settings (from env)  ※scraping_common.php から渡す
  const proxyServer = process.env.WS_PROXY_SERVER || '';
  const proxyUser = process.env.WS_PROXY_USER || '';
  const proxyPass = process.env.WS_PROXY_PASS || '';

  const proxy =
    proxyServer
      ? {
          server: proxyServer,
          ...(proxyUser ? { username: proxyUser } : {}),
          ...(proxyPass ? { password: proxyPass } : {}),
        }
      : null;

  console.log(`[INFO] url=${url}`);
  console.log(`[INFO] out=${out}`);
  console.log(`[INFO] proxy=${proxyServer || '(none)'}`);

  const launchOpt = {
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
    ...(proxy ? { proxy } : {}),
  };

  const browser = await chromium.launch(launchOpt);

  const context = await browser.newContext({
    locale: 'ja-JP',
  });

  const page = await context.newPage();

  await page.setExtraHTTPHeaders({
    'User-Agent':
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' +
      'AppleWebKit/537.36 (KHTML, like Gecko) ' +
      'Chrome/120.0 Safari/537.36',
    'Accept-Language': 'ja-JP,ja;q=0.9,en-US;q=0.8,en;q=0.7',
  });

  // --- ここから改修ポイント ---
  // networkidle は広告/計測があると永遠に終わらないことがあるので、
  // まず domcontentloaded で入る。
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90_000 });
  const status = resp ? resp.status() : 0;
  if (status && status >= 400) {
    console.error(`[WARN] goto status=${status} url=${url}`);
  }
  
  // stocksページはDOM生成が遅い時があるので、主要ブロックを少し待つ
  const isShikihoStocks = /^https:\/\/shikiho\.toyokeizai\.net\/stocks\//i.test(url);
  if (isShikihoStocks) {
    try {
      // 特色/連結事業のDL or スコアブロックが出るまで待つ（どちらか出ればOK）
      await Promise.race([
        page.waitForSelector('dl.information__list', { timeout: 20000 }),
        page.waitForSelector('div.score__chart-wrapper__main', { timeout: 20000 }),
      ]);
    } catch (e) {
      console.error(`[WARN] waitForSelector(stocks key blocks) timeout: ${e && e.message ? e.message : e}`);
      // 続行
    }
  }
  
  // 四季報オンラインの news 一覧は、DOM生成が遅い場合があるので selector 待ちを入れる
  // （stocks ページ等に影響しないようにURLで限定）
  const isShikihoNewsList = /^https:\/\/shikiho\.toyokeizai\.net\/news\?/i.test(url);
  if (isShikihoNewsList) {
    // 記事リンクが出るまで待つ（出ない場合もあるのでタイムアウトは許容）
    try {
      await page.waitForSelector('a.newsList__title', { timeout: 60000 });
    } catch (e) {
      console.error(`[WARN] waitForSelector(newsList__title) timeout: ${e && e.message ? e.message : e}`);
      // 続行（HTMLは取れる範囲で取る）
    }
  }

  // “通信が落ち着くまで”を短時間だけ追加で待つ（失敗しても無視）
  try {
    await page.waitForLoadState('networkidle', { timeout: 8000 });
  } catch (_) {
    // ignore
  }
  // --- ここまで改修ポイント ---

  const html = await page.content();
  fs.writeFileSync(out, html);

  console.log(`[OK] saved: ${out} bytes=${html.length}`);

  await browser.close();
  clearTimeout(hardTimeout);
})();
