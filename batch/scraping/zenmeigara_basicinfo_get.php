<?php
declare(strict_types=1);

/**
 * 全銘柄基本情報取得（株探のみ） PHP版
 *
 * - 参照元: Google Drive（マスタ/証券コードマスタ）
 * - スクレイピング: kabutan.jp/stock/?code=XXXX
 * - 出力: /opt/invest/scraping/tmp
 *    - 全銘柄基本情報取得_yyyy-MM-dd.csv
 *    - 全銘柄基本情報取得_メッセージ_yyyy-MM-dd.txt
 * - Driveへアップロード（CSVはGoogle Sheets化）→ 成功後ローカル削除
 * - メール送信なし
 *
 * 依存:
 *   require __DIR__ . '/lib/scraping_common.php';
 *   （scraping_common.php の OAuth/Drive upload/proxy を利用）
 *
 * 実行例:
 *   php zenmeigara_basicinfo_get.php
 *   php zenmeigara_basicinfo_get.php --date=2026-06-24 --max=3
 *   php zenmeigara_basicinfo_get.php --date=2026-06-24 --max=3 --noupload
 */

require __DIR__ . '/lib/scraping_common.php';

http_session_begin(false);

date_default_timezone_set('Asia/Tokyo');

// =====================
// 設定
// =====================
$JOB_NAME = '全銘柄基本情報取得';

$DRIVE_PATH_MASTER_FOLDER = ['投資','プログラミング','GAS','マスタ'];
$CODE_MASTER_FILE_NAME    = '証券コードマスタ';

// CSVの見出し（GASの DATA_HEADERS を踏襲）
$DATA_HEADERS = [
  '証券コード','更新日','実行結果','会社名略称','会社名','業種','概要',
  '時価総額','上場区分','売上高','経常益','最終益','PER','PBR','利回り','終値','前日比','騰落率',
  '出来高','信用日付','信用売り残','信用買い残','信用倍率'
];

$SLEEP_SEC_MIN = 15;
$SLEEP_SEC_MAX = 20;

// =====================
// 引数
// =====================
$args = parse_args($argv);
$targetISODate = $args['date'] ?? date('Y-m-d');
$maxRows = isset($args['max']) ? (int)$args['max'] : 0;
$noUpload = isset($args['noupload']);

// =====================
// メイン
// =====================
$paths   = build_output_paths($JOB_NAME, $targetISODate);
$csvPath = $paths['csv'];
$txtPath = $paths['txt'];

echo "[INFO] date={$targetISODate} \n";
echo "[INFO] maxRows={$maxRows}\n";
echo "[INFO] noUpload=" . ($noUpload ? 'true' : 'false') . "\n";

// 1) 証券コード一覧を Drive から取得（export CSV）
try {
  $codes = load_codes_from_drive_sheet($DRIVE_PATH_MASTER_FOLDER, $CODE_MASTER_FILE_NAME);
} catch (Throwable $e) {
  fwrite(STDERR, "[FATAL] code master load failed: {$e->getMessage()}\n");
  exit(1);
}

if (count($codes) === 0) {
  fwrite(STDERR, "[FATAL] no codes found in master sheet\n");
  exit(1);
}

echo "[INFO] codes loaded: " . count($codes) . "\n";

//3銘柄固定テスト
//$codes = ['7203', '9984', '6758'];
//echo "[TEST] codes overridden: " . implode(',', $codes) . "\n";
//$GLOBALS['BASICINFO_TEST_CODES'] =  $codes;

// 2) kabutan から取得して rows を作る
$rows = [];
$ok = 0; $err = 0; $skipped = 0;

$processedCount = 0;

