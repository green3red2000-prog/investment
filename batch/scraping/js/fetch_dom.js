const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const url = process.argv[2];
  const out = process.argv[3];
  const metaOut = process.argv[4];

  if (!url || !out || !metaOut) {
    console.error(
      'Usage: node fetch_dom.js <url> <output_html> <output_meta_json>'
    );
    process.exit(1);
  }
  
  const HARD_TIMEOUT_MS = 115_000;
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
  console.log(`[INFO] metaOut=${metaOut}`);
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
  
  // HTML解析に不要な重いリソースを遮断する。
  // script、stylesheet、xhr、fetchはDOM生成に必要なので遮断しない。
  await page.route('**/*', async route => {
    const resourceType = route.request().resourceType();

    if (
      resourceType === 'image' ||
      resourceType === 'media' ||
      resourceType === 'font'
    ) {
      await route.abort();
      return;
    }

    await route.continue();
  });

  await page.setExtraHTTPHeaders({
    'User-Agent':
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' +
      'AppleWebKit/537.36 (KHTML, like Gecko) ' +
      'Chrome/120.0 Safari/537.36',
    'Accept-Language': 'ja-JP,ja;q=0.9,en-US;q=0.8,en;q=0.7',
  });

  // --- ここから改修ポイント ---
  // networkidleは広告・計測通信があると完了しないことがあるため、
  // まずcommitでレスポンス受信を確認し、その後DOMを一定時間だけ待つ。
  const resp = await page.goto(url, {
    // 最初のHTTPレスポンスを受け取った時点で先へ進む。
    // 広告や計測処理の影響でDOMContentLoadedが遅れるのを避ける。
    waitUntil: 'commit',
    timeout: 45_000,
  });
  const status = resp ? resp.status() : 0;
  if (status && status >= 400) {
    console.error(`[WARN] goto status=${status} url=${url}`);
  }
  
  try {
    await page.waitForLoadState('domcontentloaded', {
      timeout: 20_000,
    });
  } catch (e) {
    console.error(
      '[WARN] waitForLoadState(domcontentloaded) timeout: ' +
      (e && e.message ? e.message : e)
    );
  }
  
  // 日経ニュース一覧
  const isNikkeiNews = /^https:\/\/www\.nikkei\.com\/news\/category\//i.test(url);

  if (isNikkeiNews) {
    try {
      await page.waitForSelector(
        'article time[datetime]',
        {
          state: 'attached',
          timeout: 45_000,
        }
      );
    } catch (e) {
      console.error(
        '[WARN] waitForSelector(Nikkei article) timeout: ' +
        (e && e.message ? e.message : e)
      );
    }
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
  
  // --- ここまで改修ポイント ---

  const html = await page.content();
  const finalUrl = page.url();
  const title = await page.title();

  fs.writeFileSync(out, html);

  const meta = {
    http_code: status,
    final_url: finalUrl,
    title,
    html_bytes: Buffer.byteLength(html, 'utf8'),
  };

  fs.writeFileSync(
    metaOut,
    JSON.stringify(meta, null, 2),
    'utf8'
  );

  console.log(
    `[OK] saved: ${out} ` +
    `bytes=${meta.html_bytes} ` +
    `status=${status} ` +
    `final_url=${finalUrl}`
  );

  await browser.close();
  clearTimeout(hardTimeout);
})();
