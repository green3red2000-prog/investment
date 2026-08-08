<?php
declare(strict_types=1);

/**
 * 本日のニュース（PHP版）
 * - 日経/ロイター/ブルームバーグ/xTECH/nikkei225jp.com等をスクレイピング
 * - Google Drive: 投資/プログラミング/GAS/スクレイピング/出力結果/ 本日のニュース_最新 を開く
 * - 末尾に追記（時刻は日付型として入れる = USER_ENTERED）
 * - 追記後、時刻(A列)降順でソート
 *
 * 通常起動:
 *   php /opt/invest/scraping/today_news_latest.php
 *
 * テスト起動:
 *   php /opt/invest/scraping/today_news_latest.php --test=NEWS
 *   php /opt/invest/scraping/today_news_latest.php --test=REUTERS
 *   php /opt/invest/scraping/today_news_latest.php --test=BLOOMBERG
 *   php /opt/invest/scraping/today_news_latest.php --test=XTECH
 *   php /opt/invest/scraping/today_news_latest.php --test=SHIKIHO
 *   php /opt/invest/scraping/today_news_latest.php --test=JPX
 *   php /opt/invest/scraping/today_news_latest.php --test=NIKKEI225JP
 *
 * HTMLファイル出力付きテスト:
 *   php /opt/invest/scraping/today_news_latest.php --test=NEWS --fileout
 *
 * テスト起動時:
 * - 指定したサイトの1ページ目のみ取得する
 * - HTTPレスポンスコードを表示する
 * - 取得したHTMLサイズをKB単位で表示する
 * - パースした「時刻」「ソース」「見出し」を表示する
 * - --fileout指定時は /opt/invest/scraping/tmp へHTMLを保存する
 * - Google Drive／Google Sheetsへの書き込みは行わない
 *
 * 【通常起動時の取得対象】
 *
 * ・日経新聞
 * 　　毎回1ページ目を取得する
 * 　　未登録記事が1件以上ある場合は次ページを取得し、
 * 　　未登録記事が0件のページで終了する（最大10ページ）
 *
 * ・ロイター
 * 　　毎回取得（1ページ）
 *     ※現在コメントアウト中
 *
 * ・ブルームバーグ
 * 　　毎回取得（1ページ）
 * 　　※現在コメントアウト中
 *
 * ・日経クロステック
 * 　　毎回取得（1ページ）
 *
 * ・nikkei225jp.com 経済ニュース
 * 　　30分以上経過している場合のみ取得（1ページ）
 *
 * ・四季報オンライン
 * 　　6時間以上経過している場合のみ取得（4ページ）
 *
 * ・JPX：サイト更新情報
 * 　　6時間以上経過している場合のみ取得
 *
 * ・JPX：マーケットニュース
 * 　　6時間以上経過している場合のみ取得
 *
 * ・JPX：お知らせ
 * 　　6時間以上経過している場合のみ取得
 */

require __DIR__ . '/lib/scraping_common.php';
require_once __DIR__ . '/vendor/autoload.php';

// ===============================
// 設定
// ===============================
const TARGET_SPREADSHEET_NAME = '本日のニュース_最新';
const HEADER = ['時刻','ソース','見出し','URL','ID','既読'];

const NEWS_URL     = 'https://www.nikkei.com/news/category/';
const REUTERS_URL  = 'https://jp.reuters.com/';
const BLOOMBERG_URL = 'https://www.bloomberg.com/jp';
const XTECH_URL    = 'https://xtech.nikkei.com/top/latest.html?i_cid=nbpnxt_navi_com_latest';
const NIKKEI225JP_URL = 'https://nikkei225jp.com/news/';
const SHIKIHO_PREFIX = 'https://shikiho.toyokeizai.net';
const SHIKIHO_URLS = [
  'https://shikiho.toyokeizai.net/news?id=original&page=1&date=&qtext=',
  'https://shikiho.toyokeizai.net/news?id=sokuho&page=1&date=&qtext=',
  'https://shikiho.toyokeizai.net/news?id=report&page=1&date=&qtext=',
  'https://shikiho.toyokeizai.net/news?id=tkol&page=1&date=&qtext=',
];

// ==== JPX 実行制御 ====
const JPX_PREFIX = 'https://www.jpx.co.jp';
const JPX_URL_SITE_UPDATES = 'https://www.jpx.co.jp/site-updates/index.html';
const JPX_URL_MARKET_NEWS  = 'https://www.jpx.co.jp/news/index.html?category=&year=&month=&day=&number=100';
const JPX_URL_INFO         = 'https://www.jpx.co.jp/corporate/news/news-releases/index.html?category=&year=&month=&day=&number=100';

const SOURCE_NAME_NIKKEI    = '日経新聞';
const SOURCE_NAME_REUTERS  = 'ロイター';
const SOURCE_NAME_BLOOMBERG= 'ブルームバーグ';
const SOURCE_NAME_XTECH    = '日経クロステック';
const SHIKIHO_SOURCE_NAME = '四季報オンライン';
const JPX_SOURCE_SITE_UPDATES = 'JPX：サイト更新情報';
const JPX_SOURCE_MARKET_NEWS  = 'JPX：マーケットニュース';
const JPX_SOURCE_INFO         = 'JPX：お知らせ';

const TIMEZONE = 'Asia/Tokyo';
const MAX_PAGE = 10;   // 日経 ?page=1..10
const SLEEP_BETWEEN_PAGES_US = 2_500_000;

// ==== nikkei225jp.com 実行制御 ====
const NIKKEI225JP_INTERVAL_SEC = 30 * 60; // 30分
const NIKKEI225JP_LASTRUN_FILE = '/opt/invest/scraping/state/last_run_nikkei225jp.txt';

// ==== 四季報オンライン 実行制御 ====
const SHIKIHO_INTERVAL_SEC = 6 * 60 * 60; // 6時間
const SHIKIHO_LASTRUN_FILE = '/opt/invest/scraping/state/last_run_shikiho.txt';
const SHIKIHO_MAX_AGE_DAYS = 8; // ★ 8日前より古いニュースは反映しない

const JPX_INTERVAL_SEC = 6 * 60 * 60; // 6時間
const JPX_LASTRUN_FILE = '/opt/invest/scraping/state/last_run_jpx.txt';

// ===============================
// 起動引数
// ===============================
$cliOptions = getopt('', [
  'test:',
  'fileout',
]);

$testTarget = isset($cliOptions['test'])
  ? strtoupper(trim((string)$cliOptions['test']))
  : '';

$fileout = array_key_exists('fileout', $cliOptions);

if ($fileout && $testTarget === '') {
  fwrite(STDERR, "[ERROR] --fileout は --test と同時に指定してください。\n");
  exit(1);
}

if ($testTarget !== '') {
  // テスト中は取得開始時に選んだ1個のプロキシを固定する
  http_session_begin(true);

  $testStartedAt = microtime(true);
  $testExitCode  = 0;

  echo "[TEST] Start  : " .
    (new DateTime(
      'now',
      new DateTimeZone(TIMEZONE)
    ))->format('Y-m-d H:i:s') .
    "\n";

  try {
    run_test_mode_($testTarget, $fileout);
  } catch (Throwable $e) {
    fwrite(
      STDERR,
      "[TEST][ERROR] " . $e->getMessage() . "\n"
    );

    $testExitCode = 1;
  } finally {
    $elapsedSec = microtime(true) - $testStartedAt;

    echo "[TEST] End    : " .
      (new DateTime(
        'now',
        new DateTimeZone(TIMEZONE)
      ))->format('Y-m-d H:i:s') .
      "\n";

    printf(
      "[TEST] Elapsed: %.3f sec\n",
      $elapsedSec
    );
  }

  exit($testExitCode);
}

