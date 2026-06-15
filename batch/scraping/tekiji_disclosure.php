<?php
declare(strict_types=1);

/**
 * 適時開示情報（株探 kabutan.jp/disclosures 当日分）
 *
 * - 未来→スキップ／同日→取り込み／過去→終了
 * - 当日分「全件」をCSV出力（詳細リンク列は =HYPERLINK("URL","詳細")）
 * - 事前登録コード一致分をメッセージTXTにまとめる（空行区切り、詳細リンクあり）
 * - /opt/invest/scraping/tmp に csv + txt 出力
 * - Google Driveへアップロード（CSVはGoogle Sheets化、TXTはそのまま）
 * - 成功後ローカル削除
 * - メール送信しない
 *
 * PHP 7.4.30
 */

require __DIR__ . '/lib/scraping_common.php';

// ====== 設定 ======
const TZ                 = 'Asia/Tokyo';
const JOB_NAME           = '適時開示';

const BASE_URL           = 'https://kabutan.jp/disclosures/?kubun=&page=';
const MAX_PAGES          = 50;
const PAGE_INTERVAL_US   = 1200000; // 1.2秒（GASの1200ms相当）

// 事前登録コード（完全一致）
const TARGET_CODES = ['6492', '290A', '262A', '2502', '4011', '3773', '3655', '5259', '5247'];

// 実行単位でプロキシ固定（推奨）
http_session_begin(true);

// ====== 実行 ======
main();

function main(): void {
  date_default_timezone_set(TZ);

  $todaySlash  = date('Y/m/d');        // yyyy/MM/dd
  $todayHyphen = str_replace('/', '-', $todaySlash); // yyyy-MM-dd
  $todayKey    = dateKey_($todaySlash);

  echo "=== 開始: 適時開示 ===\n";
  echo "当日: {$todaySlash}\n";

  $allTodayRows = [];
  $pickedRows   = [];

  $page = 1;
  $stop = false;

  while (!$stop && $page <= MAX_PAGES) {
    $url = BASE_URL . $page;
    echo "--- page={$page} GET: {$url}\n";

    $html = http_get_text($url, [
      'timeout' => 30,
      'retry_max' => 5,
      'retry_sleep_ms' => 800,
    ]);

    $rows = parse_disclosures_stock_table_($html);

    if (count($rows) === 0) {
      echo "page={$page}: 行が0件のため終了\n";
      break;
    }

    echo "page={$page}: 取得行数=" . count($rows) . "\n";

    foreach ($rows as $r) {
      $dateOnly = normalizeDateYYYYMMDD_($r['datetimeStr']); // yyyy/MM/dd or null
      if ($dateOnly === null) {
        continue;
      }
      $k = dateKey_($dateOnly);

      if ($k > $todayKey) {
        // 未来 → スキップ
        continue;
      }

      if ($k === $todayKey) {
        // 当日 → 全件プール
        $allTodayRows[] = $r;

        // コード一致 → メッセージ対象
        if (in_array(strtoupper($r['code']), array_map('strtoupper', TARGET_CODES), true)) {
          $pickedRows[] = $r;
        }
      } else {
        // 過去 → 終了
        $stop = true;
        break;
      }
    }

    if ($stop) {
      echo "過去日に到達したため終了\n";
      break;
    }

    // 次ページ判定：最終行が当日なら続行、それ以外は安全側で続行（GAS踏襲）
    $last = $rows[count($rows) - 1];
    $lastDate = normalizeDateYYYYMMDD_($last['datetimeStr']);
    $lastKey  = $lastDate ? dateKey_($lastDate) : null;

    if ($lastKey !== null && $lastKey === $todayKey) {
      $page++;
      if ($page > MAX_PAGES) {
        echo "最大ページ数(" . MAX_PAGES . ")に到達したため終了\n";
        break;
      }
      usleep(PAGE_INTERVAL_US);
      continue;
    }

    if ($lastKey !== null && $lastKey < $todayKey) {
      echo "最終行が過去日のため終了\n";
      break;
    }

    // 判定不能/未来混在 → 安全側で次ページ
    $page++;
    if ($page > MAX_PAGES) break;
    usleep(PAGE_INTERVAL_US);
  }

  echo "=== 巡回終了: 当日全件=" . count($allTodayRows) . " / 抽出=" . count($pickedRows) . " ===\n";

  // 出力パス
  $paths = build_output_paths(JOB_NAME, $todayHyphen);
  $csvPath = $paths['csv'];
  $txtPath = $paths['txt'];

  // CSV出力（当日全件）
  write_csv_all_hyperlink_($csvPath, $allTodayRows);

  // メッセージTXT（抽出分）
  $subject = JOB_NAME . "：{$todaySlash}";
  $body    = build_message_body_($todaySlash, $pickedRows, $allTodayRows);
  write_message_txt($txtPath, $subject, $body);

  echo "ローカル出力完了:\n- {$csvPath}\n- {$txtPath}\n";

  // アップロード＆削除（共通lib）
  upload_outputs_and_cleanup(JOB_NAME, $todayHyphen, $csvPath, $txtPath);

  echo "=== 完了 ===\n";
}

