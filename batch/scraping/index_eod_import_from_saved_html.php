<?php
declare(strict_types=1);

/**
 * 指数日足取得（保存済みKabutan HTML読込 → DB upsert → CSV/TXT出力 → Driveアップロード）
 *
 * - PHP 7.4.30 (cli)
 * - lib/scraping_common.php を利用
 *   - build_output_paths(): /opt/invest/scraping/tmp にCSV/TXTパス作成
 *   - write_message_txt(): TXT出力
 *   - upload_outputs_and_cleanup(): Driveへアップロード（CSVはGoogle Sheets化）し、成功後ローカル削除
 *
 * - WebアクセスによるKabutan取得は行わない
 * - 別処理で保存済みのHTMLを /opt/invest/scraping/data/YYYYMMDD から読み込む
 * - Webアクセス間のウェイト処理は廃止
 * - 異常終了時は exit(1)、正常終了時のみ exit(0)
 *
 * 引数仕様:
 *
 * 通常実行:
 *   php index_eod_import_from_saved_html.php
 *
 *   - 当日の日付 YYYYMMDD ディレクトリを読む。
 *   - 例: /opt/invest/scraping/data/20260701
 *   - 対象ファイル:
 *       07_index_0000_market_price.html
 *       07_index_0105_market_price.html
 *       など
 *
 * 日付指定実行:
 *   php index_eod_import_from_saved_html.php --target_date=2026-06-30
 *
 *   - 指定日のディレクトリを読む。
 *   - 例: /opt/invest/scraping/data/20260630
 *
 * 単一コード全件洗替リカバリ:
 *   php index_eod_import_from_saved_html.php --target_date=2026-06-30 --code_all=0105
 *
 *   - --code_all を指定した場合は、必ず --target_date が必要。
 *   - 指定コードだけをDBからDELETEして、保存済み複数ページHTMLから全件入れ直す。
 *   - 対象ファイル:
 *       07_index_0105_market_price_p001.html
 *       07_index_0105_market_price_p002.html
 *       07_index_0105_market_price_p003.html
 *       ...
 *
 * 単一コード全件洗替リカバリ（本日行を除外）:
 *   php index_eod_import_from_saved_html.php --target_date=2026-06-30 --code_all=0001 --no_today
 *
 *   - --no_today は --code_all 指定時のみ有効。
 *   - p001 の stock_kabuka0 テーブルにある本日行を取り込まない。
 *   - p001/p002... の過去日足テーブル stock_kabuka_dwm は通常どおり取り込む。
 *
 * 注意:
 *   - --code_all 指定時は通常の差分判定・直近5日終値チェックは行わない。
 *   - 指定コードの既存DBデータを削除してから、複数ページHTMLの内容で洗替する。
 *   - p001, p002... が1件も見つからない場合は異常終了 exit(1)。
 *   - --no_today は --code_all 指定時のみ有効。通常実行・日付指定実行では無視される。
 *
 */

require __DIR__ . '/lib/scraping_common.php';

date_default_timezone_set('Asia/Tokyo');

// =============================
// 設定
// =============================
$JOB_NAME = '指数日足取得';

// 保存済みHTMLの親ディレクトリ
// 実際の読込先: /opt/invest/scraping/data/YYYYMMDD
const SAVED_HTML_BASE_DIR = '/opt/invest/scraping/data';


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