// proxy は通常実行ではリクエストごとに選択
http_session_begin(false);

// ===============================
// メイン
// ===============================
$client = build_oauth_client_for_drive_and_sheets_();
$drive  = new Google\Service\Drive($client);
$sheets = new Google\Service\Sheets($client);

// 対象スプレッドシートを Drive の所定パス配下から探す
$folderId = resolve_folder_id_by_path_($drive, DRIVE_PATH_UPLOAD_OUT);
$spreadsheetId = find_spreadsheet_id_in_folder_($drive, $folderId, TARGET_SPREADSHEET_NAME);

$now = (new DateTime('now', new DateTimeZone(TIMEZONE)))->format('Y-m-d H:i:s');
echo "[INFO] now={$now} Spreadsheet: " . TARGET_SPREADSHEET_NAME . " (id={$spreadsheetId})\n";


// 先頭シートを使用（news_test_append_sort.php と同様）
$ss = $sheets->spreadsheets->get($spreadsheetId);
$sheet0 = $ss->getSheets()[0] ?? null;
if (!$sheet0) throw new RuntimeException("No sheets found in spreadsheet.");

$sheetId    = (int)$sheet0->getProperties()->getSheetId();
$sheetTitle = (string)$sheet0->getProperties()->getTitle();

echo "[INFO] Using sheet: {$sheetTitle} (sheetId={$sheetId})\n";

// ヘッダ確認（A1:F1）
$headerRange = "{$sheetTitle}!A1:F1";
$headerRes = $sheets->spreadsheets_values->get($spreadsheetId, $headerRange);
$headerVals = $headerRes->getValues()[0] ?? [];
$headerVals = array_map('strval', $headerVals);

if ($headerVals !== HEADER) {
  throw new RuntimeException(
    "Header mismatch.\nExpected: " . json_encode(HEADER, JSON_UNESCAPED_UNICODE) .
    "\nActual:   " . json_encode($headerVals, JSON_UNESCAPED_UNICODE)
  );
}
echo "[INFO] Header OK\n";

// 既存URL一覧（D列）をSet化
$existingUrls = get_existing_urls_set_($sheets, $spreadsheetId, $sheetTitle);
echo "[INFO] Existing URLs: " . count($existingUrls) . "\n";

// スクレイピングして newRows を作る
$newRows = [];

// ---- 日経新聞 ----
for ($page = 1; $page <= MAX_PAGE; $page++) {
  $url = ($page === 1)
    ? NEWS_URL
    : (NEWS_URL . '?page=' . $page);

  // このページで新規に見つかった記事数
  $newCountOnPage = 0;

  try {
    $html = http_get_text_browser($url, [
      'timeout'   => 120,
      'retry_max' => 3,
    ]);

    $articles = parse_nikkei_news_($html);
    
    if (count($articles) === 0) {
      throw new RuntimeException(
      "日経新聞のパース結果が0件でした。"
      );
    }

    foreach ($articles as $a) {
      $u = $a['url'];

      if (isset($existingUrls[$u])) {
        continue;
      }

      $existingUrls[$u] = true;
      $newCountOnPage++;

      $jst = iso_to_jst_string_($a['datetime']);
      $id  = make_id16_($jst, $a['title']);

      $newRows[] = [
        $jst,
        SOURCE_NAME_NIKKEI,
        $a['title'],
        $u,
        $id,
        '',
      ];
    }

    echo
      "[INFO] Nikkei page {$page}: " .
      "articles=" . count($articles) . " " .
      "new={$newCountOnPage}\n";

  } catch (Throwable $e) {
    fwrite(
      STDERR,
      "[WARN] Nikkei page {$page} fetch/parse failed: " .
      $e->getMessage() .
      "\n"
    );

    /*
     * 取得失敗時は「全件既存」とは判断できないため、
     * 次ページへ進まず終了する。
     */
    break;
  }

  /*
   * このページに未登録記事が1件もなければ、
   * それより古い次ページ以降も取得しない。
   */
  if ($newCountOnPage === 0) {
    echo
      "[INFO] Nikkei: stop at page {$page} " .
      "(no new articles)\n";

    break;
  }

  if ($page < MAX_PAGE) {
    usleep(SLEEP_BETWEEN_PAGES_US);
  }
}

// ---- ロイター ----
/*
try {
  $html = http_get_text_browser(REUTERS_URL, ['timeout'   => 120,'retry_max' => 3,]);
  $articles = parse_reuters_news_($html);

  foreach ($articles as $a) {
    $u = $a['url'];
    if (isset($existingUrls[$u])) continue;
    $existingUrls[$u] = true;

    $jst = iso_to_jst_string_($a['datetime']);
    $id  = make_id16_($jst, $a['title']);

    $newRows[] = [$jst, SOURCE_NAME_REUTERS, $a['title'], $u, $id, ''];
  }
} catch (Throwable $e) {
  fwrite(STDERR, "[WARN] Reuters fetch/parse failed: " . $e->getMessage() . "\n");
}
*/

// ---- ブルームバーグ ----
/*
try {
  $html = http_get_text_browser(BLOOMBERG_URL, ['timeout'   => 120,'retry_max' => 3,]);
  $articles = parse_bloomberg_news_($html);

  foreach ($articles as $a) {
    $u = $a['url'];
    if (isset($existingUrls[$u])) continue;
    $existingUrls[$u] = true;

    $jst = iso_to_jst_string_($a['datetime']);
    $id  = make_id16_($jst, $a['title']);

    $newRows[] = [$jst, SOURCE_NAME_BLOOMBERG, $a['title'], $u, $id, ''];
  }
} catch (Throwable $e) {
  fwrite(STDERR, "[WARN] Bloomberg fetch/parse failed: " . $e->getMessage() . "\n");
}
*/

// ---- xTECH（時刻は「現在時刻(JST)」仕様） ----
try {
  $html = http_get_text_browser(XTECH_URL, ['timeout'   => 120,'retry_max' => 3,]);
  $articles = parse_xtech_news_($html);

  $nowJst = now_jst_string_();
  foreach ($articles as $a) {
    $u = $a['url'];
    if (isset($existingUrls[$u])) continue;
    $existingUrls[$u] = true;

    $id  = make_id16_($nowJst, $a['title']);
    $newRows[] = [$nowJst, SOURCE_NAME_XTECH, $a['title'], $u, $id, ''];
  }
} catch (Throwable $e) {
  fwrite(STDERR, "[WARN] xTECH fetch/parse failed: " . $e->getMessage() . "\n");
}

