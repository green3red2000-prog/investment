<?php
declare(strict_types=1);

/**
 * 全銘柄日足取得（Kabutan日足スクレイピング → DB upsert → CSV/TXT出力 → Driveアップロード）
 *
 * - PHP 7.4.30 (cli)
 * - lib/scraping_common.php を利用
 *   - http_get_text(): Webshare proxyでHTML取得
 *   - build_output_paths(): /opt/invest/scraping/tmp にCSV/TXTパス作成
 *   - write_message_txt(): TXT出力
 *   - upload_outputs_and_cleanup(): Driveへアップロード（CSVはGoogle Sheets化）し、成功後ローカル削除
 *
 * - GASでUrlFetchしていたDB API呼び出しは廃止
 *   - prices_eod_latest_by_code.php のSQLをこのPHP内で実行
 *   - prices_eod_upsert.php のバリデーション＋chunk upsert をこのPHP内で実行
 *
 * - 持ち時間・消費テーブル・メール送信は廃止
 */

require __DIR__ . '/lib/scraping_common.php';

// proxyを実行単位で固定（403等のときだけローテーション）
http_session_begin(false);

date_default_timezone_set('Asia/Tokyo');

// =============================
// 設定
// =============================
$JOB_NAME = '全銘柄日足取得';

// HTTPアクセス後のランダムスリープ（μs）
const SLEEP_US_AFTER_HTTP_MIN = 1_000_000; // 1.0秒
const SLEEP_US_AFTER_HTTP_MAX = 1_500_000; // 1.5秒

// Kabutan full取得の上限件数（旧:200 → 新:280）
const FULL_FETCH_LIMIT = 280;

// 実行ウィンドウ（GAS版踏襲：土日OK、平日は17時以降のみ）
function isWithinExecutionWindow(DateTime $now): bool {
  $dow = (int)$now->format('w'); // 0=Sun ... 6=Sat
  if ($dow === 0 || $dow === 6) return true;
  $hour = (int)$now->format('G'); // 0-23
  return ($hour >= 17);
}

// =============================
// DB設定（APIファイルと同じ）
// =============================
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'stocks';
const DB_USER = 'apiuser';
const DB_PASS = 'G&TgY7Ubq5weU365a6HgxGCshU&%75MKMun8m9kMAr3S&a';

// upsert設定（prices_eod_upsert.php踏襲）
const SQL_CHUNK_ROWS = 100;

// =============================
// 日付
// =============================
$now = new DateTime('now');
if (!isWithinExecutionWindow($now)) {
  $nowStr = $now->format('Y/m/d H:i');
  fwrite(STDERR, "現在の時刻帯では実行しません（場中抑制）：{$nowStr} JST\n");
  exit(0);
}

$todayISO   = $now->format('Y-m-d');
$todaySlash = $now->format('Y/m/d');

// 出力パス（共通関数）
$paths   = build_output_paths($JOB_NAME, $todayISO);
$csvPath = $paths['csv'];
$txtPath = $paths['txt'];

