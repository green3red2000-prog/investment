<?php
declare(strict_types=1);

/**
 * 全銘柄基本情報取得（J-Quants + 財務情報DBマスタ版）
 *
 * - 参照元:
 *   - Google Drive（マスタ/カレンダーマスタ）
 *   - Google Drive（マスタ/証券コードマスタ）
 *   - MariaDB stocks.jquants_fins_summary
 * - 取得元:
 *   - J-Quants /v2/fins/summary
 *   - J-Quants /v2/equities/bars/daily
 *   - J-Quants /v2/markets/margin-interest
 * - 出力:
 *   - /opt/invest/j_quants/tmp/全銘柄基本情報取得_yyyy-MM-dd.csv
 *   - /opt/invest/j_quants/tmp/全銘柄基本情報取得_メッセージ_yyyy-MM-dd.txt
 * - Driveへアップロード（CSVはGoogle Sheets化）→ 成功後ローカル削除
 * - メール送信なし
 *
 * 実行例:
 *   php zenmeigara_basicinfo_get.php
 *   php zenmeigara_basicinfo_get.php --date=2026-06-24
 *   php zenmeigara_basicinfo_get.php --date=2026-06-24 --noupload
 *   php zenmeigara_basicinfo_get.php --recover-fins-all --noupload
 */

require __DIR__ . '/conf/config.php';
require __DIR__ . '/lib/j_quants_common.php';
require '/opt/invest/scraping/lib/scraping_common.php';

// Google API クライアント。scraping_common.php の upload_outputs_and_cleanup() と同じ vendor を使う。
require_once '/opt/invest/scraping/vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

// =====================
// 設定
// =====================
const JOB_NAME = '全銘柄基本情報取得';

const OUTPUT_DIR = '/opt/invest/j_quants/tmp';
const JQUANTS_FINS_SUMMARY_PATH = '/v2/fins/summary';
const JQUANTS_DAILY_BARS_PATH = '/v2/equities/bars/daily';
const JQUANTS_MARGIN_INTEREST_PATH = '/v2/markets/margin-interest';
const FINS_RECENT_BUSINESS_DAYS = 30;

// DB設定（zenmeigara_hiashi_get.php 踏襲）
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'stocks';
const DB_USER = 'apiuser';
const DB_PASS = 'G&TgY7Ubq5weU365a6HgxGCshU&%75MKMun8m9kMAr3S&a';

// Drive上のマスタ配置
const MASTER_FOLDER_PATH = ['投資','プログラミング','GAS','マスタ'];
const CALENDAR_MASTER_NAME = 'カレンダーマスタ';
const SECURITY_CODE_MASTER_NAME = '証券コードマスタ';

const SQL_CHUNK_ROWS = 50;
const CSV_DASH = '－';

const DATA_HEADERS = [
  '証券コード','更新日','実行結果','会社名略称','会社名','業種','概要',
  '時価総額','上場区分','売上高','経常益','最終益','PER','PBR','利回り','終値','前日比','騰落率',
  '出来高','信用日付','信用売り残','信用買い残','信用倍率'
];

const FINS_DB_COLUMNS = [
  'DiscDate','DiscTime','Code','DiscNo','DocType','CurPerType',
  'CurPerSt','CurPerEn','CurFYSt','CurFYEn','NxtFYSt','NxtFYEn',
  'Sales','OP','OdP','NP','EPS','DEPS','TA','Eq','EqAR','BPS','CFO','CFI','CFF','CashEq',
  'Div1Q','Div2Q','Div3Q','DivFY','DivAnn','DivUnit','DivTotalAnn','PayoutRatioAnn',
  'FDiv1Q','FDiv2Q','FDiv3Q','FDivFY','FDivAnn','FDivUnit','FDivTotalAnn','FPayoutRatioAnn',
  'NxFDiv1Q','NxFDiv2Q','NxFDiv3Q','NxFDivFY','NxFDivAnn','NxFDivUnit','NxFPayoutRatioAnn',
  'FSales2Q','FOP2Q','FOdP2Q','FNP2Q','FEPS2Q',
  'NxFSales2Q','NxFOP2Q','NxFOdP2Q','NxFNp2Q','NxFEPS2Q',
  'FSales','FOP','FOdP','FNP','FEPS',
  'NxFSales','NxFOP','NxFOdP','NxFNp','NxFEPS',
  'MatChgSub','SigChgInC','ChgByASRev','ChgNoASRev','ChgAcEst','RetroRst',
  'ShOutFY','TrShFY','AvgSh',
  'NCSales','NCOP','NCOdP','NCNP','NCEPS','NCTA','NCEq','NCEqAR','NCBPS',
  'FNCSales2Q','FNCOP2Q','FNCOdP2Q','FNCNP2Q','FNCEPS2Q',
  'NxFNCSales2Q','NxFNCOP2Q','NxFNCOdP2Q','NxFNCNP2Q','NxFNCEPS2Q',
  'FNCSales','FNCOP','FNCOdP','FNCNP','FNCEPS',
  'NxFNCSales','NxFNCOP','NxFNCOdP','NxFNCNP','NxFNCEPS'
];

const FINS_DATE_COLUMNS = ['DiscDate','CurPerSt','CurPerEn','CurFYSt','CurFYEn','NxtFYSt','NxtFYEn'];
const FINS_TIME_COLUMNS = ['DiscTime'];
const FINS_STRING_COLUMNS = [
  'Code','DiscNo','DocType','CurPerType','MatChgSub','SigChgInC','ChgByASRev','ChgNoASRev','ChgAcEst','RetroRst'
];


// =====================
// 引数
// =====================
$args = parse_args($argv);
$targetISODate = isset($args['date']) ? normalizeDateToIso((string)$args['date']) : date('Y-m-d');
$noUpload = isset($args['noupload']);
$recoverFinsAll = isset($args['recover-fins-all']);