foreach ($codes as $i => $code) {

  $code = trim($code);
  if ($code === '') { $skipped++; continue; }

  $n = $i + 1;
  echo "[INFO] ({$n}/" . count($codes) . ") code={$code}\n";

  $row = array_fill_keys($DATA_HEADERS, '');
  $row['証券コード'] = $code;
  $row['更新日'] = $targetISODate;

  try {
    $d = fetch_kabutan_basicinfo($code);

    // GAS と同じキーに寄せてマップ
    $row['実行結果']   = '正常';
    $row['会社名略称'] = $d['companyShort'] ?? '';
    $row['上場区分']   = $d['marketType'] ?? '';

    $row['終値']   = $d['close'] ?? '';
    $row['前日比'] = $d['prevDiff'] ?? '';
    $row['騰落率'] = $d['changeRate'] ?? '';

    $row['PER']    = $d['per'] ?? '';
    $row['PBR']    = $d['pbr'] ?? '';
    $row['利回り'] = $d['yield'] ?? '';

    $row['時価総額'] = $d['marketCap'] ?? '';

    $row['会社名'] = $d['companyName'] ?? '';
    $row['業種']   = $d['industry'] ?? '';
    $row['概要']   = $d['summary'] ?? '';

    $row['売上高'] = $d['sales'] ?? '';
    $row['経常益'] = $d['keijo'] ?? '';
    $row['最終益'] = $d['netIncome'] ?? '';

    $row['出来高'] = $d['volume'] ?? '';
    $row['信用日付']   = $d['marginDate'] ?? '';
    $row['信用売り残'] = $d['marginShort'] ?? '';
    $row['信用買い残'] = $d['marginLong'] ?? '';
    $row['信用倍率']   = $d['marginRatio'] ?? '';

    // GASの enforceOutputFormatsAsNumber_ 相当
    enforce_output_formats($row);

    $ok++;

  } catch (Throwable $e) {
    $row['実行結果'] = 'エラー：株探読み込みに失敗';
    $err++;
    fwrite(STDERR, "[WARN] code={$code} error: {$e->getMessage()}\n");
  }

  $rows[] = $row;
  
  $processedCount++;

  if ($maxRows > 0 && $processedCount >= $maxRows) {
    echo "[INFO] max={$maxRows} reached, stop scraping.\n";
    break;
  }
  
  // ★ MIN〜MAXの範囲で揺らぎ
  $sec = random_int($SLEEP_SEC_MIN, $SLEEP_SEC_MAX) + (random_int(0, 1000) / 1000);
  if (($n % 100) === 0) echo "[INFO] sleep_sec={$sec}\n";
  usleep((int)($sec * 1_000_000));
}

// 3) CSV出力
write_csv_with_headers($csvPath, $DATA_HEADERS, $rows);

// 4) メッセージ出力
$subjectLine = "{$JOB_NAME}：{$targetISODate}";
$body =
  "全銘柄基本情報取得（株探のみ）を完了しました。\n\n" .
  "対象コード数: " . count($rows) . "\n" .
  "成功: {$ok}\n" .
  "失敗: {$err}\n" .
  ($skipped ? "空行スキップ: {$skipped}\n" : '') .
  "\n";

// ★ エラーになったプロキシ一覧をTXTに追記（scraping_common.phpが収集）
if (function_exists('get_bad_proxies')) {
  $bad = get_bad_proxies(); // host:port の配列
  if (count($bad) > 0) {
    $body .=
      "エラーになったプロキシ（host:port）:\n" .
      implode("\n", array_map(function($hp){ return "- {$hp}"; }, $bad)) .
      "\n\n";
  }
}

write_message_txt($txtPath, $subjectLine, $body);

echo "[INFO] local outputs written:\n- {$csvPath}\n- {$txtPath}\n";

// 5) アップロード→削除
if ($noUpload) {
  echo "[INFO] --noupload specified. upload skipped.\n";
} else {
  upload_outputs_and_cleanup($JOB_NAME, $targetISODate, $csvPath, $txtPath);
}

// =====================
// Functions
// =====================

function parse_args(array $argv): array {
  $out = [];
  foreach ($argv as $idx => $a) {
    if ($idx === 0) continue;

    if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) {
      $out[$m[1]] = $m[2];
      continue;
    }

    if (preg_match('/^--([^=]+)$/', $a, $m)) {
      $out[$m[1]] = true;
      continue;
    }
  }
  return $out;
}

