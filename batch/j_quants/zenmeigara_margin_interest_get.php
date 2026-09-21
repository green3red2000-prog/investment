<?php
declare(strict_types=1);

/**
 * 全銘柄信用取引残高取得（J-Quants信用取引残高 → DB upsert → CSV/TXT出力 → Driveアップロード）
 *
 * - PHP 7.4.30 (cli)
 * - デプロイ先: /opt/invest/j_quants
 * - APIキー: /opt/invest/j_quants/conf/config.php の JQUANTS_API_KEY を利用
 * - Driveアップロード: /opt/invest/scraping/lib/scraping_common.php を利用
 * - スプレッドシート:
 *   - 投資/プログラミング/GAS/マスタ/カレンダーマスタ
 *   - 投資/プログラミング/GAS/マスタ/証券コードマスタ
 *
 * 実行例:
 *   php zenmeigara_margin_interest_get.php
 *   php zenmeigara_margin_interest_get.php --recover-margin-all
 *
 * 銘柄コードは証券コードマスタの4桁コードでAPIを呼び、
 * DBのcodeにも同じ4桁コードを保存する（普通株式のみを対象）。
 *
 * 自動リカバリ:
 * - 直近14営業日: APIに存在しDBに無いDateだけを欠損補完
 * - DB直近5レコード: 各DateをAPIへ問い合わせ、保存内容に差異があれば全件洗替
 */

require __DIR__ . '/conf/config.php';
require __DIR__ . '/lib/j_quants_common.php';
require '/opt/invest/scraping/lib/scraping_common.php';

require_once '/opt/invest/scraping/vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

// =============================
// 設定
// =============================
const JOB_NAME = '全銘柄信用取引残高取得';
const JQUANTS_MARGIN_INTEREST_PATH = '/v2/markets/margin-interest';
const OUTPUT_DIR = '/opt/invest/j_quants/tmp';
const SQL_CHUNK_ROWS = 100;

const MASTER_FOLDER_PATH = ['投資','プログラミング','GAS','マスタ'];
const CALENDAR_MASTER_NAME = 'カレンダーマスタ';
const SECURITY_CODE_MASTER_NAME = '証券コードマスタ';

// =============================
// 引数
// =============================
$args = parse_args($argv);
$recoverMarginAll = isset($args['recover-margin-all']);

