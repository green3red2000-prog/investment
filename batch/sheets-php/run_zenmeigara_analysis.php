<?php
declare(strict_types=1);

 /**
  * 全銘柄日足分析
  * - Google Drive/Sheets: OAuth（本人）で読み込み＆アップロード
  * - MariaDB: stocks.prices_eod から最大220本取得して分析
  * - ローカルCSV・メッセージTXT作成 → Driveへアップロード → ローカル削除
  *
  * 参考：GAS版の分析ロジック（computeAllMetrics_ 等）をPHP移植
  */

require __DIR__ . '/vendor/autoload.php';

/* =========================
 * 設定
 * ========================= */
const OAUTH_CLIENT_JSON = '/opt/invest/secrets/client_secret_347950769606-uhd9d68r6sfnokac0bovmi8a95brpanh.apps.googleusercontent.com.json';
const TOKEN_JSON        = '/opt/invest/secrets/token_default_scraping.json';

const TMP_DIR           = '/opt/invest/sheets-php/tmp';

const DRIVE_PATH_CODE_MASTER = ['投資','プログラミング','GAS','マスタ','証券コードマスタ'];
const DRIVE_PATH_BASEINFO    = ['投資','プログラミング','GAS','マスタ','全銘柄基本情報マスタ'];
const DRIVE_PATH_UPLOAD_OUT  = ['投資','プログラミング','GAS','スクレイピング','出力結果'];

const DB_DSN  = 'mysql:host=localhost;port=3306;dbname=stocks;charset=utf8mb4';
const DB_USER = 'apiuser';
const DB_PASS = 'G&TgY7Ubq5weU365a6HgxGCshU&%75MKMun8m9kMAr3S&a'; // ★ここを設定

// オシレーター標準パラメータ
const RSI_PERIOD  = 14;
const MACD_FAST   = 12;
const MACD_SLOW   = 26;
const MACD_SIGNAL = 9;

// 取得本数
const EOD_LIMIT = 220;

// データ不足判定
const MIN_REQUIRED_ROWS = 101; // 「100件以下ならエラー」なので、101以上を正常扱い

const TEST_LIMIT = 0; // ★テスト用。0なら全件処理

// =========================================================
// ★週足デバッグ出力（CSVには出さずログのみ）
// - 指定コードのみ、週足の直近N本を STDERR に出力
// =========================================================
const DEBUG_WEEKLY_CODES = ['4183','4011']; // ★対象コード
const DEBUG_WEEKLY_N     = 5;              // ★直近本数

// アップロード リトライ設定 ★
const UPLOAD_RETRY_MAX   = 3;     // 最大3回
const UPLOAD_RETRY_SLEEP = 360;   // 360秒待機

// =========================================================
// ★Sheets リトライ設定
// =========================================================
const SHEETS_RETRY_MAX  = 5;  // 最大リトライ回数
const SHEETS_RETRY_BASE = 3;  // ベース秒（指数バックオフ用）

/* =========================
 * メイン
 * ========================= */
main();

function main(): void {
  $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

  ensureDir(TMP_DIR);

  $outLocalPath = TMP_DIR . "/全銘柄日足分析_{$today}.csv";
  $outDriveName = "全銘柄日足分析_{$today}";
  $messageLocalPath = TMP_DIR . "/全銘柄日足分析_メッセージ_{$today}.txt";
  $messageDriveName = "全銘柄日足分析_メッセージ_{$today}.txt";
  
  $client = buildOAuthClient();
  $drive  = new Google\Service\Drive($client);
  $sheets = new Google\Service\Sheets($client);

  // 1) マスタ読み込み
  $codeMaster = openSpreadsheetByDrivePath($drive, DRIVE_PATH_CODE_MASTER);
  $codeMasterId = $codeMaster['id'];

  $baseInfo = openSpreadsheetByDrivePath($drive, DRIVE_PATH_BASEINFO);
  $baseInfoId = $baseInfo['id'];

  // 証券コード一覧（A列 2行目以降）
  $codes = readColumnByHeader($sheets, $codeMasterId, '証券コード');
  if (count($codes) === 0) {
    throw new RuntimeException("証券コードマスタにデータがありません（「証券コード」列の2行目以降が空）");
  }

  // 全銘柄基本情報マスタ（全範囲）
  [$baseInfoHeader, $baseInfoMap, $baseInfoExtraHeaders, $baseInfoExtraPos] = readBaseInfoMaster($sheets, $baseInfoId);


  // 2) DB接続 + TOPIX（0010）をループ外で取得
  $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  $topix = fetchEodRows($pdo, '0010', EOD_LIMIT);
  $topixCloseAsc = [];
  if (count($topix) >= MIN_REQUIRED_ROWS) {
    $topixCloseAsc = array_reverse(array_map(fn($r) => (float)$r['close'], $topix));
  }

  // 出力ヘッダ（計算列）
  $calcHeaders = buildCalcHeaders();

  // 出力ヘッダ（基本3列 + 計算列 + 基本情報追加列）
  $outHeaders = array_merge(
    ['証券コード','更新日','実行結果'],
    $calcHeaders,
    $baseInfoExtraHeaders,
    ['(98)信用買い残日数'] // ★追加（最終列）
  );

  // 2) ローカルCSV作成（見出し1行目）
  $fp = fopen($outLocalPath, 'wb');
  if (!$fp) throw new RuntimeException("CSV作成に失敗: {$outLocalPath}");
  // Excel互換を気にするならBOMを入れる選択もありますが、今回はUTF-8のまま出します。
  fputcsv($fp, $outHeaders);
  
  $processed = 0;

  // メッセージファイル出力用の集計値
  $targetCount = 0;
  $normalCount = 0;
  $errorCount  = 0;

  // 3) コードごと処理
  foreach ($codes as $code) {
    $code = trim((string)$code);
    if ($code === '') continue;
    
    $targetCount++;

    $rowBase = [
      '証券コード' => $code,
      '更新日'     => $today,
      '実行結果'   => '',
    ];

    try {
      $rows = fetchEodRows($pdo, $code, EOD_LIMIT);

      // open/high/low が null の場合 close に寄せる（SQL側ではなくPHP側で補正）
      $rows = normalizeOhlc($rows);

      if (count($rows) <= 100) {
        $rowBase['実行結果'] = 'エラー：データ不足(100件以下)';
        $outRow = buildOutputRow($rowBase, $calcHeaders, [], $baseInfoExtraHeaders, $baseInfoMap, $baseInfoExtraPos);
        fputcsv($fp, $outRow);
        $errorCount++;
        continue;
      }

      // ASC（古→新）配列を作る
      $asofAsc  = array_reverse(array_map(fn($r) => (string)$r['asof_date'], $rows)); // ★追加
      $openAsc  = array_reverse(array_map(fn($r) => (float)$r['open'],   $rows));
      $highAsc  = array_reverse(array_map(fn($r) => (float)$r['high'],   $rows));
      $lowAsc   = array_reverse(array_map(fn($r) => (float)$r['low'],    $rows));
      $closeAsc = array_reverse(array_map(fn($r) => (float)$r['close'],  $rows));
      $volAsc   = array_reverse(array_map(fn($r) => (float)$r['volume'], $rows));

      // ===== 分析ロジック（GAS移植）=====
      $metrics = computeAllMetrics([
        'asofAsc'       => $asofAsc, // ★追加
        'openAsc'       => $openAsc,
        'highAsc'       => $highAsc,
        'lowAsc'        => $lowAsc,
        'closeAsc'      => $closeAsc,
        'volAsc'        => $volAsc,
        'topixCloseAsc' => $topixCloseAsc,
      ]);
      
      // ★週足デバッグ（指定コードのみ、STDERRに出力。CSVは触らない）
      if (in_array($code, DEBUG_WEEKLY_CODES, true)) {
        debugLogWeekly_($code, $today, $asofAsc, $openAsc, $highAsc, $lowAsc, $closeAsc, $volAsc, DEBUG_WEEKLY_N);
      }

      $rowBase['実行結果'] = '正常終了';

      $outRow = buildOutputRow($rowBase, $calcHeaders, $metrics, $baseInfoExtraHeaders, $baseInfoMap, $baseInfoExtraPos);
      fputcsv($fp, $outRow);
      
      $normalCount++;
      $processed++;
      echo "processed {$processed} codes.\n";
      if (TEST_LIMIT > 0 && $processed >= TEST_LIMIT) {
        echo "TEST_MODE: processed {$processed} codes, stop early.\n";
        break;
      }
    } catch (DivisionByZeroError $e) {
      $rowBase['実行結果'] = 'エラー：ゼロ除算';
      $outRow = buildOutputRow(
        $rowBase,
        $calcHeaders,
        [],
        $baseInfoExtraHeaders,
        $baseInfoMap,
        $baseInfoExtraPos
      );
      fputcsv($fp, $outRow);
      $errorCount++;
      fwrite(STDERR, "[ZERO_DIVISION] code={$code} {$e->getMessage()}\n");
    } catch (Throwable $e) {
      $rowBase['実行結果'] = 'エラー：その他';
      $outRow = buildOutputRow($rowBase, $calcHeaders, [], $baseInfoExtraHeaders, $baseInfoMap, $baseInfoExtraPos);
      $errorCount++;
      fputcsv($fp, $outRow);
      // 必要ならログ
      fwrite(STDERR, "[ERR] code={$code} {$e->getMessage()}\n");
    }
  }

  fclose($fp);

  // 7) テキストファイル出力
  $messageText =
    "全銘柄日足分析：{$today}\n\n" .
    "処理を終了しました。\n\n" .
    "銘柄数: {$targetCount} 件\n" .
    "正常終了: {$normalCount} 件\n" .
    "エラー : {$errorCount} 件\n";

  $result = file_put_contents(
    $messageLocalPath,
    $messageText
  );

  if ($result === false) {
    throw new RuntimeException(
      "メッセージファイル作成に失敗: {$messageLocalPath}"
    );
  }

  // 8) ファイルのアップロードと削除
  $uploadFolderId = resolveFolderIdByPath($drive, DRIVE_PATH_UPLOAD_OUT);

  // CSVをGoogleスプレッドシート形式でアップロード
  $created = uploadCsvAsGoogleSheetWithRetry(
    $drive,
    $outLocalPath,
    $outDriveName,
    $uploadFolderId
  );

  echo
    "Created Google Sheet: " .
    "{$created->getName()} ({$created->getId()})\n";

  // CSVのアップロード完了後、メッセージTXTをアップロード
  $messageCreated = uploadTextFileWithRetry(
    $drive,
    $messageLocalPath,
    $messageDriveName,
    $uploadFolderId
  );

  echo
    "Created message file: " .
    "{$messageCreated->getName()} " .
    "({$messageCreated->getId()})\n";

  // 両方のアップロードが正常終了した場合のみ削除
  if (!unlink($messageLocalPath)) {
    throw new RuntimeException(
      "メッセージファイル削除に失敗: {$messageLocalPath}"
    );
  }

  if (!unlink($outLocalPath)) {
    throw new RuntimeException(
      "CSVファイル削除に失敗: {$outLocalPath}"
    );
  }

  echo
    "DONE: uploaded to Drive folder (" .
    implode('/', DRIVE_PATH_UPLOAD_OUT) .
    ")\n";
}

/* =========================================================
 * OAuth
 * ========================================================= */