// =============================
// メイン
// =============================
try {
  $pdo = buildPdo();

  // 1) マスタから銘柄コード取得（Google Sheets）
  //    - 「投資/プログラミング/GAS/マスタ/証券コードマスタ」
  //    - 見出し「証券コード」列を取得
  $codes = loadCodesFromMasterSheet();
  if (count($codes) === 0) {
    throw new RuntimeException("マスタから証券コードが0件でした。");
  }
  
  // ★★===== テスト用：先頭15銘柄で打ち切り =====★★
  //$codes = array_slice($codes, 0, 15);
  
  echo "codes loaded: " . count($codes) . "\n";

  // 2) DB最新日付を一括取得（SELECT code, MAX(asof_date) ... GROUP BY code）
  $latestMap = fetchLatestMapFromDb($pdo);
  echo "db latest map loaded: " . count($latestMap) . "\n";

  // 3) 銘柄ごとにスクレイピング（full/diff/skip）
  $statusRows = []; // 出力CSV（当日シート相当）
  $upsertTargetTotal = 0; // DB upsert 対象行数（重複排除前）
  $upsertedTotal     = 0; // DB upsert 実行行数（chunk投入合計）

  $countFull = 0;
  $countReplace = 0;
  $countDiff = 0;
  $countSkip = 0;
  $countErr  = 0;

  foreach ($codes as $idx => $code) {
    $code = trim((string)$code);
    if ($code === '') continue;

    $latest = $latestMap[$code] ?? null; // YYYY-MM-DD or null

    try {
      if ($latest === null || $latest === '') {
        // full
        $rows = scrapeKabutan_Mode2_Full($code);
        assertNoDuplicateDates($rows);

        $maxYmd = maxAsofDate($rows);
        // ★ 銘柄ごとにDB upsert（commit）
        $upsertTargetTotal += count($rows);
        $upsertedTotal     += bulkUpsertPricesEodWithReconnect($pdo, $rows);

        $countFull++;
        $statusRows[] = [$code, $todaySlash, "全件取得＆DB更新：" . count($rows) . "件", $maxYmd ?? ''];

      } else {
        $cmp = strcmp($todayISO, $latest);
        if ($cmp > 0) {
          // diff (page1)
          $rowsAll = scrapeKabutan_Mode1_Page1($code);
          
          // ===== 株式分割（調整）チェック：直近5日終値の突合 =====
          // DB: 直近5日 close（asof_date降順）
          ensurePdoAlive($pdo);

          $dbLast5 = fetchLastNCloseFromDb($pdo, $code, 5);
          $needFullReplace = false;
          if (count($dbLast5) > 0) {
            // Kabutan(page1)側も、同日付の close を拾って突合
            $webMap = [];
            foreach ($rowsAll as $r) {
              $d = (string)($r['asof_date'] ?? '');
              if ($d === '') continue;
              $webMap[$d] = (string)($r['close'] ?? '');
            }
            foreach ($dbLast5 as $d => $dbClose) {
              if (!array_key_exists($d, $webMap)) continue; // 休場などで揃わない日は無視
              // 文字列→数値に寄せて比較（"2199.5"などを想定）
              $w = (float)$webMap[$d];
              $b = (float)$dbClose;
              if ($w !== $b) { // 完全一致（仕様どおり）
                $needFullReplace = true;
                break;
              }
            }
          }

          if ($needFullReplace) {
            // 全件洗替：当該銘柄を削除→full再取得→DB更新候補へ
            // 全件洗替：まずfull再取得（0件なら削除しない）
            $fullRows = scrapeKabutan_Mode2_Full($code);
            assertNoDuplicateDates($fullRows);
            
            if (count($fullRows) === 0) {
              throw new RuntimeException("SCRAPE_ERROR: 全件洗替のfull取得が0件（削除中止）");
            }

            // DELETE前に接続確認
           ensurePdoAlive($pdo);

           // ここで初めて削除
           deletePricesByCodeWithReconnect($pdo, $code);

           $maxYmd = maxAsofDate($fullRows);

           // upsert前にも確認
           ensurePdoAlive($pdo);

           // ★ 銘柄ごとにDB upsert（commit）
           $upsertTargetTotal += count($fullRows);

           if (count($fullRows) > 0) {
             $upsertedTotal += bulkUpsertPricesEodWithReconnect($pdo, $fullRows);
           }

            $countReplace++;
            $statusRows[] = [$code, $todaySlash, "全件洗替：" . count($fullRows) . "件", $maxYmd ?? ''];
            continue; // ← 差分処理はせず次の銘柄へ
          }

          
          $rows = [];
          foreach ($rowsAll as $r) {
            if ($r['asof_date'] > $latest && $r['asof_date'] <= $todayISO) $rows[] = $r;
          }
          assertNoDuplicateDates($rows);

          $maxYmd = maxAsofDate($rows);
          // ★ 銘柄ごとにDB upsert（commit）
          $upsertTargetTotal += count($rows);
          if (count($rows) > 0) {
            $upsertedTotal += bulkUpsertPricesEodWithReconnect($pdo, $rows);
          }
 

          $countDiff++;
          $statusRows[] = [$code, $todaySlash, "差分取得＆DB更新：" . count($rows) . "件", $maxYmd ?? $latest];

        } elseif ($cmp === 0) {
          // same day
          $countSkip++;
          $statusRows[] = [$code, $todaySlash, "処理なし", $latest];

        } else {
          // latest > today
          $countErr++;
          $statusRows[] = [$code, $todaySlash, "エラー：DBに未来日が保存されている", $latest];
        }
      }

    } catch (Throwable $e) {
      $countErr++;
      $statusRows[] = [$code, $todaySlash, "エラー：株探読み込みに失敗", $latest ?? ''];
      fwrite(STDERR, "[ERR] code={$code} " . $e->getMessage() . "\n");
    }
  }

  // 5) CSV/TXT 出力
  writeStatusCsv($csvPath, $statusRows);

  $subjectLine = "{$JOB_NAME}：{$todaySlash}";
  $body =
    "処理を終了しました。\n\n" .
    "銘柄数: " . count($codes) . " 件\n" .
    "全件取得: {$countFull} 件\n" .
    "全件洗替: {$countReplace} 件\n" .
    "差分取得: {$countDiff} 件\n" .
    "処理なし: {$countSkip} 件\n" .
    "エラー  : {$countErr} 件\n\n" .
    "DB upsert 対象行数（重複排除前）: {$upsertTargetTotal} 行\n" .
    "DB upsert 実行行数（chunk投入合計）: {$upsertedTotal} 行\n";

  // 407等でエラーになったプロキシ一覧（scraping_common.php が収集）
  $badProxies = function_exists('get_bad_proxies') ? get_bad_proxies() : [];
  if (count($badProxies) > 0) {
    $body .= "\n" .
      "エラーになったプロキシ（host:port）:\n" .
      implode("\n", array_map(function($hp){ return "- " . $hp; }, $badProxies)) .
      "\n";
  }

  write_message_txt($txtPath, $subjectLine, $body);

  echo "ローカル出力完了:\n- {$csvPath}\n- {$txtPath}\n";

  // 6) Driveへアップロード → ローカル削除
  upload_outputs_and_cleanup($JOB_NAME, $todayISO, $csvPath, $txtPath);

  echo "DONE.\n";
  exit(0);

} catch (Throwable $e) {
  fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
  exit(1);
}