// =====================
// メイン
// =====================
try {
  ensureDir(OUTPUT_DIR);

  $csvPath = OUTPUT_DIR . '/' . JOB_NAME . '_' . $targetISODate . '.csv';
  $txtPath = OUTPUT_DIR . '/' . JOB_NAME . '_メッセージ_' . $targetISODate . '.txt';

  echo "[INFO] date={$targetISODate}\n";
  echo '[INFO] noUpload=' . ($noUpload ? 'true' : 'false') . "\n";

  $pdo = buildPdo();

  // 1. 直近営業日の取得
  $calendarRows = loadCalendarMasterSheet();
  $recentBizDates = findRecentBusinessDays($calendarRows, $targetISODate, FINS_RECENT_BUSINESS_DAYS);
  $currentBizISO = $recentBizDates[0];
  $prevBizISO = $recentBizDates[1];
  $currentBizYmd = isoToYmd($currentBizISO);
  $prevBizYmd = isoToYmd($prevBizISO);
  echo "[INFO] business dates current={$currentBizISO} prev={$prevBizISO} recent=" . count($recentBizDates) . "\n";

  // 2. 財務情報の取得（日次開示分）→ DB upsert
  $dailyFinsUpserted = 0;
  if ($recoverFinsAll) {
    echo "[WARN] --recover-fins-all specified. delete all jquants_fins_summary rows and skip daily fins fetch.\n";
    deleteAllFinsSummaryRows($pdo);
  } else {
    foreach ($recentBizDates as $bizISODate) {
      echo "[API] fins summary date={$bizISODate}\n";
      $dailyFinsRows = jquantsGetAll(JQUANTS_FINS_SUMMARY_PATH, ['date' => $bizISODate]);
      $upserted = upsertFinsSummaryRowsWithReconnect($pdo, $dailyFinsRows);
      $dailyFinsUpserted += $upserted;
      echo '[INFO] fins daily date=' . $bizISODate . ' rows=' . count($dailyFinsRows) . " upserted={$upserted}\n";
    }
  }

  // 3. 株価情報の取得
  // J-Quants daily bars は全銘柄一括取得の場合、from単独指定は不可。
  // 当営業日・前営業日を date 指定でそれぞれ取得する。
  echo "[API] daily bars date={$currentBizYmd}\n";
  $currentBarRows = jquantsGetAll(JQUANTS_DAILY_BARS_PATH, ['date' => $currentBizYmd]);
  $currentBarsByCode = buildBarsByCode($currentBarRows);

  echo "[API] daily bars date={$prevBizYmd}\n";
  $prevBarRows = jquantsGetAll(JQUANTS_DAILY_BARS_PATH, ['date' => $prevBizYmd]);
  $prevBarsByCode = buildBarsByCode($prevBarRows);

  echo '[INFO] current bar rows=' . count($currentBarRows) .
       ' current_codes=' . count($currentBarsByCode) .
       ' prev bar rows=' . count($prevBarRows) .
       ' prev_codes=' . count($prevBarsByCode) . "\n";

  // 4. 信用取引週末残高の取得
  [$marginRows, $marginDateISO] = fetchRecentMarginInterestRows($calendarRows, $currentBizISO, 30);
  $marginByCode = buildMarginByCode($marginRows);
  echo '[INFO] margin date=' . ($marginDateISO ?? '') .
       ' rows=' . count($marginRows) .
       ' codes=' . count($marginByCode) . "\n";

  // 5. 証券コードマスタの取得
  [$masterRows, $indexSkip, $etfCount, $tpmSkip] = loadSecurityCodeMasterSheet();
  echo '[INFO] code master target rows=' . count($masterRows) .
  	   " indexCount={$indexSkip}" .
       " etfCount={$etfCount}" .
       " tpmSkip={$tpmSkip}\n";

  // 6-7. 個別銘柄の財務情報取得とCSVレコード算出
  $rows = [];
  $ok = 0;
  $warn = 0;
  $err = 0;
  $skip = 0;          // CSV出力対象外になった銘柄
  $initialFetchCount = 0;
  $initialUpsertedTotal = 0;

  foreach ($masterRows as $idx => $master) {
    $code4 = $master['code4'];
    $code5 = $master['code5'];

    echo '[INFO] (' . ($idx + 1) . '/' . count($masterRows) . ") code={$code4} code5={$code5}\n";

    $row = array_fill_keys(DATA_HEADERS, '');
    $row['証券コード'] = $code4;
    $row['更新日'] = $targetISODate;
    $row['会社名略称'] = '';
    $row['会社名'] = $master['name'];
    $row['業種'] = $master['industry33'];
    $row['概要'] = '';
    $row['上場区分'] = $master['market_name'];

    $errors = [];
    $warnings = [];
    $errorResult = '';
    $finsApiReturnedZero = false;

    try {
      $fin = null;       // 売上高・経常益・最終益・PER/PBR/利回り用
      $finShares = null; // 時価総額の ShOutFY 用
      if (empty($master['is_etf_like']) && empty($master['is_index'])) {
         $fin = fetchLatestFinsFromDb($pdo, $code5, $targetISODate);
         $finShares = fetchLatestFinsSharesFromDb($pdo, $code5, $targetISODate);
      }
      
      if (!empty($master['is_index'])) {
        $curBar = fetchIndexPriceBarFromDb($pdo, $code4, $currentBizISO);
        $prevBar = fetchIndexPriceBarFromDb($pdo, $code4, $prevBizISO);
      } else {
        $curBar = $currentBarsByCode[$code5] ?? $currentBarsByCode[$code4] ?? null;
        $prevBar = $prevBarsByCode[$code5] ?? $prevBarsByCode[$code4] ?? null;
      }
      
      $margin = $marginByCode[$code5] ?? $marginByCode[$code4] ?? null;
      
      // 当営業日の株価データが存在しない銘柄は、
      // 上場前・ETF/ETN等の特殊コード・データ未収録の可能性があるためCSV出力対象外としてスキップする。
      // 前営業日の株価データが存在しない場合は、上場当日の可能性があるためスキップせず、
      // 前営業日出来高なしとしてCSVレコードを算出する。
      if ($curBar === null) {
        $skip++;
        fwrite(STDERR, "[SKIP] code={$code4} code5={$code5} 当営業日株価データなし\n");
        continue;
      }

      // DBに財務情報がない銘柄は、code指定で初回登録する。
      if (($fin === null || $finShares === null) && empty($master['is_etf_like']) && empty($master['is_index'])) {
        echo "[API] fins summary initial code={$code5}\n";
        $codeFinsRows = jquantsGetAll(JQUANTS_FINS_SUMMARY_PATH, ['code' => $code5]);
        
        $initialFetchCount++;
        if (count($codeFinsRows) === 0) {
          $finsApiReturnedZero = true;
        }
        $initialUpserted = upsertFinsSummaryRowsWithReconnect($pdo, $codeFinsRows);
        $initialUpsertedTotal += $initialUpserted;
        echo '[INFO] fins initial code=' . $code5 . ' rows=' . count($codeFinsRows) . " upserted={$initialUpserted}\n";

        // API取得結果はDBへ登録し、採用判定は必ずDBから再取得して行う。
        // これにより、通常時も初回取得時も同じロジックで財務情報を採用する。
        $fin = fetchLatestFinsFromDb($pdo, $code5, $targetISODate);
        $finShares = fetchLatestFinsSharesFromDb($pdo, $code5, $targetISODate);
      }

      if (($fin === null || $finShares === null) && empty($master['is_etf_like']) && empty($master['is_index'])) {
        if ($finsApiReturnedZero) {
          $warnings[] = '財務情報J-Quants登録なし';
          fwrite(STDERR, "[WARN] code={$code4} code5={$code5} 財務情報J-Quants登録なし\n");
        } else {
          $errors[] = '財務情報なし';
          $errorResult = 'エラー：財務情報API取得に失敗';
        }
      }

      // 信用残APIが30日遡っても1件も取得できなかった場合は、
      // 信用残列は空欄のままCSV出力し、実行結果は信用残情報API取得失敗とする。
      // API自体は取得できても、個別銘柄に信用残が存在しない場合は
      // 信用残列を空欄とし、WARNログのみ出力する。
      if ($marginDateISO === null) {
        $errors[] = '信用残API取得失敗';
        if ($errorResult === '') {
          $errorResult = 'エラー：信用残情報API取得に失敗';
        }
      }
      if ($margin === null && empty($master['is_index'])) {
      	$warnings[] = '信用残J-Quants登録なし';
        fwrite(STDERR, "[WARN] code={$code4} code5={$code5} 信用残J-Quants登録なし\n");
      }

      $adjC = $curBar !== null ? toFloatOrNull($curBar['AdjC'] ?? null) : null;
      $calcAdjC = $adjC;
      $prevAdjC = $prevBar !== null ? toFloatOrNull($prevBar['AdjC'] ?? null) : null;
      $adjVo = $curBar !== null ? toFloatOrNull($curBar['AdjVo'] ?? null) : null;
      $prevAdjVo = $prevBar !== null ? toFloatOrNull($prevBar['AdjVo'] ?? null) : null;

      $sales = $fin !== null ? toFloatOrNull($fin['Sales'] ?? null) : null;
      $odp = $fin !== null ? toFloatOrNull($fin['OdP'] ?? null) : null;
      $np = $fin !== null ? toFloatOrNull($fin['NP'] ?? null) : null;
      $shOut = $finShares !== null ? toFloatOrNull($finShares['ShOutFY'] ?? null) : null;
      $feps = $fin !== null ? toFloatOrNull($fin['FEPS'] ?? null) : null;
      $eps = $fin !== null ? toFloatOrNull($fin['EPS'] ?? null) : null;
      $bps = $fin !== null ? toFloatOrNull($fin['BPS'] ?? null) : null;
      $fDivAnn = $fin !== null ? toFloatOrNull($fin['FDivAnn'] ?? null) : null;
      $divAnn = $fin !== null ? toFloatOrNull($fin['DivAnn'] ?? null) : null;
      
      if (empty($master['is_index'])) {
        if ($adjVo === null) {
            $warnings[] = '当営業日出来高なし';
            if (
              empty($master['is_index']) &&
              $master['market_name'] !== 'その他' &&
              $adjVo === null
            ) {
              if ($prevAdjVo !== null && $prevAdjC !== null) {
                $calcAdjC = $prevAdjC;
              } else {
                $latestBar = fetchLatestPriceBarFromDb($pdo, $code4, $targetISODate);
                $latestClose = $latestBar !== null ? toFloatOrNull($latestBar['AdjC'] ?? null) : null;
                if ($latestClose !== null) {
                  $calcAdjC = $latestClose;
                }else {
                  $warnings[] = '代替終値なし';
                }
              }
            }
            fwrite(STDERR, "[WARN] code={$code4} code5={$code5} 当営業日出来高なし\n");
          }
         if ($prevAdjVo === null) {
           $warnings[] = '前営業日出来高なし';
           fwrite(STDERR, "[WARN] code={$code4} code5={$code5} 前営業日出来高なし\n");
         }
      }
      if ($calcAdjC !== null && $shOut !== null) $row['時価総額'] = ($calcAdjC * $shOut) / 100000000.0;
      if ($shOut === null && empty($master['is_etf_like']) && empty($master['is_index'])) {
        $row['時価総額'] = CSV_DASH;
      }
      if ($sales !== null) $row['売上高'] = $sales / 100000000.0;
      if ($sales === null && $fin !== null) $row['売上高'] = CSV_DASH;
      if ($odp !== null) $row['経常益'] = $odp / 100000000.0;
      if ($odp === null && $fin !== null) $row['経常益'] = CSV_DASH;
      if ($np !== null) $row['最終益'] = $np / 100000000.0;
      if ($np === null && $fin !== null) $row['最終益'] = CSV_DASH;

      $perBase = null;
      if ($feps !== null && $feps > 0) {
        $perBase = $feps;
      } elseif ($eps !== null && $eps > 0) {
        $perBase = $eps;
      }
      $row['PER'] = ($calcAdjC !== null && $perBase !== null) ? ($calcAdjC / $perBase) : CSV_DASH;
      $row['PBR'] = ($calcAdjC !== null && $bps !== null && $bps > 0) ? ($calcAdjC / $bps) : CSV_DASH;

      $divBase = null;
      if ($fDivAnn !== null) {
        $divBase = $fDivAnn;
      } elseif ($divAnn !== null) {
        $divBase = $divAnn;
      }
      $row['利回り'] = ($calcAdjC !== null && $calcAdjC > 0 && $divBase !== null) ? (($divBase / $calcAdjC) * 100.0) : CSV_DASH;

      if ($adjC !== null) $row['終値'] = $adjC;
      if ($prevAdjC !== null && $adjC !== null) {
        $row['前日比'] = $adjC - $prevAdjC;
        $row['騰落率'] = (($adjC - $prevAdjC) / $prevAdjC) * 100.0;
      }
      if ($adjVo !== null) $row['出来高'] = $adjVo;

      if ($margin !== null) {
        $shortVol = toFloatOrNull($margin['ShrtVol'] ?? null);
        $longVol = toFloatOrNull($margin['LongVol'] ?? null);

        $row['信用日付'] = normalizeDateToIso((string)($margin['Date'] ?? ''));
        if ($shortVol !== null) $row['信用売り残'] = $shortVol;
        if ($longVol !== null) $row['信用買い残'] = $longVol;

        if ($shortVol === null || $longVol === null || $shortVol == 0.0 || $longVol == 0.0) {
          $row['信用倍率'] = CSV_DASH;
        } else {
          $row['信用倍率'] = $longVol / $shortVol;
        }
      }

      applyDashByWarningsAndType($row, $warnings, $master);
      enforceOutputFormats($row);

    } catch (JQuantsApiException $e) {
      $errors[] = 'J-QuantsAPI取得失敗: ' . $e->getMessage();
      if (strpos($e->getMessage(), 'HTTP 429') !== false || strpos($e->getMessage(), 'retry exceeded') !== false) {
        $errorResult = 'エラー：レートリミット超過';
      } else {
        $errorResult = 'エラー：財務情報API取得に失敗';
      }
     } catch (Throwable $e) {
      $errors[] = '処理失敗: ' . $e->getMessage();
      $errorResult = 'エラー：その他例外処理発生';
    }

    if (count($errors) === 0) {
      if (count($warnings) > 0) {
        $row['実行結果'] = '警告：' . implode('、', $warnings);
        $warn++;
      } else {
        $row['実行結果'] = '正常';
        $ok++;
      }
    } else {
      $row['実行結果'] = $errorResult !== '' ? $errorResult : 'エラー：その他例外処理発生';
      $err++;
      fwrite(STDERR, "[WARN] code={$code4} code5={$code5} " . implode(', ', $errors) . "\n");
    }

    $rows[] = $row;
  }

  // 8. CSV出力
  writeCsvWithHeaders($csvPath, DATA_HEADERS, $rows);

  // 9. メッセージTXT出力
  $subjectLine = JOB_NAME . '：' . $targetISODate;
  $body =
    "全銘柄基本情報取得（J-Quants）を完了しました。\n\n" .
    '対象コード数: ' . count($rows) . "\n" .
    "成功: {$ok}\n" .
    "警告: {$warn}\n" .
    "失敗: {$err}\n" .
    "スキップ: {$skip}\n" .
    "指数件数: {$indexSkip}\n" .
    "ETF等件数: {$etfCount}\n" .
    "TPM除外件数: {$tpmSkip}\n" .
   	'財務情報 強制リカバリ全件洗替: ' . ($recoverFinsAll ? 'あり' : 'なし') . "\n" .
    "財務情報 直近30営業日 upsert: {$dailyFinsUpserted} 件\n" .
    "財務情報 初回API取得: {$initialFetchCount} 銘柄\n" .
    "財務情報 初回upsert: {$initialUpsertedTotal} 件\n";

  write_message_txt($txtPath, $subjectLine, $body);

  echo "[INFO] local outputs written:\n- {$csvPath}\n- {$txtPath}\n";

  // 10. アップロード処理
  if ($noUpload) {
    echo "[INFO] --noupload specified. upload skipped.\n";
  } else {
    upload_outputs_and_cleanup(JOB_NAME, $targetISODate, $csvPath, $txtPath);
  }

  echo "[INFO] done.\n";
  exit(0);

} catch (Throwable $e) {
  fwrite(STDERR, '[FATAL] ' . $e->getMessage() . "\n");
  exit(1);
}