// ---- nikkei225jp.com 経済ニュース（30分に1回） ----
if (should_run_nikkei225jp_()) {
  echo "[INFO] NIKKEI225JP: start (interval OK)\n";

  try {
    /*
     * HTTP 200でも、プロキシによっては記事一覧を含まないHTMLが
     * 返る場合があるため、HTML取得とパースをまとめて再試行する。
     */
    $articles = [];
    $lastParseError = null;
    $fetchParseRetryMax = 3;

    for (
      $fetchParseTry = 1;
      $fetchParseTry <= $fetchParseRetryMax;
      $fetchParseTry++
    ) {
      try {
        /*
         * メタ情報付きで取得し、
         * 通常起動ログにも使用プロキシ等を出力する。
         *
         * 外側で取得＋パースを再試行するため、
         * 1回ごとのHTTP取得リトライは1回とする。
         */
        $response = http_get_text_browser_with_meta(
          NIKKEI225JP_URL,
          [
            'timeout'          => 120,
            'retry_max'        => 1,
            'allow_http_error' => false,
            'defer_proxy_success' => true,
          ]
        );

        $html = (string)($response['html'] ?? '');
        $httpCode = (int)($response['http_code'] ?? 0);
        $proxy = (string)($response['proxy'] ?? '');
        $pageTitle = (string)($response['title'] ?? '');
        $htmlSizeKb = (int)round(strlen($html) / 1024);

        $articles = parse_nikkei225jp_news_($html);
        $articleCount = count($articles);

        echo
          "[INFO] NIKKEI225JP attempt " .
          "{$fetchParseTry}/{$fetchParseRetryMax}: " .
          "proxy={$proxy} " .
          "http={$httpCode} " .
          "title={$pageTitle} " .
          "html={$htmlSizeKb}KB " .
          "articles={$articleCount}\n";

        if ($articleCount === 0) {
          remember_url_proxy_failure_(
            NIKKEI225JP_URL,
            $proxy
          );

          throw new RuntimeException(
            'nikkei225jp.comのパース結果が0件でした。'
          );
        }

        /*
         * HTML取得だけでなくパースも正常だったため成功。
         */
        remember_url_proxy_success_(
          NIKKEI225JP_URL,
          $proxy
        );

        $lastParseError = null;
        break;

      } catch (Throwable $e) {
        $lastParseError = $e;

        fwrite(
          STDERR,
          "[WARN] NIKKEI225JP attempt " .
          "{$fetchParseTry}/{$fetchParseRetryMax} failed: " .
          $e->getMessage() .
          "\n"
        );

        if ($fetchParseTry >= $fetchParseRetryMax) {
          break;
        }

        /*
         * 通常起動はhttp_session_begin(false)なので、
         * 次の取得では別のプロキシがランダム選択される。
         */
        usleep(1_200_000);
      }
    }

    if (count($articles) === 0) {
      throw new RuntimeException(
        'nikkei225jp.comの取得・パースが' .
        "{$fetchParseRetryMax}回すべて失敗しました。" .
        (
          $lastParseError !== null
            ? ' 最終エラー: ' . $lastParseError->getMessage()
            : ''
        )
      );
    }

    $newCount = 0;

    foreach ($articles as $a) {
      $u = $a['url'];

      if (isset($existingUrls[$u])) {
        continue;
      }

      $existingUrls[$u] = true;

      $jst = $a['datetime'];
      $id  = make_id16_($jst, $a['title']);

      $newRows[] = [
        $jst,
        $a['source'],
        $a['title'],
        $u,
        $id,
        '',
      ];

      $newCount++;
    }

    /*
     * HTML取得とパースに成功した場合のみ、
     * 次回取得を30分後まで抑制する。
     */
    mark_nikkei225jp_ran_();

    echo
      '[INFO] NIKKEI225JP: success' .
      ' articles=' . count($articles) .
      ' new=' . $newCount .
      "\n";

    echo "[INFO] NIKKEI225JP: marked last_run\n";

  } catch (Throwable $e) {
    /*
     * 失敗時はlast_runを更新しないため、
     * 次の3分cronで再試行される。
     */
    fwrite(
      STDERR,
      '[WARN] NIKKEI225JP fetch/parse failed: ' .
      $e->getMessage() .
      "\n"
    );
  }

} else {
  echo "[INFO] NIKKEI225JP: skipped (interval not reached)\n";
}


// ---- 四季報オンライン（6時間に1回） ----
if (should_run_shikiho_()) {
  echo "[INFO] Shikiho: start (interval OK)\n";

  try {
    foreach (SHIKIHO_URLS as $url) {
      $html = http_get_text_browser($url, [
        'timeout'   => 120,
        'retry_max' => 2,
      ]);

      $articles = parse_shikiho_news_($html);

      foreach ($articles as $a) {
        $u = $a['url'];
        if (isset($existingUrls[$u])) continue;
        $existingUrls[$u] = true;

        $jst = shikiho_mdhi_to_jst_string_($a['mdhi']);
        // ★ 8日前より古いものは除外
        if (is_older_than_days_($jst, SHIKIHO_MAX_AGE_DAYS)) {
           continue;
        }
        $id  = make_id16_($jst, $a['title']);

        $newRows[] = [$jst, SHIKIHO_SOURCE_NAME, $a['title'], $u, $id, ''];
      }
    }
  } catch (Throwable $e) {
    fwrite(STDERR, "[WARN] Shikiho fetch/parse failed: " . $e->getMessage() . "\n");
  } finally {
    // ★ 成功/失敗に関わらず「今回実行した」扱いにして6時間抑制
    mark_shikiho_ran_();
    echo "[INFO] Shikiho: marked last_run\n";
  }
} else {
  echo "[INFO] Shikiho: skipped (interval not reached)\n";
}

