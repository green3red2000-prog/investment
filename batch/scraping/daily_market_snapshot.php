<?php
declare(strict_types=1);

/**
 * 本日の株価動向（kabutan warning）
 *
 * - 開始条件：売買代金ランキングページ上部の日付（meigara_count）が今日(Asia/Tokyo)と一致した場合のみ
 * - /warning/ 配下は 1ページ=15件なら page=2 を自動連結して取得（GETのみ）
 * - 出力列:
 *   種別, 証券コード, 銘柄名, 市場, 株価, 値幅制限, 前日比, 前日比(%), 基準値/取引量/代金
 * - 出力：/opt/invest/scraping/tmp に
 *   本日の株価動向_yyyy-MM-dd.csv
 *   本日の株価動向_メッセージ_yyyy-MM-dd.txt
 * - アップロード：Drive「投資/プログラミング/GAS/スクレイピング/出力結果」
 *   CSVはGoogle Sheets形式に変換
 * - 成功後、ローカルcsv/txt削除
 * - メール送信しない
 *
 * PHP 7.4.30 (cli)
 */

require __DIR__ . '/lib/scraping_common.php';

// ====== 設定 ======
const TZ = 'Asia/Tokyo';
const JOB_NAME = '本日の株価動向';

// 実行単位でプロキシ固定（403回避に重要）
http_session_begin(true);

// 主要URL
const URL_TRADE_VALUE = 'https://kabutan.jp/warning/trading_value_ranking';

const SNAPSHOT_ROOT = '/opt/invest/scraping/data';

const URL_FILE_MAP = [

  'https://kabutan.jp/warning/trading_value_ranking'
    => '01_market_01_trading_value_ranking.html',

  'https://kabutan.jp/warning/volume_ranking'
    => '01_market_02_volume_ranking.html',

  'https://kabutan.jp/warning/?mode=2_1'
    => '01_market_03_today_price_rise.html',

  'https://kabutan.jp/warning/?mode=2_2'
    => '01_market_04_today_price_fall.html',

  'https://kabutan.jp/warning/?mode=3_1'
    => '01_market_05_stop_high.html',

  'https://kabutan.jp/warning/?mode=3_2'
    => '01_market_06_stop_low.html',

  'https://kabutan.jp/warning/record_w52_high_price?market=0&capitalization=-1&stc=code&stm=0&col=per'
    => '01_market_07_52week_high.html',

  'https://kabutan.jp/warning/record_w52_low_price?market=0&capitalization=-1&stc=code&stm=1&col=per'
    => '01_market_08_52week_low.html',

  'https://kabutan.jp/warning/?mode=3_3&market=0&capitalization=-1&stc=per&stm=0&col=per'
    => '01_market_09_ytd_high.html',

  'https://kabutan.jp/warning/?mode=3_4&market=0&capitalization=-1&stc=code&stm=1&col=per'
    => '01_market_10_ytd_low.html',

  'https://kabutan.jp/warning/?mode=11_11'
    => '01_market_11_week_rise.html',

  'https://kabutan.jp/warning/?mode=11_15'
    => '01_market_12_month_rise.html',

  'https://kabutan.jp/warning/?mode=11_19'
    => '01_market_13_year_rise.html',

  'https://kabutan.jp/warning/?mode=11_13'
    => '01_market_14_past_week_rise.html',

  'https://kabutan.jp/warning/?mode=11_17'
    => '01_market_15_past_month_rise.html',

  'https://kabutan.jp/warning/?mode=11_21'
    => '01_market_16_past_year_rise.html',

  'https://kabutan.jp/warning/?mode=11_12'
    => '01_market_17_week_fall.html',

  'https://kabutan.jp/warning/?mode=11_16'
    => '01_market_18_month_fall.html',

  'https://kabutan.jp/warning/?mode=11_20'
    => '01_market_19_year_fall.html',

  'https://kabutan.jp/warning/?mode=11_14'
    => '01_market_20_past_week_fall.html',

  'https://kabutan.jp/warning/?mode=11_18'
    => '01_market_21_past_month_fall.html',

  'https://kabutan.jp/warning/?mode=11_22'
    => '01_market_22_past_year_fall.html',
];