// =====================
// 引数
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


// =====================
// DB / PDO
// =====================
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

function upsertFinsSummaryRowsWithReconnect(PDO &$pdo, array $rows): int {
  try {
    ensurePdoAlive($pdo);
    return upsertFinsSummaryRows($pdo, $rows);
  } catch (Throwable $e) {
    if (!isReconnectableDbError($e)) throw $e;
    fwrite(STDERR, '[DB] reconnect and retry fins upsert: ' . $e->getMessage() . "\n");
    reconnectPdo($pdo);
    ensurePdoAlive($pdo);
    return upsertFinsSummaryRows($pdo, $rows);
  }
}

function deleteAllFinsSummaryRows(PDO &$pdo): void {
  try {
    ensurePdoAlive($pdo);
    $pdo->exec('DELETE FROM jquants_fins_summary');
    echo "[INFO] jquants_fins_summary all rows deleted.\n";
  } catch (Throwable $e) {
    if (!isReconnectableDbError($e)) throw $e;
    fwrite(STDERR, '[DB] reconnect and retry delete fins summary: ' . $e->getMessage() . "\n");
    reconnectPdo($pdo);
    ensurePdoAlive($pdo);
    $pdo->exec('DELETE FROM jquants_fins_summary');
    echo "[INFO] jquants_fins_summary all rows deleted.\n";
  }
}

