<?php
/**
 * 全銘柄日足整合性チェック（ログ付き）
 */

require __DIR__ . '/lib/scraping_common.php';

date_default_timezone_set('Asia/Tokyo');

// =============================
// 設定
// =============================
$JOB_NAME = '全銘柄日足整合性チェック';
const THRESHOLD_PCT = 35.0;
const LOG_PROGRESS_EVERY = 50; // ★ 50銘柄ごとに進捗表示

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'stocks';
const DB_USER = 'apiuser';
const DB_PASS = 'G&TgY7Ubq5weU365a6HgxGCshU&%75MKMun8m9kMAr3S&a'; // ★要変更

const MASTER_FOLDERS = ['投資','プログラミング','GAS','マスタ'];
const MASTER_FILE_NAME = '証券コードマスタ';
const HEADER_CODE = '証券コード';

// =============================
// 日付・出力
// =============================
$now = new DateTime('now');
$todayISO   = $now->format('Y-m-d');
$todaySlash = $now->format('Y/m/d');
$paths = build_output_paths($JOB_NAME, $todayISO);
$txtPath = $paths['txt'];

// =============================
// メイン
// =============================
try {
  echo "[START] {$JOB_NAME} {$now->format('Y-m-d H:i:s')}\n";

  // DB
  $pdo = buildPdo_();
  echo "[INFO] DB connected\n";

  // マスタ読み込み
  $master = loadMasterValues_();
  $totalRows = count($master) - 1;
  if ($totalRows <= 0) throw new RuntimeException("マスタが空です");

  echo "[INFO] master loaded: {$totalRows} rows\n";

  $header = array_map('trimStr_', $master[0]);
  if (!is_array($header) || count($header) === 0) {
    throw new RuntimeException("ヘッダ行が取得できませんでした（A:Zの1行目が空の可能性）");
  }

  $codeIdx = array_search(HEADER_CODE, $header, true);
  if ($codeIdx === false) {
    throw new RuntimeException("マスタに「" . HEADER_CODE . "」列が見つかりません。ヘッダ=" . implode(',', $header));
  }

  $nameIdx = array_search('銘柄名', $header, true);
  if ($nameIdx === false) $nameIdx = null;

  $targetCount = 0;
  $hits = [];

  for ($i = 1; $i < count($master); $i++) {
    $row = $master[$i];
    $code = isset($row[$codeIdx]) ? trim((string)$row[$codeIdx]) : '';
    if ($code === '') break;

    $targetCount++;

    // ★ 進捗ログ（出し過ぎない）
    if (($targetCount % LOG_PROGRESS_EVERY) === 0) {
      echo "[PROGRESS] {$targetCount} / {$totalRows}\n";
    }

    $name = '';
    if ($nameIdx !== null && isset($row[$nameIdx])) {
      $name = trim((string)$row[$nameIdx]);
    }

    $prices = fetchPricesDesc_($pdo, $code);
    if (count($prices) < 2) continue;
    // ★ SQLは「DB最新日の1つ前」
    $sqlDate = (string)$prices[1]['asof_date'];
    
    // ★ DB直近日の前日（営業日）
    $dbPrevDate  = $prices[1]['asof_date'];
    $dbPrevClose = (float)$prices[1]['close'];

    for ($j = 0; $j < count($prices) - 1; $j++) {
      $d1 = $prices[$j]['asof_date'];
      $c1 = $prices[$j]['close'];
      $d0 = $prices[$j + 1]['asof_date'];
      $c0 = $prices[$j + 1]['close'];

      if ($c1 === null || $c0 === null || (float)$c0 == 0.0) continue;

      $pct = abs(((float)$c1 - (float)$c0) / (float)$c0) * 100.0;
      
      $c1f = (float)$c1;
      $c0f = (float)$c0;

      if ($c0f == 0.0) continue;

      $pct = abs(($c1f - $c0f) / $c0f) * 100.0;
      
      
      $reasons = [];

      $isHit =
        ($pct >= THRESHOLD_PCT) &&
        ($c1f > 200.0) &&
        ($c1f < $c0f);

      if ($isHit) {
        echo sprintf(
          "[HIT] code=%s date=%s close=%s prev=%s change=%.2f%%\n",
          $code,
          $d1,
          formatNum_($c1f),
          formatNum_($c0f),
          $pct
        );

        $hits[] = [
          'code' => $code,
          'asof_date' => $d1,
          'close' => $c1f,
          'name' => $name,
          // ★ログ用：比較に使った前日
          'cmp_prev_date' => $d0,
          'cmp_prev_close' => $c0f,
          // ★SQL用：DB最新日の1つ前
          'sql_date' => $sqlDate,
          'pct' => $pct,
        ];
        
        // ★最初の1件で次の証券コードへ
        break;
      }
      
    }
  }

  // TXT出力
  $subjectLine = "{$JOB_NAME}：{$todaySlash}";
  $body  = "処理を終了しました。\n\n";
  $body .= "銘柄数: {$targetCount} 件\n";
  $body .= "検知件数: " . count($hits) . " 件\n\n";
  $body .= "(1) データをチェックで保持したデータの一覧\n";

  if (count($hits) === 0) {
    $body .= "（該当なし）\n";
  } else {
    foreach ($hits as $h) {
      $body .= sprintf(
        "%s, %s, %s | prev %s %s | %.2f%% | %s\n",
        $h['code'],
        $h['asof_date'],
        formatNum_($h['close']),
        $h['cmp_prev_date'],
        formatNum_($h['cmp_prev_close']),
        $h['pct'],
        $h['reason'] ?? ''
      );
    }
    
    // =============================
    // (2) close=0 UPDATE SQL
    // =============================
    $body .= "\n";
    $body .= "(2) close=0 UPDATE SQL（確認用・未実行）\n";

    if (count($hits) === 0) {
      $body .= "（該当なし）\n";
    } else {
      foreach ($hits as $h) {
        $prevDate = $h['sql_date'] ?? '';
        if ($prevDate === '') continue;

        $body .= sprintf(
          "update stocks.prices_eod set close = 0 where code = '%s' and asof_date = '%s';\n",
          $h['code'],
          $prevDate
        );
      }
    }
  }

  write_message_txt($txtPath, $subjectLine, $body);

  echo "[END] done. targets={$targetCount} hits=" . count($hits) . "\n";
  exit(0);

} catch (Throwable $e) {
  fwrite(STDERR, "[FATAL] " . $e->getMessage() . "\n");
  exit(1);
}
// =============================
// DB
// =============================
function buildPdo_(): PDO {
  $dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    DB_HOST,
    DB_PORT,
    DB_NAME
  );

  return new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}