// =============================
// DB / PDO
// =============================
function buildPdo(): PDO {
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
  return new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}
/**
 * PDO再接続
 */
function reconnectPdo(PDO &$pdo): void {
  $pdo = buildPdo();
}

/**
 * PDO生存確認
 * - server has gone away
 * - lost connection
 * を検知したら自動再接続
 */
function ensurePdoAlive(PDO &$pdo): void {
  try {
    $pdo->query('SELECT 1');
  } catch (Throwable $e) {

    $msg = $e->getMessage();

    $isReconnect =
      stripos($msg, 'server has gone away') !== false ||
      stripos($msg, 'Lost connection') !== false;

    if ($isReconnect) {

      fwrite(STDERR,
        "[DB] reconnect PDO: {$msg}\n"
      );

      reconnectPdo($pdo);
      return;
    }

    throw $e;
  }
}

function fetchLatestMapFromDb(PDO $pdo): array {
  $sql = "SELECT code, MAX(asof_date) AS latest_asof_date
          FROM prices_eod
          GROUP BY code";
  $rows = $pdo->query($sql)->fetchAll();
  $map = [];
  foreach ($rows as $r) {
    $code = trim((string)($r['code'] ?? ''));
    $d    = trim((string)($r['latest_asof_date'] ?? ''));
    if ($code !== '' && $d !== '') $map[$code] = $d;
  }
  return $map;
}

/**
 * 直近N日分の終値を取得（asof_date降順）
 * @return array<string, float|string> 例: ['2026-01-27' => 2251, ...]
 */
function fetchLastNCloseFromDb(PDO $pdo, string $code, int $n): array {
  $sql = "SELECT asof_date, close
          FROM prices_eod
          WHERE code = :code
          ORDER BY asof_date DESC
          LIMIT {$n}";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':code' => $code]);
  $rows = $stmt->fetchAll();
  $map = [];
  foreach ($rows as $r) {
    $d = (string)($r['asof_date'] ?? '');
    if ($d === '') continue;
    $map[$d] = $r['close'] ?? null;
  }
  return $map;
}