// =============================
// メイン
// =============================
try {
  $now = new DateTime('now');
  $todayISO = $now->format('Y-m-d');

  ensureDir(OUTPUT_DIR);
  $csvPath = OUTPUT_DIR . '/' . JOB_NAME . '_' . $todayISO . '.csv';
  $txtPath = OUTPUT_DIR . '/' . JOB_NAME . '_メッセージ_' . $todayISO . '.txt';

  $pdo = jqBuildPdo();

  echo "[INFO] date={$todayISO}\n";
  echo '[INFO] recoverMarginAll=' . ($recoverMarginAll ? 'true' : 'false') . "\n";

  // (1) 営業日カレンダー取得
  $calendarRows = loadCalendarMasterSheet();
  $businessDays = buildRecentBusinessDays($calendarRows, $todayISO, 14);
  if (count($businessDays) === 0) {
    throw new RuntimeException('営業日カレンダーから営業日を取得できませんでした。');
  }
  echo '[INFO] recent business days: ' . implode(',', $businessDays) . "\n";

  // (2) 直近14営業日分の信用取引残高取得（日付指定）
  //     公表タイミングの都合で当日申込分は未配信でも正常。0件の日は空Mapとして保持する。
  $recentByDateCode = [];
  if ($recoverMarginAll) {
    echo "[WARN] --recover-margin-all specified. skip step(2): recent margin-interest fetch.\n";
  } else {
    foreach ($businessDays as $d) {
      $ymd = str_replace('-', '', $d);
      echo "[API] margin-interest date={$ymd}\n";
      $rows = fetchJQuantsMarginInterest(['date' => $ymd]);
      $recentByDateCode[$d] = indexMarginRowsByCode4($rows);
      echo "[API] margin-interest date={$ymd} count=" . count($rows) . "\n";
    }
  }

  // (3) DBデータ取得状況チェック
  $latestMap = [];
  if ($recoverMarginAll) {
    echo "[WARN] --recover-margin-all specified. skip step(3): latest DB status check.\n";
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
    $apiCode = $code4;
    $dbCode = $code4;
    $latest = $latestMap[$dbCode] ?? null;

    try {
      echo '[INFO] (' . ($idx + 1) . '/' . count($masterRows) . ") code={$code4} apiCode={$apiCode}\n";

      if ($recoverMarginAll) {
        $from = tenYearsAgoSameDay($todayISO);
        $rows = fetchRowsForCodeFrom($apiCode, $dbCode, $from);
        assertNoDuplicateDates($rows);
        if (count($rows) === 0) {
          throw new RuntimeException('JQUANTS_ERROR: 強制リカバリ全件洗替の取得が0件（削除中止）');
        }

        deleteMarginByCodeWithReconnect($pdo, $dbCode);
        $upsertTargetTotal += count($rows);
        $upsertedTotal += bulkUpsertMarginWithReconnect($pdo, $rows);

        $countReplace++;
        $statusRows[] = [$code4, $todayISO, '強制リカバリ全件洗替：' . count($rows) . '件', maxDataDate($rows) ?? ''];
        continue;
      }

      if ($latest === null || $latest === '') {
        $from = tenYearsAgoSameDay($todayISO);
        $rows = fetchRowsForCodeFrom($apiCode, $dbCode, $from);
        assertNoDuplicateDates($rows);

        $upsertTargetTotal += count($rows);
        if (count($rows) > 0) {
          $upsertedTotal += bulkUpsertMarginWithReconnect($pdo, $rows);
        }

        $countFull++;
        $statusRows[] = [$code4, $todayISO, '全件取得＆DB更新：' . count($rows) . '件', maxDataDate($rows) ?? ''];
        continue;
      }

      $cmp = strcmp($todayISO, $latest);

      if ($cmp > 0) {
        // ---------------------------------------------------------
        // A. 直近14営業日の欠損補完
        // ---------------------------------------------------------
        // API側に存在する申込日付がDBに無い場合、その日だけを追加する。
        // 2026-09-24以前は週次データしか存在しないため、
        // API側にデータが存在しない営業日は正常として無視する。
        jqEnsurePdoAlive($pdo);
        $dbRecentDates = fetchExistingMarginDatesFromDb($pdo, $dbCode, $businessDays);

        $missingRows = [];
        foreach ($businessDays as $d) {
          $webRow = $recentByDateCode[$d][$dbCode] ?? null;
          if ($webRow === null) continue;

          if (!isset($dbRecentDates[$d])) {
            $missingRows[] = $webRow;
          }
        }

        assertNoDuplicateDates($missingRows);

        if (count($missingRows) > 0) {
          $upsertTargetTotal += count($missingRows);
          $upsertedTotal += bulkUpsertMarginWithReconnect($pdo, $missingRows);
          echo "[RECOVER] code={$code4} missing dates added=" . count($missingRows) . "\n";
        }

        // ---------------------------------------------------------
        // B. DB直近5レコードの内容チェック
        // ---------------------------------------------------------
        // DBに実在する直近5レコードについて、そのDateを指定してAPIから取得し、
        // 全保存項目を照合する。
        //
        // ・同日APIデータが無い       → 異常として全件洗替
        // ・保存値とAPI値に差異あり   → 全件洗替
        //
        // これにより旧週次データ（2026-09-24以前）でも、
        // 「営業日なのにAPIデータが無い」ことを誤って異常判定しない。
        jqEnsurePdoAlive($pdo);
        $dbLast5 = fetchLastNMarginFromDb($pdo, $dbCode, 5);

        $needFullReplace = false;

        if (count($dbLast5) !== 5) {
          $needFullReplace = true;
        } else {
          foreach ($dbLast5 as $d => $dbRow) {
            $webRow = fetchMarginRowForCodeDate($apiCode, $dbCode, $d);

            if ($webRow === null || !sameMarginRow($webRow, $dbRow)) {
              $needFullReplace = true;
              break;
            }
          }
        }

        if ($needFullReplace) {
          $from = tenYearsAgoSameDay($todayISO);
          $rows = fetchRowsForCodeFrom($apiCode, $dbCode, $from);
          assertNoDuplicateDates($rows);
          if (count($rows) === 0) {
            throw new RuntimeException('JQUANTS_ERROR: 全件洗替の取得が0件（削除中止）');
          }

          deleteMarginByCodeWithReconnect($pdo, $dbCode);
          $upsertTargetTotal += count($rows);
          $upsertedTotal += bulkUpsertMarginWithReconnect($pdo, $rows);

          $countReplace++;
          $statusRows[] = [$code4, $todayISO, '全件洗替：' . count($rows) . '件', maxDataDate($rows) ?? ''];
          continue;
        }

        // 欠損補完後、API側に存在するDB最新日より新しいデータも追加する。
        // 通常は上記Aで包含されるが、処理意図を明確にするため件数をまとめて結果表示する。
        $countDiff++;
        $statusRows[] = [
          $code4,
          $todayISO,
          '差分取得＆DB更新：' . count($missingRows) . '件',
          maxDataDate($missingRows) ?? $latest
        ];

      } elseif ($cmp === 0) {
        // DB最新日が本日でも、直近14営業日の途中欠損は補完する。
        jqEnsurePdoAlive($pdo);
        $dbRecentDates = fetchExistingMarginDatesFromDb($pdo, $dbCode, $businessDays);

        $missingRows = [];
        foreach ($businessDays as $d) {
          $webRow = $recentByDateCode[$d][$dbCode] ?? null;
          if ($webRow === null) continue;

          if (!isset($dbRecentDates[$d])) {
            $missingRows[] = $webRow;
          }
        }

        assertNoDuplicateDates($missingRows);

        if (count($missingRows) > 0) {
          $upsertTargetTotal += count($missingRows);
          $upsertedTotal += bulkUpsertMarginWithReconnect($pdo, $missingRows);

          $countDiff++;
          $statusRows[] = [
            $code4,
            $todayISO,
            '欠損補完：' . count($missingRows) . '件',
            maxDataDate($missingRows) ?? $latest
          ];
        } else {
          $countSkip++;
          $statusRows[] = [$code4, $todayISO, '処理なし', $latest];
        }

      } else {
        $countErr++;
        $statusRows[] = [$code4, $todayISO, 'エラー：DBに未来日が保存されている', $latest];
      }

    } catch (JQuantsApiException $e) {
      $countErr++;
      $statusRows[] = [$code4, $todayISO, 'エラー：J-QuantsAPIの取得に失敗', $latest ?? ''];
      fwrite(STDERR, "[ERR][JQUANTS] code={$code4} " . $e->getMessage() . "\n");
    } catch (Throwable $e) {
      $countErr++;
      $statusRows[] = [$code4, $todayISO, 'エラー：' . $e->getMessage(), $latest ?? ''];
      fwrite(STDERR, "[ERR] code={$code4} " . $e->getMessage() . "\n");
    }
  }

  writeStatusCsv($csvPath, $statusRows);

  $subjectLine = JOB_NAME . '：' . $todayISO;
  $body =
    "処理を終了しました。\n\n" .
    '銘柄数: ' . count($masterRows) . " 件\n" .
    '強制リカバリ全件洗替: ' . ($recoverMarginAll ? 'あり' : 'なし') . "\n" .
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
function fetchRowsForCodeFrom(string $apiCode4, string $dbCode4, string $fromISO): array {
  $rows = fetchJQuantsMarginInterest([
    'code' => $apiCode4,
    'from' => str_replace('-', '', $fromISO),
  ]);

  $out = [];
  foreach ($rows as $row) {
    $norm = normalizeMarginRow($row, $dbCode4);
    if ($norm !== null) $out[] = $norm;
  }
  return $out;
}

function fetchJQuantsMarginInterest(array $params): array {
  return jquantsGetAll(JQUANTS_MARGIN_INTEREST_PATH, $params);
}

function normalizeMarginRow(array $row, ?string $dbCode4 = null): ?array {
  $date = normalizeYMD((string)($row['Date'] ?? ''));
  if ($date === null) return null;

  $apiCode = strtoupper(trim((string)($row['Code'] ?? '')));
  $apiCode = preg_replace('/[^0-9A-Za-z]/', '', $apiCode) ?? '';
  if ($apiCode === '') return null;

  $code = $dbCode4 !== null && $dbCode4 !== ''
    ? $dbCode4
    : normalizeDbCode4($apiCode);
  if ($code === '') return null;

  $pubDate = null;
  if (array_key_exists('PubDate', $row) && $row['PubDate'] !== null && $row['PubDate'] !== '') {
    $pubDate = normalizeYMD((string)$row['PubDate']);
  }

  return [
    'data_date'    => $date,
    'pub_date'     => $pubDate,
    'code'         => $code,
    'iss_type'     => toNullableInt($row['IssType'] ?? null),
    'shrt_vol'     => toNullableInt($row['ShrtVol'] ?? null),
    'long_vol'     => toNullableInt($row['LongVol'] ?? null),
    'shrt_neg_vol' => toNullableInt($row['ShrtNegVol'] ?? null),
    'long_neg_vol' => toNullableInt($row['LongNegVol'] ?? null),
    'shrt_std_vol' => toNullableInt($row['ShrtStdVol'] ?? null),
    'long_std_vol' => toNullableInt($row['LongStdVol'] ?? null),
    'shrt_val'     => toNullableInt($row['ShrtVal'] ?? null),
    'long_val'     => toNullableInt($row['LongVal'] ?? null),
    'shrt_neg_val' => toNullableInt($row['ShrtNegVal'] ?? null),
    'long_neg_val' => toNullableInt($row['LongNegVal'] ?? null),
    'shrt_std_val' => toNullableInt($row['ShrtStdVal'] ?? null),
    'long_std_val' => toNullableInt($row['LongStdVal'] ?? null),
  ];
}

function indexMarginRowsByCode4(array $rawRows): array {
  $map = [];
  foreach ($rawRows as $row) {
    if (!is_array($row)) continue;

    $apiCode = strtoupper(trim((string)($row['Code'] ?? '')));
    $apiCode = preg_replace('/[^0-9A-Za-z]/', '', $apiCode) ?? '';
    $code4 = normalizeDbCode4($apiCode);
    if ($code4 === '') continue;

    $norm = normalizeMarginRow($row, $code4);
    if ($norm === null) continue;
    $map[$code4] = $norm;
  }
  return $map;
}

// =============================
// DB / PDO
// =============================
function fetchLatestMapFromDb(PDO $pdo): array {
  $sql = "SELECT code, MAX(data_date) AS latest_data_date
          FROM margin_interest
          GROUP BY code";
  $rows = $pdo->query($sql)->fetchAll();

  $map = [];
  foreach ($rows as $r) {
    $code = trim((string)($r['code'] ?? ''));
    $d = trim((string)($r['latest_data_date'] ?? ''));
    if ($code !== '' && $d !== '') $map[$code] = $d;
  }
  return $map;
}

function fetchLastNMarginFromDb(PDO $pdo, string $code, int $n): array {
  $sql = "SELECT
            data_date, pub_date, code, iss_type,
            shrt_vol, long_vol, shrt_neg_vol, long_neg_vol, shrt_std_vol, long_std_vol,
            shrt_val, long_val, shrt_neg_val, long_neg_val, shrt_std_val, long_std_val
          FROM margin_interest
          WHERE code = :code
          ORDER BY data_date DESC
          LIMIT {$n}";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':code' => $code]);
  $rows = $stmt->fetchAll();

  $map = [];
  foreach ($rows as $r) {
    $d = (string)($r['data_date'] ?? '');
    if ($d === '') continue;
    $map[$d] = $r;
  }
  return $map;
}

function fetchExistingMarginDatesFromDb(PDO $pdo, string $code, array $dates): array {
  if (count($dates) === 0) return [];

  $placeholders = [];
  $params = [':code' => $code];

  foreach (array_values($dates) as $i => $d) {
    $ph = ':d' . $i;
    $placeholders[] = $ph;
    $params[$ph] = $d;
  }

  $sql = "SELECT data_date
          FROM margin_interest
          WHERE code = :code
            AND data_date IN (" . implode(',', $placeholders) . ")";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $map = [];
  foreach ($stmt->fetchAll() as $r) {
    $d = (string)($r['data_date'] ?? '');
    if ($d !== '') $map[$d] = true;
  }
  return $map;
}

function fetchMarginRowForCodeDate(string $apiCode4, string $dbCode4, string $dateISO): ?array {
  $rows = fetchJQuantsMarginInterest([
    'code' => $apiCode4,
    'date' => str_replace('-', '', $dateISO),
  ]);

  foreach ($rows as $row) {
    if (!is_array($row)) continue;

    $norm = normalizeMarginRow($row, $dbCode4);
    if ($norm === null) continue;

    if (($norm['data_date'] ?? null) === $dateISO) {
      return $norm;
    }
  }

  return null;
}

function sameMarginRow(array $a, array $b): bool {
  $fields = [
    'pub_date', 'iss_type',
    'shrt_vol', 'long_vol', 'shrt_neg_vol', 'long_neg_vol', 'shrt_std_vol', 'long_std_vol',
    'shrt_val', 'long_val', 'shrt_neg_val', 'long_neg_val', 'shrt_std_val', 'long_std_val',
  ];

  foreach ($fields as $f) {
    $av = $a[$f] ?? null;
    $bv = $b[$f] ?? null;

    if ($f === 'pub_date') {
      if (normalizeNullableString($av) !== normalizeNullableString($bv)) return false;
    } else {
      if (compareNullableNumber($av, $bv) !== 0) return false;
    }
  }
  return true;
}

function deleteMarginByCode(PDO $pdo, string $code): void {
  $sql = "DELETE FROM margin_interest WHERE code = :code";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':code' => $code]);
}

