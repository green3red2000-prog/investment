<?php
declare(strict_types=1);

/**
 * 全銘柄日足取得（J-Quants日足 → DB upsert → CSV/TXT出力 → Driveアップロード）
 *
 * - PHP 7.4.30 (cli)
 * - デプロイ先: /opt/invest/j_quants
 * - APIキー: /opt/invest/j_quants/conf/config.php の JQUANTS_API_KEY を利用
 * - Driveアップロード: /opt/invest/scraping/lib/scraping_common.php を利用
 * - スプレッドシート:
 *   - 投資/プログラミング/GAS/マスタ/カレンダーマスタ
 *   - 投資/プログラミング/GAS/マスタ/証券コードマスタ
 
 * 実行例:
 *   php zenmeigara_hiashi_get.php
 *   php zenmeigara_hiashi_get.php --recover-prices-all
 */

require __DIR__ . '/conf/config.php';
require __DIR__ . '/lib/j_quants_common.php';
require '/opt/invest/scraping/lib/scraping_common.php';

// Google API クライアント。scraping_common.php の upload_outputs_and_cleanup() と同じ vendor を使う。
require_once '/opt/invest/scraping/vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

// =============================
// 設定
// =============================
const JOB_NAME = '全銘柄日足取得';
const JQUANTS_DAILY_BARS_PATH = '/v2/equities/bars/daily';
const OUTPUT_DIR = '/opt/invest/j_quants/tmp';
const SQL_CHUNK_ROWS = 100;

// DB設定（既存 zenmeigara_hiashi_get.php 踏襲）
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'stocks';
const DB_USER = 'apiuser';
const DB_PASS = 'G&TgY7Ubq5weU365a6HgxGCshU&%75MKMun8m9kMAr3S&a';

// Drive上のマスタ配置
const MASTER_FOLDER_PATH = ['投資','プログラミング','GAS','マスタ'];
const CALENDAR_MASTER_NAME = 'カレンダーマスタ';
const SECURITY_CODE_MASTER_NAME = '証券コードマスタ';

// =============================
// 引数
// =============================
$args = parse_args($argv);
$recoverPricesAll = isset($args['recover-prices-all']);