// ====== テスト用オプション ======
// 例:
//   # 通常（当日判定）
//   php daily_market_snapshot.php
//
//   # テスト：日付を 2026-02-16 扱いにする（開始判定もその日付）
//   php daily_market_snapshot.php --date=2026-02-16
//
//   # テスト：日付不一致でも強制的に通す（過去日付テストで必要になりがち）
//   php daily_market_snapshot.php --date=2026-02-16 --force
[$targetYmd, $force] = parse_cli_options_($argv ?? []);
main($targetYmd, $force);

function main(?string $targetYmd = null, bool $force = false): void {
  date_default_timezone_set(TZ);

  // "今日" を任意日付に差し替え可能（テスト用）
  $dt = get_target_datetime_($targetYmd);
  $todayYmd   = $dt->format('Y-m-d');
  $todayJp    = $dt->format('Y年m月d日');
  $todaySlash = $dt->format('Y/m/d');
  
  $GLOBALS['SNAPSHOT_DATE'] = $dt->format('Ymd');

  echo "=== 開始: " . JOB_NAME . " ===\n";
  echo "今日: {$todayJp} ({$todayYmd})\n";
  
  if ($targetYmd !== null) {
    echo "※テスト指定日付: {$todayYmd}\n";
  }
  if ($force) {
    echo "※--force: 開始判定を無視します（テスト用）\n";
  }

  // ====== 開始判定（売買代金ページの日付が今日か） ======
  echo "開始判定: 売買代金ページ\n";
  $firstHtml = http_get_warning_html_with_page2_(URL_TRADE_VALUE);
  $headerInfo = parse_meigara_count_($firstHtml);
  $pageDateStr = $headerInfo['date'] ?? '';

  if (!$force && $pageDateStr !== $todayJp) {
    echo "処理中止: ページ日付({$pageDateStr})が今日({$todayJp})と一致しません。\n";
    exit(0);
  }
  if ($pageDateStr === $todayJp) {
    echo "OK: 本日分 ({$pageDateStr}) を検出。取得開始します。\n";
  } else {
    echo "WARN: ページ日付({$pageDateStr})と指定日付({$todayJp})が不一致ですが、--force で続行します。\n";
  }

  // ====== 出力ヘッダ（A列=日付なし） ======
  $rows = [];
  $header = ['種別','証券コード','銘柄名','市場','株価','値幅制限','前日比','前日比(%)','基準値/取引量/代金'];
  $rows[] = $header;

  // 件数（メッセージ用）
  $cntStopHigh = 0;
  $cntStopLow  = 0;
  $cnt52wHigh  = 0;
  $cnt52wLow   = 0;
  $cntYtdHigh  = 0;
  $cntYtdLow   = 0;

  // ========= 出力順 =========

  // 1) 売買代金（上位20）
  {
    $html = http_get_warning_html_with_page2_(URL_TRADE_VALUE);
    $list = parse_stock_table_rows_multi_($html);
    $list = array_slice($list, 0, 20);
    foreach ($list as $r) {
      $rows[] = [
        '売買代金',
        $r['code'], $r['name'], $r['market'],
        $r['td'][4] ?? '',
        $r['td'][5] ?? '',
        $r['td'][6] ?? '',
        $r['td'][7] ?? '',
        $r['td'][8] ?? '',
      ];
    }
  }

  // 2) 出来高（株価>=100）上位20
  {
    $url = 'https://kabutan.jp/warning/volume_ranking';
    $html = http_get_warning_html_with_page2_($url);
    $list = parse_stock_table_rows_multi_($html);

    $filtered = [];
    foreach ($list as $r) {
      $price = to_number_safe_($r['td'][4] ?? '');
      if ($price >= 100) $filtered[] = $r;
    }
    $filtered = array_slice($filtered, 0, 20);

    foreach ($filtered as $r) {
      $rows[] = [
        '出来高',
        $r['code'], $r['name'], $r['market'],
        $r['td'][4] ?? '',
        $r['td'][5] ?? '',
        $r['td'][6] ?? '',
        $r['td'][7] ?? '',
        $r['td'][8] ?? '',
      ];
    }
  }

  // 3) 今日の株価上昇率（上位20）
  push_today_change_($rows, '今日の株価上昇率', 'https://kabutan.jp/warning/?mode=2_1');

  // 4) 今日の株価下落率（上位20）
  push_today_change_($rows, '今日の株価下落率', 'https://kabutan.jp/warning/?mode=2_2');

  // 5) 本日のストップ高（全件） + 件数
  {
    $url = 'https://kabutan.jp/warning/?mode=3_1';
    $html = http_get_warning_html_with_page2_($url);
    $cntStopHigh = (int)(parse_meigara_count_($html)['count'] ?? 0);
    $list = parse_stock_table_rows_multi_($html);
    foreach ($list as $r) {
      $rows[] = [
        '本日のストップ高',
        $r['code'], $r['name'], $r['market'],
        $r['td'][4] ?? '',
        $r['td'][5] ?? '',
        $r['td'][6] ?? '',
        $r['td'][7] ?? '',
        '', // 最後は空
      ];
    }
  }

  // 6) 本日のストップ安（全件） + 件数
  {
    $url = 'https://kabutan.jp/warning/?mode=3_2';
    $html = http_get_warning_html_with_page2_($url);
    $cntStopLow = (int)(parse_meigara_count_($html)['count'] ?? 0);
    $list = parse_stock_table_rows_multi_($html);
    foreach ($list as $r) {
      $rows[] = [
        '本日のストップ安',
        $r['code'], $r['name'], $r['market'],
        $r['td'][4] ?? '',
        $r['td'][5] ?? '',
        $r['td'][6] ?? '',
        $r['td'][7] ?? '',
        '',
      ];
    }
  }

  // 7) 本日、52週高値更新（全件） + 件数
  {
    $url = 'https://kabutan.jp/warning/record_w52_high_price?market=0&capitalization=-1&stc=code&stm=0&col=per';
    $html = http_get_warning_html_with_page2_($url);
    $cnt52wHigh = (int)(parse_meigara_count_($html)['count'] ?? 0);
    $list = parse_stock_table_rows_multi_($html);
    foreach ($list as $r) {
      $rows[] = [
        '本日、52週高値更新',
        $r['code'], $r['name'], $r['market'],
        $r['td'][4] ?? '',
        $r['td'][5] ?? '',
        $r['td'][6] ?? '',
        $r['td'][7] ?? '',
        '',
      ];
    }
  }

  // 8) 本日、52週安値更新（全件） + 件数
  {
    $url = 'https://kabutan.jp/warning/record_w52_low_price?market=0&capitalization=-1&stc=code&stm=1&col=per';
    $html = http_get_warning_html_with_page2_($url);
    $cnt52wLow = (int)(parse_meigara_count_($html)['count'] ?? 0);
    $list = parse_stock_table_rows_multi_($html);
    foreach ($list as $r) {
      $rows[] = [
        '本日、52週安値更新',
        $r['code'], $r['name'], $r['market'],
        $r['td'][4] ?? '',
        $r['td'][5] ?? '',
        $r['td'][6] ?? '',
        $r['td'][7] ?? '',
        '',
      ];
    }
  }

  // 9) 本日、年初来高値更新（全件） + 件数
  {
    $url = 'https://kabutan.jp/warning/?mode=3_3&market=0&capitalization=-1&stc=per&stm=0&col=per';
    $html = http_get_warning_html_with_page2_($url);
    $cntYtdHigh = (int)(parse_meigara_count_($html)['count'] ?? 0);
    $list = parse_stock_table_rows_multi_($html);
    foreach ($list as $r) {
      $rows[] = [
        '本日、年初来高値更新',
        $r['code'], $r['name'], $r['market'],
        $r['td'][4] ?? '',
        $r['td'][5] ?? '',
        $r['td'][6] ?? '',
        $r['td'][7] ?? '',
        '',
      ];
    }
  }

  // 10) 本日、年初来安値更新（全件） + 件数
  {
    $url = 'https://kabutan.jp/warning/?mode=3_4&market=0&capitalization=-1&stc=code&stm=1&col=per';
    $html = http_get_warning_html_with_page2_($url);
    $cntYtdLow = (int)(parse_meigara_count_($html)['count'] ?? 0);
    $list = parse_stock_table_rows_multi_($html);
    foreach ($list as $r) {
      $rows[] = [
        '本日、年初来安値更新',
        $r['code'], $r['name'], $r['market'],
        $r['td'][4] ?? '',
        $r['td'][5] ?? '',
        $r['td'][6] ?? '',
        $r['td'][7] ?? '',
        '',
      ];
    }
  }

  // 11)〜16) 上昇率（週/月/年/過去◯）
  add_period_change_($rows, '今週の株価上昇率',       'https://kabutan.jp/warning/?mode=11_11');
  add_period_change_($rows, '今月の株価上昇率',       'https://kabutan.jp/warning/?mode=11_15');
  add_period_change_($rows, '今年の株価上昇率',       'https://kabutan.jp/warning/?mode=11_19');
  add_period_change_($rows, '過去1週間の株価上昇率', 'https://kabutan.jp/warning/?mode=11_13');
  add_period_change_($rows, '過去1ヵ月の株価上昇率', 'https://kabutan.jp/warning/?mode=11_17');
  add_period_change_($rows, '過去1年の株価上昇率',   'https://kabutan.jp/warning/?mode=11_21');

  // 17)〜22) 下落率（週/月/年/過去◯）
  add_period_change_($rows, '今週の株価下落率',       'https://kabutan.jp/warning/?mode=11_12');
  add_period_change_($rows, '今月の株価下落率',       'https://kabutan.jp/warning/?mode=11_16');
  add_period_change_($rows, '今年の株価下落率',       'https://kabutan.jp/warning/?mode=11_20');
  add_period_change_($rows, '過去1週間の株価下落率', 'https://kabutan.jp/warning/?mode=11_14');
  add_period_change_($rows, '過去1ヵ月の株価下落率', 'https://kabutan.jp/warning/?mode=11_18');
  add_period_change_($rows, '過去1年の株価下落率',   'https://kabutan.jp/warning/?mode=11_22');

  // ====== 出力（CSV + メッセージTXT） ======
  $paths = build_output_paths(JOB_NAME, $todayYmd);
  $csvPath = $paths['csv'];
  $txtPath = $paths['txt'];

  write_csv_($csvPath, $rows);

  $subject = JOB_NAME . "：{$todaySlash}";
  $body =
    "本日のストップ高銘柄数は、{$cntStopHigh}銘柄でした。\n" .
    "本日のストップ安銘柄数は、{$cntStopLow}銘柄でした。\n" .
    "本日、52週高値を更新した銘柄数は、{$cnt52wHigh}銘柄でした。\n" .
    "本日、52週安値を更新した銘柄数は、{$cnt52wLow}銘柄でした。\n" .
    "本日、年初来高値を更新した銘柄数は、{$cntYtdHigh}銘柄でした。\n" .
    "本日、年初来安値を更新した銘柄数は、{$cntYtdLow}銘柄でした。\n";

  write_message_txt($txtPath, $subject, $body);

  echo "ローカル出力完了:\n- {$csvPath}\n- {$txtPath}\n";

  // ====== アップロード & ローカル削除 ======
  upload_outputs_and_cleanup(JOB_NAME, $todayYmd, $csvPath, $txtPath);

  echo "=== 完了 ===\n";
}