function upsertFinsSummaryRows(PDO $pdo, array $rows): int {
  $items = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $norm = normalizeFinsRowForDb($row);
    if ($norm === null) continue;
    $items[$norm['DiscNo']] = $norm;
  }

  $items = array_values($items);
  $count = count($items);
  if ($count === 0) return 0;

  $pdo->beginTransaction();
  try {
    $done = 0;
    for ($offset = 0; $offset < $count; $offset += SQL_CHUNK_ROWS) {
      $chunk = array_slice($items, $offset, SQL_CHUNK_ROWS);
      $stmt = $pdo->prepare(buildFinsUpsertSql(count($chunk)));
      $params = [];
      foreach ($chunk as $i => $row) {
        foreach (FINS_DB_COLUMNS as $col) {
          $params[':' . $col . $i] = $row[$col] ?? null;
        }
      }
      $stmt->execute($params);
      $done += count($chunk);
    }
    $pdo->commit();
    return $done;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw new RuntimeException('jquants_fins_summary upsert failed: ' . $e->getMessage(), 0, $e);
  }
}

function buildFinsUpsertSql(int $n): string {
  $columns = FINS_DB_COLUMNS;
  $colSql = implode(', ', $columns);

  $values = [];
  for ($i = 0; $i < $n; $i++) {
    $placeholders = [];
    foreach ($columns as $col) {
      $placeholders[] = ':' . $col . $i;
    }
    $values[] = '(' . implode(', ', $placeholders) . ')';
  }

  $updates = [];
  foreach ($columns as $col) {
    if ($col === 'DiscNo') continue;
    $updates[] = $col . ' = VALUES(' . $col . ')';
  }

  return 'INSERT INTO jquants_fins_summary (' . $colSql . ') VALUES ' .
    implode(",\n", $values) .
    ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
}