// ---- JPX（6時間に1回） ----
if (should_run_jpx_()) {
  echo "[INFO] JPX: start (interval OK)\n";

  /*
   * 3つの取得元は個別に処理する。
   * 1つが失敗しても、残りの取得元は続行する。
   */
  $jpxSuccessCount = 0;

  // (A) サイト更新情報
  try {

    $response = http_get_text_browser_with_meta(
      JPX_URL_SITE_UPDATES,
      [
        'timeout'          => 120,
        'retry_max'        => 2,
        'allow_http_error' => true,
      ]
    );

    $html = (string)($response['html'] ?? '');
    $httpCode = (int)($response['http_code'] ?? 0);
    $proxy = (string)($response['proxy'] ?? '');
    $pageTitle = (string)($response['title'] ?? '');
    $htmlSizeKb = (int)round(strlen($html) / 1024);

    if ($httpCode !== 200) {
      throw new RuntimeException(
        "HTTPレスポンスコードが200ではありません: {$httpCode}"
      );
    }

    if ($html === '') {
      throw new RuntimeException(
        '取得したHTMLが空です。'
      );
    }
    
    $items = parse_jpx_site_updates_($html);

    if (count($items) === 0) {
      throw new RuntimeException(
        "JPXサイト更新情報のパース結果が0件でした。"
      );
    }

    echo
      "[INFO] JPX site updates fetch: " .
      "proxy={$proxy} " .
      "http={$httpCode} " .
      "title={$pageTitle} " .
      "html={$htmlSizeKb}KB " .
      "items=" . count($items) .
      "\n";

    $nowHms = (new DateTime('now', new DateTimeZone(TIMEZONE)))->format('H:i:s');

    foreach ($items as $a) {
      $u = $a['url'];
      if (isset($existingUrls[$u])) continue;

      $jst = jpx_ymd_to_jst_string_($a['ymd'], $nowHms); // yyyy-MM-dd HH:mm:ss
      if (is_older_than_days_($jst, SHIKIHO_MAX_AGE_DAYS)) continue;

      $existingUrls[$u] = true;
      $id  = make_id16_($jst, $a['title']);
      $newRows[] = [$jst, JPX_SOURCE_SITE_UPDATES, $a['title'], $u, $id, ''];
    }

    $jpxSuccessCount++;

    echo "[INFO] JPX site updates: success\n";

  } catch (Throwable $e) {
    fwrite(
      STDERR,
      "[WARN] JPX site updates fetch/parse failed: " .
      $e->getMessage() .
      "\n"
    );
  }

  // 各JPX取得元で同じ現在時刻を使用する
  $nowHms = (new DateTime(
    'now',
    new DateTimeZone(TIMEZONE)
  ))->format('H:i:s');

  // (B) マーケットニュース
  try {

    $response = http_get_text_browser_with_meta(
      JPX_URL_MARKET_NEWS,
      [
        'timeout'          => 120,
        'retry_max'        => 2,
        'allow_http_error' => true,
      ]
    );

    $html = (string)($response['html'] ?? '');
    $httpCode = (int)($response['http_code'] ?? 0);
    $proxy = (string)($response['proxy'] ?? '');
    $pageTitle = (string)($response['title'] ?? '');
    $htmlSizeKb = (int)round(strlen($html) / 1024);

    if ($httpCode !== 200) {
      throw new RuntimeException(
        "HTTPレスポンスコードが200ではありません: {$httpCode}"
      );
    }

    if ($html === '') {
      throw new RuntimeException(
        '取得したHTMLが空です。'
      );
    }
    
    $items = parse_jpx_list_common_($html, 'JPX-news-list-date', 'JPX-news-list-title');

    if (count($items) === 0) {
      throw new RuntimeException(
        "JPXマーケットニュースのパース結果が0件でした。"
      );
    }

    echo
      "[INFO] JPX market news fetch: " .
      "proxy={$proxy} " .
      "http={$httpCode} " .
      "title={$pageTitle} " .
      "html={$htmlSizeKb}KB " .
      "items=" . count($items) .
      "\n";

    foreach ($items as $a) {
      $u = $a['url'];
      if (isset($existingUrls[$u])) continue;

      $jst = jpx_ymd_to_jst_string_($a['ymd'], $nowHms);
      if (is_older_than_days_($jst, SHIKIHO_MAX_AGE_DAYS)) continue;

      $existingUrls[$u] = true;
      $id  = make_id16_($jst, $a['title']);
      $newRows[] = [$jst, JPX_SOURCE_MARKET_NEWS, $a['title'], $u, $id, ''];
    }

    $jpxSuccessCount++;

    echo "[INFO] JPX market news: success\n";

  } catch (Throwable $e) {
    fwrite(
      STDERR,
      "[WARN] JPX market news fetch/parse failed: " .
      $e->getMessage() .
      "\n"
    );
  }

  // (C) お知らせ（news-releases）
  try {
    
    $response = http_get_text_browser_with_meta(
      JPX_URL_INFO,
      [
        'timeout'          => 120,
        'retry_max'        => 2,
        'allow_http_error' => true,
      ]
    );

    $html = (string)($response['html'] ?? '');
    $httpCode = (int)($response['http_code'] ?? 0);
    $proxy = (string)($response['proxy'] ?? '');
    $pageTitle = (string)($response['title'] ?? '');
    $htmlSizeKb = (int)round(strlen($html) / 1024);

    if ($httpCode !== 200) {
      throw new RuntimeException(
        "HTTPレスポンスコードが200ではありません: {$httpCode}"
      );
    }

    if ($html === '') {
      throw new RuntimeException(
        '取得したHTMLが空です。'
      );
    }    
    
    $items = parse_jpx_list_common_($html, 'JPX-news-list-date', 'JPX-news-list-title');

    if (count($items) === 0) {
      throw new RuntimeException(
        "JPXお知らせのパース結果が0件でした。"
      );
    }

    echo
      "[INFO] JPX info fetch: " .
      "proxy={$proxy} " .
      "http={$httpCode} " .
      "title={$pageTitle} " .
      "html={$htmlSizeKb}KB " .
      "items=" . count($items) .
      "\n";

    foreach ($items as $a) {
      $u = $a['url'];
      if (isset($existingUrls[$u])) continue;

      $jst = jpx_ymd_to_jst_string_($a['ymd'], $nowHms);
      if (is_older_than_days_($jst, SHIKIHO_MAX_AGE_DAYS)) continue;

      $existingUrls[$u] = true;
      $id  = make_id16_($jst, $a['title']);
      $newRows[] = [$jst, JPX_SOURCE_INFO, $a['title'], $u, $id, ''];
    }
    $jpxSuccessCount++;

    echo "[INFO] JPX info: success\n";

  } catch (Throwable $e) {

    fwrite(
      STDERR,
      "[WARN] JPX info fetch/parse failed: " .
      $e->getMessage() .
      "\n"
    );
  }

  /*
   * 3取得元のうち、少なくとも1つが正常に取得・パースできた場合のみ
   * 今回実行済みとして6時間抑制する。
   */
  if ($jpxSuccessCount > 0) {

    mark_jpx_ran_();
    
    echo
      "[INFO] JPX: marked last_run" .
      " success={$jpxSuccessCount}/3\n";

  } else {
    /*
     * 全取得元が失敗した場合はlast_runを更新しない。
     * 次回の3分cronで再試行する。
     */
    fwrite(
      STDERR,
      "[WARN] JPX: all sources failed. " .
      "last_run was not updated.\n"
    );
  }
  
} else {
  echo "[INFO] JPX: skipped (interval not reached)\n";
}

echo "[INFO] New rows: " . count($newRows) . "\n";

// 追記（時刻を日付型に寄せるため USER_ENTERED）
if (count($newRows) > 0) {
  $appendRange = "{$sheetTitle}!A:F";
  $body = new Google\Service\Sheets\ValueRange(['values' => $newRows]);

  $appendParams = [
    'valueInputOption' => 'USER_ENTERED', // ★ここが重要（RAWだと文字列になる）
    'insertDataOption' => 'INSERT_ROWS',
  ];

  $sheets->spreadsheets_values->append($spreadsheetId, $appendRange, $body, $appendParams);
  echo "[INFO] Appended.\n";
} else {
  echo "[INFO] Nothing to append.\n";
}

// 総行数を A列で把握し、2行目以降を時刻降順ソート
$totalRows = get_total_rows_by_colA_($sheets, $spreadsheetId, $sheetTitle);
if ($totalRows > 1) {
  sort_by_time_desc_($sheets, $spreadsheetId, $sheetId, $totalRows);
  echo "[INFO] Sorted by 時刻 DESC.\n";
}

echo "DONE\n";

// ===============================
// テスト起動
// ===============================

/**
 * 指定したニュースサイトの1ページ目だけを取得し、
 * HTTPコード、HTMLサイズ、パース結果をコンソールへ表示する。
 *
 * --fileout指定時は、取得HTMLをSCRAPING_TMP_DIRへ保存する。
 */