function buildOAuthClient(): Google\Client {
  if (!file_exists(OAUTH_CLIENT_JSON)) {
    throw new RuntimeException("OAuth client json not found: " . OAUTH_CLIENT_JSON);
  }

  $client = new Google\Client();
  $client->setApplicationName('invest-sheets-php');
  $client->setAuthConfig(OAUTH_CLIENT_JSON);

  $client->setScopes([
    Google\Service\Drive::DRIVE,
    Google\Service\Sheets::SPREADSHEETS_READONLY,
  ]);

  // refresh_token を取得するために必須
  $client->setAccessType('offline');
  $client->setPrompt('select_account consent');

  // ※古いOOBは使えない環境が増えているので、可能なら下の行は削除推奨
  // どうしても現状維持なら残してOK（ただし環境によっては認可が失敗します）
  $client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');

  // 既存トークン読み込み（refresh_token保持用に配列も確保）
  $oldToken = [];
  if (file_exists(TOKEN_JSON)) {
    $oldToken = json_decode((string)file_get_contents(TOKEN_JSON), true) ?: [];
    // setAccessToken は配列 or JSON文字列どちらでもOK
    $client->setAccessToken($oldToken);
  }

  // 期限切れなら更新（refresh_token があれば黙って更新）
  if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
      // refresh 実行（戻り値には refresh_token が含まれないことが多い）
      $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
      if (isset($newToken['error'])) {
        throw new RuntimeException('OAuth refresh error: ' . ($newToken['error_description'] ?? $newToken['error']));
      }
      $client->setAccessToken($newToken);

    } else {
      // refresh_token が無い＝初回 or tokenが壊れた/消えた → 再認可
      $authUrl = $client->createAuthUrl();
      echo "1) Open this URL in your browser:\n{$authUrl}\n\n";
      echo "2) Approve access, then paste the verification code here: ";
      $authCode = trim((string)fgets(STDIN));

      $newToken = $client->fetchAccessTokenWithAuthCode($authCode);
      if (isset($newToken['error'])) {
        throw new RuntimeException('OAuth auth_code error: ' . ($newToken['error_description'] ?? $newToken['error']));
      }
      $client->setAccessToken($newToken);
    }

    // ===== ここが重要：refresh_token を消さずに保存する =====
    $saveToken = $client->getAccessToken();

    // refresh_token が返ってこない更新パターンがあるので、旧トークンから補完
    if (empty($saveToken['refresh_token']) && !empty($oldToken['refresh_token'])) {
      $saveToken['refresh_token'] = $oldToken['refresh_token'];
    }

    ensureDir(dirname(TOKEN_JSON));
    file_put_contents(TOKEN_JSON, json_encode($saveToken, JSON_UNESCAPED_SLASHES));
  }

  return $client;
}

/* =========================================================
 * Drive / Sheets（マスタ読み込み）
 * ========================================================= */
function openSpreadsheetByDrivePath(Google\Service\Drive $drive, array $path): array {
  // path: [...folders..., fileName]
  if (count($path) < 1) throw new RuntimeException("invalid path");
  $fileName = array_pop($path);
  $parentId = resolveFolderIdByPath($drive, $path);

  $q = sprintf(
    "name = '%s' and '%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.spreadsheet'",
    str_replace("'", "\\'", $fileName),
    $parentId
  );

  $res = $drive->files->listFiles([
    'q' => $q,
    'fields' => 'files(id,name)',
    'pageSize' => 10
  ]);

  $files = $res->getFiles();
  if (!$files || count($files) === 0) {
    throw new RuntimeException("Spreadsheet not found: " . implode('/', array_merge($path, [$fileName])));
  }

  return ['id' => $files[0]->getId(), 'name' => $files[0]->getName()];
}

function resolveFolderIdByPath(Google\Service\Drive $drive, array $folders): string {
  // MyDrive root = 'root'
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
/* =========================================================
 * ★Sheets 読み取りリトライ
 * ========================================================= */
function sheetsGetWithRetry(callable $fn) {
  $lastErr = null;

  for ($try = 1; $try <= SHEETS_RETRY_MAX; $try++) {
    try {
      return $fn();

    } catch (Google\Service\Exception $e) {
      $lastErr = $e;
      $code = (int)$e->getCode();
      $msg  = $e->getMessage();

      $retryable = (
        $code === 500 ||
        $code === 503 ||
        $code === 429 ||
        stripos($msg, 'timeout') !== false ||
        stripos($msg, 'timed out') !== false
      );

      if ($retryable && $try < SHEETS_RETRY_MAX) {
        $sleep = pow(2, $try) * SHEETS_RETRY_BASE;
        fwrite(STDERR, "[SHEETS][retry {$try}/" . SHEETS_RETRY_MAX . "] code={$code} sleep {$sleep}s\n");
        sleep($sleep);
        continue;
      }

      throw $e;

    } catch (Throwable $e) {
      $lastErr = $e;
      $msg = $e->getMessage();

      $retryable = (
        stripos($msg, 'timeout') !== false ||
        stripos($msg, 'timed out') !== false
      );

      if ($retryable && $try < SHEETS_RETRY_MAX) {
        $sleep = pow(2, $try) * SHEETS_RETRY_BASE;
        fwrite(STDERR, "[SHEETS][retry {$try}/" . SHEETS_RETRY_MAX . "] timeout sleep {$sleep}s\n");
        sleep($sleep);
        continue;
      }

      throw $e;
    }
  }

  // 念のため（通常ここには来ない）
  if ($lastErr) throw $lastErr;
}

/**
 * 先頭シートの「指定ヘッダ列」を 2行目以降で読み込む（列順変更・列追加に強い）
 */
function readColumnByHeader(Google\Service\Sheets $sheets, string $spreadsheetId, string $headerName): array {
  $meta = sheetsGetWithRetry(fn() =>
    $sheets->spreadsheets->get($spreadsheetId)
  );
  $sheetName = $meta->getSheets()[0]->getProperties()->getTitle();
  $safeSheet = "'" . str_replace("'", "''", $sheetName) . "'";

  // 1行目（ヘッダ）を取得（広めに読む）
  $headerRange = "{$safeSheet}!A1:ZZ1";
  $resp = sheetsGetWithRetry(fn() =>
    $sheets->spreadsheets_values->get($spreadsheetId, $headerRange)
  );
  $vals = $resp->getValues() ?? [];
  $headers = $vals[0] ?? [];

  $targetIdx = null;
  foreach ($headers as $i => $h) {
    if (trim((string)$h) === $headerName) { $targetIdx = (int)$i; break; }
  }
  if ($targetIdx === null) {
    throw new RuntimeException("証券コードマスタのヘッダに「{$headerName}」列が見つかりません");
  }

  $col = indexToColumnLetter($targetIdx);
  $range = "{$safeSheet}!{$col}2:{$col}";
  $resp2 = sheetsGetWithRetry(fn() =>
    $sheets->spreadsheets_values->get($spreadsheetId, $range)
  );
  $vals2 = $resp2->getValues() ?? [];

  $out = [];
  foreach ($vals2 as $row) {
    if (!isset($row[0])) continue;
    $out[] = $row[0];
  }
  return $out;
}

/** 0-based列番号 → A1表記の列文字（A, B, ..., Z, AA, AB, ...） */
function indexToColumnLetter(int $idx0): string {
  if ($idx0 < 0) throw new InvalidArgumentException("idx0 must be >= 0");
  $n = $idx0 + 1; // 1-based
  $s = '';
  while ($n > 0) {
    $n--; // 0..25
    $s = chr(($n % 26) + 65) . $s;
    $n = intdiv($n, 26);
  }
  return $s;
}

function readBaseInfoMaster(Google\Service\Sheets $sheets, string $spreadsheetId): array {
  // 全範囲読み（先頭シート）
  $meta = sheetsGetWithRetry(fn() =>
    $sheets->spreadsheets->get($spreadsheetId)
  );
  $sheetName = $meta->getSheets()[0]->getProperties()->getTitle();

  // ざっくりA1:ZZ（必要なら拡張）
  $range = "'" . str_replace("'", "''", $sheetName) . "'!A1:ZZ";
  $resp = sheetsGetWithRetry(fn() =>
    $sheets->spreadsheets_values->get($spreadsheetId, $range)
  );
  $values = $resp->getValues() ?? [];
  if (count($values) < 1) {
    return [[], [], [], []];
  }

  $header = array_map(fn($x) => trim((string)$x), $values[0]);

  // 「証券コード」「更新日」「実行結果」以外の列を全て追加
  $skip = ['証券コード','更新日','実行結果'];
  $extraIdx = [];
  $extraHeaders = [];
  foreach ($header as $i => $h) {
    if ($h === '') continue;
    if (in_array($h, $skip, true)) continue;
    $extraIdx[] = $i;
    $extraHeaders[] = $h;
  }

  // code => extraValues
  $map = [];
  for ($r=1; $r<count($values); $r++) {
    $row = $values[$r];
    $code = $row[0] ?? '';
    $code = trim((string)$code);
    if ($code === '') continue;

    $extras = [];
    foreach ($extraIdx as $i) {
      $extras[] = $row[$i] ?? '';
    }
    $map[$code] = $extras;
  }

  $extraPos = [];
  foreach ($extraHeaders as $i => $h) {
    $extraPos[$h] = $i; // extras配列内の位置
  }

  return [$header, $map, $extraHeaders, $extraPos]; // ★追加

}

/* =========================================================
 * Drive アップロード
 * ========================================================= */
function uploadCsvAsGoogleSheet(
  Google\Service\Drive $drive,
  string $localCsvPath,
  string $sheetName,
  string $folderId
): Google\Service\Drive\DriveFile {
  if (!file_exists($localCsvPath)) {
    throw new RuntimeException("local file not found: {$localCsvPath}");
  }

  $fileMeta = new Google\Service\Drive\DriveFile([
    'name' => $sheetName,
    'parents' => [$folderId],
    // ★ここがポイント：作成されるファイルは「Googleスプレッドシート」
    'mimeType' => 'application/vnd.google-apps.spreadsheet',
  ]);

  $content = file_get_contents($localCsvPath);

  // ★送る実体はCSV（Driveが変換してSheetsとして保存する）
  return $drive->files->create($fileMeta, [
    'data' => $content,
    'mimeType' => 'text/csv',
    'uploadType' => 'multipart',
    'fields' => 'id,name,mimeType,parents',
  ]);
}
/**
 * テキストファイルを通常のDriveファイルとしてアップロードする。
 */
function uploadTextFile(
  Google\Service\Drive $drive,
  string $localTextPath,
  string $driveFileName,
  string $folderId
): Google\Service\Drive\DriveFile {
  if (!file_exists($localTextPath)) {
    throw new RuntimeException(
      "local file not found: {$localTextPath}"
    );
  }

  $fileMeta = new Google\Service\Drive\DriveFile([
    'name' => $driveFileName,
    'parents' => [$folderId],
  ]);

  $content = file_get_contents($localTextPath);

  if ($content === false) {
    throw new RuntimeException(
      "テキストファイル読み込みに失敗: {$localTextPath}"
    );
  }

  return $drive->files->create($fileMeta, [
    'data' => $content,
    'mimeType' => 'text/plain',
    'uploadType' => 'multipart',
    'fields' => 'id,name,mimeType,parents',
  ]);
}
function uploadCsvAsGoogleSheetWithRetry(
  Google\Service\Drive $drive,
  string $localCsvPath,
  string $sheetName,
  string $folderId
): Google\Service\Drive\DriveFile {

  $lastErr = null;

  for ($try = 1; $try <= UPLOAD_RETRY_MAX; $try++) {
    try {
      return uploadCsvAsGoogleSheet($drive, $localCsvPath, $sheetName, $folderId);

    } catch (Google\Service\Exception $e) {
      $lastErr = $e;
      $code = (int)$e->getCode();
      $msg  = $e->getMessage();

      $retryable = ($code === 503 || $code === 429 || stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false);

      if ($retryable && $try < UPLOAD_RETRY_MAX) {
        fwrite(STDERR, "[UPLOAD][retry {$try}/" . UPLOAD_RETRY_MAX . "] retryable error (code={$code}). sleep " . UPLOAD_RETRY_SLEEP . "s\n");
        sleep(UPLOAD_RETRY_SLEEP);
        continue;
      }

      break;

    } catch (Throwable $e) {
      // Google\Service\Exception 以外の "timeoutっぽい" 例外も拾う（環境差対策）
      $lastErr = $e;
      $msg = $e->getMessage();
      $retryable = (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false);

      if ($retryable && $try < UPLOAD_RETRY_MAX) {
        fwrite(STDERR, "[UPLOAD][retry {$try}/" . UPLOAD_RETRY_MAX . "] timeout-like error. sleep " . UPLOAD_RETRY_SLEEP . "s\n");
        sleep(UPLOAD_RETRY_SLEEP);
        continue;
      }

      break;
    }
  }

  // ★3回失敗したら指定ログを出して終了
  fwrite(STDERR, "Google側（Drive/Sheets API）の一時的なバックエンド障害／過負荷のため終了\n");
  if ($lastErr) {
    fwrite(STDERR, "[UPLOAD][FAILED] " . $lastErr->getMessage() . "\n");
  }
  exit(1);
}

/**
 * テキストファイルをリトライ付きでアップロードする。
 */
function uploadTextFileWithRetry(
  Google\Service\Drive $drive,
  string $localTextPath,
  string $driveFileName,
  string $folderId
): Google\Service\Drive\DriveFile {
  $lastErr = null;

  for ($try = 1; $try <= UPLOAD_RETRY_MAX; $try++) {
    try {
      return uploadTextFile(
        $drive,
        $localTextPath,
        $driveFileName,
        $folderId
      );

    } catch (Google\Service\Exception $e) {
      $lastErr = $e;
      $code = (int)$e->getCode();
      $msg  = $e->getMessage();

      $retryable =
        $code === 503 ||
        $code === 429 ||
        stripos($msg, 'timed out') !== false ||
        stripos($msg, 'timeout') !== false;

      if ($retryable && $try < UPLOAD_RETRY_MAX) {
        fwrite(
          STDERR,
          "[UPLOAD][retry {$try}/" .
          UPLOAD_RETRY_MAX .
          "] retryable error " .
          "(code={$code}). sleep " .
          UPLOAD_RETRY_SLEEP .
          "s\n"
        );

        sleep(UPLOAD_RETRY_SLEEP);
        continue;
      }

      break;

    } catch (Throwable $e) {
      $lastErr = $e;
      $msg = $e->getMessage();

      $retryable =
        stripos($msg, 'timed out') !== false ||
        stripos($msg, 'timeout') !== false;

      if ($retryable && $try < UPLOAD_RETRY_MAX) {
        fwrite(
          STDERR,
          "[UPLOAD][retry {$try}/" .
          UPLOAD_RETRY_MAX .
          "] timeout-like error. sleep " .
          UPLOAD_RETRY_SLEEP .
          "s\n"
        );

        sleep(UPLOAD_RETRY_SLEEP);
        continue;
      }

      break;
    }
  }

  fwrite(
    STDERR,
    "Google側（Drive/Sheets API）の" .
    "一時的なバックエンド障害／過負荷のため終了\n"
  );

  if ($lastErr) {
    fwrite(
      STDERR,
      "[UPLOAD][FAILED] " .
      $lastErr->getMessage() .
      "\n"
    );
  }

  exit(1);
}
/* =========================================================
 * DB
 * ========================================================= */
function fetchEodRows(PDO $pdo, string $code, int $limit): array {
  $sql = "
    SELECT asof_date, open, high, low, close, volume
    FROM stocks.prices_eod
    WHERE code = :code
    ORDER BY asof_date DESC
    LIMIT {$limit}
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':code' => $code]);
  return $st->fetchAll();
}