/**
 * CLIオプション
 *  --date=YYYY-MM-DD : "今日" をその日付扱いにする（テスト用）
 *  --force           : 開始判定（日付一致）を無視して続行（テスト用）
 */
function parse_cli_options_(array $argv): array {
  $targetYmd = null;
  $force = false;
  foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if ($a === '--force') {
      $force = true;
      continue;
    }
    if (strpos($a, '--date=') === 0) {
      $v = substr($a, strlen('--date='));
      $v = trim($v);
      if ($v !== '') $targetYmd = $v;
      continue;
    }
  }
  return [$targetYmd, $force];
}

/**
 * 指定日付（YYYY-MM-DD）を Asia/Tokyo の DateTimeImmutable に変換
 */
function get_target_datetime_(?string $ymd): DateTimeImmutable {
  $tz = new DateTimeZone(TZ);
  if ($ymd === null || $ymd === '') {
    return new DateTimeImmutable('now', $tz);
  }
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
    throw new InvalidArgumentException("Invalid --date format. expected YYYY-MM-DD: {$ymd}");
  }
  $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, $tz);
  if ($dt === false) {
    throw new InvalidArgumentException("Invalid --date value: {$ymd}");
  }
  return $dt;
}

/**
 * 今日の上昇/下落（上位20）
 */