function run_test_mode_(string $target, bool $fileout): void {

  $targets = [
    'NEWS' => [
      'url'    => NEWS_URL,
      'source' => SOURCE_NAME_NIKKEI,
      'parser' => 'parse_nikkei_news_',
    ],
    'REUTERS' => [
      'url'    => REUTERS_URL,
      'source' => SOURCE_NAME_REUTERS,
      'parser' => 'parse_reuters_news_',
    ],
    'BLOOMBERG' => [
      'url'    => BLOOMBERG_URL,
      'source' => SOURCE_NAME_BLOOMBERG,
      'parser' => 'parse_bloomberg_news_',
    ],
    'XTECH' => [
      'url'    => XTECH_URL,
      'source' => SOURCE_NAME_XTECH,
      'parser' => 'parse_xtech_news_',
    ],
    'NIKKEI225JP' => [
      'url'    => NIKKEI225JP_URL,
      /*
       * 配信元は記事ごとに異なるため、
       * parse_nikkei225jp_news_()のsourceを使用する。
       */
      'source' => '',
      'parser' => 'parse_nikkei225jp_news_',
    ],
    'SHIKIHO' => [
      // 四季報はSHIKIHO_URLSの先頭だけをテストする
      'url'    => SHIKIHO_URLS[0],
      'source' => SHIKIHO_SOURCE_NAME,
      'parser' => 'parse_shikiho_news_',
    ],
    'JPX' => [
      // JPXはマーケットニュースだけをテストする
      'url'    => JPX_URL_MARKET_NEWS,
      'source' => JPX_SOURCE_MARKET_NEWS,
      'parser' => null,
    ],
  ];

  if (!isset($targets[$target])) {
    throw new InvalidArgumentException(
      "不正な --test の値です: {$target}\n" .
      "指定可能値: NEWS, REUTERS, BLOOMBERG, XTECH, NIKKEI225JP, SHIKIHO, JPX"
    );
  }

  $config = $targets[$target];
  $url    = (string)$config['url'];
  $source = (string)$config['source'];
  $parser = (string)$config['parser'];

  echo "========================================\n";
  echo "[TEST] Target : {$target}\n";
  echo "[TEST] URL    : {$url}\n";
  echo "========================================\n";

 $result = http_get_text_browser_with_meta($url, ['timeout'          => 120,'retry_max'        => 1, 'allow_http_error' => true,]);

  $html       = $result['html'];
  $httpCode   = $result['http_code'];
  $proxy      = $result['proxy'];
  $finalUrl   = $result['final_url'];
  $pageTitle  = $result['title'];
  $htmlSizeKb = (int)round(strlen($html) / 1024);

  echo "プロキシ              : {$proxy}\n";
  echo "HTTPレスポンスコード  : {$httpCode}\n";
  echo "最終URL               : {$finalUrl}\n";
  echo "ページタイトル        : {$pageTitle}\n";
  echo "HTMLサイズ            : {$htmlSizeKb}KB\n";

  if ($fileout) {
    ensure_dir(SCRAPING_TMP_DIR);

    $timestamp = (new DateTime(
      'now',
      new DateTimeZone(TIMEZONE)
    ))->format('YmdHis');

    $outPath = SCRAPING_TMP_DIR .
      '/' .
      $target .
      '_' .
      $timestamp .
      '.html';

    if (file_put_contents($outPath, $html) === false) {
      throw new RuntimeException(
        "HTMLファイルの出力に失敗しました: {$outPath}"
      );
    }

    echo "HTML出力先            : {$outPath}\n";
  }

  if ($httpCode !== 200) {
    throw new RuntimeException(
      "HTTPレスポンスコードが200ではありません: {$httpCode}"
    );
  }

  if ($target === 'JPX') {
    $articles = parse_jpx_list_common_(
      $html,
      'JPX-news-list-date',
      'JPX-news-list-title'
    );
  } else {
    if (!is_string($parser) || !is_callable($parser)) {
      throw new RuntimeException(
        "パース関数が呼び出せません: " . (string)$parser
      );
    }

    $articles = $parser($html);
  }

  $articleCount = count($articles);

  echo "パース件数            : {$articleCount}件\n";

  if ($articleCount === 0) {
    throw new RuntimeException(
      "HTMLは取得できましたが、パース結果が0件でした。"
    );
  }

  echo "----------------------------------------\n";
  echo "時刻\tソース\t見出し\n";
  echo "----------------------------------------\n";

  $nowJst = now_jst_string_();
  $nowHms = (new DateTime(
    'now',
    new DateTimeZone(TIMEZONE)
  ))->format('H:i:s');

  foreach ($articles as $article) {
    switch ($target) {
      case 'NEWS':
      case 'REUTERS':
      case 'BLOOMBERG':
        $time = iso_to_jst_string_(
          (string)$article['datetime']
        );
        break;

      case 'XTECH':
        // 通常処理と同様、取得時点の現在時刻を使用する
        $time = $nowJst;
        break;
        
      case 'NIKKEI225JP':
        /*
         * パーサー側で
         * yyyy-MM-dd HH:mm:ssへ変換済み。
         */
        $time = (string)$article['datetime'];
        break;

      case 'SHIKIHO':
        $time = shikiho_mdhi_to_jst_string_(
          (string)$article['mdhi']
        );
        break;
        
      case 'JPX':
        $time = jpx_ymd_to_jst_string_(
          (string)$article['ymd'],
          $nowHms
        );
        break;

      default:
        throw new LogicException(
          "未対応のテスト対象です: {$target}"
        );
    }

    $title = trim((string)($article['title'] ?? ''));

    /*
     * 記事データにsourceがあればそれを優先する。
     * nikkei225jp.comは記事ごとに配信元が異なる。
     */
    $articleSource = trim(
      (string)($article['source'] ?? $source)
    );

    echo "{$time}\t{$articleSource}\t{$title}\n";
  }

  echo "----------------------------------------\n";
  echo "[TEST] DONE\n";
}

// ===============================
// Google OAuth (Drive + Sheets) / Drive検索
// ===============================

function build_oauth_client_for_drive_and_sheets_(): Google\Client {
  $tokenJson = (string)($GLOBALS['SCRAPING_TOKEN_JSON'] ?? '');
  if ($tokenJson === '') throw new RuntimeException("token json path not set");
  if (!file_exists(OAUTH_CLIENT_JSON)) throw new RuntimeException("OAuth client json not found: " . OAUTH_CLIENT_JSON);

  $client = new Google\Client();
  $client->setApplicationName('invest-news-php');
  $client->setAuthConfig(OAUTH_CLIENT_JSON);
  $client->setScopes([
    Google\Service\Drive::DRIVE,
    Google\Service\Sheets::SPREADSHEETS,
  ]);
  $client->setAccessType('offline');
  $client->setPrompt('select_account consent');
  $client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');

  if (file_exists($tokenJson)) {
    $token = json_decode((string)file_get_contents($tokenJson), true);
    if (is_array($token)) $client->setAccessToken($token);
  }

  if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
      $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    } else {
      $client = reauthorize_interactive_($client); // scraping_common.php の関数
    }
    ensure_dir(dirname($tokenJson));
    file_put_contents($tokenJson, json_encode($client->getAccessToken(), JSON_UNESCAPED_SLASHES));
  }

  return $client;
}

function find_spreadsheet_id_in_folder_(Google\Service\Drive $drive, string $folderId, string $name): string {
  $q = sprintf(
    "name = '%s' and '%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.spreadsheet'",
    str_replace("'", "\\'", $name),
    $folderId
  );

  $res = $drive->files->listFiles([
    'q' => $q,
    'fields' => 'files(id,name)',
    'pageSize' => 10,
  ]);

  $files = $res->getFiles();
  if (!$files || count($files) === 0) throw new RuntimeException("Spreadsheet not found in folder: {$name}");
  return (string)$files[0]->getId();
}

// ===============================
// Sheets操作（URL取得 / 行数 / ソート）
// ===============================