/**
 * Drive上の Google Spreadsheet を CSV で export し、「証券コード」列を抽出
 * - 1行目に「証券コード」見出しがある想定
 */
function load_codes_from_drive_sheet(array $folderPath, string $sheetName): array {
  require_once __DIR__ . '/vendor/autoload.php';

  $client = build_oauth_client_();
  $drive  = new Google\Service\Drive($client);

  $folderId = resolve_folder_id_by_path_($drive, $folderPath);

  // 指定フォルダ直下で同名のスプレッドシートを探す
  $q = sprintf(
    "name = '%s' and '%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.spreadsheet'",
    str_replace("'", "\\'", $sheetName),
    $folderId
  );

  $res = $drive->files->listFiles([
    'q' => $q,
    'fields' => 'files(id,name)',
    'pageSize' => 10,
  ]);

  $files = $res->getFiles();
  if (!$files || count($files) === 0) {
    throw new RuntimeException("Spreadsheet not found: {$sheetName}");
  }

  $fileId = $files[0]->getId();

  // export
  $resp = $drive->files->export($fileId, 'text/csv', ['alt' => 'media']);
  $csv = (string)$resp->getBody();
  if (trim($csv) === '') throw new RuntimeException('exported CSV is empty');

  // BOM除去
  $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);

  $lines = preg_split('/\r\n|\n|\r/', $csv);
  if (!$lines || count($lines) === 0) return [];

  $header = str_getcsv(array_shift($lines));
  $idx = array_search('証券コード', $header, true);
  if ($idx === false) throw new RuntimeException('「証券コード」列が見つかりません');

  $codes = [];
  foreach ($lines as $line) {
    if (trim($line) === '') continue;
    $cols = str_getcsv($line);
    $c = isset($cols[$idx]) ? trim((string)$cols[$idx]) : '';
    if ($c === '') continue;
    $codes[] = $c;
  }

  // 重複排除（順序維持）
  $seen = [];
  $uniq = [];
  foreach ($codes as $c) {
    if (isset($seen[$c])) continue;
    $seen[$c] = true;
    $uniq[] = $c;
  }
  return $uniq;
}

