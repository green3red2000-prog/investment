<?php
declare(strict_types=1);

/**
 * CSV -> MariaDB prices_eod 一括投入（UPSERT）
 * - CSV: /var/www/html/api/bq-results-20260104-000622-1767485218899.csv
 * - 1行目は見出し
 * - 空欄はNULL
 * - PK(code, asof_date) で ON DUPLICATE KEY UPDATE
 *
 * 実行例（推奨：CLI）:
 *   php /var/www/html/api/import_csv_to_prices_eod.php
 *
 * Webで実行する場合:
 *   http://host/api/import_csv_to_prices_eod.php?token=xxxxx
 */

header('Content-Type: text/plain; charset=utf-8');

/* ====== 設定 ====== */
const API_TOKEN = 'x2K3a9nSZZhvnTR9JH3chRqdhjrzULK7SHuWrQwiaHd2T3vyS4BCH38pau8TtM8sub3FyWZ2FYHhzEFEsB8J6cc4ZYSFtvdHHYRm2VVBWqDWZZBWQW9NAUaX'; // ★必要なら設定（Web実行時）
const CSV_PATH  = '/var/www/html/api/bq-results-20260104-000622-1767485218899.csv';

const DB_HOST = '127.0.0.1';
const DB_NAME = 'stocks';
const DB_USER = 'apiuser';
const DB_PASS = 'G&TgY7Ubq5weU365a6HgxGCshU&%75MKMun8m9kMAr3S&a';  // ★置き換え

// パフォーマンス調整
const COMMIT_EVERY = 1000;      // 1000行ごとにcommit
const PRINT_EVERY  = 10000;     // 1万行ごとに進捗表示
const STOP_ON_ERROR = true;     // true: エラーで停止 / false: エラー行だけスキップ
/* ================= */

/* ====== Web実行ならトークン必須（CLIはスルー） ====== */
if (php_sapi_name() !== 'cli') {
  $token = $_GET['token'] ?? $_POST['token'] ?? '';
  if (!$token || !hash_equals(API_TOKEN, $token)) {
    http_response_code(401);
    echo "Invalid token\n";
    exit;
  }
}

function now(): float { return microtime(true); }

function asNull($s) {
  if ($s === null) return null;
  $s = trim((string)$s);
  return ($s === '') ? null : $s;
}

function asFloatOrNull($s): ?float {
  $v = asNull($s);
  if ($v === null) return null;
  // BigQuery exportは数値が素直に入る想定。念のためカンマ除去。
  $v = str_replace(',', '', $v);
  return is_numeric($v) ? (float)$v : null;
}

function asIntOrNull($s): ?int {
  $v = asNull($s);
  if ($v === null) return null;
  $v = str_replace(',', '', $v);
  if (!is_numeric($v)) return null;
  return (int)floor((float)$v);
}

function validateDate(?string $d): bool {
  if ($d === null) return false;
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

/* ====== CSV open ====== */
if (!is_readable(CSV_PATH)) {
  http_response_code(500);
  echo "CSV not readable: " . CSV_PATH . "\n";
  exit;
}

$fp = fopen(CSV_PATH, 'rb');
if (!$fp) {
  http_response_code(500);
  echo "Failed to open CSV: " . CSV_PATH . "\n";
  exit;
}

// 大きめバッファ（環境により効果）
stream_set_read_buffer($fp, 1024 * 1024);

/* ====== DB connect ====== */
try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB connection failed: " . $e->getMessage() . "\n";
  exit;
}

// 高速化（安全に許容できるなら）
// $pdo->exec("SET SESSION sql_log_bin=0"); // レプリケーションがある環境では注意
//$pdo->exec("SET SESSION innodb_flush_log_at_trx_commit=2"); // ★速度優先（電源断で最後の数秒消える可能性）
//$pdo->exec("SET SESSION sync_binlog=0");

/* ====== prepared statement ====== */
$sql = <<<SQL
INSERT INTO prices_eod
(asof_date, code, open, high, low, close, volume)
VALUES
(:asof_date, :code, :open, :high, :low, :close, :volume)
ON DUPLICATE KEY UPDATE
open   = VALUES(open),
high   = VALUES(high),
low    = VALUES(low),
close  = VALUES(close),
volume = VALUES(volume)
SQL;