function normalizeFinsRowForDb(array $row): ?array {
  $out = [];
  foreach (FINS_DB_COLUMNS as $col) {
    $val = $row[$col] ?? null;

    // 2024/7/22以降の仕様名が長い場合の吸収。
    if ($col === 'SigChgInC' && ($val === null || $val === '') && isset($row['SignificantChangesInTheScopeOfConsolidation'])) {
      $val = $row['SignificantChangesInTheScopeOfConsolidation'];
    }

    if (in_array($col, FINS_DATE_COLUMNS, true)) {
      $out[$col] = nullableDate($val);
    } elseif (in_array($col, FINS_TIME_COLUMNS, true)) {
      $out[$col] = nullableTime($val);
    } elseif (in_array($col, FINS_STRING_COLUMNS, true)) {
      $out[$col] = nullableString($val);
    } else {
      $out[$col] = nullableDecimal($val);
    }
  }

  if (($out['DiscDate'] ?? null) === null ||
      ($out['DiscTime'] ?? null) === null ||
      ($out['Code'] ?? null) === null ||
      ($out['DiscNo'] ?? null) === null ||
      ($out['DocType'] ?? null) === null ||
      ($out['CurPerType'] ?? null) === null) {
    fwrite(STDERR, '[WARN] invalid fins row skipped: ' . json_encode([
      'DiscDate' => $row['DiscDate'] ?? null,
      'DiscTime' => $row['DiscTime'] ?? null,
      'Code' => $row['Code'] ?? null,
      'DiscNo' => $row['DiscNo'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    return null;
  }

  $out['Code'] = normalizeCode5((string)$out['Code']);
  return $out;
}

function fetchLatestFinsFromDb(PDO &$pdo, string $code5, string $targetISODate): ?array {
  try {
    ensurePdoAlive($pdo);
    $sql = "SELECT *
            FROM jquants_fins_summary
            WHERE Code = :code
              AND DiscDate <= :target_date
            ORDER BY DiscDate DESC, DiscTime DESC, DiscNo DESC
            LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':code' => normalizeCode5($code5),
      ':target_date' => $targetISODate,
    ]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
      return null;
    }
    foreach ($rows as $row) {
      if (hasUsableFinancialValues($row)) {
        return $row;
      }
    }

    // 全部値なしなら、一番新しいレコードを返す
    return $rows[0];
  } catch (Throwable $e) {
    if (!isReconnectableDbError($e)) throw $e;
    fwrite(STDERR, '[DB] reconnect and retry fetch fins: ' . $e->getMessage() . "\n");
    reconnectPdo($pdo);
    return fetchLatestFinsFromDb($pdo, $code5, $targetISODate);
  }
}

function fetchLatestFinsSharesFromDb(PDO &$pdo, string $code5, string $targetISODate): ?array {
  try {
    ensurePdoAlive($pdo);
    $sql = "SELECT *
            FROM jquants_fins_summary
            WHERE Code = :code
              AND DiscDate <= :target_date
            ORDER BY DiscDate DESC, DiscTime DESC, DiscNo DESC
            LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':code' => normalizeCode5($code5),
      ':target_date' => $targetISODate,
    ]);

    $rows = $stmt->fetchAll();
    if (!$rows) return null;

    foreach ($rows as $row) {
      if (hasUsableSharesValue($row)) {
        return $row;
      }
    }

    return $rows[0];
  } catch (Throwable $e) {
    if (!isReconnectableDbError($e)) throw $e;
    fwrite(STDERR, '[DB] reconnect and retry fetch fins shares: ' . $e->getMessage() . "\n");
    reconnectPdo($pdo);
    return fetchLatestFinsSharesFromDb($pdo, $code5, $targetISODate);
  }
}
function selectLatestFinsSharesRowFromArray(array $rows, string $code5, string $targetISODate): ?array {
  $candidates = [];
  $code5 = normalizeCode5($code5);

  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $code = normalizeCode5((string)($row['Code'] ?? ''));
    if ($code !== $code5) continue;

    try {
      $discDate = normalizeDateToIso((string)($row['DiscDate'] ?? ''));
    } catch (Throwable $e) {
      continue;
    }
    if ($discDate > $targetISODate) continue;

    $candidates[] = $row;
  }

  usort($candidates, function(array $a, array $b): int {
    return strcmp(finsSortKey($b), finsSortKey($a));
  });

  foreach ($candidates as $row) {
    if (hasUsableSharesValue($row)) {
      return $row;
    }
  }

  return $candidates[0] ?? null;
}