function fetchPricesDesc_(PDO $pdo, string $code): array {
  $sql = "select asof_date, close
          from stocks.prices_eod
          where code = :code
          order by asof_date desc";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':code' => $code]);
  return $stmt->fetchAll();
}
// =============================
// Master Sheet 読み込み
// =============================
function loadMasterValues_(): array {
  require_once __DIR__ . '/vendor/autoload.php';

  // OAuth / Drive / Sheets クライアント
  $client = build_oauth_client_();   // scraping_common.php
  $drive  = new Google\Service\Drive($client);
  $sheets = new Google\Service\Sheets($client);

  // フォルダ探索
  $folderId = resolve_folder_id_by_path_($drive, MASTER_FOLDERS);

  // ファイル検索（証券コードマスタ）
  $q = sprintf(
    "name = '%s' and '%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.spreadsheet'",
    str_replace("'", "\\'", MASTER_FILE_NAME),
    $folderId
  );

  $res = $drive->files->listFiles([
    'q' => $q,
    'fields' => 'files(id,name)',
    'pageSize' => 1,
  ]);

  $files = $res->getFiles();
  if (!$files || count($files) === 0) {
    throw new RuntimeException('証券コードマスタが見つかりません');
  }

  $fileId = $files[0]->getId();

  // 1枚目のシート名取得
  $ss = $sheets->spreadsheets->get($fileId);
  $sheet0 = $ss->getSheets()[0] ?? null;
  if ($sheet0 === null) {
    throw new RuntimeException('スプレッドシートの取得に失敗しました');
  }

  $title = $sheet0->getProperties()->getTitle();

  // A:Z を一括取得
  $range = $title . '!A:Z';
  $resp = $sheets->spreadsheets_values->get($fileId, $range);

  return $resp->getValues() ?? [];
}
// =============================
// Utils
// =============================
function trimStr_($v): string {
  return trim((string)$v);
}
function formatNum_($v): string {
  if (!is_numeric($v)) return (string)$v;

  $f = (float)$v;
  // 整数っぽければ整数表示、小数なら余分な0を削る
  if (abs($f - round($f)) < 0.0000001) {
    return (string)(int)round($f);
  }
  return rtrim(rtrim(sprintf('%.6f', $f), '0'), '.');
}
