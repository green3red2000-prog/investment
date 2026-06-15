<?php
declare(strict_types=1);

/**
 * PTS＆朝刊ニュース（PHP版）
 * - Webshare Proxy（lib側で複数持ち、毎回ランダム / 圧縮）
 * - 開始条件：
 *   「PTSナイトタイム上昇率」ページの <div class="meigara_count"> 最初の<li>＝日付 が本日(Asia/Tokyo)と一致したら処理開始
 * - 取得：
 *   - PTSナイトタイム上昇率：最大30件（1ページ15件 → 不足時 page=2 取得）
 *   - PTSナイトタイム下落率：最大30件（同上）
 *   - 朝刊ニュース銘柄：全件ページング（次ページが15件未満になったら終了）
 * - 出力（CSV）：
 *   シート想定の共通10列：
 *   '種別','証券コード','銘柄名','市場','終値','値幅制限','PTS終値／内容','終値比','終値比(%)','出来高'
 * - 出力先：/opt/invest/scraping/tmp に csv + メッセージtxt
 * - Driveへアップロード（CSVはGoogleスプレッドシート化）→ 成功後ローカル削除
 * - メール送信なし
 */

require __DIR__ . '/lib/scraping_common.php';

http_session_begin(true);

date_default_timezone_set('Asia/Tokyo');

// ===== 設定 =====
$JOB_NAME = 'PTS＆朝刊ニュース';

$URL_PTS_UP   = 'https://kabutan.jp/warning/pts_night_price_increase';
$URL_PTS_DOWN = 'https://kabutan.jp/warning/pts_night_price_decrease';
$URL_MORNING  = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu';

$MAX_PTS = 30;
$INTERVAL_US = 800000; // 0.8秒（必要なら調整）

// ===== 日付 =====
$todayJP   = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y年m月d日');
$todayISO  = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$todayMail = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y/m/d');

// 出力パス（共通関数）
$paths   = build_output_paths($JOB_NAME, $todayISO);
$csvPath = $paths['csv'];
$txtPath = $paths['txt'];

// ===== 1) 開始条件チェック（PTS上昇率ページの日付が本日か）=====
echo "開始条件チェック: PTS上昇率ページ\n";
$htmlUp = http_get_text($URL_PTS_UP);

$startMeta = parseMeigaraCountBlock($htmlUp); // ['dateText','timeText','countText']
if (!$startMeta || ($startMeta['dateText'] ?? '') !== $todayJP) {
  $got = $startMeta['dateText'] ?? '不明';
  echo "本日分ではないため終了: 取得日={$got} / 本日={$todayJP}\n";
  exit(0);
}

echo "OK: 本日分 ({$todayJP}) を検出。取得開始します。\n";

// ===== 2) PTS上昇率 最大30件 =====
$ptsUp = collectPtsTopN($URL_PTS_UP, $MAX_PTS, $INTERVAL_US);
echo "PTS上昇率: " . count($ptsUp) . "件\n";

// ===== 3) PTS下落率 最大30件 =====
$ptsDown = collectPtsTopN($URL_PTS_DOWN, $MAX_PTS, $INTERVAL_US);
echo "PTS下落率: " . count($ptsDown) . "件\n";

// ===== 4) 朝刊ニュース銘柄（全件ページング）=====
echo "朝刊ニュース銘柄: 1ページ目取得\n";
$firstMorningHtml = http_get_text($URL_MORNING);

$morningMeta  = parseMeigaraCountBlock($firstMorningHtml);
$morningCount = $morningMeta ? extractNumber($morningMeta['countText'] ?? null) : null;

$morningRows = parseMorningTableRows($firstMorningHtml);

$page = 2;
while (true) {
  usleep($INTERVAL_US);

  $url = addPageParam($URL_MORNING, $page);
  echo "朝刊ニュース銘柄: page={$page} 取得\n";
  $html = http_get_text($url);

  $rows = parseMorningTableRows($html);
  if (count($rows) === 0) break;

  $morningRows = array_merge($morningRows, $rows);

  // 15件未満なら最終ページ
  if (count($rows) < 15) break;

  $page++;
}

echo "朝刊ニュース銘柄: " . count($morningRows) . "件\n";

// ===== 5) CSV出力（tmp配下）=====
writeCsv($csvPath, $ptsUp, $ptsDown, $morningRows);
echo "ローカルCSV出力完了: {$csvPath}\n";

// ===== 6) メッセージTXT出力（tmp配下）=====
$subjectLine = "{$JOB_NAME}：{$todayMail}";