/**
 * CSV出力（当日全件）
 * 列: コード,会社名,市場,情報種別,タイトル,詳細リンク(式),開示日時
 *
 * 詳細リンクは:
 *   =HYPERLINK("URL","詳細")
 */
function write_csv_all_hyperlink_(string $csvPath, array $rows): void {
  $fp = fopen($csvPath, 'w');
  if ($fp === false) throw new RuntimeException("CSV open failed: {$csvPath}");

  // Excel想定ならBOM。不要なら削除OK
  fwrite($fp, "\xEF\xBB\xBF");

  fputcsv($fp, ['コード','会社名','市場','情報種別','タイトル','詳細リンク','開示日時']);

  foreach ($rows as $r) {
    $url = (string)($r['titleUrl'] ?? '');
    $linkFormula = ($url !== '') ? make_hyperlink_formula_($url, '詳細') : '';

    fputcsv($fp, [
      $r['code'] ?? '',
      $r['company'] ?? '',
      $r['market'] ?? '',
      $r['kind'] ?? '',
      $r['title'] ?? '',
      $linkFormula,
      $r['datetimeStr'] ?? '',
    ]);
  }

  fclose($fp);
}

/**
 * =HYPERLINK("URL","TEXT") を生成
 * - CSV経由でGoogle Sheetsに変換される前提
 * - URLや表示文字列内の " は "" にエスケープ
 */
function make_hyperlink_formula_(string $url, string $text): string {
  $u = str_replace('"', '""', $url);
  $t = str_replace('"', '""', $text);
  return '=HYPERLINK("' . $u . '","' . $t . '")';
}

/**
 * メッセージ本文（抽出分のみ）
 */
function build_message_body_(string $todaySlash, array $pickedRows, array $allTodayRows): string {
  $lines = [];
  $lines[] = "----- 抽出結果（" . count($pickedRows) . "件, 当日={$todaySlash}）-----";

  $i = 1;
  foreach ($pickedRows as $r) {
    $lines[] = "{$i}.";
    $lines[] = "コード=" . ($r['code'] ?? '');
    $lines[] = "会社名=" . ($r['company'] ?? '');
    $lines[] = "市場=" . ($r['market'] ?? '');
    $lines[] = "種別=" . ($r['kind'] ?? '');
    $lines[] = "タイトル=" . ($r['title'] ?? '');
    $lines[] = "詳細リンク=" . ($r['titleUrl'] ?? '');
    $lines[] = "開示日時=" . ($r['datetimeStr'] ?? '');
    $lines[] = ""; // 空行
    $i++;
  }

  $lines[] = "----------------------------------------------";
  $lines[] = "当日全件数: " . count($allTodayRows) . "件";

  return implode("\n", $lines) . "\n";
}

/**
 * kabutan disclosures の stock_table から行抽出
 * 返却: [{code, company, market, kind, title, titleUrl, datetimeStr}, ...]
 */