// =============================
// メイン
// =============================
try {
  $now = new DateTime('now');
  $todayISO = $now->format('Y-m-d');
  $todayYmd = $now->format('Ymd');

  ensureDir(OUTPUT_DIR);
  $csvPath = OUTPUT_DIR . '/' . JOB_NAME . '_' . $todayISO . '.csv';
  $txtPath = OUTPUT_DIR . '/' . JOB_NAME . '_メッセージ_' . $todayISO . '.txt';

  $pdo = buildPdo();

  echo "[INFO] date={$todayISO}\n";
  echo '[INFO] recoverPricesAll=' . ($recoverPricesAll ? 'true' : 'false') . "\n";

  // (1) 営業日カレンダー取得
  $calendarRows = loadCalendarMasterSheet();
  $businessDays = buildRecentBusinessDays($calendarRows, $todayISO, 14);
  if (count($businessDays) === 0) {
    throw new RuntimeException('営業日カレンダーから営業日を取得できませんでした。');
  }
  echo '[INFO] recent business days: ' . implode(',', $businessDays) . "\n";

  // (2) 直近14営業日分の株価情報取得（日付指定）
  $recentBarsByDateCode = [];
  if ($recoverPricesAll) {
    echo "[WARN] --recover-prices-all specified. skip step(2): recent daily bars fetch.\n";
  } else {
    foreach ($businessDays as $d) {
      $ymd = str_replace('-', '', $d);
      echo "[API] daily bars date={$ymd}\n";
      $rows = fetchJQuantsDailyBars(['date' => $ymd]);
      $recentBarsByDateCode[$d] = indexBarsByCode($rows);
      echo "[API] daily bars date={$ymd} count=" . count($rows) . "\n";
    }
  }

  // (3) DBデータ取得状況チェック
  $latestMap = [];
  if ($recoverPricesAll) {
    echo "[WARN] --recover-prices-all specified. skip step(3): latest DB status check.\n";
  } else {
    $latestMap = fetchLatestMapFromDb($pdo);
    echo '[INFO] db latest map loaded: ' . count($latestMap) . "\n";
  }
  // (4) 証券コードマスタ取得
  $masterRows = loadSecurityCodeMasterSheet();
  echo '[INFO] master target codes loaded: ' . count($masterRows) . "\n";

  $statusRows = [];
  $upsertTargetTotal = 0;
  $upsertedTotal = 0;

  $countFull = 0;
  $countReplace = 0;
  $countDiff = 0;
  $countSkip = 0;
  $countErr = 0;

  foreach ($masterRows as $idx => $m) {
    $code4 = $m['code4'];
    $apiCode = $m['api_code'];
    $latest = $latestMap[$code4] ?? null;

    try {
      echo '[INFO] (' . ($idx + 1) . '/' . count($masterRows) . ") code={$code4} apiCode={$apiCode}\n";
      
      if ($recoverPricesAll) {
        $from = tenYearsAgoSameDay($todayISO);
        $rows = fetchRowsForCodeFrom($apiCode, $code4, $from);
        assertNoDuplicateDates($rows);
        if (count($rows) === 0) {
          throw new RuntimeException('JQUANTS_ERROR: 強制リカバリ全件洗替の取得が0件（削除中止）');
        }

        deletePricesByCodeWithReconnect($pdo, $code4);
        $upsertTargetTotal += count($rows);
        $upsertedTotal += bulkUpsertPricesEodWithReconnect($pdo, $rows);

        $countReplace++;
        $statusRows[] = [$code4, $todayISO, '強制リカバリ全件洗替：' . count($rows) . '件', maxAsofDate($rows) ?? ''];
        continue;
      }

      if ($latest === null || $latest === '') {
        $from = tenYearsAgoSameDay($todayISO);
        $rows = fetchRowsForCodeFrom($apiCode, $code4, $from);
        assertNoDuplicateDates($rows);

        $upsertTargetTotal += count($rows);
        $upsertedTotal += bulkUpsertPricesEodWithReconnect($pdo, $rows);

        $countFull++;
        $statusRows[] = [$code4, $todayISO, '全件取得＆DB更新：' . count($rows) . '件', maxAsofDate($rows) ?? ''];
        continue;
      }

      $cmp = strcmp($todayISO, $latest);

      if ($cmp > 0) {
        $needFullReplace = false;

        // データ欠損・分割等チェック：DB直近5日 vs J-Quants直近14営業日の同日データ
        ensurePdoAlive($pdo);
        $dbLast5 = fetchLastNPriceFromDb($pdo, $code4, 5);

        if (count($dbLast5) !== 5) {
          $needFullReplace = true;
        } else {
          foreach ($dbLast5 as $d => $dbRow) {
            $webRow = $recentBarsByDateCode[$d][$code4] ?? null;

            if ($webRow === null) {
              $needFullReplace = true;
              break;
            }

            if (compareNullableNumber($webRow['close'], $dbRow['close']) !== 0) {
              $needFullReplace = true;
              break;
            }

            if (compareNullableNumber($webRow['volume'], $dbRow['volume']) !== 0) {
              $needFullReplace = true;
              break;
            }
          }
        }

        if ($needFullReplace) {
          $from = tenYearsAgoSameDay($todayISO);
          $rows = fetchRowsForCodeFrom($apiCode, $code4, $from);
          assertNoDuplicateDates($rows);
          if (count($rows) === 0) {
            throw new RuntimeException('JQUANTS_ERROR: 全件洗替の取得が0件（削除中止）');
          }

          deletePricesByCodeWithReconnect($pdo, $code4);
          $upsertTargetTotal += count($rows);
          $upsertedTotal += bulkUpsertPricesEodWithReconnect($pdo, $rows);

          $countReplace++;
          $statusRows[] = [$code4, $todayISO, '全件洗替：' . count($rows) . '件', maxAsofDate($rows) ?? ''];
          continue;
        }

        // チェックOK：DBにない日付全部
        $rows = [];

        foreach ($businessDays as $d) {
          if ($d <= $latest || $d > $todayISO) continue;

          $row = $recentBarsByDateCode[$d][$code4] ?? null;
          if ($row !== null) {
            $rows[] = $row;
          }
        }
        assertNoDuplicateDates($rows);

        $upsertTargetTotal += count($rows);
        if (count($rows) > 0) {
          $upsertedTotal += bulkUpsertPricesEodWithReconnect($pdo, $rows);
        }

        $countDiff++;
        $statusRows[] = [$code4, $todayISO, '差分取得＆DB更新：' . count($rows) . '件', maxAsofDate($rows) ?? $latest];

      } elseif ($cmp === 0) {
        $countSkip++;
        $statusRows[] = [$code4, $todayISO, '処理なし', $latest];

      } else {
        $countErr++;
        $statusRows[] = [$code4, $todayISO, 'エラー：DBに未来日が保存されている', $latest];
      }

    } catch (JQuantsApiException $e) {
      $countErr++;
      $statusRows[] = [$code4, $todayISO, 'エラー：J-QuantsAPIの取得に失敗', $latest ?? ''];
      fwrite(STDERR, "[ERR][JQUANTS] code={$code4} " . $e->getMessage() . "\n");
    }
  }

  writeStatusCsv($csvPath, $statusRows);

  $subjectLine = JOB_NAME . '：' . $todayISO;
  $body =
    "処理を終了しました。\n\n" .
    '銘柄数: ' . count($masterRows) . " 件\n" .
    '強制リカバリ全件洗替: ' . ($recoverPricesAll ? 'あり' : 'なし') . "\n" .
    "全件取得: {$countFull} 件\n" .
    "全件洗替: {$countReplace} 件\n" .
    "差分取得: {$countDiff} 件\n" .
    "処理なし: {$countSkip} 件\n" .
    "エラー : {$countErr} 件\n\n" .
    "DB upsert 対象行数（重複排除前）: {$upsertTargetTotal} 行\n" .
    "DB upsert 実行行数（chunk投入合計）: {$upsertedTotal} 行\n";

  write_message_txt($txtPath, $subjectLine, $body);

  echo "[INFO] local output done:\n- {$csvPath}\n- {$txtPath}\n";

  upload_outputs_and_cleanup(JOB_NAME, $todayISO, $csvPath, $txtPath);

  echo "DONE.\n";
  exit(0);

} catch (Throwable $e) {
  fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n");
  exit(1);
}