$morningCountText = ($morningCount !== null) ? "{$morningCount}" : "不明";
$body =
  "朝刊ニュース銘柄数は、{$morningCountText}銘柄でした。\n\n" .
  "取得件数：\n" .
  "- PTSナイトタイム上昇率: " . count($ptsUp) . "件\n" .
  "- PTSナイトタイム下落率: " . count($ptsDown) . "件\n" .
  "- 朝刊ニュース銘柄: " . count($morningRows) . "件\n";

write_message_txt($txtPath, $subjectLine, $body);
echo "ローカルTXT出力完了: {$txtPath}\n";

// ===== 7) Driveへアップロード → ローカル削除 =====
upload_outputs_and_cleanup($JOB_NAME, $todayISO, $csvPath, $txtPath);

echo "DONE: uploaded and cleaned up.\n";
exit(0);

/* =========================================================
 * CSV出力
 * ========================================================= */
function writeCsv(string $csvPath, array $ptsUp, array $ptsDown, array $morningRows): void {
  $fp = fopen($csvPath, 'wb');
  if (!$fp) {
    throw new RuntimeException("CSV作成に失敗: {$csvPath}");
  }

  // Excel向けBOM
  fwrite($fp, "\xEF\xBB\xBF");

  $header = [
    '種別', '証券コード', '銘柄名', '市場',
    '終値', '値幅制限', 'PTS終値／内容', '終値比', '終値比(%)', '出来高'
  ];
  fputcsv($fp, $header);

  // PTS上昇率
  foreach ($ptsUp as $r) {
    fputcsv($fp, [
      'PTSナイトタイム上昇率',
      $r['code'] ?? '',
      $r['name'] ?? '',
      $r['market'] ?? '',
      $r['close'] ?? '',
      '',
      $r['ptsClose'] ?? '',
      $r['diff'] ?? '',
      $r['diffPct'] ?? '',
      $r['volume'] ?? '',
    ]);
  }

  // PTS下落率
  foreach ($ptsDown as $r) {
    fputcsv($fp, [
      'PTSナイトタイム下落率',
      $r['code'] ?? '',
      $r['name'] ?? '',
      $r['market'] ?? '',
      $r['close'] ?? '',
      '',
      $r['ptsClose'] ?? '',
      $r['diff'] ?? '',
      $r['diffPct'] ?? '',
      $r['volume'] ?? '',
    ]);
  }

  // 朝刊ニュース銘柄
  foreach ($morningRows as $r) {
    fputcsv($fp, [
      '朝刊ニュース銘柄',
      $r['code'] ?? '',
      $r['name'] ?? '',
      $r['market'] ?? '',
      $r['close'] ?? '',
      $r['limit'] ?? '',
      $r['content'] ?? '',
      $r['diff'] ?? '',
      $r['diffPct'] ?? '',
      '', // 出来高は空白（GAS仕様）
    ]);
  }

  fclose($fp);
}

/* =========================================================
 * 取得＆パース（GASロジック移植）
 * ========================================================= */

function collectPtsTopN(string $baseUrl, int $maxN, int $intervalUs): array {
  $rows = [];

  $rows = array_merge($rows, parsePtsTableRows(http_get_text($baseUrl)));

  // 1ページ目が15件で、まだ足りないならpage=2
  if (count($rows) < $maxN && count($rows) === 15) {
    usleep($intervalUs);
    $rows = array_merge($rows, parsePtsTableRows(http_get_text(addPageParam($baseUrl, 2))));
  }

  if (count($rows) > $maxN) $rows = array_slice($rows, 0, $maxN);
  return $rows;
}

/**
 * <div class="meigara_count"> の最初〜3番目の<li>（date, time, count）を抽出
 * return: ['dateText'=>?, 'timeText'=>?, 'countText'=>?] or null
 */
function parseMeigaraCountBlock(string $html): ?array {
  if (!preg_match('/<div\s+class="meigara_count">([\s\S]*?)<\/div>/i', $html, $divMatch)) {
    return null;
  }
  $divHtml = $divMatch[1];

  if (!preg_match('/<ul>([\s\S]*?)<\/ul>/i', $divHtml, $ulMatch)) {
    return null;
  }
  $ulHtml = $ulMatch[1];

  preg_match_all('/<li>([\s\S]*?)<\/li>/i', $ulHtml, $liMatches);
  $items = [];
  foreach ($liMatches[1] as $li) {
    $items[] = trim(stripTags($li));
  }

  return [
    'dateText'  => $items[0] ?? null,
    'timeText'  => $items[1] ?? null,
    'countText' => $items[2] ?? null,
  ];
}