function parse_disclosures_stock_table_(string $html): array {
  if (!preg_match('/<table[^>]*class="[^"]*\bstock_table\b[^"]*"[^>]*>[\s\S]*?<\/table>/i', $html, $m)) {
    return [];
  }
  $tableHtml = $m[0];

  if (preg_match('/<tbody[^>]*>([\s\S]*?)<\/tbody>/i', $tableHtml, $m2)) {
    $scope = $m2[1];
  } else {
    $scope = $tableHtml;
  }

  preg_match_all('/<tr[^>]*>([\s\S]*?)<\/tr>/i', $scope, $trs);
  if (empty($trs[1])) return [];

  $rows = [];

  foreach ($trs[1] as $tr) {
    if (preg_match('/scope=["\']col["\']/', $tr)) continue;

    // code
    $code = '';
    if (preg_match('/<td[^>]*class="[^"]*\btac\b[^"]*"[^>]*>[\s\S]*?<a[^>]*>([\s\S]*?)<\/a>[\s\S]*?<\/td>/i', $tr, $mCode)) {
      $code = strtoupper(cleanText_($mCode[1]));
    }
    if ($code === '') continue;

    // company
    $company = '';
    if (preg_match('/<th[^>]*class="[^"]*\btal\b[^"]*"[^>]*>([\s\S]*?)<\/th>/i', $tr, $mName)) {
      $company = cleanText_($mName[1]);
    }

    // market (2nd td.tac)
    $market = '';
    preg_match_all('/<td[^>]*class="[^"]*\btac\b[^"]*"[^>]*>([\s\S]*?)<\/td>/i', $tr, $mTac);
    if (!empty($mTac[1]) && count($mTac[1]) >= 2) {
      $market = cleanText_($mTac[1][1]);
    }

    // title + url (td.tal.wsnormal a)
    $title = '';
    $titleUrl = '';
    if (preg_match('/<td[^>]*class="[^"]*\btal\b[^"]*\bwsnormal\b[^"]*"[^>]*>[\s\S]*?<a[^>]*href=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>[\s\S]*?<\/td>/i', $tr, $mTitle)) {
      $titleUrl = $mTitle[1];
      $title    = cleanText_($mTitle[2]);
    } else {
      if (preg_match('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i', $tr, $mA)) {
        $titleUrl = $mA[1];
        $title    = cleanText_($mA[2]);
      }
    }

    // kind (simple)
    $kind = '';
    preg_match_all('/<td[^>]*class="([^"]*)"[^>]*>([\s\S]*?)<\/td>/i', $tr, $mTds, PREG_SET_ORDER);
    foreach ($mTds as $td) {
      $cls = $td[1] ?? '';
      $cellHtml = $td[2] ?? '';
      if (stripos($cls, 'tal') !== false && stripos($cls, 'wsnormal') === false) {
        $candidate = cleanText_($cellHtml);
        if ($candidate !== '' && $candidate !== $market && $candidate !== $code) {
          $kind = $candidate;
          break;
        }
      }
    }

    // datetime
    $datetimeStr = '';
    if (preg_match('/<time[^>]*>([\s\S]*?)<\/time>/i', $tr, $mTime)) {
      $datetimeStr = cleanText_($mTime[1]);
      $datetimeStr = str_replace(["\xC2\xA0", '&nbsp;'], ' ', $datetimeStr);
      $datetimeStr = trim(preg_replace('/\s+/', ' ', $datetimeStr));
    }

    $rows[] = [
      'code' => $code,
      'company' => $company,
      'market' => $market,
      'kind' => $kind,
      'title' => $title,
      'titleUrl' => $titleUrl,
      'datetimeStr' => $datetimeStr,
    ];
  }

  return $rows;
}

// ===== 小物 =====

function dateKey_(string $yyyyMMddSlash): int {
  $num = preg_replace('/\D+/', '', $yyyyMMddSlash);
  return (int)substr($num, 0, 8);
}

function normalizeDateYYYYMMDD_(string $s): ?string {
  if ($s === '') return null;

  if (preg_match('/(\d{4})\/(\d{2})\/(\d{2})/', $s, $m)) {
    return "{$m[1]}/{$m[2]}/{$m[3]}";
  }
  if (preg_match('/(?:^|\s)(\d{2})\/(\d{2})\/(\d{2})(?:\s|$)/', $s, $m)) {
    return "20{$m[1]}/{$m[2]}/{$m[3]}";
  }
  return null;
}

function cleanText_(string $s): string {
  if ($s === '') return '';
  $t = preg_replace('/<[^>]+>/', '', $s);
  $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
  $t = str_replace("\xC2\xA0", ' ', $t);
  $t = preg_replace('/\s+/', ' ', $t);
  return trim($t);
}