function fetch_kabutan_basicinfo(string $code): array {
  $url = 'https://kabutan.jp/stock/?code=' . rawurlencode($code);
  
  if (!function_exists('http_get_text_browser')) {
    throw new RuntimeException("http_get_text_browser() が見つかりません（scraping_common.php を確認）");
  }

  echo "[HTTP] {$code} {$url}\n";

  //$html = http_get_text_browser_with_good_proxy_pool($url, $GLOBALS['BASICINFO_TEST_CODES'] ?? [], [
  $html = http_get_text_browser($url, [
    'timeout' => 90,
  ]);
  if ($html === '' || strpos($html, 'id="stockinfo_i1"') === false) {
    throw new RuntimeException('Kabutan HTML not found or layout changed');
  }

  // 会社名略称
  $companyShort = extract_text($html, '/<div id="stockinfo_i1"[\s\S]*?<h2>([\s\S]*?)<\/h2>/i', true);
  $companyShort = strip_span_and_content($companyShort);
  $companyShort = preg_replace('/^\d{4}\s*/u', '', $companyShort);
  $companyShort = trim(preg_replace('/^[\s　]+/u', '', $companyShort));

  // 市場区分
  $marketType = trim(extract_text($html, '/<span class="market">([\s\S]*?)<\/span>/i'));

  // 株価
  $close = trim(extract_text($html, '/<div class="si_i1_2">[\s\S]*?<span class="kabuka">([\s\S]*?)<\/span>/i'));
  $close = normalize_number($close);

  // 前日比/騰落率
  $ddBlock = match_first($html, '/<dl class="si_i1_dl1">([\s\S]*?)<\/dl>/i');
  $prevDiff = '';
  $changeRate = '';
  if ($ddBlock !== '') {
    preg_match_all('/<dd>[\s\S]*?<\/dd>/i', $ddBlock, $dds);
    $arr = $dds[0] ?? [];
    if (!empty($arr[0])) $prevDiff = strip_tags_simple($arr[0]);
    if (!empty($arr[1])) $changeRate = normalize_number(strip_tags_simple($arr[1]));
  }

  // 指標ブロック
  $i3 = match_first($html, '/<div id="stockinfo_i3">([\s\S]*?)<\/div><!--stockinfo_i3-->/i');
  if ($i3 === '') {
    $i3 = match_first($html, '/<div id="stockinfo_i3">([\s\S]*?)<\/div>/i');
  }

  $per = $pbr = $yieldPct = $marketCap = $marginRatio = '';
  if ($i3 !== '') {
    $per = strip_span_and_content(extract_text($i3, '/<tbody>[\s\S]*?<tr>\s*<td>([\s\S]*?)<\/td>/i', true));
    $per = trim(str_replace('倍', '', $per));

    $pbr = strip_span_and_content(extract_text($i3, '/<tbody>[\s\S]*?<tr>[\s\S]*?<td>[\s\S]*?<\/td>\s*<td>([\s\S]*?)<\/td>/i', true));
    $pbr = trim(str_replace('倍', '', $pbr));

    $yieldPct = strip_span_and_content(extract_text($i3, '/<tbody>[\s\S]*?<tr>[\s\S]*?<td>[\s\S]*?<\/td>\s*<td>[\s\S]*?<\/td>\s*<td>([\s\S]*?)<\/td>/i', true));
    $yieldPct = normalize_number($yieldPct);

    $mr = extract_text($i3, '/<tbody>[\s\S]*?<tr>[\s\S]*?<td>[\s\S]*?<\/td>\s*<td>[\s\S]*?<\/td>\s*<td>[\s\S]*?<\/td>\s*<td>([\s\S]*?)<\/td>/i', true);
    if ($mr !== '') $marginRatio = normalize_number(strip_span_and_content($mr));

    $zika = extract_text($i3, '/class="v_zika2">([\s\S]*?)<\/td>/i', true);
    $marketCap = trim(str_replace('億円', '', strip_span_only($zika)));
  }

  // 会社名（詳細側）
  $companyName = trim(extract_text($html, '/<div id="kobetsu_right"[\s\S]*?<div class="company_block">[\s\S]*?<h3>([\s\S]*?)<\/h3>/i'));

  $companyTable = extract_match1($html, '/<div id="kobetsu_right"[\s\S]*?<div class="company_block">[\s\S]*?<table[^>]*>([\s\S]*?)<\/table>/i');
  $industry = $summary = '';
  if ($companyTable !== '') {
    $industry = extract_following_cell($companyTable, '/<th[^>]*>業種<\/th>/i');
    $summary  = extract_following_cell($companyTable, '/<th[^>]*>概要<\/th>/i');
  }

  $gyousekiTbody = extract_match1($html, '/<div id="kobetsu_right"[\s\S]*?<div class="gyouseki_block">[\s\S]*?<tbody>([\s\S]*?)<\/tbody>/i');
  $sales = $keijo = $netIncome = '';
  if ($gyousekiTbody !== '') {
    preg_match_all('/<tr[\s\S]*?<\/tr>/i', $gyousekiTbody, $trs);
    $rows = $trs[0] ?? [];
    if (isset($rows[1])) {
      preg_match_all('/<td[\s\S]*?<\/td>/i', $rows[1], $tds);
      $cells = $tds[0] ?? [];
      if (isset($cells[0])) $sales     = strip_tags_simple($cells[0]);
      if (isset($cells[1])) $keijo     = strip_tags_simple($cells[1]);
      if (isset($cells[2])) $netIncome = strip_tags_simple($cells[2]);
    }
  }

  $leftBlock = extract_match0($html, '/<div id="kobetsu_left">[\s\S]*?<\/div>/i');
  $volume = '';
  if ($leftBlock !== '') {
    preg_match_all('/<table[\s\S]*?<\/table>/i', $leftBlock, $tables);
    $tbls = $tables[0] ?? [];
    if (isset($tbls[1])) {
      $firstTd = extract_match1($tbls[1], '/<tbody>[\s\S]*?<tr>[\s\S]*?<td>([\s\S]*?)<\/td>/i');
      $volume = normalize_number(strip_tags_simple($firstTd));
    }
  }

  $marginDate = $marginShort = $marginLong = $marginRatio2 = '';
  $creditTable = extract_match1($html, '/<div id="kobetsu_left"[\s\S]*?<h2[^>]*>\s*信用取引[\s\S]*?<\/h2>[\s\S]*?<table[^>]*>([\s\S]*?)<\/table>/i');
  if ($creditTable !== '') {
    $firstRow = extract_match1($creditTable, '/<tbody>[\s\S]*?<tr>([\s\S]*?)<\/tr>/i');
    $thDate   = extract_match1($firstRow, '/<th[^>]*>([\s\S]*?)<\/th>/i');

    preg_match_all('/<td>[\s\S]*?<\/td>/i', $firstRow, $tds);
    $cells = $tds[0] ?? [];

    $marginDate = trim(preg_replace('/[^\d\.\/\-]/', '', strip_tags_simple($thDate)));
    if (isset($cells[0])) $marginShort = normalize_number(strip_tags_simple($cells[0]));
    if (isset($cells[1])) $marginLong  = normalize_number(strip_tags_simple($cells[1]));
    if (isset($cells[2])) $marginRatio2 = normalize_number(strip_tags_simple($cells[2]));
  }

  $marginRatioFinal = ($marginRatio !== '') ? $marginRatio : $marginRatio2;

  return [
    'companyShort' => $companyShort,
    'marketType'   => $marketType,
    'close'        => $close,
    'prevDiff'     => $prevDiff,
    'changeRate'   => $changeRate,
    'per'          => $per,
    'pbr'          => $pbr,
    'yield'        => $yieldPct,
    'marketCap'    => $marketCap,

    'companyName'  => $companyName,
    'industry'     => $industry,
    'summary'      => $summary,
    'sales'        => $sales,
    'keijo'        => $keijo,
    'netIncome'    => $netIncome,

    'volume'       => $volume,
    'marginDate'   => $marginDate,
    'marginShort'  => $marginShort,
    'marginLong'   => $marginLong,
    'marginRatio'  => $marginRatioFinal,
  ];
}