/** 数値だけ抽出（例："4,175銘柄" → 4175） */
function extractNumber(?string $text): ?int {
  if ($text === null) return null;
  $s = str_replace([',','，'], '', $text);
  if (preg_match('/\d+/', $s, $m)) return (int)$m[0];
  return null;
}

function addPageParam(string $url, int $page): string {
  return $url . ((strpos($url, '?') !== false) ? '&' : '?') . 'page=' . $page;
}


function stripTags(string $s): string {
  return preg_replace('/<[^>]*>/', '', $s);
}

/**
 * そのページの <table class="stock_table st_market"> の <tbody> を抽出
 */
function extractStockTableTbody(string $html): ?string {
  if (!preg_match('/<table[^>]*class="[^"]*\bstock_table\b[^"]*\bst_market\b[^"]*"[^>]*>([\s\S]*?)<\/table>/i', $html, $tableMatch)) {
    return null;
  }
  $tableHtml = $tableMatch[1];

  if (!preg_match('/<tbody>([\s\S]*?)<\/tbody>/i', $tableHtml, $tbodyMatch)) {
    return null;
  }
  return $tbodyMatch[1];
}

/**
 * PTSテーブルから行を抽出
 * 必要項目：
 *  code(最初の<td>), name(最初の<th>), market(2番目<td>),
 *  close(5番目<td>), ptsClose(6番目<td>), diff(7番目<td>), diffPct(8番目<td>), volume(9番目<td>)
 */
function parsePtsTableRows(string $html): array {
  $tbody = extractStockTableTbody($html);
  if ($tbody === null) return [];

  preg_match_all('/<tr>([\s\S]*?)<\/tr>/i', $tbody, $trMatches);

  $rows = [];
  foreach ($trMatches[1] as $tr) {
    // name: th
    $name = '';
    if (preg_match('/<th[^>]*>([\s\S]*?)<\/th>/i', $tr, $thMatch)) {
      $name = trim(stripTags($thMatch[1]));
    }

    // tds
    preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/i', $tr, $tdMatches);
    $tds = $tdMatches[1] ?? [];
    if (count($tds) < 9) continue;

    $code     = trim(stripTags($tds[0])); // 1
    $market   = trim(stripTags($tds[1])); // 2
    $close    = trim(stripTags($tds[4])); // 5
    $ptsClose = trim(stripTags($tds[5])); // 6
    $diff     = trim(stripTags($tds[6])); // 7
    $diffPct  = trim(stripTags($tds[7])); // 8
    $volume   = trim(stripTags($tds[8])); // 9

    if ($code === '' && $name === '') continue;

    $rows[] = [
      'code' => $code,
      'name' => $name,
      'market' => $market,
      'close' => $close,
      'ptsClose' => $ptsClose,
      'diff' => $diff,
      'diffPct' => $diffPct,
      'volume' => $volume,
    ];
  }

  return $rows;
}

/**
 * 朝刊ニュース銘柄テーブルから行を抽出
 * 必要項目：
 *  code(最初<td>), name(最初<th>), market(2番目<td>),
 *  content(4番目<td>), close(5番目<td>), limit(6番目<td>), diff(7番目<td>), diffPct(8番目<td>)
 */
function parseMorningTableRows(string $html): array {
  $tbody = extractStockTableTbody($html);
  if ($tbody === null) return [];

  preg_match_all('/<tr>([\s\S]*?)<\/tr>/i', $tbody, $trMatches);

  $rows = [];
  foreach ($trMatches[1] as $tr) {
    // name: th
    $name = '';
    if (preg_match('/<th[^>]*>([\s\S]*?)<\/th>/i', $tr, $thMatch)) {
      $name = trim(stripTags($thMatch[1]));
    }

    // tds
    preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/i', $tr, $tdMatches);
    $tds = $tdMatches[1] ?? [];

    // 必要列：少なくとも8列
    if (count($tds) < 8) continue;

    $code    = trim(stripTags($tds[0])); // 1: 証券コード
    $market  = trim(stripTags($tds[1])); // 2: 市場
    $content = trim(stripTags($tds[3])); // 4: 内容（ニュース見出し）
    $close   = trim(stripTags($tds[4])); // 5: 終値
    $limit   = trim(stripTags($tds[5])); // 6: 値幅制限
    $diff    = trim(stripTags($tds[6])); // 7: 終値比
    $diffPct = trim(stripTags($tds[7])); // 8: 終値比(%)

    if ($code === '' && $name === '') continue;

    $rows[] = [
      'code' => $code,
      'name' => $name,
      'market' => $market,
      'close' => $close,
      'limit' => $limit,
      'content' => $content,
      'diff' => $diff,
      'diffPct' => $diffPct,
    ];
  }

  return $rows;
}