function push_today_change_(array &$rows, string $label, string $url): void {
  $html = http_get_warning_html_with_page2_($url);
  $list = parse_stock_table_rows_multi_($html);
  $list = array_slice($list, 0, 20);
  foreach ($list as $r) {
    $rows[] = [
      $label,
      $r['code'], $r['name'], $r['market'],
      $r['td'][4] ?? '',
      $r['td'][5] ?? '',
      $r['td'][6] ?? '',
      $r['td'][7] ?? '',
      '',
    ];
  }
}

/**
 * 期間騰落ランキング系（上位20）
 * 列マッピング：
 *  td5:株価, td6:値幅制限, td7:基準値, td8:前日比, td9:前日比(%)
 * 出力列:
 *  株価=td5, 値幅制限=td6, 前日比=td8, 前日比(%)=td9, 基準値/取引量/代金=td7
 */
function add_period_change_(array &$rows, string $label, string $url): void {
  $html = http_get_warning_html_with_page2_($url);
  $list = parse_stock_table_rows_multi_($html);
  $list = array_slice($list, 0, 20);
  foreach ($list as $r) {
    $rows[] = [
      $label,
      $r['code'], $r['name'], $r['market'],
      $r['td'][4] ?? '',
      $r['td'][5] ?? '',
      $r['td'][7] ?? '',
      $r['td'][8] ?? '',
      $r['td'][6] ?? '',
    ];
  }
}