function normalizeOhlc(array $rows): array {
  foreach ($rows as &$r) {
    $close = $r['close'];
    if ($r['open'] === null) $r['open'] = $close;
    if ($r['high'] === null) $r['high'] = $close;
    if ($r['low']  === null) $r['low']  = $close;
    if ($r['volume'] === null) $r['volume'] = 0;
  }
  unset($r);
  return $rows;
}

/* =========================================================
 * 出力ヘッダ（仕様の文言そのまま）
 * ========================================================= */
function buildCalcHeaders(): array {
  // 仕様の番号・文言どおり（GAS buildCalcHeaders_ 準拠）
  return [
    '(0)直近の日付',
    '(1)直近の始値','(2)直近の高値','(3)直近の安値','(4)直近の終値','(5)直近の出来高',
    '(6)直近の終値の前日比','(7)直近の終値の前日比率','(8)直近の出来高の前日比','(9)直近の出来高の前日比率',
    '(10)終値の直近5日間の回帰係数','(11)終値の直近10日間の回帰係数','(12)終値の直近22日間の回帰係数','(13)終値の直近66日間の回帰係数','(14)終値の直近132日間の回帰係数','(105)終値の直近200日間の回帰係数',
    '(15)出来高の直近5日間の回帰係数','(16)出来高の直近10日間の回帰係数','(17)出来高の直近22日間の回帰係数','(18)出来高の直近66日間の回帰係数','(19)出来高の直近132日間の回帰係数','(106)出来高の直近200日間の回帰係数',
    '(20)終値5日移動平均','(21)終値10日移動平均','(22)終値22日移動平均','(23)終値66日移動平均','(24)終値132日移動平均','(107)終値200日移動平均',
    '(25)出来高5日移動平均','(26)出来高10日移動平均','(27)出来高22日移動平均','(28)出来高66日移動平均','(29)出来高132日移動平均','(108)出来高200日移動平均',
    '(30)終値5日移動平均の直近5日の回帰係数','(31)終値10日移動平均の直近10日の回帰係数','(32)終値22日移動平均の直近10日の回帰係数','(33)終値66日移動平均の直近10日の回帰係数','(34)終値132日移動平均の直近10日の回帰係数','(109)終値200日移動平均の直近10日の回帰係数',
    '(35)出来高5日移動平均の直近5日の回帰係数','(36)出来高10日移動平均の直近10日の回帰係数','(37)出来高22日移動平均の直近10日の回帰係数','(38)出来高66日移動平均の直近10日の回帰係数','(39)出来高132日移動平均の直近10日の回帰係数','(110)出来高200日移動平均の直近10日の回帰係数',
    '(40)200日分の高値-安値の一日の値幅平均','(41)直近5日間の高値-安値の値幅のボラティリティ','(42)直近10日間の高値-安値の値幅のボラティリティ','(43)直近22日間の高値-安値の値幅のボラティリティ','(44)直近66日間の高値-安値の値幅のボラティリティ','(45)直近132日間の高値-安値の値幅のボラティリティ','(111)直近200日間の高値-安値の値幅のボラティリティ',
    '(46)直近5日間の高値-安値の値幅のボラティリティの直近5日間の回帰係数','(47)直近10日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数','(48)直近22日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数','(49)直近66日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数','(50)直近132日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数','(112)直近200日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数',
    '(51)終値5日移動平均と終値の移動平均乖離率','(52)終値10日移動平均と終値の移動平均乖離率','(53)終値22日移動平均と終値の移動平均乖離率','(55)終値66日移動平均と終値の移動平均乖離率','(56)終値132日移動平均と終値の移動平均乖離率','(113)終値200日移動平均と終値の移動平均乖離率',
    '(57)出来高5日移動平均と出来高の移動平均乖離率','(58)出来高10日移動平均と出来高の移動平均乖離率','(59)出来高22日移動平均と出来高の移動平均乖離率','(60)出来高66日移動平均と出来高の移動平均乖離率','(61)出来高132日移動平均と出来高の移動平均乖離率','(114)出来高200日移動平均と出来高の移動平均乖離率',
    '(62)終値5日移動平均の移動平均乖離率の直近5日間の回帰係数','(63)終値10日移動平均の移動平均乖離率の直近10日間の回帰係数','(64)終値22日移動平均の移動平均乖離率の直近10日間の回帰係数','(65)終値66日移動平均の移動平均乖離率の直近10日間の回帰係数','(66)終値132日移動平均の移動平均乖離率の直近10日間の回帰係数','(115)終値200日移動平均の移動平均乖離率の直近10日間の回帰係数',
    '(67)出来高5日移動平均の移動平均乖離率の直近5日間の回帰係数','(68)出来高10日移動平均の移動平均乖離率の直近10日間の回帰係数','(69)出来高22日移動平均の移動平均乖離率の直近10日間の回帰係数','(70)出来高66日移動平均の移動平均乖離率の直近10日間の回帰係数','(71)出来高132日移動平均の移動平均乖離率の直近10日間の回帰係数','(116)出来高200日移動平均の移動平均乖離率の直近10日間の回帰係数',
    '(72)β','(73)相関','(74)相対ボラ','(75)残差ボラ','(76)アップサイドβ','(77)ダウンサイドβ','(78)Up Capture','(79)Down Capture',
    '(80)RSI','(81)RSIの直近22日間の回帰係数','(82)MACD','(83)MACDの直近22日間の回帰係数',
    '(84)連続日数','(85)5日間上昇率','(86)10日間上昇率','(88)22日間上昇率','(89)5日間下落率','(90)10日間下落率','(91)22日間下落率',
    '(92)低ボラ出来高増','(93)水平ライン上突破','(94)水平ライン下突破','(95)GUPから全モ','(96)AI基準判定','(97)タイプ分類',
    '(99)直近5日間の値幅不安定率','(100)直近10日間の値幅不安定率','(101)直近22日間の値幅不安定率','(102)直近66日間の値幅不安定率','(103)直近132日間の値幅不安定率','(104)パーフェクトオーダー判定',
    // ===== ★週足/追加オシレーター =====
    '(117)直近の週足の日付',
    '(118)直近の週足の始値',
    '(119)直近の週足の高値',
    '(120)直近の週足の安値',
    '(121)直近の週足の終値',
    '(122)直近の週足の出来高',
    '(123)直近の週足の終値の前週比',
    '(124)直近の週足の終値の前週比率',
    '(125)直近の週足の出来高の前週比',
    '(126)直近の週足の出来高の前週比率',
    '(127)RSIの直近5日間の回帰係数',
    '(128)週足RSI',
    '(129)週足RSIの直近5週間の回帰係数',
    '(130)ストキャスティクス%K',
    '(131)ストキャスティクス%D',
    '(132)週足ストキャスティクス%K',
    '(133)週足ストキャスティクス%D',
    '(134)ストキャスティクス%Kの直近5日間の回帰係数',
    '(135)ストキャスティクス%Dの直近5日間の回帰係数',
    '(136)週足ストキャスティクス%Kの直近5週間の回帰係数',
    '(137)週足ストキャスティクス%Dの直近5週間の回帰係数',
    '(138)ボリンジャーバンドのσ値',
  ];
}

/* =========================================================
 * CSV 1行の組み立て
 * ========================================================= */