function deleteMarginByCodeWithReconnect(PDO &$pdo, string $code): void {
  try {
    jqEnsurePdoAlive($pdo);
    deleteMarginByCode($pdo, $code);
  } catch (Throwable $e) {
    if (!jqIsReconnectableDbError($e)) throw $e;
    fwrite(STDERR, '[DB] reconnect and retry delete: ' . $e->getMessage() . "\n");
    jqReconnectPdo($pdo);
    jqEnsurePdoAlive($pdo);
    deleteMarginByCode($pdo, $code);
  }
}

function bulkUpsertMarginWithReconnect(PDO &$pdo, array $rows): int {
  try {
    jqEnsurePdoAlive($pdo);
    return bulkUpsertMargin($pdo, $rows);
  } catch (Throwable $e) {
    if (!jqIsReconnectableDbError($e)) throw $e;
    fwrite(STDERR, '[DB] reconnect and retry upsert: ' . $e->getMessage() . "\n");
    jqReconnectPdo($pdo);
    jqEnsurePdoAlive($pdo);
    return bulkUpsertMargin($pdo, $rows);
  }
}

function bulkUpsertMargin(PDO $pdo, array $items): int {
  $validMap = [];

  foreach ($items as $row) {
    if (!is_array($row)) continue;

    $date = $row['data_date'] ?? null;
    $code = $row['code'] ?? null;

    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) continue;
    if ($code === null || !preg_match('/^[0-9A-Za-z]{1,8}$/', (string)$code)) continue;

    $norm = [
      'data_date'    => $date,
      'pub_date'     => normalizeNullableDate($row['pub_date'] ?? null),
      'code'         => (string)$code,
      'iss_type'     => toNullableInt($row['iss_type'] ?? null),
      'shrt_vol'     => toNullableInt($row['shrt_vol'] ?? null),
      'long_vol'     => toNullableInt($row['long_vol'] ?? null),
      'shrt_neg_vol' => toNullableInt($row['shrt_neg_vol'] ?? null),
      'long_neg_vol' => toNullableInt($row['long_neg_vol'] ?? null),
      'shrt_std_vol' => toNullableInt($row['shrt_std_vol'] ?? null),
      'long_std_vol' => toNullableInt($row['long_std_vol'] ?? null),
      'shrt_val'     => toNullableInt($row['shrt_val'] ?? null),
      'long_val'     => toNullableInt($row['long_val'] ?? null),
      'shrt_neg_val' => toNullableInt($row['shrt_neg_val'] ?? null),
      'long_neg_val' => toNullableInt($row['long_neg_val'] ?? null),
      'shrt_std_val' => toNullableInt($row['shrt_std_val'] ?? null),
      'long_std_val' => toNullableInt($row['long_std_val'] ?? null),
    ];

    $validMap[$norm['code'] . '|' . $norm['data_date']] = $norm;
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
        foreach ($r as $key => $value) {
          $params[":{$key}{$i}"] = $value;
        }
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
  $cols = [
    'data_date', 'pub_date', 'code', 'iss_type',
    'shrt_vol', 'long_vol', 'shrt_neg_vol', 'long_neg_vol', 'shrt_std_vol', 'long_std_vol',
    'shrt_val', 'long_val', 'shrt_neg_val', 'long_neg_val', 'shrt_std_val', 'long_std_val',
  ];

  $values = [];
  for ($i = 0; $i < $n; $i++) {
    $ph = [];
    foreach ($cols as $c) $ph[] = ":{$c}{$i}";
    $values[] = '(' . implode(', ', $ph) . ')';
  }

  $updates = [];
  foreach ($cols as $c) {
    if ($c === 'data_date' || $c === 'code') continue;
    $updates[] = "{$c} = VALUES({$c})";
  }

  return "INSERT INTO margin_interest\n(" . implode(', ', $cols) . ")\nVALUES\n" .
    implode(",\n", $values) .
    "\nON DUPLICATE KEY UPDATE\n" . implode(",\n", $updates);
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

    $out[] = [
      'code4' => $code4,
      'market_code' => $marketCode,
    ];
  }
  return $out;
}

function loadSpreadsheetValues(string $fileName): array {
  $client = build_oauth_client_();
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

function normalizeNullableDate($v): ?string {
  if ($v === null || $v === '') return null;
  return normalizeYMD((string)$v);
}

function normalizeNullableString($v): ?string {
  if ($v === null || $v === '') return null;
  return (string)$v;
}

function assertNoDuplicateDates(array $rows): void {
  $seen = [];
  foreach ($rows as $r) {
    $d = (string)($r['data_date'] ?? '');
    if ($d === '') continue;

    if (isset($seen[$d])) {
      throw new RuntimeException('同一日付のレコードが重複しています: ' . $d);
    }
    $seen[$d] = true;
  }
}

function maxDataDate(array $rows): ?string {
  $max = null;
  foreach ($rows as $r) {
    $d = (string)($r['data_date'] ?? '');
    if ($d === '') continue;
    if ($max === null || $d > $max) $max = $d;
  }
  return $max;
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