$stmt = $pdo->prepare($sql);

/* ====== read header ====== */
$lineNo = 0;
$header = fgetcsv($fp);
$lineNo++;

if ($header === false) {
  echo "Empty CSV\n";
  exit;
}

// 期待ヘッダ確認（厳密にやると事故が減る）
$expected = ['asof_date','code','open','high','low','close','volume'];
$h = array_map(fn($x) => trim((string)$x), $header);
if ($h !== $expected) {
  echo "Header mismatch.\n";
  echo "Expected: " . implode(',', $expected) . "\n";
  echo "Actual  : " . implode(',', $h) . "\n";
  echo "Stop.\n";
  exit;
}

echo "[START] import CSV -> prices_eod\n";
echo "[CSV] " . CSV_PATH . "\n";
echo "[DB]  " . DB_HOST . ":" . "3306" . "/" . DB_NAME . " user=" . DB_USER . "\n";
echo "[CONF] commitEvery=" . COMMIT_EVERY . " stopOnError=" . (STOP_ON_ERROR ? "true":"false") . "\n";

$t0 = now();
$pdo->beginTransaction();

$processed = 0;
$insertedOrUpdated = 0; // affected_rows相当はPDOだと厳密でないのでexecute成功数で扱う
$skipped = 0;
$errors = 0;

$sinceCommit = 0;
$lastPrintAt = now();

try {
  while (($row = fgetcsv($fp)) !== false) {
    $lineNo++;

    // 空行スキップ
    if (count($row) === 1 && trim((string)$row[0]) === '') continue;

    // 7列想定
    if (count($row) < 7) {
      $errors++;
      $skipped++;
      $msg = "[ERR] line={$lineNo} colCount=" . count($row) . " row=" . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
      echo $msg;
      if (STOP_ON_ERROR) throw new RuntimeException("CSV column count error at line {$lineNo}");
      continue;
    }

    [$asof_date, $code, $open, $high, $low, $close, $volume] = $row;

    $asof_date = asNull($asof_date);
    $code      = asNull($code);

    // validate
    if (!validateDate($asof_date) || $code === null) {
      $skipped++;
      continue;
    }

    // 数値変換（空欄→null）
    $openF  = asFloatOrNull($open);
    $highF  = asFloatOrNull($high);
    $lowF   = asFloatOrNull($low);
    $closeF = asFloatOrNull($close);
    $volI   = asIntOrNull($volume);

    try {
      $stmt->execute([
        ':asof_date' => $asof_date,
        ':code'      => (string)$code,
        ':open'      => $openF,
        ':high'      => $highF,
        ':low'       => $lowF,
        ':close'     => $closeF,
        ':volume'    => $volI,
      ]);
      $insertedOrUpdated++;
    } catch (Throwable $e) {
      $errors++;
      $msg = "[ERR] line={$lineNo} code={$code} date={$asof_date} msg=" . $e->getMessage() . "\n";
      echo $msg;
      if (STOP_ON_ERROR) throw $e;
    }

    $processed++;
    $sinceCommit++;

    if ($sinceCommit >= COMMIT_EVERY) {
      $pdo->commit();
      $pdo->beginTransaction();
      $sinceCommit = 0;

      $now = now();
      if ($processed % PRINT_EVERY === 0 || ($now - $lastPrintAt) > 5) {
        $elapsed = $now - $t0;
        $rps = $elapsed > 0 ? ($processed / $elapsed) : 0;
        echo "[PROGRESS] processed={$processed} ok={$insertedOrUpdated} skipped={$skipped} errors={$errors} rps=" . number_format($rps, 1) . "\n";
        $lastPrintAt = $now;
      }
    }
  }

  // 残commit
  $pdo->commit();

  $t1 = now();
  $elapsed = $t1 - $t0;
  $rps = $elapsed > 0 ? ($processed / $elapsed) : 0;

  echo "[DONE] processed={$processed} ok={$insertedOrUpdated} skipped={$skipped} errors={$errors} elapsedSec=" . number_format($elapsed, 1) . " rps=" . number_format($rps, 1) . "\n";

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo "[FATAL] " . $e->getMessage() . "\n";
  exit(1);
} finally {
  fclose($fp);
}