function buildOutputRow(
  array $rowBase,
  array $calcHeaders,
  array $metrics,
  array $baseInfoExtraHeaders,
  array $baseInfoMap,
  array $baseInfoExtraPos // ★追加
): array {

  $code = (string)$rowBase['証券コード'];

  $out = [];
  $out[] = $rowBase['証券コード'];
  $out[] = $rowBase['更新日'];
  $out[] = $rowBase['実行結果'];

  // 計算列
  foreach ($calcHeaders as $h) {
    $out[] = $metrics[$h] ?? '';
  }

  // 基本情報追加列（見出し順に揃える）
  if (isset($baseInfoMap[$code])) {
    foreach ($baseInfoMap[$code] as $v) $out[] = $v;
  } else {
    // 無ければ空
    foreach ($baseInfoExtraHeaders as $_) $out[] = '';
  }
  
  // (98)信用買い残日数 = 「信用買い残」 / 「(26)出来高10日移動平均」
  $shinyoDays = '';
  $volMa10 = toFloatOrNull($metrics['(26)出来高10日移動平均'] ?? null);

  $zandaka = null;
  if (isset($baseInfoMap[$code]) && isset($baseInfoExtraPos['信用買い残'])) {
    $pos = $baseInfoExtraPos['信用買い残'];
    $zandaka = toFloatOrNull($baseInfoMap[$code][$pos] ?? null);
  }

  if ($zandaka !== null && $volMa10 !== null && $volMa10 > 0) {
     $shinyoDays = $zandaka / $volMa10;
  }

  $out[] = $shinyoDays; // ★最終列

  return $out;
}

/* =========================================================
 * 分析ロジック（GAS移植の中核）
 * ========================================================= */