function parseCliOptions(array $argv, DateTime $now): array {
  $targetDateYmd = null;
  $targetDateSpecified = false;
  $codeAll = null;
  $noToday = false;

  foreach ($argv as $arg) {
    $arg = (string)$arg;

    if (preg_match('/^--target_date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
      $targetDateYmd = $m[1];
      $targetDateSpecified = true;
      continue;
    }

    if (preg_match('/^--code_all=([0-9A-Za-z_\-]{1,8})$/', $arg, $m)) {
      $codeAll = $m[1];
      continue;
    }
    
    if ($arg === '--no_today') {
      $noToday = true;
      continue;
    }
  }

  if ($codeAll !== null && !$targetDateSpecified) {
    throw new RuntimeException("--code_all を指定する場合は --target_date=YYYY-MM-DD が必須です。");
  }

  if ($targetDateYmd === null) {
    $targetDateISO = $now->format('Y-m-d');
  } else {
    $dt = DateTime::createFromFormat('!Y-m-d', $targetDateYmd);
    if (!$dt || $dt->format('Y-m-d') !== $targetDateYmd) {
      throw new RuntimeException("日付指定が不正です: {$targetDateYmd}");
    }
    $targetDateISO = $dt->format('Y-m-d');
  }

  return [
    'targetDateISO' => $targetDateISO,
    'targetDateSpecified' => $targetDateSpecified,
    'codeAll' => $codeAll,
    'noToday' => $noToday,
  ];
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
// 日付
// =============================
$now = new DateTime('now');

try {
  $cliOptions = parseCliOptions($argv ?? [], $now);
  $targetDateISO = $cliOptions['targetDateISO'];
  $codeAll = $cliOptions['codeAll'];
  $noToday = $cliOptions['noToday'];
} catch (Throwable $e) {
  fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
  exit(1);
}

$targetDateYmd   = str_replace('-', '', $targetDateISO);
$targetDateSlash = str_replace('-', '/', $targetDateISO);
$htmlDir = SAVED_HTML_BASE_DIR . '/' . $targetDateYmd;

if (!is_dir($htmlDir)) {
  fwrite(STDERR, "保存済みHTMLディレクトリが見つかりません: {$htmlDir}\n");
  exit(1);
}

// 出力パス（共通関数）
$paths   = build_output_paths($JOB_NAME, $targetDateISO);
$csvPath = $paths['csv'];
$txtPath = $paths['txt'];

// =============================
// メイン
// =============================
try {
  $pdo = buildPdo();

  // 1) 保存済みHTMLディレクトリから処理対象コードを自動検出
  if ($codeAll !== null) {
    $htmlFiles = discoverSavedKabutanAllPageHtmlFiles($htmlDir, $codeAll);
    $codes = [$codeAll];
  } else {
    $htmlFiles = discoverSavedKabutanHtmlFiles($htmlDir);
    $codes = array_keys($htmlFiles);
  }

  if (count($codes) === 0) {
    throw new RuntimeException("処理対象HTMLファイルが0件でした: {$htmlDir}");
  }
  
  echo "target date: {$targetDateISO}\n";
  echo "html dir: {$htmlDir}\n";
  echo "html files loaded: " . count($htmlFiles) . "\n";
  echo "codes loaded: " . count($codes) . "\n";

  // 2) DB最新日付を一括取得（SELECT code, MAX(asof_date) ... GROUP BY code）
  $latestMap = fetchLatestMapFromDb($pdo);
  echo "db latest map loaded: " . count($latestMap) . "\n";

  // 3) 銘柄ごとに保存済みHTMLを読込（full/diff/skip）
  $statusRows = []; // 出力CSV（当日シート相当）
  $upsertTargetTotal = 0; // DB upsert 対象行数（重複排除前）
  $upsertedTotal     = 0; // DB upsert 実行行数（chunk投入合計）

  $countFull = 0;
  $countReplace = 0;
  $countDiff = 0;
  $countSkip = 0;
  $countErr  = 0;

  foreach ($codes as $idx => $code) {
    
    if ($codeAll !== null) {
      $rows = scrapeKabutan_AllPages($code, $noToday);
      assertNoDuplicateDates($rows);

      if (count($rows) === 0) {
        throw new RuntimeException("SCRAPE_ERROR: 全件洗替用HTMLから日足が0件でした");
      }

      ensurePdoAlive($pdo);
      deletePricesByCodeWithReconnect($pdo, $code);

      $maxYmd = maxAsofDate($rows);

      ensurePdoAlive($pdo);
      $upsertTargetTotal += count($rows);
      $upsertedTotal += bulkUpsertPricesEodWithReconnect($pdo, $rows);

      $countReplace++;
      $statusRows[] = [$code, $targetDateSlash, "リカバリ全件洗替：" . count($rows) . "件", $maxYmd ?? ''];
      continue;
    }
  	
    $code = trim((string)$code);
    if ($code === '') continue;

    $latest = $latestMap[$code] ?? null; // YYYY-MM-DD or null

    try {
      if ($latest === null || $latest === '') {
        // 初回：保存済みHTMLのpage1範囲を取得
        $rows = scrapeKabutan_Mode2_Full($code);
        assertNoDuplicateDates($rows);

        if (count($rows) === 0) {
          throw new RuntimeException("SCRAPE_ERROR: 保存済みHTMLから日足が0件でした");
        }

        $maxYmd = maxAsofDate($rows);
        $upsertTargetTotal += count($rows);
        $upsertedTotal     += bulkUpsertPricesEodWithReconnect($pdo, $rows);

        $countFull++;
        $statusRows[] = [$code, $targetDateSlash, "初回取得＆DB更新：" . count($rows) . "件", $maxYmd ?? ''];

      } else {
        $cmp = strcmp($targetDateISO, $latest);
        if ($cmp > 0) {
          // 差分：保存済みHTMLのpage1範囲からDB最新日付より新しい行だけ取得
          $rowsAll = scrapeKabutan_Mode1_Page1($code);
          if (count($rowsAll) === 0) {
            throw new RuntimeException("SCRAPE_ERROR: 保存済みHTMLから日足が0件でした");
          }

          // ===== 株式分割（調整）チェック：直近5日終値の突合 =====
          // DB: 直近5日 close（asof_date降順）
          ensurePdoAlive($pdo);

          $dbLast5 = fetchLastNCloseFromDb($pdo, $code, 5);
          $needFullReplace = false;
          if (count($dbLast5) > 0) {
            // 保存済みHTML側も、同日付の close を拾って突合
            $webMap = [];
            foreach ($rowsAll as $r) {
              $d = (string)($r['asof_date'] ?? '');
              if ($d === '') continue;
              $webMap[$d] = (string)($r['close'] ?? '');
            }
            foreach ($dbLast5 as $d => $dbClose) {
              if (!array_key_exists($d, $webMap)) continue; // 休場などで揃わない日は無視
              $w = (float)$webMap[$d];
              $b = (float)$dbClose;
              if ($w !== $b) { // 完全一致（仕様どおり）
                $needFullReplace = true;
                break;
              }
            }
          }

          if ($needFullReplace) {
            // 全件洗替：保存済みHTMLのpage1範囲で洗替
            $fullRows = scrapeKabutan_Mode2_Full($code);
            assertNoDuplicateDates($fullRows);

            if (count($fullRows) === 0) {
              throw new RuntimeException("SCRAPE_ERROR: 全件洗替用HTML取得が0件（削除中止）");
            }

            ensurePdoAlive($pdo);
            deletePricesByCodeWithReconnect($pdo, $code);

            $maxYmd = maxAsofDate($fullRows);

            ensurePdoAlive($pdo);
            $upsertTargetTotal += count($fullRows);
            $upsertedTotal += bulkUpsertPricesEodWithReconnect($pdo, $fullRows);

            $countReplace++;
            $statusRows[] = [$code, $targetDateSlash, "全件洗替：" . count($fullRows) . "件", $maxYmd ?? ''];
            continue;
          }

          $rows = [];
          foreach ($rowsAll as $r) {
            if ($r['asof_date'] > $latest && $r['asof_date'] <= $targetDateISO) $rows[] = $r;
          }
          assertNoDuplicateDates($rows);

          $maxYmd = maxAsofDate($rows);
          $upsertTargetTotal += count($rows);
          if (count($rows) > 0) {
            $upsertedTotal += bulkUpsertPricesEodWithReconnect($pdo, $rows);
          }

          $countDiff++;
          $statusRows[] = [$code, $targetDateSlash, "差分取得＆DB更新：" . count($rows) . "件", $maxYmd ?? $latest];

        } elseif ($cmp === 0) {
          $countSkip++;
          $statusRows[] = [$code, $targetDateSlash, "処理なし", $latest];

        } else {
          $countErr++;
          $statusRows[] = [$code, $targetDateSlash, "エラー：DBに未来日が保存されている", $latest];
        }
      }

    } catch (Throwable $e) {
      $countErr++;
      $statusRows[] = [$code, $targetDateSlash, "エラー：保存済みHTML読み込みまたはDB更新に失敗", $latest ?? ''];
      fwrite(STDERR, "[ERR] code={$code} " . $e->getMessage() . "\n");
    }
  }

  // 5) CSV/TXT 出力
  writeStatusCsv($csvPath, $statusRows);

  $subjectLine = "{$JOB_NAME}：{$targetDateSlash}";
  $body =
    "処理を終了しました。\n\n" .
    "対象日: {$targetDateSlash}\n" .
    "HTMLディレクトリ: {$htmlDir}\n" .
    "銘柄数: " . count($codes) . " 件\n" .
    "初回取得: {$countFull} 件\n" .
    "全件洗替: {$countReplace} 件\n" .
    "差分取得: {$countDiff} 件\n" .
    "処理なし: {$countSkip} 件\n" .
    "エラー  : {$countErr} 件\n\n" .
    "DB upsert 対象行数（重複排除前）: {$upsertTargetTotal} 行\n" .
    "DB upsert 実行行数（chunk投入合計）: {$upsertedTotal} 行\n";

  write_message_txt($txtPath, $subjectLine, $body);

  echo "ローカル出力完了:\n- {$csvPath}\n- {$txtPath}\n";

  // 6) Driveへアップロード → ローカル削除
  upload_outputs_and_cleanup($JOB_NAME, $targetDateISO, $csvPath, $txtPath);

  if ($countErr > 0) {
    fwrite(STDERR, "DONE_WITH_ERRORS: {$countErr} 件のエラーがありました。\n");
    exit(1);
  }

  echo "DONE.\n";
  exit(0);

} catch (Throwable $e) {
  fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
  exit(1);
}


// =============================
// 保存済みHTML読込・解析
// =============================
function scrapeKabutan_Mode1_Page1(string $code): array {
  global $htmlFiles;
  
  $html = readSavedKabutanHtml($code);
  echo "[HTML] page=1 {$code} " . ($htmlFiles[$code] ?? "") . "\n";

  $rows = [];
  $todayRow = parseTable_stock_kabuka0($html, $code);
  $dwmRows  = parseTable_stock_kabuka_dwm($html, $code);

  if ($todayRow !== null) $rows[] = $todayRow;
  foreach ($dwmRows as $r) $rows[] = $r;

  return $rows;
}

function scrapeKabutan_Mode2_Full(string $code): array {
  // 現行の保存済みHTMLはpage=1のみなので、full取得も保存済みpage1の範囲で行う。
  return scrapeKabutan_Mode1_Page1($code);
}

function readSavedKabutanHtml(string $code): string {
  global $htmlDir, $htmlFiles;

  if (!isset($htmlFiles[$code])) {
    throw new RuntimeException("保存済みHTML対応表に存在しないコードです: {$code}");
  }

  $filePath = $htmlDir . '/' . $htmlFiles[$code];
  if (!is_file($filePath)) {
    throw new RuntimeException("保存済みHTMLファイルが見つかりません: {$filePath}");
  }

  $html = file_get_contents($filePath);
  if ($html === false || $html === '') {
    throw new RuntimeException("保存済みHTMLファイルの読込に失敗しました: {$filePath}");
  }

  return $html;
}

function discoverSavedKabutanHtmlFiles(string $htmlDir): array {
  if (!is_dir($htmlDir)) {
    throw new RuntimeException("保存済みHTMLディレクトリが見つかりません: {$htmlDir}");
  }

  $files = scandir($htmlDir);
  if ($files === false) {
    throw new RuntimeException("保存済みHTMLディレクトリの読込に失敗しました: {$htmlDir}");
  }

  $map = [];
  foreach ($files as $fileName) {
    if ($fileName === '.' || $fileName === '..') continue;

    $filePath = $htmlDir . '/' . $fileName;
    if (!is_file($filePath)) continue;

    // 例: 07_index_0000_market_price.html → code=0000
    if (!preg_match('/^07_index_([0-9A-Za-z_\\-]{1,8})_market_price\\.html$/', $fileName, $m)) {
      continue;
    }

    $code = $m[1];
    if (isset($map[$code])) {
      throw new RuntimeException("同一コードの保存済みHTMLが複数あります: code={$code}, file1={$map[$code]}, file2={$fileName}");
    }

    $map[$code] = $fileName;
  }

  ksort($map, SORT_STRING);
  return $map;
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
function discoverSavedKabutanAllPageHtmlFiles(string $htmlDir, string $code): array {
  if (!is_dir($htmlDir)) {
    throw new RuntimeException("保存済みHTMLディレクトリが見つかりません: {$htmlDir}");
  }

  $files = scandir($htmlDir);
  if ($files === false) {
    throw new RuntimeException("保存済みHTMLディレクトリの読込に失敗しました: {$htmlDir}");
  }

  $map = [];
  $quotedCode = preg_quote($code, '/');

  foreach ($files as $fileName) {
    if ($fileName === '.' || $fileName === '..') continue;

    $filePath = $htmlDir . '/' . $fileName;
    if (!is_file($filePath)) continue;

    if (!preg_match('/^07_index_' . $quotedCode . '_market_price_p(\d{3})\.html$/', $fileName, $m)) {
      continue;
    }

    $pageNo = (int)$m[1];
    $map[$pageNo] = $fileName;
  }

  if (count($map) === 0) {
    throw new RuntimeException("全件洗替用HTMLが見つかりません: {$htmlDir}/07_index_{$code}_market_price_p001.html ...");
  }

  ksort($map, SORT_NUMERIC);
  return [$code => $map];
}

function scrapeKabutan_AllPages(string $code, bool $noToday = false): array {
  global $htmlDir, $htmlFiles;

  if (!isset($htmlFiles[$code]) || !is_array($htmlFiles[$code])) {
    throw new RuntimeException("全件洗替用HTML対応表に存在しないコードです: {$code}");
  }

  $out = [];

  foreach ($htmlFiles[$code] as $pageNo => $fileName) {
    $filePath = $htmlDir . '/' . $fileName;
    if (!is_file($filePath)) {
      throw new RuntimeException("全件洗替用HTMLファイルが見つかりません: {$filePath}");
    }

    $html = file_get_contents($filePath);
    if ($html === false || $html === '') {
      throw new RuntimeException("全件洗替用HTMLファイルの読込に失敗しました: {$filePath}");
    }

    echo "[HTML] all page={$pageNo} {$code} {$fileName}\n";

    if ((int)$pageNo === 1 && !$noToday) {
      $todayRow = parseTable_stock_kabuka0($html, $code);
      if ($todayRow !== null) $out[] = $todayRow;
    }

    $dwmRows = parseTable_stock_kabuka_dwm($html, $code);
    foreach ($dwmRows as $r) {
      $out[] = $r;
    }
  }

  return $out;
}