function fetchIndexPriceBarFromDb(PDO &$pdo, string $code4, string $dateISO): ?array {
  try {
    ensurePdoAlive($pdo);

    $sql = "SELECT asof_date, code, close, volume
            FROM prices_eod
            WHERE code = :code
              AND asof_date = :asof_date
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':code' => normalizeDbCode4($code4),
      ':asof_date' => $dateISO,
    ]);

    $row = $stmt->fetch();
    if (!is_array($row)) return null;

    return [
      'Date' => $row['asof_date'],
      'Code' => $row['code'],
      'AdjC' => $row['close'],
      'AdjVo' => $row['volume'],
    ];
  } catch (Throwable $e) {
    if (!isReconnectableDbError($e)) throw $e;
    fwrite(STDERR, '[DB] reconnect and retry fetch index price: ' . $e->getMessage() . "\n");
    reconnectPdo($pdo);
    return fetchIndexPriceBarFromDb($pdo, $code4, $dateISO);
  }
}

function fetchLatestPriceBarFromDb(PDO &$pdo, string $code4, string $targetISODate): ?array {
  try {
    ensurePdoAlive($pdo);

    $sql = "SELECT asof_date, code, close, volume
            FROM prices_eod
            WHERE code = :code
              AND asof_date <= :asof_date
              AND close IS NOT NULL
            ORDER BY asof_date DESC
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':code' => normalizeDbCode4($code4),
      ':asof_date' => $targetISODate,
    ]);

    $row = $stmt->fetch();
    if (!is_array($row)) return null;

    return [
      'Date' => $row['asof_date'],
      'Code' => $row['code'],
      'AdjC' => $row['close'],
      'AdjVo' => $row['volume'],
    ];
  } catch (Throwable $e) {
    if (!isReconnectableDbError($e)) throw $e;
    fwrite(STDERR, '[DB] reconnect and retry fetch latest price: ' . $e->getMessage() . "\n");
    reconnectPdo($pdo);
    return fetchLatestPriceBarFromDb($pdo, $code4, $targetISODate);
  }
}

function selectLatestFinsRowFromArray(array $rows, string $code5, string $targetISODate): ?array {
  $candidates = [];
  $code5 = normalizeCode5($code5);

  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $code = normalizeCode5((string)($row['Code'] ?? ''));
    if ($code !== $code5) continue;

    try {
      $discDate = normalizeDateToIso((string)($row['DiscDate'] ?? ''));
    } catch (Throwable $e) {
      continue;
    }
    if ($discDate > $targetISODate) continue;

     $candidates[] = $row;
  }

  usort($candidates, function(array $a, array $b): int {
    return strcmp(finsSortKey($b), finsSortKey($a));
  });

  foreach ($candidates as $row) {
    if (hasUsableFinancialValues($row)) {
      return $row;
    }
  }

  return $candidates[0] ?? null;
}

function finsSortKey(array $row): string {
  $date = '';
  try {
    $date = normalizeDateToIso((string)($row['DiscDate'] ?? ''));
  } catch (Throwable $e) {
    $date = (string)($row['DiscDate'] ?? '');
  }

  return $date . ' ' .
    (string)($row['DiscTime'] ?? '') . ' ' .
    (string)($row['DiscNo'] ?? '');
}