function get_existing_urls_set_(Google\Service\Sheets $sheets, string $spreadsheetId, string $sheetTitle): array {
  // D2:D を取得（空セル含む場合もあるので values ベースで十分）
  $range = "{$sheetTitle}!D2:D";
  $res = $sheets->spreadsheets_values->get($spreadsheetId, $range);
  $vals = $res->getValues() ?? [];

  $set = [];
  foreach ($vals as $row) {
    $u = isset($row[0]) ? trim((string)$row[0]) : '';
    if ($u !== '') $set[$u] = true;
  }
  return $set;
}

function get_total_rows_by_colA_(Google\Service\Sheets $sheets, string $spreadsheetId, string $sheetTitle): int {
  $res = $sheets->spreadsheets_values->get($spreadsheetId, "{$sheetTitle}!A:A");
  $vals = $res->getValues() ?? [];
  return count($vals); // ヘッダ含む
}

function sort_by_time_desc_(Google\Service\Sheets $sheets, string $spreadsheetId, int $sheetId, int $totalRows): void {
  $requests = [
    new Google\Service\Sheets\Request([
      'sortRange' => new Google\Service\Sheets\SortRangeRequest([
        'range' => new Google\Service\Sheets\GridRange([
          'sheetId' => $sheetId,
          'startRowIndex' => 1,          // 2行目
          'endRowIndex'   => $totalRows, // exclusive
          'startColumnIndex' => 0,       // A
          'endColumnIndex'   => 6,       // F
        ]),
        'sortSpecs' => [
          new Google\Service\Sheets\SortSpec([
            'dimensionIndex' => 0,          // A列（時刻）
            'sortOrder' => 'DESCENDING',
          ]),
        ],
      ]),
    ]),
  ];

  $batchBody = new Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => $requests]);
  $sheets->spreadsheets->batchUpdate($spreadsheetId, $batchBody);
}

// ===============================
// 日付/ID
// ===============================

function iso_to_jst_string_(string $iso): string {
  // ISO8601 / "2026-01-18T01:23:45Z" / "+00:00" など想定
  $dt = new DateTime($iso);
  $dt->setTimezone(new DateTimeZone(TIMEZONE));
  return $dt->format('Y-m-d H:i:s');
}

function now_jst_string_(): string {
  $dt = new DateTime('now', new DateTimeZone(TIMEZONE));
  return $dt->format('Y-m-d H:i:s');
}

function make_id16_(string $timeStr, string $title): string {
  $src = $timeStr . '|' . $title;
  return substr(hash('sha256', $src), 0, 16);
}

// ===============================
// HTMLパース（GAS版の正規表現を移植）
// ===============================