function http_get_warning_html_with_page2_(string $url): string {

  if (!isset(URL_FILE_MAP[$url])) {
    throw new RuntimeException(
      "URL not mapped: {$url}"
    );
  }

  $snapshotDate =
    $GLOBALS['SNAPSHOT_DATE']
    ?? date('Ymd');

  $file =
    SNAPSHOT_ROOT .
    '/' .
    $snapshotDate .
    '/' .
    URL_FILE_MAP[$url];

  if (!is_file($file)) {
    throw new RuntimeException(
      "snapshot file not found: {$file}"
    );
  }

  $html = file_get_contents($file);

  if ($html === false) {
    throw new RuntimeException(
      "snapshot read failed: {$file}"
    );
  }

  return $html;
}

/**
 * <div class="meigara_count"> の date/time/count を抽出
 */
function parse_meigara_count_(string $html): array {
  $date = '';
  $time = '';
  $count = 0;

  if (preg_match('/<div\s+class="meigara_count">([\s\S]*?)<\/div>/i', $html, $m)) {
    if (preg_match('/<ul>([\s\S]*?)<\/ul>/i', $m[1], $m2)) {
      preg_match_all('/<li>([\s\S]*?)<\/li>/i', $m2[1], $lis);
      $items = [];
      foreach (($lis[1] ?? []) as $li) {
        $items[] = trim(strip_tags_($li));
      }
      $date = $items[0] ?? '';
      $time = $items[1] ?? '';
      $countStr = $items[2] ?? '';
      $countStr = str_replace(['，',',','銘柄'], '', $countStr);
      $count = to_number_safe_($countStr);
    }
  }

  return ['date' => $date, 'time' => $time, 'count' => $count];
}