function computeAllMetrics(array $in): array {
  $openAsc  = $in['openAsc'];
  $highAsc  = $in['highAsc'];
  $lowAsc   = $in['lowAsc'];
  $closeAsc = $in['closeAsc'];
  $volAsc   = $in['volAsc'];
  $topixCloseAsc = $in['topixCloseAsc'];
  $asofAsc = $in['asofAsc'] ?? null; // ★追加（古→新）

  $out = [];
  $n = count($closeAsc);
  
  // ★(0)直近の日付：DBから取得した最も直近日付（YYYY-MM-DD想定）
  $out['(0)直近の日付'] = (is_array($asofAsc) && count($asofAsc) > 0) ? (string)$asofAsc[$n-1] : '';

  $lastOpen  = $openAsc[$n-1];
  $lastHigh  = $highAsc[$n-1];
  $lastLow   = $lowAsc[$n-1];
  $lastClose = $closeAsc[$n-1];
  $lastVol   = $volAsc[$n-1];

  $prevClose = ($n>=2) ? $closeAsc[$n-2] : null;
  $prevVol   = ($n>=2) ? $volAsc[$n-2]   : null;

  $out['(1)直近の始値'] = $lastOpen;
  $out['(2)直近の高値'] = $lastHigh;
  $out['(3)直近の安値'] = $lastLow;
  $out['(4)直近の終値'] = $lastClose;
  $out['(5)直近の出来高'] = $lastVol;

  $out['(6)直近の終値の前日比'] = is_finite_num($prevClose) ? ($lastClose - $prevClose) : '';
  $out['(7)直近の終値の前日比率'] = (is_finite_num($prevClose) && $prevClose != 0.0) ? (($lastClose/$prevClose)-1.0)*100.0 : '';
  $out['(8)直近の出来高の前日比'] = is_finite_num($prevVol) ? ($lastVol - $prevVol) : '';
  $out['(9)直近の出来高の前日比率'] = (is_finite_num($prevVol) && $prevVol != 0.0) ? (($lastVol/$prevVol)-1.0)*100.0 : '';

  // 回帰係数（終値）10..14
  $out['(10)終値の直近5日間の回帰係数']  = slopeLastN($closeAsc, 5);
  $out['(11)終値の直近10日間の回帰係数'] = slopeLastN($closeAsc, 10);
  $out['(12)終値の直近22日間の回帰係数'] = slopeLastN($closeAsc, 22);
  $out['(13)終値の直近66日間の回帰係数']  = slopeLastN($closeAsc, 66);
  $out['(14)終値の直近132日間の回帰係数'] = slopeLastN($closeAsc, 132);
  $out['(105)終値の直近200日間の回帰係数'] = slopeLastN($closeAsc, 200);

  // 回帰係数（出来高）15..19
  $out['(15)出来高の直近5日間の回帰係数']  = slopeLastN($volAsc, 5);
  $out['(16)出来高の直近10日間の回帰係数'] = slopeLastN($volAsc, 10);
  $out['(17)出来高の直近22日間の回帰係数'] = slopeLastN($volAsc, 22);
  $out['(18)出来高の直近66日間の回帰係数']  = slopeLastN($volAsc, 66);
  $out['(19)出来高の直近132日間の回帰係数'] = slopeLastN($volAsc, 132);
  $out['(106)出来高の直近200日間の回帰係数'] = slopeLastN($volAsc, 200);

  // 移動平均（終値・出来高）20..29
  $W = [5,10,22,66,132,200];
  $maC = [];
  $maV = [];
  foreach ($W as $w) {
    $maC[$w] = movingAverage($closeAsc, $w);
    $maV[$w] = movingAverage($volAsc, $w);
  }

  $out['(20)終値5日移動平均']  = lastDefined($maC[5]);
  $out['(21)終値10日移動平均'] = lastDefined($maC[10]);
  $out['(22)終値22日移動平均'] = lastDefined($maC[22]);
  $out['(23)終値66日移動平均']  = lastDefined($maC[66]);
  $out['(24)終値132日移動平均'] = lastDefined($maC[132]);
  $out['(107)終値200日移動平均'] = lastDefined($maC[200]);

  $out['(25)出来高5日移動平均']  = lastDefined($maV[5]);
  $out['(26)出来高10日移動平均'] = lastDefined($maV[10]);
  $out['(27)出来高22日移動平均'] = lastDefined($maV[22]);
  $out['(28)出来高66日移動平均']  = lastDefined($maV[66]);
  $out['(29)出来高132日移動平均'] = lastDefined($maV[132]);
  $out['(108)出来高200日移動平均'] = lastDefined($maV[200]);

  // 移動平均の回帰係数 30..39
  $out['(30)終値5日移動平均の直近5日の回帰係数']   = slopeLastNDefined($maC[5], 5);
  $out['(31)終値10日移動平均の直近10日の回帰係数'] = slopeLastNDefined($maC[10], 10);
  $out['(32)終値22日移動平均の直近10日の回帰係数'] = slopeLastNDefined($maC[22], 10);
  $out['(33)終値66日移動平均の直近10日の回帰係数']  = slopeLastNDefined($maC[66], 10);
  $out['(34)終値132日移動平均の直近10日の回帰係数'] = slopeLastNDefined($maC[132], 10);
  $out['(109)終値200日移動平均の直近10日の回帰係数'] = slopeLastNDefined($maC[200], 10);

  $out['(35)出来高5日移動平均の直近5日の回帰係数']   = slopeLastNDefined($maV[5], 5);
  $out['(36)出来高10日移動平均の直近10日の回帰係数'] = slopeLastNDefined($maV[10], 10);
  $out['(37)出来高22日移動平均の直近10日の回帰係数'] = slopeLastNDefined($maV[22], 10);
  $out['(38)出来高66日移動平均の直近10日の回帰係数']  = slopeLastNDefined($maV[66], 10);
  $out['(39)出来高132日移動平均の直近10日の回帰係数'] = slopeLastNDefined($maV[132], 10);
  $out['(110)出来高200日移動平均の直近10日の回帰係数'] = slopeLastNDefined($maV[200], 10);

  // 値幅系 40..50
  $rangeAsc = [];
  for ($i=0; $i<count($highAsc); $i++) {
    $rangeAsc[] = ($highAsc[$i] - $lowAsc[$i]);
  }
  $out['(40)200日分の高値-安値の一日の値幅平均'] = mean($rangeAsc);

  $vol5  = rollingStdDev($rangeAsc, 5);
  $vol10 = rollingStdDev($rangeAsc, 10);
  $vol22 = rollingStdDev($rangeAsc, 22);
  $vol66  = rollingStdDev($rangeAsc, 66);
  $vol132 = rollingStdDev($rangeAsc, 132);
  $vol200 = rollingStdDev($rangeAsc, 200);

  $out['(41)直近5日間の高値-安値の値幅のボラティリティ']  = lastDefined($vol5);
  $out['(42)直近10日間の高値-安値の値幅のボラティリティ'] = lastDefined($vol10);
  $out['(43)直近22日間の高値-安値の値幅のボラティリティ'] = lastDefined($vol22);
  $out['(44)直近66日間の高値-安値の値幅のボラティリティ']  = lastDefined($vol66);
  $out['(45)直近132日間の高値-安値の値幅のボラティリティ'] = lastDefined($vol132);
  $out['(111)直近200日間の高値-安値の値幅のボラティリティ'] = lastDefined($vol200);

  $out['(46)直近5日間の高値-安値の値幅のボラティリティの直近5日間の回帰係数']   = slopeLastNDefined($vol5, 5);
  $out['(47)直近10日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数'] = slopeLastNDefined($vol10, 10);
  $out['(48)直近22日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数'] = slopeLastNDefined($vol22, 10);
  $out['(49)直近66日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数']  = slopeLastNDefined($vol66, 10);
  $out['(50)直近132日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数'] = slopeLastNDefined($vol132, 10);
  $out['(112)直近200日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数'] = slopeLastNDefined($vol200, 10);

  // 乖離率 51..61
  $devC5  = deviationSeries($closeAsc, $maC[5]);
  $devC10 = deviationSeries($closeAsc, $maC[10]);
  $devC22 = deviationSeries($closeAsc, $maC[22]);
  $devC66  = deviationSeries($closeAsc, $maC[66]);
  $devC132 = deviationSeries($closeAsc, $maC[132]);
  $devC200 = deviationSeries($closeAsc, $maC[200]);

  $devV5  = deviationSeries($volAsc, $maV[5]);
  $devV10 = deviationSeries($volAsc, $maV[10]);
  $devV22 = deviationSeries($volAsc, $maV[22]);
  $devV66  = deviationSeries($volAsc, $maV[66]);
  $devV132 = deviationSeries($volAsc, $maV[132]);
  $devV200 = deviationSeries($volAsc, $maV[200]);

  $out['(51)終値5日移動平均と終値の移動平均乖離率']  = lastDefined($devC5);
  $out['(52)終値10日移動平均と終値の移動平均乖離率'] = lastDefined($devC10);
  $out['(53)終値22日移動平均と終値の移動平均乖離率'] = lastDefined($devC22);
  $out['(55)終値66日移動平均と終値の移動平均乖離率']  = lastDefined($devC66);
  $out['(56)終値132日移動平均と終値の移動平均乖離率'] = lastDefined($devC132);
  $out['(113)終値200日移動平均と終値の移動平均乖離率'] = lastDefined($devC200);

  $out['(57)出来高5日移動平均と出来高の移動平均乖離率']  = lastDefined($devV5);
  $out['(58)出来高10日移動平均と出来高の移動平均乖離率'] = lastDefined($devV10);
  $out['(59)出来高22日移動平均と出来高の移動平均乖離率'] = lastDefined($devV22);
  $out['(60)出来高66日移動平均と出来高の移動平均乖離率']  = lastDefined($devV66);
  $out['(61)出来高132日移動平均と出来高の移動平均乖離率'] = lastDefined($devV132);
  $out['(114)出来高200日移動平均と出来高の移動平均乖離率'] = lastDefined($devV200);

  // 乖離率回帰 62..71
  $out['(62)終値5日移動平均の移動平均乖離率の直近5日間の回帰係数']   = slopeLastNDefined($devC5, 5);
  $out['(63)終値10日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastNDefined($devC10, 10);
  $out['(64)終値22日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastNDefined($devC22, 10);
  $out['(65)終値66日移動平均の移動平均乖離率の直近10日間の回帰係数']  = slopeLastNDefined($devC66, 10);
  $out['(66)終値132日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastNDefined($devC132, 10);
  $out['(115)終値200日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastNDefined($devC200, 10);

  $out['(67)出来高5日移動平均の移動平均乖離率の直近5日間の回帰係数']   = slopeLastNDefined($devV5, 5);
  $out['(68)出来高10日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastNDefined($devV10, 10);
  $out['(69)出来高22日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastNDefined($devV22, 10);
  $out['(70)出来高66日移動平均の移動平均乖離率の直近10日間の回帰係数']  = slopeLastNDefined($devV66, 10);
  $out['(71)出来高132日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastNDefined($devV132, 10);
  $out['(116)出来高200日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastNDefined($devV200, 10);

  // 市場指標 72..79（topixCloseAsc があれば）
  $market = null;
  if (is_array($topixCloseAsc) && count($topixCloseAsc) > 0) {
    $market = computeMarketStats($closeAsc, $topixCloseAsc);
  }
  $out['(72)β']           = $market['beta']      ?? '';
  $out['(73)相関']         = $market['corr']      ?? '';
  $out['(74)相対ボラ']      = $market['relVol']    ?? '';
  $out['(75)残差ボラ']      = $market['residVol']  ?? '';
  $out['(76)アップサイドβ']  = $market['betaUp']    ?? '';
  $out['(77)ダウンサイドβ']  = $market['betaDown']  ?? '';
  $out['(78)Up Capture']   = $market['upCapture'] ?? '';
  $out['(79)Down Capture'] = $market['downCapture'] ?? '';

  // RSI / MACD 80..83
  $rsiSeries = computeRsiSeries($closeAsc, RSI_PERIOD);
  $out['(80)RSI'] = lastDefined($rsiSeries);
  $out['(127)RSIの直近5日間の回帰係数'] = slopeLastNDefined($rsiSeries, 5);
  $out['(81)RSIの直近22日間の回帰係数'] = slopeLastNDefined($rsiSeries, 22);

  $macd = computeMacd($closeAsc, MACD_FAST, MACD_SLOW, MACD_SIGNAL);
  $out['(82)MACD'] = isset($macd['macdLine']) ? lastDefined($macd['macdLine']) : '';
  $out['(83)MACDの直近22日間の回帰係数'] = isset($macd['macdLine']) ? slopeLastNDefined($macd['macdLine'], 22) : '';

  // 84..91
  $out['(84)連続日数'] = computeStreak($closeAsc);

  $min5  = minLastN($closeAsc, 5);
  $min10 = minLastN($closeAsc, 10);
  $min22 = minLastN($closeAsc, 22);

  $max5  = maxLastN($closeAsc, 5);
  $max10 = maxLastN($closeAsc, 10);
  $max22 = maxLastN($closeAsc, 22);

  $out['(85)5日間上昇率']  = trunc2((($lastClose / $min5)  - 1.0) * 100.0);
  $out['(86)10日間上昇率'] = trunc2((($lastClose / $min10) - 1.0) * 100.0);
  $out['(88)22日間上昇率'] = trunc2((($lastClose / $min22) - 1.0) * 100.0);

  $out['(89)5日間下落率']  = trunc2((($lastClose / $max5)  - 1.0) * 100.0);
  $out['(90)10日間下落率'] = trunc2((($lastClose / $max10) - 1.0) * 100.0);
  $out['(91)22日間下落率'] = trunc2((($lastClose / $max22) - 1.0) * 100.0);

  // 92..96（評価：GAS版と同じ考え方）
  $score92 = scoreLowVolVolumeInc([
    'vol10RangeStd'  => $out['(42)直近10日間の高値-安値の値幅のボラティリティ'],
    'vol66RangeStd'  => $out['(44)直近66日間の高値-安値の値幅のボラティリティ'],
    'slopeCloseMA10' => $out['(31)終値10日移動平均の直近10日の回帰係数'],
    'slopeVolMA10'   => $out['(36)出来高10日移動平均の直近10日の回帰係数'],
    'slopeVolMA66'   => $out['(38)出来高66日移動平均の直近10日の回帰係数'],
    'volMA10'        => $out['(26)出来高10日移動平均'],
    'volMA66'        => $out['(28)出来高66日移動平均'],
  ]);
  $out['(92)低ボラ出来高増'] = $score92;

  $nop = scoreNOP_likeSpec($closeAsc);
  $out['(93)水平ライン上突破'] = $nop['N'];
  $out['(94)水平ライン下突破'] = $nop['O'];
  $out['(95)GUPから全モ']       = scoreGupFullRetrace($closeAsc);

  $out['(96)AI基準判定'] = computeAiScore($closeAsc, $out, $score92, $out['(93)水平ライン上突破'], $out['(94)水平ライン下突破'], $out['(95)GUPから全モ']);
  
  $out['(97)タイプ分類'] = classifyTypeFromMetrics($out);
  
  // ★(99)〜(103)値幅不安定率 = 値幅ボラ / 終値MA
  $out['(99)直近5日間の値幅不安定率']   = safeDiv($out['(41)直近5日間の高値-安値の値幅のボラティリティ'] ?? null, $out['(20)終値5日移動平均'] ?? null);
  $out['(100)直近10日間の値幅不安定率'] = safeDiv($out['(42)直近10日間の高値-安値の値幅のボラティリティ'] ?? null, $out['(21)終値10日移動平均'] ?? null);
  $out['(101)直近22日間の値幅不安定率'] = safeDiv($out['(43)直近22日間の高値-安値の値幅のボラティリティ'] ?? null, $out['(22)終値22日移動平均'] ?? null);
  $out['(102)直近66日間の値幅不安定率']  = safeDiv($out['(44)直近66日間の高値-安値の値幅のボラティリティ'] ?? null, $out['(23)終値66日移動平均'] ?? null);
  $out['(103)直近132日間の値幅不安定率'] = safeDiv($out['(45)直近132日間の高値-安値の値幅のボラティリティ'] ?? null, $out['(24)終値132日移動平均'] ?? null);
  
  // ★(104)パーフェクトオーダー判定
  // 4本の回帰係数（(31)〜(34)）が全て負→-1、全て正→1、それ以外→0
  $s31 = $out['(31)終値10日移動平均の直近10日の回帰係数'] ?? null;
  $s32 = $out['(32)終値22日移動平均の直近10日の回帰係数'] ?? null;
  $s33 = $out['(33)終値66日移動平均の直近10日の回帰係数'] ?? null;
  $s34 = $out['(34)終値132日移動平均の直近10日の回帰係数'] ?? null;
  $s109 = $out['(109)終値200日移動平均の直近10日の回帰係数'] ?? null;

  if (is_finite_num($s31) && is_finite_num($s32) && is_finite_num($s33) && is_finite_num($s34) && is_finite_num($s109)) {
    $allNeg = ((float)$s31 < 0) && ((float)$s32 < 0) && ((float)$s33 < 0) && ((float)$s34 < 0) && ((float)$s109 < 0);
    $allPos = ((float)$s31 > 0) && ((float)$s32 > 0) && ((float)$s33 > 0) && ((float)$s34 > 0) && ((float)$s109 > 0);

    if ($allNeg) $out['(104)パーフェクトオーダー判定'] = -1;
    elseif ($allPos) $out['(104)パーフェクトオーダー判定'] = 1;
    else $out['(104)パーフェクトオーダー判定'] = 0;
  } else {
    $out['(104)パーフェクトオーダー判定'] = '';
  }
  
  // =========================================================
  // ★(117)〜(126) 週足（直近週と前週の差分）
  // =========================================================
  $weekly = buildWeeklyFromDaily($asofAsc, $openAsc, $highAsc, $lowAsc, $closeAsc, $volAsc);
  // 週足が2本以上無いと前週比が出ない（ただし値が無ければ空でOK）
  if ($weekly && count($weekly['dateAsc']) >= 1) {
    $wN = count($weekly['dateAsc']);
    $out['(117)直近の週足の日付']   = (string)$weekly['dateAsc'][$wN-1];
    $out['(118)直近の週足の始値']   = $weekly['openAsc'][$wN-1];
    $out['(119)直近の週足の高値']   = $weekly['highAsc'][$wN-1];
    $out['(120)直近の週足の安値']   = $weekly['lowAsc'][$wN-1];
    $out['(121)直近の週足の終値']   = $weekly['closeAsc'][$wN-1];
    $out['(122)直近の週足の出来高'] = $weekly['volAsc'][$wN-1];

    if ($wN >= 2) {
      $wClose = (float)$weekly['closeAsc'][$wN-1];
      $pClose = (float)$weekly['closeAsc'][$wN-2];
      $wVol   = (float)$weekly['volAsc'][$wN-1];
      $pVol   = (float)$weekly['volAsc'][$wN-2];

      $out['(123)直近の週足の終値の前週比'] = $wClose - $pClose;
      $out['(124)直近の週足の終値の前週比率'] = ($pClose != 0.0) ? round((($wClose/$pClose)-1.0)*100.0, 2) : '';
      $out['(125)直近の週足の出来高の前週比'] = $wVol - $pVol;
      $out['(126)直近の週足の出来高の前週比率'] = ($pVol != 0.0) ? round((($wVol/$pVol)-1.0)*100.0, 2) : '';
    } else {
      $out['(123)直近の週足の終値の前週比'] = '';
      $out['(124)直近の週足の終値の前週比率'] = '';
      $out['(125)直近の週足の出来高の前週比'] = '';
      $out['(126)直近の週足の出来高の前週比率'] = '';
    }
  } else {
    $out['(117)直近の週足の日付'] = '';
    $out['(118)直近の週足の始値'] = '';
    $out['(119)直近の週足の高値'] = '';
    $out['(120)直近の週足の安値'] = '';
    $out['(121)直近の週足の終値'] = '';
    $out['(122)直近の週足の出来高'] = '';
    $out['(123)直近の週足の終値の前週比'] = '';
    $out['(124)直近の週足の終値の前週比率'] = '';
    $out['(125)直近の週足の出来高の前週比'] = '';
    $out['(126)直近の週足の出来高の前週比率'] = '';
  }

  // =========================================================
  // ★(128)(129) 週足RSI
  // =========================================================
  if ($weekly && count($weekly['closeAsc']) > 0) {
    $wRsi = computeRsiSeries($weekly['closeAsc'], RSI_PERIOD);
    $out['(128)週足RSI'] = lastDefined($wRsi);
    $out['(129)週足RSIの直近5週間の回帰係数'] = slopeLastNDefined($wRsi, 5);
  } else {
    $out['(128)週足RSI'] = '';
    $out['(129)週足RSIの直近5週間の回帰係数'] = '';
  }

  // =========================================================
  // ★(130)〜(137) ストキャスティクス（標準: 14, %D=3）
  // =========================================================
  $stochD = computeStochSeries($highAsc, $lowAsc, $closeAsc, 14, 3);
  $out['(130)ストキャスティクス%K'] = lastDefined($stochD['k']);
  $out['(131)ストキャスティクス%D'] = lastDefined($stochD['d']);
  $out['(134)ストキャスティクス%Kの直近5日間の回帰係数'] = slopeLastNDefined($stochD['k'], 5);
  $out['(135)ストキャスティクス%Dの直近5日間の回帰係数'] = slopeLastNDefined($stochD['d'], 5);

  if ($weekly && count($weekly['closeAsc']) > 0) {
    $wStoch = computeStochSeries($weekly['highAsc'], $weekly['lowAsc'], $weekly['closeAsc'], 14, 3);
    $out['(132)週足ストキャスティクス%K'] = lastDefined($wStoch['k']);
    $out['(133)週足ストキャスティクス%D'] = lastDefined($wStoch['d']);
    $out['(136)週足ストキャスティクス%Kの直近5週間の回帰係数'] = slopeLastNDefined($wStoch['k'], 5);
    $out['(137)週足ストキャスティクス%Dの直近5週間の回帰係数'] = slopeLastNDefined($wStoch['d'], 5);
  } else {
    $out['(132)週足ストキャスティクス%K'] = '';
    $out['(133)週足ストキャスティクス%D'] = '';
    $out['(136)週足ストキャスティクス%Kの直近5週間の回帰係数'] = '';
    $out['(137)週足ストキャスティクス%Dの直近5週間の回帰係数'] = '';
  }

  // =========================================================
  // ★(138) ボリンジャーバンドのσ値（標準: 20日）
  // sigma = (終値 - MA20) / std20
  // =========================================================
  $bb = computeBollingerSigma($closeAsc, 20);
  $out['(138)ボリンジャーバンドのσ値'] = $bb;

  return $out;
}

/* =========================================================
 * 数学/統計ユーティリティ（GAS相当）
 * ========================================================= */
function is_finite_num($v): bool {
  if ($v === null) return false;
  if (is_string($v)) {
    $v = trim($v);
    if ($v === '') return false;   // ★空文字は未定義
  }
  if (!is_numeric($v)) return false;
  $f = (float)$v;
  // PHP 7.4: is_finite が無い環境もあるので保険
  if (function_exists('is_finite')) return is_finite($f);
  return !is_infinite($f) && !is_nan($f);
}

function slopeLastN(array $arr, int $N) {
  if (count($arr) < $N) return '';
  $seg = array_slice($arr, -$N);
  foreach ($seg as $v) if (!is_finite_num($v)) return '';
  return linregSlope($seg);
}

function slopeLastNDefined(array $series, int $n): ?float {
  // ★末尾から「定義済み」を n 個回収（空文字/NULL混在に強い）
  $tail = [];
  for ($i = count($series) - 1; $i >= 0 && count($tail) < $n; $i--) {
    $v = $series[$i];
    if (is_finite_num($v)) {
      $tail[] = (float)$v;
    }
  }
  if (count($tail) < $n) return null;
  $tail = array_reverse($tail); // 時系列順に戻す
  return slopeLastN($tail, $n);
}

function linregSlope(array $y): float {
  $n = count($y);
  // x = 0..n-1
  $xSum  = ($n - 1) * $n / 2.0;
  $x2Sum = ($n - 1) * $n * (2*$n - 1) / 6.0;
  $ySum = 0.0; $xySum = 0.0;
  for ($i=0; $i<$n; $i++) {
    $ySum += $y[$i];
    $xySum += $i * $y[$i];
  }
  $denom = ($n * $x2Sum - $xSum * $xSum);
  if (abs($denom) < 1e-12) return 0.0;
  return ($n * $xySum - $xSum * $ySum) / $denom;
}

function movingAverage(array $arr, int $W): array {
  $n = count($arr);
  $out = array_fill(0, $n, null);
  if ($W <= 0 || $n === 0) return $out;

  $sum = 0.0;
  for ($i=0; $i<$n; $i++) {
    $v = (float)$arr[$i];
    $sum += $v;
    if ($i >= $W) $sum -= (float)$arr[$i-$W];
    if ($i >= $W-1) $out[$i] = $sum / $W;
  }
  return $out;
}

function rollingStdDev(array $arr, int $W): array {
  $n = count($arr);
  $out = array_fill(0, $n, null);
  if ($W <= 1 || $n === 0) return $out;

  $sum = 0.0; $sum2 = 0.0;
  for ($i=0; $i<$n; $i++) {
    $v = (float)$arr[$i];
    $sum += $v;
    $sum2 += $v*$v;
    if ($i >= $W) {
      $old = (float)$arr[$i-$W];
      $sum -= $old;
      $sum2 -= $old*$old;
    }
    if ($i >= $W-1) {
      $mean = $sum / $W;
      $varPop = max(0.0, ($sum2/$W) - $mean*$mean);
      $out[$i] = sqrt($varPop);
    }
  }
  return $out;
}

function deviationSeries(array $series, array $maArr): array {
  $n = count($series);
  $out = array_fill(0, $n, null);
  for ($i=0; $i<$n; $i++) {
    $m = $maArr[$i];
    $s = $series[$i];
    if ($m === null) continue;
    $m = (float)$m;
    if (!is_finite_num($m) || $m == 0.0) continue;
    if (!is_finite_num($s)) continue;
    $out[$i] = ((float)$s / $m) - 1.0;
  }
  return $out;
}

function lastNDefinedArray(array $arr, int $N): ?array {
  $buf = [];
  for ($i=count($arr)-1; $i>=0 && count($buf)<$N; $i--) {
    $v = $arr[$i];
    if (is_finite_num($v)) $buf[] = (float)$v;
  }
  if (count($buf) < $N) return null;
  return array_reverse($buf);
}

function lastDefined(array $series) {
  for ($i = count($series) - 1; $i >= 0; $i--) {
    if (is_finite_num($series[$i])) return (float)$series[$i];
  }
  return null;
}

function mean(array $arr) {
  $s = 0.0; $n = 0;
  foreach ($arr as $v) {
    if (!is_finite_num($v)) continue;
    $s += (float)$v;
    $n++;
  }
  return $n ? ($s/$n) : '';
}

function trunc2($x) {
  if (!is_finite_num($x)) return '';
  $v = (float)$x;
  return floor($v * 100.0) / 100.0; // 小数点2位切り捨て
}

function minLastN(array $arr, int $N) {
  $seg = array_slice($arr, -$N);
  $vals = array_values(array_filter(
    $seg,
    fn($v) => is_finite_num($v) && (float)$v != 0.0
  ));

  if (count($vals) === 0) {
    throw new DivisionByZeroError(
      "直近{$N}日間の終値がすべて0のため、上昇率を計算できません"
    );
  }

  return min($vals);
}
function maxLastN(array $arr, int $N) {
  $seg = array_slice($arr, -$N);
   $vals = array_values(array_filter(
    $seg,
    fn($v) => is_finite_num($v) && (float)$v != 0.0
  ));

  if (count($vals) === 0) {
    throw new DivisionByZeroError(
      "直近{$N}日間の終値がすべて0のため、下落率を計算できません"
    );
  }

  return max($vals);
}

/* =========================================================
 * 市場統計（β等）：GAS computeMarketStats_ 準拠
 * ========================================================= */
function computeMarketStats(array $symCloseAsc, array $mktCloseAsc): ?array {
  $n = min(count($symCloseAsc), count($mktCloseAsc));
  if ($n < 67) return null;

  $sym = array_slice($symCloseAsc, $n-67, 67);
  $mkt = array_slice($mktCloseAsc, $n-67, 67);

  $symRet = [];
  $mktRet = [];
  $symPrevNonZero = null;
  $mktPrevNonZero = null;

  for ($i=1; $i<count($sym); $i++) {
    // 現在位置より前から、直近の0以外の銘柄終値を探す
    $symPrevNonZero = null;
    for ($j = $i - 1; $j >= 0; $j--) {
      if (is_finite_num($sym[$j]) && (float)$sym[$j] != 0.0) {
        $symPrevNonZero = (float)$sym[$j];
        break;
      }
    }

    // 現在位置より前から、直近の0以外のTOPIX終値を探す
    $mktPrevNonZero = null;
    for ($j = $i - 1; $j >= 0; $j--) {
      if (is_finite_num($mkt[$j]) && (float)$mkt[$j] != 0.0) {
        $mktPrevNonZero = (float)$mkt[$j];
        break;
      }
    }

    // まだ過去に0以外の値が存在しない先頭部分は読み飛ばす
    if ($symPrevNonZero === null || $mktPrevNonZero === null) {
      continue;
    }

    if (!is_finite_num($sym[$i]) || !is_finite_num($mkt[$i])) {
      continue;
    }

    $rs = ((float)$sym[$i] / $symPrevNonZero) - 1.0;
    $rm = ((float)$mkt[$i] / $mktPrevNonZero) - 1.0;

    if (is_finite_num($rs) && is_finite_num($rm)) {
      $symRet[] = $rs;
      $mktRet[] = $rm;
    }
  }

  if (count($symRet) === 0 || count($mktRet) === 0) {
    throw new DivisionByZeroError(
      '銘柄またはTOPIXの終値がすべて0のため、市場指標を計算できません'
    );
  }
  if (count($symRet) < 30) return null;

  $mean = fn($a) => array_sum($a)/count($a);
  $std = function($a) use ($mean) {
    $mu = $mean($a);
    $ss = 0.0;
    for ($i=0; $i<count($a); $i++) { $d = $a[$i]-$mu; $ss += $d*$d; }
    return sqrt($ss / max(1, (count($a)-1)));
  };

  $linreg = function($x,$y) {
    $n = count($x);
    $sx=0.0;$sy=0.0;$sxx=0.0;$sxy=0.0;
    for ($i=0;$i<$n;$i++){
      $xi=$x[$i];$yi=$y[$i];
      $sx+=$xi;$sy+=$yi;$sxx+=$xi*$xi;$sxy+=$xi*$yi;
    }
    $den = $n*$sxx - $sx*$sx;
    $b = ($den!=0.0) ? (($n*$sxy - $sx*$sy)/$den) : 0.0;
    $a = ($sy - $b*$sx)/$n;

    $ss=0.0;
    for ($i=0;$i<$n;$i++){
      $e = $y[$i] - ($a + $b*$x[$i]);
      $ss += $e*$e;
    }
    $residStd = sqrt($ss / max(1, ($n-2)));
    return ['a'=>$a,'b'=>$b,'residStd'=>$residStd];
  };

  $corr = function($x,$y) use ($mean) {
    $n = count($x);
    $mux=$mean($x); $muy=$mean($y);
    $num=0.0;$dx=0.0;$dy=0.0;
    for ($i=0;$i<$n;$i++){
      $vx=$x[$i]-$mux; $vy=$y[$i]-$muy;
      $num += $vx*$vy; $dx += $vx*$vx; $dy += $vy*$vy;
    }
    return ($dx>0 && $dy>0) ? ($num / sqrt($dx*$dy)) : 0.0;
  };

  $all = $linreg($mktRet,$symRet);
  $r   = $corr($mktRet,$symRet);
  $relvol = $std($symRet) / max(1e-12, $std($mktRet));

  $upX=[];$upY=[];$dnX=[];$dnY=[];
  for ($i=0;$i<count($mktRet);$i++){
    $rm=$mktRet[$i]; $rs=$symRet[$i];
    if ($rm>0){ $upX[]=$rm; $upY[]=$rs; }
    if ($rm<0){ $dnX[]=$rm; $dnY[]=$rs; }
  }
  $up = (count($upX)>=5) ? $linreg($upX,$upY) : ['b'=>null];
  $dn = (count($dnX)>=5) ? $linreg($dnX,$dnY) : ['b'=>null];

  $avg = fn($a) => count($a)? array_sum($a)/count($a) : null;
  $upCap   = ($avg($upY)!==null && $avg($upX)!==null) ? ($avg($upY)/$avg($upX)) : null;
  $downCap = ($avg($dnY)!==null && $avg($dnX)!==null) ? ($avg($dnY)/$avg($dnX)) : null;

  return [
    'beta' => $all['b'],
    'corr' => $r,
    'relVol' => $relvol,
    'residVol' => $all['residStd'],
    'betaUp' => $up['b'],
    'betaDown' => $dn['b'],
    'upCapture' => $upCap,
    'downCapture' => $downCap,
  ];
}

/* =========================================================
 * RSI / MACD / streak（GAS相当）
 * ========================================================= */
function computeRsiSeries(array $closeAsc, int $period): array {
  $n = count($closeAsc);
  $out = array_fill(0, $n, null);
  if ($n < $period + 1) return $out;

  $gain = 0.0; $loss = 0.0;
  for ($i=1; $i<=$period; $i++){
    $d = $closeAsc[$i] - $closeAsc[$i-1];
    if ($d > 0) $gain += $d; else $loss += -$d;
  }
  $gain /= $period; $loss /= $period;
  $rs = ($loss == 0.0) ? INF : ($gain/$loss);
  $out[$period] = 100.0 - (100.0/(1.0+$rs));

  for ($i=$period+1; $i<$n; $i++){
    $d = $closeAsc[$i] - $closeAsc[$i-1];
    $g = ($d>0) ? $d : 0.0;
    $l = ($d<0) ? -$d : 0.0;
    $gain = ($gain*($period-1) + $g) / $period;
    $loss = ($loss*($period-1) + $l) / $period;
    $rs = ($loss == 0.0) ? INF : ($gain/$loss);
    $out[$i] = 100.0 - (100.0/(1.0+$rs));
  }
  return $out;
}

function emaSeries(array $arr, int $period): array {
  $n = count($arr);
  $out = array_fill(0, $n, null);
  if ($period <= 0 || $n < $period) return $out;

  $k = 2.0 / ($period + 1.0);
  $sum = 0.0;
  for ($i=0; $i<$period; $i++) $sum += (float)$arr[$i];
  $ema = $sum / $period;
  $out[$period-1] = $ema;

  for ($i=$period; $i<$n; $i++){
    $v = $arr[$i];
    if (!is_finite_num($v)) { $out[$i] = $out[$i-1]; continue; }
    $ema = (float)$v*$k + $ema*(1.0-$k);
    $out[$i] = $ema;
  }
  return $out;
}

function computeMacd(array $closeAsc, int $fast, int $slow, int $signal): ?array {
  if (count($closeAsc) < $slow + $signal + 2) return null;
  $emaFast = emaSeries($closeAsc, $fast);
  $emaSlow = emaSeries($closeAsc, $slow);

  $macdLine = [];
  for ($i=0; $i<count($closeAsc); $i++){
    $a = $emaFast[$i]; $b = $emaSlow[$i];
    $macdLine[$i] = ($a===null || $b===null) ? null : ($a - $b);
  }
  $signalLine = emaSeries($macdLine, $signal);
  return ['macdLine'=>$macdLine, 'signalLine'=>$signalLine];
}

function computeStreak(array $closeAsc) {
  $n = count($closeAsc);
  if ($n < 2) return '';
  $diffs = [];
  for ($i=1; $i<$n; $i++) $diffs[] = $closeAsc[$i] - $closeAsc[$i-1];
  $last = $diffs[count($diffs)-1];
  if (!is_finite_num($last) || $last == 0.0) return 0;
  $dir = ($last > 0) ? 1 : -1;
  $cnt = 0;
  for ($i=count($diffs)-1; $i>=0; $i--){
    $d = $diffs[$i];
    if (!is_finite_num($d) || $d == 0.0) break;
    if (($d > 0 && $dir === 1) || ($d < 0 && $dir === -1)) $cnt++;
    else break;
  }
  return $dir * $cnt;
}

/* =========================================================
 * 評価スコア（GAS準拠）
 * ========================================================= */
function scoreLowVolVolumeInc(array $x): int {
  $v10  = (float)($x['vol10RangeStd']  ?? 0);
  $v66  = (float)($x['vol66RangeStd']  ?? 0);
  $sC   = (float)($x['slopeCloseMA10'] ?? 0);
  $sV10 = (float)($x['slopeVolMA10']   ?? 0);
  $sV66 = (float)($x['slopeVolMA66']   ?? 0);
  $m10  = (float)($x['volMA10']        ?? 0);
  $m66  = (float)($x['volMA66']        ?? 0);

  $cond = ($v10 < $v66) && ($sC > 0) && ($sV10 > 0) && ($sV10 > $sV66) && ($m10 > $m66);
  if (!$cond) return 0;

  $ratio = ($m66 > 0) ? ($m10/$m66) : 0;
  if (!is_finite_num($ratio) || $ratio <= 1.0) return 0;

  $score = (int)floor(($ratio - 1.0)/0.2) + 1;
  return max(1, min(10, $score));
}

function scoreNOP_likeSpec(array $closeAsc): array {
  $n = count($closeAsc);
  if ($n < 67) return ['N'=>0,'O'=>0];
  $todayClose = $closeAsc[$n-1];

  $start = max(0, $n-66);
  $end   = max(0, $n-14); // 非含む
  $slice = array_slice($closeAsc, $start, $end-$start);
  if (count($slice) === 0) return ['N'=>0,'O'=>0];

  $maxClose = max($slice);
  $minClose = min($slice);

  $Nscore = 0; $Oscore = 0;

  if ($todayClose > $maxClose && $maxClose > 0) {
    $dPerc = abs(($todayClose - $maxClose)/$maxClose)*100.0;
    $Nscore = ($dPerc < 0.3) ? 10 : max(1, 10 - (int)floor($dPerc/0.3));
  }
  if ($todayClose < $minClose && $minClose > 0) {
    $dPerc = abs(($minClose - $todayClose)/$minClose)*100.0;
    $Oscore = ($dPerc < 0.3) ? 10 : max(1, 10 - (int)floor($dPerc/0.3));
  }
  return ['N'=>$Nscore,'O'=>$Oscore];
}

function scoreGupFullRetrace(array $closeAsc): int {
  $n = count($closeAsc);
  if ($n < 15) return 0;
  $todayClose = $closeAsc[$n-1];

  $look = min(14, $n-1);
  for ($i=$n-$look; $i<$n; $i++){
    $prev = $closeAsc[$i-1];
    $cur  = $closeAsc[$i];
    if ($prev > 0 && (($cur/$prev)-1.0) >= 0.08) {
      if ($todayClose < $prev) {
        $dPerc = (($prev - $todayClose)/$prev)*100.0;
        $score = (int)floor($dPerc/0.5) + 1;
        return max(1, min(10, $score));
      }
      break;
    }
  }
  return 0;
}

function computeAiScore(array $closeAsc, array $out, int $score92, int $score93, int $score94, int $score95) {
  $n = count($closeAsc);
  if ($n < 30) return '';

  $p10 = (float)($out['(11)終値の直近10日間の回帰係数'] ?? 0);
  $p22 = (float)($out['(12)終値の直近22日間の回帰係数'] ?? 0);
  $p66 = (float)($out['(13)終値の直近66日間の回帰係数'] ?? 0);
  $v10 = (float)($out['(16)出来高の直近10日間の回帰係数'] ?? 0);

  $rsi  = $out['(80)RSI'] ?? null;
  $macd = $out['(82)MACD'] ?? null;

  $lastClose = $closeAsc[$n-1] ?? 0;
  $macdPct = (is_finite_num($macd) && $lastClose > 0) ? ((float)$macd / $lastClose) : 0.0;

  $trendRaw = 0.5*sign01($p10) + 0.3*sign01($p22) + 0.2*sign01($p66);
  $volTrend = 0.5*sign01($v10);

  $osc = 0.0;
  if (is_finite_num($rsi)) $osc += clamp(((float)$rsi - 50.0)/20.0, -1.0, 1.0) * 0.7;
  $osc += tanh($macdPct * 80.0) * 0.3;

  $support  = $score92 / 10.0;
  $breakout = $score93 / 10.0;

  $downBreak = $score94 / 10.0;
  $retrace   = $score95 / 10.0;

  $raw =
    0.35*$trendRaw +
    0.10*$volTrend +
    0.20*$support +
    0.15*$breakout +
    0.10*$osc
    -0.20*$downBreak
    -0.15*$retrace;

  $score = 5.0 + 5.0*$raw;
  $score = max(0.0, min(10.0, $score));
  return (int)round($score);
}

function sign01($x): float {
  if (!is_finite_num($x) || (float)$x == 0.0) return 0.0;
  return ((float)$x > 0.0) ? 1.0 : -1.0;
}
function clamp($x,$a,$b){ return max($a, min($b, $x)); }
function safeDiv($a, $b) {
  if (!is_finite_num($a) || !is_finite_num($b)) return '';
  $aa = (float)$a; $bb = (float)$b;
  if (abs($bb) < 1e-12) return '';
  return $aa / $bb;
}

/* =========================================================
 * タイプ分類
 * ========================================================= */
function classifyTypeFromMetrics(array $m): string {
  // (72)-(79)を参照
  $beta     = toFloatOrNull($m['(72)β'] ?? null);
  $corr     = toFloatOrNull($m['(73)相関'] ?? null);
  $relVol   = toFloatOrNull($m['(74)相対ボラ'] ?? null);
  $residVol = toFloatOrNull($m['(75)残差ボラ'] ?? null);
  $upBeta   = toFloatOrNull($m['(76)アップサイドβ'] ?? null);
  $dnBeta   = toFloatOrNull($m['(77)ダウンサイドβ'] ?? null);
  $upCap    = toFloatOrNull($m['(78)Up Capture'] ?? null);
  $dnCap    = toFloatOrNull($m['(79)Down Capture'] ?? null);

  // 値が揃っていない場合
  if ($beta===null || $corr===null || $relVol===null || $residVol===null || $upBeta===null || $dnBeta===null || $upCap===null || $dnCap===null) {
    return '該当なし';
  }

  // Captureは computeMarketStats() で「比率（例: 1.2）」として返っているので、%表記に合わせる
  $upCapPct = $upCap * 100.0;
  $dnCapPct = $dnCap * 100.0;

  // 「高」「極大」「不安定」「バラバラ」の数値化（必要なら運用で調整）
  $RESID_VOL_HIGH    = 0.03;
  $RESID_VOL_EXTREME = 0.05;

  $betaUnstable = ($beta >= 0.5 && $beta <= 1.5);

  $captureScattered =
    (abs($upCapPct - 100) > 30 && abs($dnCapPct - 100) > 30) &&
    (abs($upCapPct - $dnCapPct) > 60 || (($upCapPct - 100) * ($dnCapPct - 100) < 0));

  $betaNear1   = ($beta >= 0.8 && $beta <= 1.2);
  $relVolNear1 = ($relVol >= 0.8 && $relVol <= 1.2);
  $capNear100  = ($upCapPct >= 90 && $upCapPct <= 110 && $dnCapPct >= 90 && $dnCapPct <= 110);

  // 仕様の番号順
  // 1. 危険物
  if ($beta > 1.3 && $corr < 0.5 && $residVol >= $RESID_VOL_EXTREME && $dnCapPct > 130) {
    return '危険物';
  }

  // 2. 只のハイボラ危険株
  if ($beta > 1.2 && $upBeta > $beta && $upCapPct > 120 && $corr >= 0.6 && $dnCapPct > 120) {
    return '只のハイボラ危険株';
  }

  // 3. 統計拒否
  if ($corr < 0.5 && $residVol >= $RESID_VOL_HIGH && $betaUnstable && $captureScattered) {
    return '統計拒否';
  }

  // 4. 攻撃的順張り
  if ($beta > 1.2 && $upBeta > $beta && $upCapPct > 120 && $corr >= 0.6) {
    return '攻撃的順張り';
  }

  // 5. 市場の写像
  if ($betaNear1 && $corr >= 0.8 && $relVolNear1 && $capNear100) {
    return '市場の写像';
  }

  // 6. ディフェンシブ耐久
  if ($beta < 0.8 && $dnCapPct < 80 && $dnBeta < 0.7 && $corr <= 0.6) {
    return 'ディフェンシブ耐久';
  }

  // 7. 非対称アルファ
  // ※仕様の文面が(5)と同条件になっていたので、実務的に「下げに強い」を非対称として定義
  if ($betaNear1 && $corr >= 0.8 && $relVolNear1 && $upCapPct >= 95 && $upCapPct <= 115 && $dnCapPct < 95) {
    return '非対称アルファ';
  }

  return '該当なし';
}

function toFloatOrNull($v): ?float {
  if ($v === null) return null;
  if (is_float($v) || is_int($v)) return (float)$v;
  $s = trim((string)$v);
  if ($s === '' || $s === '-' || $s === '—') return null;
  $s = str_replace([',', '　', ' '], '', $s);
  return is_numeric($s) ? (float)$s : null;
}


/* =========================================================
 * 雑務
 * ========================================================= */
function ensureDir(string $dir): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
      throw new RuntimeException("mkdir failed: {$dir}");
    }
  }
}

/* =========================================================
 * ★週足生成（日足→週足）
 * - 週の定義：月〜金（ISO週でグループ化）
 * - 日付：金曜があれば金曜、無ければその週の最終営業日
 * - 始値：月曜があれば月曜、無ければその週の最初の日
 * - 高値/安値：週内の最大/最小
 * - 終値：金曜があれば金曜、無ければその週の最終日
 * - 出来高：週内合計
 * ========================================================= */
function buildWeeklyFromDaily(?array $asofAsc, array $openAsc, array $highAsc, array $lowAsc, array $closeAsc, array $volAsc): ?array {
  if (!is_array($asofAsc) || count($asofAsc) === 0) return null;
  $n = count($asofAsc);
  if ($n !== count($openAsc) || $n !== count($highAsc) || $n !== count($lowAsc) || $n !== count($closeAsc) || $n !== count($volAsc)) {
    return null;
  }

  $wkMap = []; // weekKey => bucket
  for ($i=0; $i<$n; $i++) {
    $ds = (string)$asofAsc[$i]; // YYYY-MM-DD
    try {
      $dt = new DateTimeImmutable($ds, new DateTimeZone('Asia/Tokyo'));
    } catch (Throwable $e) {
      continue;
    }
    // ISO week key (o=ISO year, W=week number)
    $weekKey = $dt->format('o-W');
    $dow = (int)$dt->format('N'); // 1..7 (Mon..Sun)
    // 週内は月〜金のみを見る想定だが、DBに土日が混じっても一応除外
    if ($dow >= 6) continue;

    if (!isset($wkMap[$weekKey])) {
      $wkMap[$weekKey] = [
        'firstDate' => $ds,
        'lastDate'  => $ds,
        'monOpen'   => null,
        'friClose'  => null,
        'friDate'   => null,
        'open'      => (float)$openAsc[$i],
        'high'      => (float)$highAsc[$i],
        'low'       => (float)$lowAsc[$i],
        'close'     => (float)$closeAsc[$i],
        'vol'       => (float)$volAsc[$i],
      ];
    } else {
      // first/last
      if ($ds < $wkMap[$weekKey]['firstDate']) $wkMap[$weekKey]['firstDate'] = $ds;
      if ($ds > $wkMap[$weekKey]['lastDate'])  $wkMap[$weekKey]['lastDate']  = $ds;
      // high/low
      $wkMap[$weekKey]['high'] = max($wkMap[$weekKey]['high'], (float)$highAsc[$i]);
      $wkMap[$weekKey]['low']  = min($wkMap[$weekKey]['low'],  (float)$lowAsc[$i]);
      // close = last day close (暫定)
      if ($ds >= $wkMap[$weekKey]['lastDate']) {
        $wkMap[$weekKey]['close'] = (float)$closeAsc[$i];
      }
      // volume sum
      $wkMap[$weekKey]['vol'] += (float)$volAsc[$i];
    }

    // Monday open
    if ($dow === 1 && $wkMap[$weekKey]['monOpen'] === null) {
      $wkMap[$weekKey]['monOpen'] = (float)$openAsc[$i];
      $wkMap[$weekKey]['open'] = (float)$openAsc[$i];
    }
    // If no Monday, keep earliest day's open: when we see a new earlier date within week
    if ($wkMap[$weekKey]['monOpen'] === null && $ds === $wkMap[$weekKey]['firstDate']) {
      $wkMap[$weekKey]['open'] = (float)$openAsc[$i];
    }

    // Friday close
    if ($dow === 5) {
      $wkMap[$weekKey]['friClose'] = (float)$closeAsc[$i];
      $wkMap[$weekKey]['friDate']  = $ds;
    }
  }

  if (count($wkMap) === 0) return null;

  // sort by weekKey chronological
  ksort($wkMap, SORT_STRING);

  $dateAsc=[];$oAsc=[];$hAsc=[];$lAsc=[];$cAsc=[];$vAsc=[];
  foreach ($wkMap as $wk) {
    $date = $wk['friDate'] ?? $wk['lastDate'];
    $open = ($wk['monOpen'] !== null) ? (float)$wk['monOpen'] : (float)$wk['open'];
    $close = ($wk['friClose'] !== null) ? (float)$wk['friClose'] : (float)$wk['close'];
    $dateAsc[] = $date;
    $oAsc[] = $open;
    $hAsc[] = (float)$wk['high'];
    $lAsc[] = (float)$wk['low'];
    $cAsc[] = $close;
    $vAsc[] = (float)$wk['vol'];
  }

  return [
    'dateAsc'=>$dateAsc,
    'openAsc'=>$oAsc,
    'highAsc'=>$hAsc,
    'lowAsc'=>$lAsc,
    'closeAsc'=>$cAsc,
    'volAsc'=>$vAsc,
  ];
}

/* =========================================================
 * ★ストキャスティクス（%K, %D）
 * - 標準: %K=14, %D=3(SMA)
 * ========================================================= */
function computeStochSeries(array $highAsc, array $lowAsc, array $closeAsc, int $kPeriod = 14, int $dPeriod = 3): array {
  $n = min(count($highAsc), count($lowAsc), count($closeAsc));
  $k = array_fill(0, $n, null);
  $d = array_fill(0, $n, null);
  if ($n === 0 || $kPeriod <= 0) return ['k'=>$k,'d'=>$d];

  for ($i=0; $i<$n; $i++) {
    if ($i < $kPeriod-1) continue;
    $hh = null; $ll = null;
    for ($j=$i-$kPeriod+1; $j<=$i; $j++) {
      $h = $highAsc[$j];
      $l = $lowAsc[$j];
      if (!is_finite_num($h) || !is_finite_num($l)) continue;
      $hh = ($hh===null) ? (float)$h : max($hh, (float)$h);
      $ll = ($ll===null) ? (float)$l : min($ll, (float)$l);
    }
    if ($hh===null || $ll===null) continue;
    $den = $hh - $ll;
    if (abs($den) < 1e-12) continue;
    $c = $closeAsc[$i];
    if (!is_finite_num($c)) continue;
    $k[$i] = (((float)$c - $ll) / $den) * 100.0;
  }

  // %D = SMA(dPeriod) of %K
  if ($dPeriod <= 0) return ['k'=>$k,'d'=>$d];
  $sum = 0.0; $cnt = 0;
  for ($i=0; $i<$n; $i++) {
    if (is_finite_num($k[$i])) {
      $sum += (float)$k[$i];
      $cnt++;
    }
    // remove
    if ($i >= $dPeriod) {
      $old = $k[$i-$dPeriod];
      if (is_finite_num($old)) { $sum -= (float)$old; $cnt--; }
    }
    if ($i >= $dPeriod-1 && $cnt === $dPeriod) {
      $d[$i] = $sum / $dPeriod;
    }
  }

  return ['k'=>$k,'d'=>$d];
}

/* =========================================================
 * ★ボリンジャーσ（標準: 20日）
 * sigma = (close - MA) / std
 * ========================================================= */
function computeBollingerSigma(array $closeAsc, int $period = 20) {
  $n = count($closeAsc);
  if ($n < $period) return '';
  $ma = movingAverage($closeAsc, $period);
  $std = rollingStdDev($closeAsc, $period);
  $lastClose = $closeAsc[$n-1];
  $lastMa = $ma[$n-1] ?? null;
  $lastStd = $std[$n-1] ?? null;
  if (!is_finite_num($lastClose) || $lastMa === null || $lastStd === null) return '';
  $sd = (float)$lastStd;
  if (abs($sd) < 1e-12) return '';
  return (((float)$lastClose - (float)$lastMa) / $sd);
}

/* =========================================================
 * ★週足デバッグ出力（STDERR）
 * - 指定銘柄の週足を直近N本だけ整形して出力
 * - CSVには一切影響しない
 * ========================================================= */
function debugLogWeekly_(
  string $code,
  string $today,
  array $asofAsc,
  array $openAsc,
  array $highAsc,
  array $lowAsc,
  array $closeAsc,
  array $volAsc,
  int $nLatest
): void {
  $weekly = buildWeeklyFromDaily($asofAsc, $openAsc, $highAsc, $lowAsc, $closeAsc, $volAsc);
  if (!$weekly || count($weekly['dateAsc']) === 0) {
    fwrite(STDERR, "[DEBUG][WEEKLY] code={$code} {$today} weekly=none\n");
    return;
  }

  $wN = count($weekly['dateAsc']);
  $n = max(1, min($nLatest, $wN));
  $start = $wN - $n;

  fwrite(STDERR, "[DEBUG][WEEKLY] code={$code} {$today} last={$n} (date open high low close vol wdiff wpct vdiff vpct)\n");

  for ($i=$start; $i<$wN; $i++) {
    $date  = (string)$weekly['dateAsc'][$i];
    $open  = (float)$weekly['openAsc'][$i];
    $high  = (float)$weekly['highAsc'][$i];
    $low   = (float)$weekly['lowAsc'][$i];
    $close = (float)$weekly['closeAsc'][$i];
    $vol   = (float)$weekly['volAsc'][$i];

    $wdiff = '';
    $wpct  = '';
    $vdiff = '';
    $vpct  = '';
    if ($i >= 1) {
      $pClose = (float)$weekly['closeAsc'][$i-1];
      $pVol   = (float)$weekly['volAsc'][$i-1];
      $wdiff = $close - $pClose;
      $wpct  = ($pClose != 0.0) ? round((($close/$pClose)-1.0)*100.0, 2) : '';
      $vdiff = $vol - $pVol;
      $vpct  = ($pVol != 0.0) ? round((($vol/$pVol)-1.0)*100.0, 2) : '';
    }

    // ログの見やすさ優先（桁はそのまま。必要なら後で整形）
    fwrite(STDERR, sprintf(
      "[DEBUG][WEEKLY] %s\t%.4f\t%.4f\t%.4f\t%.4f\t%.0f\t%s\t%s\t%s\t%s\n",
      $date, $open, $high, $low, $close, $vol,
      (is_string($wdiff)?$wdiff:(string)$wdiff),
      (is_string($wpct)?$wpct:(string)$wpct),
      (is_string($vdiff)?$vdiff:(string)$vdiff),
      (is_string($vpct)?$vpct:(string)$vpct)
    ));
  }
}