/**
 * 当該銘柄の全日足を削除（全件洗替用）
 */
function deletePricesByCode(PDO $pdo, string $code): void {
  $sql = "DELETE FROM prices_eod WHERE code = :code";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':code' => $code]);
}
/**
 * prices_eod_upsert.php のロジックを移植
 * - (code, asof_date) 重複は last-wins
 * - バリデーション（最低限）
 * - chunk insert ... on duplicate key update
 */
function bulkUpsertPricesEod(PDO $pdo, array $items): int {
  // validate + last-wins map
  $validMap = [];
  foreach ($items as $idx => $row) {
    if (!is_array($row)) continue;

    $asof = $row['asof_date'] ?? null;
    $code = $row['code'] ?? null;
    if (!is_string($asof) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asof) || strtotime($asof) === false) continue;
    if ($code === null || !preg_match('/^[0-9A-Za-z_\-]{1,8}$/', (string)$code)) continue;

    $norm = [
      'asof_date' => $asof,
      'code'      => (string)$code,
      'open'      => toNullableFloat($row['open']  ?? null),
      'high'      => toNullableFloat($row['high']  ?? null),
      'low'       => toNullableFloat($row['low']   ?? null),
      'close'     => toNullableFloat($row['close'] ?? null),
      'volume'    => toNullableInt($row['volume']  ?? null),
    ];
    $key = $norm['code'] . '|' . $norm['asof_date'];
    $validMap[$key] = $norm; // last-wins
  }

  $validItems = array_values($validMap);
  $validCount = count($validItems);
  if ($validCount === 0) return 0;

  $pdo->beginTransaction();
  try {
    $upserted = 0;
    for ($offset = 0; $offset < $validCount; $offset += SQL_CHUNK_ROWS) {
      $chunk = array_slice($validItems, $offset, SQL_CHUNK_ROWS);
      $n = count($chunk);
      if ($n === 0) break;

      $sql = buildChunkSql($n);
      $stmt = $pdo->prepare($sql);

      $params = [];
      for ($i = 0; $i < $n; $i++) {
        $r = $chunk[$i];
        $params[":asof_date{$i}"] = $r['asof_date'];
        $params[":code{$i}"]      = $r['code'];
        $params[":open{$i}"]      = $r['open'];
        $params[":high{$i}"]      = $r['high'];
        $params[":low{$i}"]       = $r['low'];
        $params[":close{$i}"]     = $r['close'];
        $params[":volume{$i}"]    = $r['volume'];
      }

      $stmt->execute($params);
      $upserted += $n;
    }

    $pdo->commit();
    return $upserted;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw new RuntimeException("Upsert failed: " . $e->getMessage(), 0, $e);
  }
}
/**
 * upsert実行
 * - 2006(MySQL server has gone away)
 * - Lost connection
 * の時だけ1回再接続リトライ
 */
function bulkUpsertPricesEodWithReconnect(PDO &$pdo, array $rows): int {

  try {

    ensurePdoAlive($pdo);

    return bulkUpsertPricesEod($pdo, $rows);

  } catch (Throwable $e) {

    $msg = $e->getMessage();

    $isReconnect =
      stripos($msg, 'server has gone away') !== false ||
      stripos($msg, 'Lost connection') !== false;

    if (!$isReconnect) {
      throw $e;
    }

    fwrite(STDERR,
      "[DB] reconnect and retry upsert: {$msg}\n"
    );

    reconnectPdo($pdo);

    ensurePdoAlive($pdo);

    return bulkUpsertPricesEod($pdo, $rows);
  }
}

function buildChunkSql(int $n): string {
  $values = [];
  for ($i = 0; $i < $n; $i++) {
    $values[] = "(:asof_date{$i}, :code{$i}, :open{$i}, :high{$i}, :low{$i}, :close{$i}, :volume{$i})";
  }
  $valuesSql = implode(",\n", $values);

  return
"INSERT INTO prices_eod
(asof_date, code, open, high, low, close, volume)
VALUES
{$valuesSql}
ON DUPLICATE KEY UPDATE
open   = VALUES(open),
high   = VALUES(high),
low    = VALUES(low),
close  = VALUES(close),
volume = VALUES(volume)";
}