/**
 * HTML（複数ページ連結を想定）から、stock_table st_market を全部拾って行配列にする
 * return: [{code,name,market,td:[...]}]
 */
function parse_stock_table_rows_multi_(string $html): array {
  preg_match_all('/<table[^>]*class="[^"]*\bstock_table\b[^"]*\bst_market\b[^"]*"[^>]*>([\s\S]*?)<\/table>/i', $html, $tables);
  if (empty($tables[1])) return [];

  $out = [];

  foreach ($tables[1] as $tableInner) {
    $tbody = '';
    if (preg_match('/<tbody[^>]*>([\s\S]*?)<\/tbody>/i', $tableInner, $m2)) {
      $tbody = $m2[1];
    }
    if ($tbody === '') continue;

    preg_match_all('/<tr[^>]*>([\s\S]*?)<\/tr>/i', $tbody, $trs);
    foreach (($trs[1] ?? []) as $tr) {
      // td全部
      preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/i', $tr, $tds);
      $tdMatches = $tds[1] ?? [];

      // th（銘柄名）
      preg_match_all('/<th[^>]*>([\s\S]*?)<\/th>/i', $tr, $ths);
      $thMatches = $ths[1] ?? [];

      // code（最初の<td> の aテキスト優先）
      $code = '';
      if (preg_match('/<td[^>]*>([\s\S]*?)<\/td>/i', $tr, $mFirstTd)) {
        $firstTdRaw = $mFirstTd[1] ?? '';
        if (preg_match('/<a[^>]*>([\s\S]*?)<\/a>/i', $firstTdRaw, $mA)) {
          $code = preg_replace('/\s+/', '', strip_tags_($mA[1]));
        } else {
          $code = preg_replace('/\s+/', '', strip_tags_($firstTdRaw));
        }
        $code = trim($code);
      }

      $name = isset($thMatches[0]) ? trim(strip_tags_($thMatches[0])) : '';
      $market = isset($tdMatches[1]) ? trim(strip_tags_($tdMatches[1])) : '';

      // tdPlain（span除去＝タグ全部除去）
      $tdPlain = [];
      foreach ($tdMatches as $cell) {
        $tdPlain[] = strip_tags_and_space_($cell);
      }

      $out[] = [
        'code' => $code,
        'name' => $name,
        'market' => $market,
        'td' => $tdPlain,
      ];
    }
  }

  return $out;
}

function strip_tags_(string $s): string {
  return preg_replace('/<[^>]*>/', '', $s);
}

function strip_tags_and_space_(string $s): string {
  $t = preg_replace('/<[^>]+>/', '', $s);
  $t = preg_replace('/\s+/', ' ', $t);
  return trim($t);
}

function to_number_safe_($s): int {
  $t = is_string($s) ? $s : (string)$s;
  $t = str_replace([',','，',' ','　'], '', $t);
  $t = preg_replace('/[^\-0-9.]/', '', $t);
  if ($t === '' || $t === '-' || $t === '.' ) return 0;
  $n = (float)$t;
  return (int)$n;
}

/**
 * CSV出力（Google Sheets変換前提）
 */
function write_csv_(string $csvPath, array $rows): void {
  $fp = fopen($csvPath, 'w');
  if ($fp === false) throw new RuntimeException("CSV open failed: {$csvPath}");

  // Excelで開く可能性があるならBOM
  fwrite($fp, "\xEF\xBB\xBF");

  foreach ($rows as $r) {
    fputcsv($fp, $r);
  }
  fclose($fp);
}