function write_csv_with_headers(string $csvPath, array $headers, array $rows): void {
  $fp = fopen($csvPath, 'wb');
  if (!$fp) throw new RuntimeException("CSV作成に失敗: {$csvPath}");

  // Excel向けにBOM
  fwrite($fp, "\xEF\xBB\xBF");

  fputcsv($fp, $headers);
  foreach ($rows as $row) {
    $line = [];
    foreach ($headers as $h) {
      $line[] = $row[$h] ?? '';
    }
    fputcsv($fp, $line);
  }

  fclose($fp);
}

// ===== フォーマット（GAS enforceOutputFormatsAsNumber_ 相当） =====
function enforce_output_formats(array &$row): void {
  $row['時価総額'] = to_fixed1_marketcap_number($row['時価総額'] ?? '');
  $row['売上高']   = to_fixed1_number($row['売上高'] ?? '');
  $row['経常益']   = to_fixed1_number($row['経常益'] ?? '');
  $row['最終益']   = to_fixed1_number($row['最終益'] ?? '');

  $rawPer = trim(str_replace('倍', '', (string)($row['PER'] ?? '')));
  if (preg_match('/^(?:-|－|ー)$/u', $rawPer)) {
    $row['PER'] = 'ー';
  } else {
    $row['PER'] = to_fixed1_number($rawPer);
  }

  $row['PBR']    = to_fixed1_number(str_replace('倍', '', (string)($row['PBR'] ?? '')));
  $row['出来高'] = to_number($row['出来高'] ?? '');

  $shortNum = to_number($row['信用売り残'] ?? '');
  $longNum  = to_number($row['信用買い残'] ?? '');
  if ($shortNum === 0.0 || $longNum === 0.0) {
    $row['信用倍率'] = 'ー';
  } else {
    $row['信用倍率'] = to_fixed1_number(str_replace('倍', '', (string)($row['信用倍率'] ?? '')));
  }
}