// =============================
// J-Quants API
// =============================
function fetchRowsForCodeFrom(string $apiCode, string $dbCode4, string $fromISO): array {
  $rows = fetchJQuantsDailyBars([
    'code' => $apiCode,
    'from' => str_replace('-', '', $fromISO),
  ]);

  $out = [];
  foreach ($rows as $row) {
    $norm = normalizeJQuantsBarRow($row, $dbCode4);
    if ($norm !== null) $out[] = $norm;
  }
  return $out;
}

function fetchJQuantsDailyBars(array $params): array {
  return jquantsGetAll(JQUANTS_DAILY_BARS_PATH, $params);
}

function normalizeJQuantsBarRow(array $row, string $dbCode4): ?array {
  $date = normalizeYMD((string)($row['Date'] ?? ''));
  if ($date === null) return null;

  return [
    'asof_date' => $date,
    'code'      => $dbCode4,
    'open'      => toNullableFloat($row['AdjO'] ?? null),
    'high'      => toNullableFloat($row['AdjH'] ?? null),
    'low'       => toNullableFloat($row['AdjL'] ?? null),
    'close'     => toNullableFloat($row['AdjC'] ?? null),
    'volume'    => toNullableInt($row['AdjVo'] ?? null),
  ];
}

function indexBarsByCode(array $rawRows): array {
  $map = [];
  foreach ($rawRows as $row) {
    if (!is_array($row)) continue;
    $code4 = normalizeDbCode4((string)($row['Code'] ?? ''));
    if ($code4 === '') continue;
    $norm = normalizeJQuantsBarRow($row, $code4);
    if ($norm === null) continue;
    $map[$code4] = $norm;
  }
  return $map;
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

function reconnectPdo(PDO &$pdo): void {
  $pdo = buildPdo();
}

function ensurePdoAlive(PDO &$pdo): void {
  try {
    $pdo->query('SELECT 1');
  } catch (Throwable $e) {
    if (isReconnectableDbError($e)) {
      fwrite(STDERR, '[DB] reconnect PDO: ' . $e->getMessage() . "\n");
      reconnectPdo($pdo);
      return;
    }
    throw $e;
  }
}

function isReconnectableDbError(Throwable $e): bool {
  $msg = $e->getMessage();
  return stripos($msg, 'server has gone away') !== false ||
         stripos($msg, 'Lost connection') !== false;
}

function fetchLatestMapFromDb(PDO $pdo): array {
  $sql = "SELECT code, MAX(asof_date) AS latest_asof_date
          FROM prices_eod
          GROUP BY code";
  $rows = $pdo->query($sql)->fetchAll();
  $map = [];
  foreach ($rows as $r) {
    $code = trim((string)($r['code'] ?? ''));
    $d = trim((string)($r['latest_asof_date'] ?? ''));
    if ($code !== '' && $d !== '') $map[$code] = $d;
  }
  return $map;
}

function fetchLastNPriceFromDb(PDO $pdo, string $code, int $n): array {
  $sql = "SELECT asof_date, close, volume
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
    $map[$d] = [
      'close'  => $r['close'] ?? null,
      'volume' => $r['volume'] ?? null,
    ];
  }
  return $map;
}