// =====================
// Google Sheets マスタ読み込み
// =====================
function loadCalendarMasterSheet(): array {
  $values = loadSpreadsheetValues(CALENDAR_MASTER_NAME);
  if (count($values) < 2) return [];

  $header = normalizeHeader($values[0]);
  $dateIdx = requireHeaderIndex($header, '日付', CALENDAR_MASTER_NAME);
  $holIdx = requireHeaderIndex($header, '日本市場休日区分', CALENDAR_MASTER_NAME);

  $out = [];
  for ($i = 1; $i < count($values); $i++) {
    $row = $values[$i];
    $date = nullableDate($row[$dateIdx] ?? null);
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
  $code5Idx = requireHeaderIndex($header, '証券コード5桁', SECURITY_CODE_MASTER_NAME);
  $nameIdx = requireHeaderIndex($header, '銘柄名', SECURITY_CODE_MASTER_NAME);
  $marketCodeIdx = requireHeaderIndex($header, '市場区分コード', SECURITY_CODE_MASTER_NAME);
  $marketNameIdx = requireHeaderIndex($header, '市場区分名', SECURITY_CODE_MASTER_NAME);
  $industry33Idx = requireHeaderIndex($header, '33業種コード名', SECURITY_CODE_MASTER_NAME);

  $out = [];
  $indexSkip = 0;
  $etfCount  = 0;
  $tpmSkip   = 0;
  for ($i = 1; $i < count($values); $i++) {
    $row = $values[$i];
    $codeRaw = trim((string)($row[$codeIdx] ?? ''));
    if ($codeRaw === '') break;

    $marketCode = trim((string)($row[$marketCodeIdx] ?? ''));
    
    $isIndex = ($marketCode === '-');
    $isEtfLike = ($marketCode === '109');

    if ($isIndex) {
      $indexSkip++;
    }
    if ($marketCode === '105') {
      $tpmSkip++;
      continue;
    }
    if ($isEtfLike) {
      $etfCount++;
    }

    $code4 = normalizeDbCode4($codeRaw);
    if ($code4 === '') continue;

    $code5 = normalizeCode5((string)($row[$code5Idx] ?? ''));
    if ($code5 === '') $code5 = normalizeCode5($code4);

    $out[] = [
      'code4' => $code4,
      'code5' => $code5,
      'name' => trim((string)($row[$nameIdx] ?? '')),
      'market_code' => $marketCode,
      'market_name' => $isIndex ? '指数' : trim((string)($row[$marketNameIdx] ?? '')),
      'industry33' => trim((string)($row[$industry33Idx] ?? '')),
      'is_index' => $isIndex,
      'is_etf_like' => $isEtfLike,
    ];
  }
  return [$out, $indexSkip, $etfCount, $tpmSkip];
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

// =====================
// 営業日・相場データ整形
// =====================
function findRecentBusinessDays(array $calendarRows, string $targetISODate, int $count): array {
  $businessDates = [];

  foreach ($calendarRows as $row) {
    $date = (string)($row['date'] ?? '');
    $holDiv = trim((string)($row['hol_div'] ?? ''));
    if ($date === '') continue;
    if (!in_array($holDiv, ['1', '2'], true)) continue;
    if ($date <= $targetISODate) $businessDates[$date] = true;
  }

  $dates = array_keys($businessDates);
  rsort($dates);

  if (count($dates) < $count) {
    throw new RuntimeException('カレンダーマスタから直近' . $count . '営業日を取得できません。');
  }

  return array_slice($dates, 0, $count);
}

function fetchRecentMarginInterestRows(array $calendarRows, string $currentBizISO, int $lookbackDays): array {
  $candidateDates = [];

  foreach ($calendarRows as $row) {
    $date = (string)($row['date'] ?? '');
    if ($date === '') continue;
    if ($date > $currentBizISO) continue;

    $diffDays = (int)((strtotime($currentBizISO) - strtotime($date)) / 86400);
    if ($diffDays < 0 || $diffDays > $lookbackDays) continue;

    $candidateDates[] = $date;
  }

  rsort($candidateDates);

  foreach ($candidateDates as $dateISO) {
    $dateYmd = isoToYmd($dateISO);
    echo "[API] margin-interest date={$dateYmd}\n";

    $rows = jquantsGetAll(JQUANTS_MARGIN_INTEREST_PATH, ['date' => $dateYmd]);
    $codes = count(buildMarginByCode($rows));

    echo "[INFO] margin candidate date={$dateISO} rows=" . count($rows) . " codes={$codes}\n";

    if (count($rows) > 0 && $codes > 0) {
      return [$rows, $dateISO];
    }
  }

  fwrite(STDERR, "[WARN] margin-interest rows=0 codes=0 lookbackDays={$lookbackDays}. 信用残は空欄で続行します。\n");
  return [[], null];
}

function buildBarsByCode(array $rows): array {
  $out = [];

  foreach ($rows as $row) {
    if (!is_array($row)) continue;

    $code5 = normalizeCode5((string)($row['Code'] ?? ''));
    if ($code5 === '') continue;

    $out[$code5] = $row;

    $code4 = normalizeDbCode4($code5);
    if ($code4 !== '') {
      $out[$code4] = $row;
    }
  }

  return $out;
}

function buildMarginByCode(array $rows): array {
  $out = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $code5 = normalizeCode5((string)($row['Code'] ?? ''));
    $code4 = normalizeDbCode4((string)($row['Code'] ?? ''));
    if ($code5 === '') continue;
    $out[$code5] = $row;
    if ($code4 !== '') $out[$code4] = $row;
  }
  return $out;
}

// =====================
// CSV/TXT 補助
// =====================
function writeCsvWithHeaders(string $csvPath, array $headers, array $rows): void {
  $fp = fopen($csvPath, 'wb');
  if (!$fp) throw new RuntimeException('CSV作成に失敗: ' . $csvPath);

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

function enforceOutputFormats(array &$row): void {
  foreach (['時価総額','売上高','経常益','最終益','PER','PBR','信用倍率'] as $key) {
    if (!isset($row[$key]) || $row[$key] === '') continue;
    if (isDashValue($row[$key])) {
      $row[$key] = CSV_DASH;
      continue;
    }
    $row[$key] = toFixed1Number($row[$key]);
  }

  foreach (['終値','前日比','騰落率','利回り'] as $key) {
    if (!isset($row[$key]) || $row[$key] === '') continue;
    if (isDashValue($row[$key])) {
      $row[$key] = CSV_DASH;
      continue;
    }
    $row[$key] = toFixed2Number($row[$key]);
  }

  foreach (['出来高','信用売り残','信用買い残'] as $key) {
    if (!isset($row[$key]) || $row[$key] === '') continue;
    if (isDashValue($row[$key])) {
      $row[$key] = CSV_DASH;
      continue;
    }
    $n = toFloatOrNull($row[$key]);
    $row[$key] = ($n === null) ? '' : (string)(int)round($n);
  }
  
  // 日付は YYYY-MM-DD 形式で統一
  foreach (['更新日', '信用日付'] as $key) {
    if (!isset($row[$key]) || $row[$key] === '' || isDashValue($row[$key])) {
      continue;
    }
    $row[$key] = normalizeDateToIso((string)$row[$key]);
  }
}

function applyDashByWarningsAndType(array &$row, array $warnings, array $master): void {
  $hasWarning = function(string $warning) use ($warnings): bool {
    return in_array($warning, $warnings, true);
  };

  if ($hasWarning('財務情報J-Quants登録なし')) {
    setDashValues($row, ['時価総額','売上高','経常益','最終益']);
  }

  if ($hasWarning('前営業日出来高なし')) {
    setDashValues($row, ['前日比','騰落率']);
  }

  if ($hasWarning('当営業日出来高なし')) {
    setDashValues($row, ['終値','前日比','騰落率','出来高']);
  }
  
  if ($hasWarning('代替終値なし')) {
    setDashValues($row, ['時価総額','PER','PBR','利回り']);
  }

  if ($hasWarning('信用残J-Quants登録なし')) {
    setDashValues($row, ['信用日付','信用売り残','信用買い残','信用倍率']);
  }

  if (!empty($master['is_index'])) {
    setDashValues($row, ['時価総額','売上高','経常益','最終益','信用日付','信用売り残','信用買い残','信用倍率']);
    if (!isset($row['出来高']) || $row['出来高'] === '') {
      $row['出来高'] = CSV_DASH;
    }
  }

  if (!empty($master['is_etf_like'])) {
    setDashValues($row, ['時価総額','売上高','経常益','最終益']);
  }
}

function setDashValues(array &$row, array $keys): void {
  foreach ($keys as $key) {
    $row[$key] = CSV_DASH;
  }
}

// =====================
// 変換ヘルパー
// =====================
function normalizeHeader(array $header): array {
  return array_map(function($v) {
    return trim((string)$v);
  }, $header);
}

function requireHeaderIndex(array $header, string $name, string $sheetName): int {
  $idx = array_search($name, $header, true);
  if ($idx === false) {
    throw new RuntimeException("{$sheetName} に「{$name}」列が見つかりません。");
  }
  return (int)$idx;
}

function ensureDir(string $dir): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
      throw new RuntimeException('mkdir failed: ' . $dir);
    }
  }
}