function toNullableFloat($v): ?float {
  if ($v === null || $v === '') return null;
  if (!is_numeric($v)) return null;
  return (float)$v;
}
function toNullableInt($v): ?int {
  if ($v === null || $v === '') return null;
  if (!is_numeric($v)) return null;
  return (int)$v;
}

// =============================
// CSV出力（当日シート相当）
// =============================
function writeStatusCsv(string $csvPath, array $rows): void {
  $fp = fopen($csvPath, 'wb');
  if (!$fp) throw new RuntimeException("CSV作成に失敗: {$csvPath}");

  // Excel向けにBOM
  fwrite($fp, "\xEF\xBB\xBF");

  // 見出し（GAS仕様に合わせた4列）
  fputcsv($fp, ['証券コード','更新日','実行結果','DB最新日付']);

  foreach ($rows as $r) {
    fputcsv($fp, [
      $r[0] ?? '',
      $r[1] ?? '',
      $r[2] ?? '',
      $r[3] ?? '',
    ]);
  }
  fclose($fp);
}

// =============================
// スクレイピング（GASロジック移植）
// =============================
function scrapeKabutan_Mode1_Page1(string $code): array {
  $url = 'https://kabutan.jp/stock/kabuka?code=' . rawurlencode($code) . '&ashi=day';
  echo "[HTTP] page=1 {$code} {$url}\n";
  $html = http_get_text($url);

  $rows = [];
  $todayRow = parseTable_stock_kabuka0($html, $code);
  $dwmRows  = parseTable_stock_kabuka_dwm($html, $code);

  if ($todayRow !== null) $rows[] = $todayRow;
  foreach ($dwmRows as $r) $rows[] = $r;
  
  // ★ Kabutanアクセス後は必ずランダムスリープ
  $us = random_int(SLEEP_US_AFTER_HTTP_MIN, SLEEP_US_AFTER_HTTP_MAX);
  if ((count($rows) % 100) === 0) echo "[INFO] sleep_us={$us}\n";
  usleep($us);

  return $rows;
}

function scrapeKabutan_Mode2_Full(string $code): array {
  $out = [];
  $page = 1;

  while (count($out) < FULL_FETCH_LIMIT) {
    $url = ($page === 1)
      ? 'https://kabutan.jp/stock/kabuka?code=' . rawurlencode($code) . '&ashi=day'
      : 'https://kabutan.jp/stock/kabuka?code=' . rawurlencode($code) . '&ashi=day&page=' . $page;

    echo "[HTTP] page={$page} {$code} {$url}\n";
    $html = http_get_text($url);

    if ($page === 1) {
      $todayRow = parseTable_stock_kabuka0($html, $code);
      $dwmRows  = parseTable_stock_kabuka_dwm($html, $code);
      if ($todayRow !== null) $out[] = $todayRow;
      foreach ($dwmRows as $r) $out[] = $r;
    } else {
      $dwmRows = parseTable_stock_kabuka_dwm($html, $code);
      foreach ($dwmRows as $r) $out[] = $r;
      if (count($dwmRows) === 0) break;
    }

    if (count($out) >= FULL_FETCH_LIMIT) break;
    
    $page++;
	$us = random_int(SLEEP_US_AFTER_HTTP_MIN, SLEEP_US_AFTER_HTTP_MAX);
	if (($page % 10) === 0) echo "[INFO] page={$page} sleep_us={$us}\n";
	usleep($us);
	
    if ($page > 50) break;
  }

  return array_slice($out, 0, FULL_FETCH_LIMIT);
}