function deletePricesByCode(PDO $pdo, string $code): void {
  $sql = "DELETE FROM prices_eod WHERE code = :code";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':code' => $code]);
}

function deletePricesByCodeWithReconnect(PDO &$pdo, string $code): void {
  try {
    ensurePdoAlive($pdo);
    deletePricesByCode($pdo, $code);
  } catch (Throwable $e) {
    if (!isReconnectableDbError($e)) throw $e;
    fwrite(STDERR, '[DB] reconnect and retry delete: ' . $e->getMessage() . "\n");
    reconnectPdo($pdo);
    ensurePdoAlive($pdo);
    deletePricesByCode($pdo, $code);
  }
}

function bulkUpsertPricesEodWithReconnect(PDO &$pdo, array $rows): int {
  try {
    ensurePdoAlive($pdo);
    return bulkUpsertPricesEod($pdo, $rows);
  } catch (Throwable $e) {
    if (!isReconnectableDbError($e)) throw $e;
    fwrite(STDERR, '[DB] reconnect and retry upsert: ' . $e->getMessage() . "\n");
    reconnectPdo($pdo);
    ensurePdoAlive($pdo);
    return bulkUpsertPricesEod($pdo, $rows);
  }
}

function bulkUpsertPricesEod(PDO $pdo, array $items): int {
  $validMap = [];
  foreach ($items as $row) {
    if (!is_array($row)) continue;

    $asof = $row['asof_date'] ?? null;
    $code = $row['code'] ?? null;
    if (!is_string($asof) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asof) || strtotime($asof) === false) continue;
    if ($code === null || !preg_match('/^[0-9A-Za-z_\-]{1,8}$/', (string)$code)) continue;

    $norm = [
      'asof_date' => $asof,
      'code'      => (string)$code,
      'open'      => toNullableFloat($row['open'] ?? null),
      'high'      => toNullableFloat($row['high'] ?? null),
      'low'       => toNullableFloat($row['low'] ?? null),
      'close'     => toNullableFloat($row['close'] ?? null),
      'volume'    => toNullableInt($row['volume'] ?? null),
    ];
    $validMap[$norm['code'] . '|' . $norm['asof_date']] = $norm;
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

      $stmt = $pdo->prepare(buildChunkSql($n));
      $params = [];
      for ($i = 0; $i < $n; $i++) {
        $r = $chunk[$i];
        $params[":asof_date{$i}"] = $r['asof_date'];
        $params[":code{$i}"] = $r['code'];
        $params[":open{$i}"] = $r['open'];
        $params[":high{$i}"] = $r['high'];
        $params[":low{$i}"] = $r['low'];
        $params[":close{$i}"] = $r['close'];
        $params[":volume{$i}"] = $r['volume'];
      }
      $stmt->execute($params);
      $upserted += $n;
    }

    $pdo->commit();
    return $upserted;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw new RuntimeException('Upsert failed: ' . $e->getMessage(), 0, $e);
  }
}