function normalizeDbCode4(string $code): string {
  $code = trim($code);
  $code = preg_replace('/\.0$/', '', $code);
  $code = preg_replace('/[^0-9A-Za-z]/', '', $code);
  if ($code === null || $code === '') return '';
  if (strlen($code) >= 5 && substr($code, -1) === '0') {
    return substr($code, 0, 4);
  }
  return substr($code, 0, 4);
}

function normalizeCode5(string $code): string {
  $code = trim($code);
  $code = preg_replace('/\.0$/', '', $code);
  $code = preg_replace('/[^0-9A-Za-z]/', '', $code);
  if ($code === null || $code === '') return '';
  $code = strtoupper($code);
  if (strlen($code) === 4) return $code . '0';
  if (strlen($code) >= 5) return substr($code, 0, 5);
  return str_pad($code, 5, '0', STR_PAD_LEFT);
}

function normalizeDateToIso(string $date): string {
  $date = trim($date);
  if ($date === '') throw new RuntimeException('date is empty');

  if (preg_match('/^\d{8}$/', $date)) {
    return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
  }
  if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $date)) {
    $dt = DateTime::createFromFormat('Y/m/d', $date);
    if ($dt instanceof DateTime) return $dt->format('Y-m-d');
  }
  if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $date)) {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt instanceof DateTime) return $dt->format('Y-m-d');
  }
  $ts = strtotime($date);
  if ($ts !== false) return date('Y-m-d', $ts);

  throw new RuntimeException('Invalid date: ' . $date);
}

function isoToYmd(string $iso): string {
  return str_replace('-', '', normalizeDateToIso($iso));
}

function nullableDate($raw): ?string {
  if ($raw === null) return null;
  $s = trim((string)$raw);
  if ($s === '' || isDashValue($s)) return null;
  try {
    return normalizeDateToIso($s);
  } catch (Throwable $e) {
    return null;
  }
}

function nullableTime($raw): ?string {
  if ($raw === null) return null;
  $s = trim((string)$raw);
  if ($s === '' || isDashValue($s)) return null;
  if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $s)) {
    $parts = explode(':', $s);
    return sprintf('%02d:%02d:%02d', (int)$parts[0], (int)$parts[1], (int)$parts[2]);
  }
  if (preg_match('/^\d{1,2}:\d{2}$/', $s)) {
    $parts = explode(':', $s);
    return sprintf('%02d:%02d:00', (int)$parts[0], (int)$parts[1]);
  }
  return null;
}

function nullableString($raw): ?string {
  if ($raw === null) return null;
  $s = trim((string)$raw);
  return $s === '' ? null : $s;
}

function nullableDecimal($raw): ?string {
  $n = toFloatOrNull($raw);
  if ($n === null) return null;
  return (string)$n;
}

function isDashValue($raw): bool {
  $s = trim((string)$raw);
  return $s === '-' || $s === '－' || $s === 'ー' || $s === CSV_DASH;
}

function toFloatOrNull($raw): ?float {
  if ($raw === null) return null;
  $s = trim((string)$raw);
  if ($s === '' || isDashValue($s)) return null;
  $s = str_replace(['&nbsp;', "\xC2\xA0"], '', $s);
  $s = preg_replace('/[\s　,]/u', '', $s);
  $s = preg_replace('/円|株|％|%/u', '', $s);
  if ($s === '' || !is_numeric($s)) return null;
  $n = (float)$s;
  return is_finite($n) ? $n : null;
}

function toFixed1Number($raw) {
  $n = toFloatOrNull($raw);
  if ($n === null) return '';
  return (float)number_format($n, 1, '.', '');
}

function toFixed2Number($raw) {
  $n = toFloatOrNull($raw);
  if ($n === null) return '';
  return (float)number_format($n, 2, '.', '');
}
function hasUsableFinancialValues(array $row): bool {

    return
      toFloatOrNull($row['Sales'] ?? null) !== null ||
      toFloatOrNull($row['OdP'] ?? null) !== null ||
      toFloatOrNull($row['NP'] ?? null) !== null;
}
function hasUsableSharesValue(array $row): bool {
  return toFloatOrNull($row['ShOutFY'] ?? null) !== null;
}