// ===== HTML helpers =====
function extract_match0(string $s, string $regex): string {
  if (preg_match($regex, $s, $m)) return $m[0] ?? '';
  return '';
}

function extract_match1(string $s, string $regex): string {
  if (preg_match($regex, $s, $m)) return $m[1] ?? '';
  return '';
}

function extract_text(string $s, string $regex, bool $keepSpanOnly = false): string {
  if (!preg_match($regex, $s, $m)) return '';
  $t = (string)($m[1] ?? '');
  return $keepSpanOnly ? strip_span_only($t) : strip_tags_simple($t);
}

function match_first(string $s, string $regex): string {
  if (!preg_match($regex, $s, $m)) return '';
  return (string)($m[0] ?? '');
}

function strip_tags_simple(string $s): string {
  $s = preg_replace('/<[^>]*>/', '', $s);
  $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
  $s = str_replace(["\xC2\xA0", '&nbsp;'], ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}

function strip_span_only(string $s): string {
  $s = preg_replace('/<span[^>]*>|<\/span>/', '', $s);
  $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
  $s = str_replace(["\xC2\xA0", '&nbsp;'], ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}

function strip_span_and_content(string $s): string {
  $s = preg_replace('/<span[^>]*>[\s\S]*?<\/span>/i', '', $s);
  $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
  $s = str_replace(["\xC2\xA0", '&nbsp;'], ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}

function extract_following_cell(string $block, string $thRegex): string {
  $pos = preg_match($thRegex, $block, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : -1;
  if ($pos < 0) return '';
  $sub = substr($block, $pos);
  if (preg_match('/<\/th>\s*<td[^>]*>([\s\S]*?)<\/td>/i', $sub, $mm)) {
    return strip_tags_simple($mm[1]);
  }
  return '';
}

// ===== number helpers =====
function to_fixed1_number($raw) {
  if ($raw === null || $raw === '') return '';
  $clean = (string)$raw;
  $clean = str_replace(['&nbsp;', "\xC2\xA0"], '', $clean);
  $clean = preg_replace('/[\s　,]/u', '', $clean);
  $n = (float)$clean;
  if (!is_finite($n)) return '';
  return (float)number_format($n, 1, '.', '');
}

function to_number($raw) {
  if ($raw === null || $raw === '') return '';
  $clean = (string)$raw;
  $clean = str_replace(['&nbsp;', "\xC2\xA0"], '', $clean);
  $clean = preg_replace('/[\s　,]/u', '', $clean);
  $clean = preg_replace('/円|株|％|%/u', '', $clean);
  if ($clean === '' || $clean === '-' || $clean === '－' || $clean === 'ー') return 0.0;
  $n = (float)$clean;
  return is_finite($n) ? $n : '';
}

function to_fixed1_marketcap_number($raw) {
  if ($raw === null || $raw === '') return '';

  $str = (string)$raw;
  $str = str_replace(['&nbsp;', "\xC2\xA0"], '', $str);
  $str = str_replace(',', '', $str);
  $str = preg_replace('/億円?/u', '', $str);
  $str = trim($str);
  if ($str === '') return '';

  $totalOoku = 0.0;
  if (mb_strpos($str, '兆') !== false) {
    $parts = explode('兆', $str);
    $cho = isset($parts[0]) ? (float)$parts[0] : 0.0;
    $oku = isset($parts[1]) ? (float)$parts[1] : 0.0;
    $totalOoku = $cho * 10000.0 + $oku;
  } else {
    $totalOoku = (float)$str;
  }

  return (float)number_format($totalOoku, 1, '.', '');
}

function normalize_number(string $s): string {
  $s = str_replace(['&nbsp;', "\xC2\xA0"], '', $s);
  $s = preg_replace('/[\s　,]/u', '', $s);
  $s = preg_replace('/円|株|％|%/u', '', $s);
  return trim($s);
}