function buildChunkSql(int $n): string {
  $values = [];
  for ($i = 0; $i < $n; $i++) {
    $values[] = "(:asof_date{$i}, :code{$i}, :open{$i}, :high{$i}, :low{$i}, :close{$i}, :volume{$i})";
  }
  $valuesSql = implode(",\n", $values);

  return "INSERT INTO prices_eod
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

// =============================
// Google Sheets マスタ読み込み
// =============================
function loadCalendarMasterSheet(): array {
  $values = loadSpreadsheetValues(CALENDAR_MASTER_NAME);
  if (count($values) < 2) return [];

  $header = normalizeHeader($values[0]);
  $dateIdx = requireHeaderIndex($header, '日付', CALENDAR_MASTER_NAME);
  $holIdx = requireHeaderIndex($header, '日本市場休日区分', CALENDAR_MASTER_NAME);

  $out = [];
  for ($i = 1; $i < count($values); $i++) {
    $row = $values[$i];
    $date = normalizeYMD((string)($row[$dateIdx] ?? ''));
    if ($date === null) continue;
    $holDiv = trim((string)($row[$holIdx] ?? ''));
    $out[] = ['date' => $date, 'hol_div' => $holDiv];
  }
  return $out;
}

function loadSecurityCodeMasterSheet(): array {
  $values = loadSpreadsheetValues(SECURITY_CODE_MASTER_NAME);
  if (count($values) < 2) return [];

  $header = normalizeHeader($values[0]);
  $codeIdx = requireHeaderIndex($header, '証券コード', SECURITY_CODE_MASTER_NAME);
  $code5Idx = array_search('証券コード5桁', $header, true);
  $marketIdx = requireHeaderIndex($header, '市場区分コード', SECURITY_CODE_MASTER_NAME);

  $out = [];
  for ($i = 1; $i < count($values); $i++) {
    $row = $values[$i];
    $codeRaw = trim((string)($row[$codeIdx] ?? ''));
    if ($codeRaw === '') break;

    $marketCode = trim((string)($row[$marketIdx] ?? ''));
    if ($marketCode === '-') continue;

    $code4 = normalizeDbCode4($codeRaw);
    if ($code4 === '') continue;

    $code5 = '';
    if ($code5Idx !== false) {
      $code5 = trim((string)($row[$code5Idx] ?? ''));
    }
    $apiCode = $code5 !== ''
      ? strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $code5) ?? '')
      : strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $codeRaw) ?? '');

    if ($apiCode === '') $apiCode = $code4;
    
    $out[] = [
      'code4' => $code4,
      'api_code' => $apiCode,
      'market_code' => $marketCode,
    ];
  }
  return $out;
}