function parseTable_stock_kabuka0(string $html, string $code): ?array {
  if (!preg_match('/<table[^>]*class="stock_kabuka0[^"]*"[^>]*>[\s\S]*?<\/table>/i', $html, $m)) return null;
  $table = $m[0];
  if (!preg_match('/<tbody[^>]*>([\s\S]*?)<\/tbody>/i', $table, $m2)) return null;
  $tbody = $m2[1];
  if (!preg_match('/<tr[^>]*>([\s\S]*?)<\/tr>/i', $tbody, $m3)) return null;
  $tr = $m3[1];

  $ymd = null;
  if (preg_match('/<time[^>]*datetime="([^"]+)"/i', $tr, $t)) {
    $ymd = normalizeYMD($t[1]);
  } else if (preg_match('/<th[^>]*>([\s\S]*?)<\/th>/i', $tr, $th)) {
    $ymd = normalizeYMD(stripHtml($th[1]));
  }
  if ($ymd === null) return null;

  preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/i', $tr, $tds);
  $cells = array_map('stripHtml', $tds[1] ?? []);
  if (count($cells) < 7) return null;

  $open   = decomma($cells[0]);
  $high   = decomma($cells[1]);
  $low    = decomma($cells[2]);
  $close  = decomma($cells[3]);
  $volume = decomma($cells[6]);

  return ['asof_date'=>$ymd,'code'=>$code,'open'=>$open,'high'=>$high,'low'=>$low,'close'=>$close,'volume'=>$volume];
}

function parseTable_stock_kabuka_dwm(string $html, string $code): array {
  if (!preg_match('/<table[^>]*class="stock_kabuka_dwm[^"]*"[^>]*>[\s\S]*?<\/table>/i', $html, $m)) return [];
  $table = $m[0];
  if (!preg_match('/<tbody[^>]*>([\s\S]*?)<\/tbody>/i', $table, $m2)) return [];
  $tbody = $m2[1];

  preg_match_all('/<tr[^>]*>([\s\S]*?)<\/tr>/i', $tbody, $trs);
  $out = [];

  foreach (($trs[1] ?? []) as $tr) {
    $ymd = null;
    if (preg_match('/<time[^>]*datetime="([^"]+)"/i', $tr, $t)) {
      $ymd = normalizeYMD($t[1]);
    } else if (preg_match('/<th[^>]*>([\s\S]*?)<\/th>/i', $tr, $th)) {
      $ymd = normalizeYMD(stripHtml($th[1]));
    }
    if ($ymd === null) continue;

    preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/i', $tr, $tds);
    $cells = array_map('stripHtml', $tds[1] ?? []);
    if (count($cells) < 7) continue;

    $open   = decomma($cells[0]);
    $high   = decomma($cells[1]);
    $low    = decomma($cells[2]);
    $close  = decomma($cells[3]);
    $volume = decomma($cells[6]);

    $out[] = ['asof_date'=>$ymd,'code'=>$code,'open'=>$open,'high'=>$high,'low'=>$low,'close'=>$close,'volume'=>$volume];
  }

  return $out;
}

function stripHtml(string $s): string {
  $s = preg_replace('/<[^>]+>/', '', $s);
  $s = str_replace("\xC2\xA0", ' ', $s); // nbsp
  return trim($s);
}

function decomma(string $s): string {
  // 全角数字→半角、カンマ除去
  $s = preg_replace_callback('/[０-９]/u', function($m){
    $c = $m[0];
    $code = mb_ord($c, 'UTF-8') - 0xFF10 + 0x30;
    return chr($code);
  }, $s);
  $s = str_replace(',', '', $s);
  return trim($s);
}

function normalizeYMD(string $s): ?string {
  $s = trim($s);
  if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
  }
  if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $s, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
  }
  if (preg_match('/^(\d{2})\/(\d{1,2})\/(\d{1,2})$/', $s, $m)) {
    return sprintf('20%02d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
  }
  // kabutanのtime datetimeはISOで来るので strtotime も許可
  $t = strtotime($s);
  if ($t !== false) return date('Y-m-d', $t);
  return null;
}

function assertNoDuplicateDates(array $rows): void {
  $seen = [];
  foreach ($rows as $r) {
    $d = $r['asof_date'] ?? '';
    if ($d === '') continue;
    if (isset($seen[$d])) throw new RuntimeException("SCRAPE_ERROR: 同一日付のレコードが重複しています: {$d}");
    $seen[$d] = true;
  }
}