function parse_nikkei_news_(string $html): array {
  $results = [];

  $re = '/<div class="default_d1slj7py"[^>]*>([\s\S]*?)<\/article>\s*<\/div>/';
  if (!preg_match_all($re, $html, $m)) return $results;

  foreach ($m[1] as $block) {
    if (!preg_match('/<time[^>]*date[Tt]ime="([^"]+)"/i', $block, $tm)) continue;
    $datetime = $tm[1];

    if (!preg_match('/<a[^>]*class="[^"]*fauxBlockLink_[^"]*"[^>]*>[\s\S]*?<\/a>/', $block, $am)) continue;
    $aTag = $am[0];

    if (!preg_match('/href="([^"]+)"/', $aTag, $hm)) continue;
    $href = $hm[1];

    $titleHtml = preg_replace('/^<a[^>]*>/', '', $aTag);
    $titleHtml = preg_replace('/<\/a>$/', '', $titleHtml);
    $titleText = trim(html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if (strpos($href, '/') === 0) $href = 'https://www.nikkei.com' . $href;

    $results[] = ['datetime' => $datetime, 'title' => $titleText, 'url' => $href];
  }

  return $results;
}

function parse_reuters_news_(string $html): array {
  $results = [];

  $re = '/<div class="basic-card-module__body__yIIcL"[^>]*>([\s\S]*?)<\/div>/';
  if (!preg_match_all($re, $html, $m)) return $results;

  foreach ($m[1] as $block) {
    if (!preg_match('/<time[^>]*datetime="([^"]+)"/i', $block, $tm)) continue;
    $datetime = $tm[1];

    if (!preg_match('/<a[^>]*data-testid="Title"[^>]*href="([^"]+)"/', $block, $hm)) continue;
    $href = $hm[1];

    if (!preg_match('/<h3[^>]*data-testid="Heading"[^>]*>([\s\S]*?)<\/h3>/', $block, $titleM)) continue;
    $titleText = trim(html_entity_decode(strip_tags($titleM[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if (strpos($href, '/') === 0) $href = 'https://jp.reuters.com' . $href;

    $results[] = ['datetime' => $datetime, 'title' => $titleText, 'url' => $href];
  }

  return $results;
}

function parse_bloomberg_news_(string $html): array {
  $results = [];

  /**
   * 例（ユーザー提示）:
   * <article ...>
   *   ... <time datetime="2026-01-17T16:36:08.161Z">...</time>
   *   ... <a ... href="/jp/news/articles/...">タイトル</a>
   * </article>
   *
   * 方針:
   * - article単位で取り、time@datetime と a@href + aテキストを拾う
   * - href は bloomberg.com をprefixして絶対URL化
   */

  // article を全部抜き出す（Bloomberg側は data-component="story-list-latest-article" が安定しやすい想定）
  $articleRe = '/<article\b[^>]*data-component="story-list-latest-article"[^>]*>[\s\S]*?<\/article>/i';
  if (!preg_match_all($articleRe, $html, $am)) return $results;

  foreach ($am[0] as $block) {
    // time datetime
    if (!preg_match('/<time\b[^>]*datetime="([^"]+)"/i', $block, $tm)) continue;
    $datetime = $tm[1];

    // headline link: <a ... href="...">TEXT</a>
    // data-testid="headline" の中にある a を優先して拾う（構造が変わっても a は必須なので二段階に）
    $href = '';
    $titleText = '';

    // まず headline ブロック内から a を探す
    if (preg_match('/data-testid="headline"[\s\S]*?<a\b[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/i', $block, $hm)) {
      $href = $hm[1];
      $titleHtml = $hm[2];
      $titleText = trim(html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    } else {
      // フォールバック：article 内の最初の /jp/news/articles/ っぽいリンクを拾う
      if (preg_match('/<a\b[^>]*href="([^"]*\/jp\/news\/articles\/[^"]+)"[^>]*>([\s\S]*?)<\/a>/i', $block, $hm2)) {
        $href = $hm2[1];
        $titleHtml = $hm2[2];
        $titleText = trim(html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      } else {
        continue;
      }
    }

    if ($href === '' || $titleText === '') continue;

    // 絶対URL化
    if (strpos($href, 'http://') === 0 || strpos($href, 'https://') === 0) {
      $url = $href;
    } else {
      if ($href[0] !== '/') $href = '/' . $href;
      $url = 'https://www.bloomberg.com' . $href;
    }

    $results[] = [
      'datetime' => $datetime,
      'title' => $titleText,
      'url' => $url,
    ];
  }

  return $results;
}


function parse_xtech_news_(string $html): array {
  $results = [];

  $re = '/<h3 class="articleList_item_title[^"]*"[^>]*>\s*<a[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>\s*<\/h3>/';
  if (!preg_match_all($re, $html, $m, PREG_SET_ORDER)) return $results;

  foreach ($m as $mm) {
    $href = $mm[1];
    $titleHtml = $mm[2];
    $titleText = trim(html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if (strpos($href, '/') === 0) $href = 'https://xtech.nikkei.com' . $href;

    $results[] = ['title' => $titleText, 'url' => $href];
  }

  return $results;
}

/**
 * nikkei225jp.comの経済ニュース一覧を解析する。
 *
 * 戻り値:
 * [
 *   [
 *     'datetime' => '2026-08-02 09:08:00',
 *     'source'   => 'CRYPTO TIMES',
 *     'title'    => '記事見出し',
 *     'url'      => 'https://元記事URL',
 *   ],
 * ]
 */
function parse_nikkei225jp_news_(string $html): array {
  $results = [];

  libxml_use_internal_errors(true);

  $dom = new DOMDocument();
  $loaded = $dom->loadHTML(
    '<?xml encoding="UTF-8">' . $html,
    LIBXML_NOWARNING | LIBXML_NOERROR
  );

  libxml_clear_errors();

  if (!$loaded) {
    return $results;
  }

  $xp = new DOMXPath($dom);

  /*
   * classにNtexを含むdivを記事単位として取得する。
   */
  $nodes = $xp->query(
    "//div[" .
    "contains(" .
    "concat(' ', normalize-space(@class), ' ')," .
    "' Ntex '" .
    ")" .
    "]"
  );

  if (!$nodes) {
    return $results;
  }

  foreach ($nodes as $node) {
    /*
     * 見出しリンク
     */
    $aNodes = $xp->query('.//a[@href]', $node);

    if (!$aNodes || $aNodes->length === 0) {
      continue;
    }

    $a = $aNodes->item(0);

    if (!$a instanceof DOMElement) {
      continue;
    }

    $href = trim(
      html_entity_decode(
        (string)$a->getAttribute('href'),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
      )
    );

    if ($href === '') {
      continue;
    }

    /*
     * 見出しはa要素の表示文字列を使用する。
     * title属性末尾の[配信元]は含めない。
     */
    $title = normalize_ws_(
      html_entity_decode(
        (string)$a->textContent,
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
      )
    );

    if ($title === '') {
      continue;
    }

    /*
     * 配信元
     * 例：<span class="vn"> CRYPTO TIMES</span>
     */
    $source = '';

    $sourceNodes = $xp->query(
      ".//span[" .
      "contains(" .
      "concat(' ', normalize-space(@class), ' ')," .
      "' vn '" .
      ")" .
      "]",
      $node
    );

    if ($sourceNodes && $sourceNodes->length > 0) {
      $source = normalize_ws_(
        (string)$sourceNodes->item(0)->textContent
      );
    }

    if ($source === '') {
      $source = 'nikkei225jp.com';
    }

    /*
     * title属性の先頭から日時を取得する。
     * 例：
     * 2026/08/02 09:08 記事見出し [CRYPTO TIMES]
     */
    $titleAttr = normalize_ws_(
      html_entity_decode(
        (string)$a->getAttribute('title'),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
      )
    );

    if (
      !preg_match(
        '/^(\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2})/',
        $titleAttr,
        $dateMatch
      )
    ) {
      continue;
    }

    $dt = DateTime::createFromFormat(
      '!Y/m/d H:i',
      $dateMatch[1],
      new DateTimeZone(TIMEZONE)
    );

    if ($dt === false) {
      continue;
    }

    $datetime = $dt->format('Y-m-d H:i:s');

    /*
     * jump.nikkei225jp.comのURLパラメータから
     * 元記事URLを取り出す。
     */
    $directUrl = extract_nikkei225jp_direct_url_($href);

    if ($directUrl === '') {
      continue;
    }

    $results[] = [
      'datetime' => $datetime,
      'source'   => $source,
      'title'    => $title,
      'url'      => $directUrl,
    ];
  }

  return $results;
}

/**
 * nikkei225jp.comのジャンプURLから直接URLを取得する。
 *
 * 例：
 * //jump.nikkei225jp.com/j.php?URL=https%3A%2F%2Fexample.com
 * ↓
 * https://example.com
 */
function extract_nikkei225jp_direct_url_(string $href): string {
  $href = trim(
    html_entity_decode(
      $href,
      ENT_QUOTES | ENT_HTML5,
      'UTF-8'
    )
  );

  if ($href === '') {
    return '';
  }

  /*
   * プロトコル相対URLにも対応する。
   */
  if (strpos($href, '//') === 0) {
    $href = 'https:' . $href;
  }

  $parts = parse_url($href);

  if ($parts === false) {
    return '';
  }

  $host = strtolower((string)($parts['host'] ?? ''));

  /*
   * ジャンプURLでない場合は、直接URLとしてそのまま使う。
   */
  if ($host !== 'jump.nikkei225jp.com') {
    return preg_match('#^https?://#i', $href)
      ? $href
      : '';
  }

  $query = (string)($parts['query'] ?? '');

  if ($query === '') {
    return '';
  }

  $params = [];
  parse_str($query, $params);

  $directUrl = trim((string)($params['URL'] ?? ''));

  /*
   * parse_str()で通常はデコード済みだが、
   * 二重エンコードにもある程度対応する。
   */
  for ($i = 0; $i < 2; $i++) {
    if (!preg_match('/%[0-9A-Fa-f]{2}/', $directUrl)) {
      break;
    }

    $decoded = rawurldecode($directUrl);

    if ($decoded === $directUrl) {
      break;
    }

    $directUrl = $decoded;
  }

  if (!preg_match('#^https?://#i', $directUrl)) {
    return '';
  }

  return $directUrl;
}

function normalize_ws_(string $s): string {
  $s = preg_replace('/[ \t\r\n]+/u', ' ', $s);
  return trim($s ?? '');
}

/**
 * "MM/DD HH:MM" → "YYYY-MM-DD HH:MM:SS" (JST)
 * - 年は現在年を補完
 * - 秒は 00 固定
 * - 補完結果が「現在より未来」なら year-1（元旦跨ぎ対策）
 */
function shikiho_mdhi_to_jst_string_(string $s): string {
  $s = trim($s);

  $tz  = new DateTimeZone(TIMEZONE);
  $now = new DateTime('now', $tz);

  // 1) YYYY/MM/DD HH:MM(:SS)? に対応
  if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
    $year  = (int)$m[1];
    $month = (int)$m[2];
    $day   = (int)$m[3];
    $hour  = (int)$m[4];
    $min   = (int)$m[5];
    $sec   = isset($m[6]) ? (int)$m[6] : (int)$now->format('s'); // 秒が無ければ現在秒で補完（好みで 0 でもOK）

    $dt = new DateTime('now', $tz);
    $dt->setDate($year, $month, $day);
    $dt->setTime($hour, $min, $sec);

    return $dt->format('Y-m-d H:i:s');
  }

  // 2) MM/DD HH:MM(:SS)? に対応（年は補完）
  if (preg_match('/^(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
    $month = (int)$m[1];
    $day   = (int)$m[2];
    $hour  = (int)$m[3];
    $min   = (int)$m[4];
    $sec   = isset($m[5]) ? (int)$m[5] : (int)$now->format('s'); // 秒補完（好みで 0 でもOK）

    $year = (int)$now->format('Y');

    $dt = new DateTime('now', $tz);
    $dt->setDate($year, $month, $day);
    $dt->setTime($hour, $min, $sec);

    // 未来になってしまったら前年扱いに補正（元旦跨ぎ対策）
    if ($dt->getTimestamp() > $now->getTimestamp()) {
      $dt->modify('-1 year');
    }

    return $dt->format('Y-m-d H:i:s');
  }

  throw new RuntimeException("Invalid shikiho date format: {$s}");
}

function parse_shikiho_news_(string $html): array {
  $results = [];

  // DOMで解析（ブラウザ取得HTMLに強い）
  libxml_use_internal_errors(true);

  $dom = new DOMDocument();
  $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
  if (!$loaded) return $results;

  $xp = new DOMXPath($dom);

  // li.newsList__item を全部拾う
  $liNodes = $xp->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' newsList__item ')]");
  if (!$liNodes) return $results;

  foreach ($liNodes as $li) {
    // a.newsList__title を拾う
    $aList = $xp->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' newsList__title ')]", $li);
    $a = ($aList && $aList->length > 0) ? $aList->item(0) : null;
    if (!$a) continue;

    $title = normalize_ws_($a->textContent ?? '');
    if ($title === '') continue;

    $href = trim((string)$a->getAttribute('href'));
    if ($href === '') continue;

    // span.newsList__date を拾う（複数あるので最初の非空を採用）
    $dateNodes = $xp->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' newsList__date ')]", $li);
    $mdhi = '';
    if ($dateNodes && $dateNodes->length > 0) {
      for ($i = 0; $i < $dateNodes->length; $i++) {
        $dn = $dateNodes->item($i);
        $t = normalize_ws_($dn ? ($dn->textContent ?? '') : '');
        if ($t !== '') { $mdhi = $t; break; }
      }
    }
    if ($mdhi === '') continue;

    // URL：絶対URLならそのまま、相対ならプレフィックス付与
    if (preg_match('#^https?://#i', $href)) {
      $url = $href;
    } else {
      if ($href[0] !== '/') $href = '/' . $href;
      $url = SHIKIHO_PREFIX . $href;
    }

    $results[] = [
      'mdhi'  => $mdhi,   // "01/17 08:00"
      'title' => $title,
      'url'   => $url,
    ];
  }

  return $results;
}

function should_run_shikiho_(): bool {
  if (!file_exists(SHIKIHO_LASTRUN_FILE)) {
    return true; // 初回は実行
  }

  $last = (int)trim((string)file_get_contents(SHIKIHO_LASTRUN_FILE));
  if ($last <= 0) return true;

  return (time() - $last) >= SHIKIHO_INTERVAL_SEC;
}

function mark_shikiho_ran_(): void {
  ensure_dir(dirname(SHIKIHO_LASTRUN_FILE));
  file_put_contents(SHIKIHO_LASTRUN_FILE, (string)time());
}

function should_run_nikkei225jp_(): bool {
  if (!file_exists(NIKKEI225JP_LASTRUN_FILE)) {
    return true;
  }

  $last = (int)trim(
    (string)file_get_contents(NIKKEI225JP_LASTRUN_FILE)
  );

  if ($last <= 0) {
    return true;
  }

  return
    (time() - $last) >= NIKKEI225JP_INTERVAL_SEC;
}

function mark_nikkei225jp_ran_(): void {
  ensure_dir(dirname(NIKKEI225JP_LASTRUN_FILE));

  file_put_contents(
    NIKKEI225JP_LASTRUN_FILE,
    (string)time()
  );
}


/**
 * $timeStr(yyyy-MM-dd HH:mm:ss) が「現在(JST)から $days 日より古い」なら true
 */
function is_older_than_days_(string $timeStr, int $days): bool {
  $tz = new DateTimeZone(TIMEZONE);
  $now = new DateTime('now', $tz);
  $cutoff = (clone $now)->modify("-{$days} days");

  $dt = DateTime::createFromFormat('Y-m-d H:i:s', $timeStr, $tz);
  if (!$dt) {
    // フォーマット崩れは安全側（除外）に倒す
    return true;
  }
  return $dt->getTimestamp() < $cutoff->getTimestamp();
}

function should_run_jpx_(): bool {
  if (!file_exists(JPX_LASTRUN_FILE)) {
    return true;
  }
  $last = (int)trim((string)file_get_contents(JPX_LASTRUN_FILE));
  if ($last <= 0) return true;

  return (time() - $last) >= JPX_INTERVAL_SEC;
}

function mark_jpx_ran_(): void {
  ensure_dir(dirname(JPX_LASTRUN_FILE));
  file_put_contents(JPX_LASTRUN_FILE, (string)time());
}

/**
 * JPXの "YYYY/MM/DD" と、現在時刻 "HH:MM:SS" を合成して "YYYY-MM-DD HH:MM:SS" を返す
 */
function jpx_ymd_to_jst_string_(string $ymd, string $hmsNow): string {
  $ymd = trim($ymd);
  if (!preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/', $ymd, $m)) {
    throw new RuntimeException("Invalid JPX date format: {$ymd}");
  }
  [$hh, $ii, $ss] = array_map('intval', explode(':', $hmsNow));

  $tz = new DateTimeZone(TIMEZONE);
  $dt = new DateTime('now', $tz);
  $dt->setDate((int)$m[1], (int)$m[2], (int)$m[3]);
  $dt->setTime($hh, $ii, $ss);

  return $dt->format('Y-m-d H:i:s');
}

function parse_jpx_site_updates_(string $html): array {
  // site-updates は class="news-list-date" / class="news-list-title"
  return parse_jpx_list_common_($html, 'news-list-date', 'news-list-title');
}

/**
 * JPXの「li > a > span.date / span.title」形式をDOMで共通抽出
 * - $dateClass: "news-list-date" or "JPX-news-list-date"
 * - $titleClass: "news-list-title" or "JPX-news-list-title"
 * 戻り: [ ['ymd'=>'2026/02/03','title'=>'...','url'=>'https://www.jpx.co.jp/...'], ... ]
 */
function parse_jpx_list_common_(string $html, string $dateClass, string $titleClass): array {
  $results = [];

  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
  if (!$loaded) return $results;

  $xp = new DOMXPath($dom);

  // a[href] を全部拾って、内部に date/title があるものだけ採用
  $aNodes = $xp->query("//a[@href]");
  if (!$aNodes) return $results;

  foreach ($aNodes as $a) {
    $href = trim((string)$a->getAttribute('href'));
    if ($href === '') continue;

    // date
    $dNodes = $xp->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' {$dateClass} ')]", $a);
    $ymd = '';
    if ($dNodes && $dNodes->length > 0) {
      $ymd = normalize_ws_((string)($dNodes->item(0)->textContent ?? ''));
    }
    if ($ymd === '') continue;

    // title
    $tNodes = $xp->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' {$titleClass} ')]", $a);
    $title = '';
    if ($tNodes && $tNodes->length > 0) {
      $title = normalize_ws_((string)($tNodes->item(0)->textContent ?? ''));
    }
    if ($title === '') continue;

    // URL 絶対化（相対ならJPX_PREFIX付与）
    if (preg_match('#^https?://#i', $href)) {
      $url = $href;
    } else {
      if ($href[0] !== '/') $href = '/' . $href;
      $url = JPX_PREFIX . $href;
    }

    // ymd は "YYYY/MM/DD" を想定
    if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $ymd)) continue;

    $results[] = [
      'ymd'   => $ymd,
      'title' => $title,
      'url'   => $url,
    ];
  }

  return $results;
}