function loadSpreadsheetValues(string $fileName): array {
  $client = build_oauth_client_(); // scraping_common.php の共通OAuth関数を利用
  $drive = new Google\Service\Drive($client);
  $sheets = new Google\Service\Sheets($client);

  $folderId = resolveFolderIdByPath($drive, MASTER_FOLDER_PATH);
  $fileId = findSpreadsheetFileIdByName($drive, $folderId, $fileName);
  if ($fileId === null) {
    throw new RuntimeException('マスタスプレッドシートが見つかりません: ' . $fileName);
  }

  $ss = $sheets->spreadsheets->get($fileId);
  $sheet0 = $ss->getSheets()[0] ?? null;
  if ($sheet0 === null) {
    throw new RuntimeException('マスタのシート取得に失敗: ' . $fileName);
  }
  $title = $sheet0->getProperties()->getTitle();

  $resp = $sheets->spreadsheets_values->get($fileId, $title . '!A:Z');
  return $resp->getValues() ?? [];
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
      throw new RuntimeException('Folder not found: ' . implode('/', $folders));
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

// =============================
// CSV/TXT 補助
// =============================
function writeStatusCsv(string $csvPath, array $rows): void {
  $fp = fopen($csvPath, 'wb');
  if (!$fp) throw new RuntimeException('CSV作成に失敗: ' . $csvPath);

  fwrite($fp, "\xEF\xBB\xBF");
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

// =============================
// 汎用補助
// =============================
function ensureDir(string $dir): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
      throw new RuntimeException('mkdir failed: ' . $dir);
    }
  }
}

function normalizeHeader(array $row): array {
  return array_map(function($v) {
    return trim((string)$v);
  }, $row);
}

function requireHeaderIndex(array $header, string $name, string $fileName): int {
  $idx = array_search($name, $header, true);
  if ($idx === false) {
    throw new RuntimeException($fileName . 'に「' . $name . '」列が見つかりません');
  }
  return (int)$idx;
}

function buildRecentBusinessDays(array $calendarRows, string $todayISO, int $n): array {
  $days = [];
  foreach ($calendarRows as $r) {
    $d = (string)($r['date'] ?? '');
    $hol = (string)($r['hol_div'] ?? '');
    if ($d === '' || $d > $todayISO) continue;
    if ($hol === '1' || $hol === '2') $days[] = $d;
  }
  sort($days);
  return array_slice($days, -$n);
}

function tenYearsAgoSameDay(string $todayISO): string {
   return (new DateTime($todayISO))->modify('-10 years')->format('Y-m-d');
 }

function normalizeDbCode4(string $code): string {
  $code = trim($code);
  $code = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $code) ?? '');

  if ($code === '') return '';

  if (strlen($code) >= 5 && substr($code, -1) === '0') {
    return substr($code, 0, 4);
  }

  return substr($code, 0, 4);
}

function normalizeYMD(string $s): ?string {
  $s = trim($s);
  if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
  }
  if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $s, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
  }
  if (preg_match('/^(\d{8})$/', $s, $m)) {
    return substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
  }
  $t = strtotime($s);
  if ($t !== false) return date('Y-m-d', $t);
  return null;
}

function assertNoDuplicateDates(array $rows): void {
  $seen = [];
  foreach ($rows as $r) {
    $d = (string)($r['asof_date'] ?? '');
    if ($d === '') continue;
    if (isset($seen[$d])) {
      throw new RuntimeException('同一日付のレコードが重複しています: ' . $d);
    }
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

function compareNullableNumber($a, $b): int {
  if (($a === null || $a === '') && ($b === null || $b === '')) return 0;
  if ($a === null || $a === '' || $b === null || $b === '') return -1;
  $fa = (float)$a;
  $fb = (float)$b;
  if (abs($fa - $fb) < 0.000001) return 0;
  return ($fa < $fb) ? -1 : 1;
}