function maxAsofDate(array $rows): ?string {
  $max = null;
  foreach ($rows as $r) {
    $d = (string)($r['asof_date'] ?? '');
    if ($d === '') continue;
    if ($max === null || $d > $max) $max = $d;
  }
  return $max;
}

// =============================
// マスタ（Google Sheets）からコード取得
// =============================
function loadCodesFromMasterSheet(): array {
  require_once __DIR__ . '/vendor/autoload.php';

  // Drive上：投資/プログラミング/GAS/マスタ/証券コードマスタ
  $pathFolders = ['投資','プログラミング','GAS','マスタ'];
  $fileName = '証券コードマスタ';
  $headerName = '証券コード';

  $client = build_oauth_client_(); // scraping_common.php の共通OAuth（対話式再認可あり）
  $drive  = new Google\Service\Drive($client);
  $sheets = new Google\Service\Sheets($client);

  $folderId = resolveFolderIdByPath($drive, $pathFolders);
  $fileId = findSpreadsheetFileIdByName($drive, $folderId, $fileName);
  if ($fileId === null) throw new RuntimeException("マスタスプレッドシートが見つかりません: {$fileName}");

  // 1枚目のシート名を取得
  $ss = $sheets->spreadsheets->get($fileId);
  $sheet0 = $ss->getSheets()[0] ?? null;
  if ($sheet0 === null) throw new RuntimeException("マスタのシート取得に失敗");
  $title = $sheet0->getProperties()->getTitle();

  // 全範囲を値取得（必要十分に広め）
  $range = $title . '!A:Z';
  $resp = $sheets->spreadsheets_values->get($fileId, $range);
  $values = $resp->getValues() ?? [];
  if (count($values) < 2) return [];

  $header = array_map(function($v){ return trim((string)$v); }, $values[0]);
  $idx = array_search($headerName, $header, true);
  if ($idx === false) throw new RuntimeException("マスタに「{$headerName}」列が見つかりません");

  $out = [];
  for ($i = 1; $i < count($values); $i++) {
    $row = $values[$i];
    $code = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
    
    // マスタは「空行が出たら終了」の前提
    if ($code === '') break;
    $out[] = $code;
    
  }
  return $out;
}

function resolveFolderIdByPath(Google\Service\Drive $drive, array $folders): string {
  $parent = 'root';
  foreach ($folders as $name) {
    $name = (string)$name;
    if ($name === '') continue;

    $q = sprintf(
      "name = '%s' and '%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder'",
      str_replace("'", "\\'", $name),
      $parent
    );

    $res = $drive->files->listFiles([
      'q' => $q,
      'fields' => 'files(id,name)',
      'pageSize' => 10,
    ]);

    $files = $res->getFiles();
    if (!$files || count($files) === 0) {
      throw new RuntimeException("Folder not found: " . implode('/', $folders));
    }
    $parent = $files[0]->getId();
  }
  return $parent;
}

function findSpreadsheetFileIdByName(Google\Service\Drive $drive, string $folderId, string $fileName): ?string {
  $q = sprintf(
    "name = '%s' and '%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.spreadsheet'",
    str_replace("'", "\\'", $fileName),
    $folderId
  );
  $res = $drive->files->listFiles([
    'q' => $q,
    'fields' => 'files(id,name)',
    'pageSize' => 10,
  ]);
  $files = $res->getFiles();
  if (!$files || count($files) === 0) return null;
  return $files[0]->getId();
}

function deletePricesByCodeWithReconnect(PDO &$pdo, string $code): void {

  try {

    ensurePdoAlive($pdo);

    deletePricesByCode($pdo, $code);
    return;

  } catch (Throwable $e) {

    $msg = $e->getMessage();

    $isReconnect =
      stripos($msg, 'server has gone away') !== false ||
      stripos($msg, 'Lost connection') !== false;

    if (!$isReconnect) {
      throw $e;
    }

    fwrite(STDERR,
      "[DB] reconnect and retry delete: {$msg}\n"
    );

    reconnectPdo($pdo);

    ensurePdoAlive($pdo);

    deletePricesByCode($pdo, $code);
  }
}