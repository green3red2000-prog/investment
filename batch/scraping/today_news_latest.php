<?php
declare(strict_types=1);

/**
 * 本日のニュース（PHP版）
 * - 日経/ロイター/ブルームバーグ/xTECH をスクレイピング
 * - Google Drive: 投資/プログラミング/GAS/スクレイピング/出力結果/ 本日のニュース_最新 を開く
 * - 末尾に追記（時刻は日付型として入れる = USER_ENTERED）
 * - 追記後、時刻(A列)降順でソート
 *
 * 実行例:
 *   php /opt/invest/sheets-php/nikkei_news_latest.php
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

// ==== 四季報オンライン 実行制御 ====
const SHIKIHO_INTERVAL_SEC = 6 * 60 * 60; // 6時間
const SHIKIHO_LASTRUN_FILE = '/opt/invest/scraping/state/last_run_shikiho.txt';
const SHIKIHO_MAX_AGE_DAYS = 8; // ★ 8日前より古いニュースは反映しない

const JPX_INTERVAL_SEC = 6 * 60 * 60; // 6時間
const JPX_LASTRUN_FILE = '/opt/invest/scraping/state/last_run_jpx.txt';

// proxy は実行単位で固定（必要なら false に）
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
  $url = ($page === 1) ? NEWS_URL : (NEWS_URL . '?page=' . $page);
  try {
    $html = http_get_text($url);
    $articles = parse_nikkei_news_($html);

    foreach ($articles as $a) {
      $u = $a['url'];
      if (isset($existingUrls[$u])) continue;
      $existingUrls[$u] = true;

      $jst = iso_to_jst_string_($a['datetime']); // yyyy-MM-dd HH:mm:ss
      $id  = make_id16_($jst, $a['title']);

      $newRows[] = [$jst, SOURCE_NAME_NIKKEI, $a['title'], $u, $id, ''];
    }
  } catch (Throwable $e) {
    fwrite(STDERR, "[WARN] Nikkei page {$page} fetch/parse failed: " . $e->getMessage() . "\n");
  }

  if ($page < MAX_PAGE) usleep(SLEEP_BETWEEN_PAGES_US);
}

// ---- ロイター ----
try {
  $html = http_get_text(REUTERS_URL);
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

// ---- ブルームバーグ ----
/*
try {
  $html = http_get_text(BLOOMBERG_URL);
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
  $html = http_get_text(XTECH_URL);
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

// ---- 四季報オンライン（6時間に1回） ----
if (should_run_shikiho_()) {
  echo "[INFO] Shikiho: start (interval OK)\n";

  try {
    foreach (SHIKIHO_URLS as $url) {
      $html = http_get_text_browser($url, [
        'timeout'   => 30,
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

  try {
    // (A) サイト更新情報
    $html = http_get_text_browser(JPX_URL_SITE_UPDATES, [
      'timeout'   => 30,
      'retry_max' => 2,
    ]);
    $items = parse_jpx_site_updates_($html);

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

    // (B) マーケットニュース
    $html = http_get_text_browser(JPX_URL_MARKET_NEWS, [
      'timeout'   => 30,
      'retry_max' => 2,
    ]);
    $items = parse_jpx_list_common_($html, 'JPX-news-list-date', 'JPX-news-list-title');

    foreach ($items as $a) {
      $u = $a['url'];
      if (isset($existingUrls[$u])) continue;

      $jst = jpx_ymd_to_jst_string_($a['ymd'], $nowHms);
      if (is_older_than_days_($jst, SHIKIHO_MAX_AGE_DAYS)) continue;

      $existingUrls[$u] = true;
      $id  = make_id16_($jst, $a['title']);
      $newRows[] = [$jst, JPX_SOURCE_MARKET_NEWS, $a['title'], $u, $id, ''];
    }

    // (C) お知らせ（news-releases）
    $html = http_get_text_browser(JPX_URL_INFO, [
      'timeout'   => 30,
      'retry_max' => 2,
    ]);
    $items = parse_jpx_list_common_($html, 'JPX-news-list-date', 'JPX-news-list-title');

    foreach ($items as $a) {
      $u = $a['url'];
      if (isset($existingUrls[$u])) continue;

      $jst = jpx_ymd_to_jst_string_($a['ymd'], $nowHms);
      if (is_older_than_days_($jst, SHIKIHO_MAX_AGE_DAYS)) continue;

      $existingUrls[$u] = true;
      $id  = make_id16_($jst, $a['title']);
      $newRows[] = [$jst, JPX_SOURCE_INFO, $a['title'], $u, $id, ''];
    }

  } catch (Throwable $e) {
    fwrite(STDERR, "[WARN] JPX fetch/parse failed: " . $e->getMessage() . "\n");
  } finally {
    // ★ 成功/失敗に関わらず「今回実行した」扱いにして6時間抑制
    mark_jpx_ran_();
    echo "[INFO] JPX: marked last_run\n";
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
