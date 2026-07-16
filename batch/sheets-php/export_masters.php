<?php
declare(strict_types=1);

/**
 * export_masters.php (SQLite版)
 * - scraping_common.php のOAuthを使って、Drive上の2つのマスタを取得
 * - SQLiteへ格納（WebはSQLiteを読むだけ＝超高速）
 * - 10分おき運用想定（cronじゃなくても手動実行OK）
 *
 * 実行例:
 *   /usr/bin/php /opt/invest/sheets-php/export_masters.php
 */

require '/opt/invest/scraping/vendor/autoload.php';
require '/opt/invest/scraping/lib/scraping_common.php';

date_default_timezone_set('Asia/Tokyo');

// =============================
// 設定（ここだけ環境に合わせて）
// =============================
const MASTER_PARENT_FOLDERS      = ['投資','プログラミング','GAS','マスタ'];
const BASIC_MASTER_FILE_NAME     = '全銘柄基本情報マスタ';
const DAILY_MASTER_FILE_NAME     = '全銘柄日足分析マスタ';
const MASTER_SHEET_NAME_PREFERRED = '全銘柄';
const MASTER_CODE_HEADER         = '証券コード';

// Sheets values 取得レンジ（列数が多いなら増やす）
const VALUES_RANGE_COLS          = 'A:ZZ';

// 出力SQLite（Webから読む場所）
// ※ apache が読める場所に置く（例: /opt/invest/master_cache）
const OUT_DIR                    = '/opt/invest/master_cache';
const OUT_DB                     = OUT_DIR . '/masters.sqlite';

// =============================
// Main
// =============================
main();

function main(): void {
  echo "[START] export masters to SQLite\n";

  // SQLite拡張チェック
  if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "[FATAL] PDO SQLite driver not available. (php -m | grep -i sqlite)\n");
    exit(1);
  }

  export_once();
  echo "[DONE] " . OUT_DB . "\n";
}

function export_once(): void {
  ensure_dir_export_(OUT_DIR, 0755);

  $tmpDb = OUT_DB . '.tmp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
  $pdo = open_sqlite_($tmpDb);

  init_schema_($pdo);

  $client = build_oauth_client_(); // scraping_common.php 側のOAuth（トークン管理もそちら）
  $drive  = new Google\Service\Drive($client);
  $sheets = new Google\Service\Sheets($client);

  $folderId = resolve_folder_id_by_path_($drive, MASTER_PARENT_FOLDERS);

  // 1) basic
  echo "[FETCH] " . BASIC_MASTER_FILE_NAME . "\n";
  $basic = fetch_master_as_records_($drive, $sheets, $folderId, BASIC_MASTER_FILE_NAME);
  save_master_to_sqlite_($pdo, 'basic', BASIC_MASTER_FILE_NAME, $basic);

  // 2) daily
  echo "[FETCH] " . DAILY_MASTER_FILE_NAME . "\n";
  $daily = fetch_master_as_records_($drive, $sheets, $folderId, DAILY_MASTER_FILE_NAME);
  save_master_to_sqlite_($pdo, 'daily', DAILY_MASTER_FILE_NAME, $daily);

  // 最適化（サイズと読み取り速度）
  $pdo->exec("ANALYZE;");
  $pdo->exec("VACUUM;");

  // atomic swap
  atomic_replace_file_($tmpDb, OUT_DB);
}

function open_sqlite_(string $path): PDO {
  $pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // ★ Web(読み取り専用) で "readonly write" を起こさないため WAL禁止
  $pdo->exec("PRAGMA journal_mode=DELETE;");   // ← ここが最重要
  $pdo->exec("PRAGMA synchronous=OFF;");       // export一括なら速い（気になるなら NORMALでもOK）
  $pdo->exec("PRAGMA temp_store=MEMORY;");
  $pdo->exec("PRAGMA cache_size=-200000;");    // 約200MB（環境に合わせて）
  return $pdo;
}

function init_schema_(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS meta (
      k TEXT PRIMARY KEY,
      v TEXT NOT NULL
    );
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS master_headers (
      kind TEXT PRIMARY KEY,          -- 'basic' or 'daily'
      file_name TEXT NOT NULL,
      sheet_title TEXT NOT NULL,
      header_row_index INTEGER NOT NULL,
      headers_json TEXT NOT NULL,
      updated_at INTEGER NOT NULL
    );
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS master_rows (
      kind TEXT NOT NULL,             -- 'basic' or 'daily'
      code4 TEXT NOT NULL,            -- 正規化した4桁（検索キー）
      company_name TEXT NOT NULL,     -- 無い場合は空
      row_json TEXT NOT NULL,         -- 行を丸ごとJSON（列追加/順番変更に強い）
      updated_at INTEGER NOT NULL,
      PRIMARY KEY(kind, code4)
    );
  ");

  // kindで絞った上で code4 に当たるので基本不要だが、念のため
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_master_rows_code4 ON master_rows(code4);");
}

function fetch_master_as_records_(
  Google\Service\Drive $drive,
  Google\Service\Sheets $sheets,
  string $folderId,
  string $fileName
): array {
  $fileId = find_spreadsheet_file_id_by_name_($drive, $folderId, $fileName);
  if ($fileId === null) throw new RuntimeException("Master spreadsheet not found: {$fileName}");

  $ss = $sheets->spreadsheets->get($fileId);
  $sheetTitle = pick_sheet_title_($ss, MASTER_SHEET_NAME_PREFERRED);

  $range = $sheetTitle . '!' . VALUES_RANGE_COLS;
  $resp = $sheets->spreadsheets_values->get($fileId, $range);
  $values = $resp->getValues() ?? [];
  if (!is_array($values) || count($values) < 1) {
    return [
      'fileId' => $fileId,
      'sheetTitle' => $sheetTitle,
      'headerRowIndex' => 0,
      'headers' => [],
      'rowsByCode4' => [],
    ];
  }

  $headerRowIndex = find_header_row_index_($values, MASTER_CODE_HEADER, 30);
  if ($headerRowIndex < 0) {
    throw new RuntimeException("Header not found: " . MASTER_CODE_HEADER . " in {$fileName} / sheet={$sheetTitle}");
  }

  $headers = array_map(fn($v) => trim((string)$v), $values[$headerRowIndex] ?? []);
  $codeCol = array_search(MASTER_CODE_HEADER, $headers, true);
  if ($codeCol === false) throw new RuntimeException("Code column not found: " . MASTER_CODE_HEADER);
  $codeCol = (int)$codeCol;

  // 会社名っぽい列を探しておく（なくてもOK）
  $nameCol = -1;
  foreach ($headers as $i => $h) {
    if ($h === '会社名' || $h === '銘柄名' || $h === '名称') { $nameCol = (int)$i; break; }
  }

  $rowsByCode4 = [];
  for ($r = $headerRowIndex + 1; $r < count($values); $r++) {
    $row = $values[$r] ?? [];
    if (!is_array($row)) continue;

    $codeRaw = isset($row[$codeCol]) ? trim((string)$row[$codeCol]) : '';
    if ($codeRaw === '') continue;

    $code4 = normalize_code4_($codeRaw);
    if ($code4 === '') continue;

    $company = '';
    if ($nameCol >= 0 && isset($row[$nameCol])) $company = trim((string)$row[$nameCol]);

    // last-wins
    $rowsByCode4[$code4] = [
      'company' => $company,
      'row' => $row,
    ];
  }

  return [
    'fileId' => $fileId,
    'sheetTitle' => $sheetTitle,
    'headerRowIndex' => $headerRowIndex,
    'headers' => $headers,
    'rowsByCode4' => $rowsByCode4,
  ];
}

function save_master_to_sqlite_(PDO $pdo, string $kind, string $fileName, array $master): void {
  $now = time();

  $headers = $master['headers'] ?? [];
  $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE);
  if ($headersJson === false) throw new RuntimeException("headers json encode failed");

  $sheetTitle = (string)($master['sheetTitle'] ?? '');
  $headerRowIndex = (int)($master['headerRowIndex'] ?? 0);

  $pdo->beginTransaction();
  try {
    // headers
    $stH = $pdo->prepare("
      INSERT INTO master_headers(kind, file_name, sheet_title, header_row_index, headers_json, updated_at)
      VALUES(:kind,:file,:sheet,:hri,:headers,:t)
      ON CONFLICT(kind) DO UPDATE SET
        file_name=excluded.file_name,
        sheet_title=excluded.sheet_title,
        header_row_index=excluded.header_row_index,
        headers_json=excluded.headers_json,
        updated_at=excluded.updated_at
    ");
    $stH->execute([
      ':kind' => $kind,
      ':file' => $fileName,
      ':sheet' => $sheetTitle,
      ':hri' => $headerRowIndex,
      ':headers' => $headersJson,
      ':t' => $now,
    ]);

    // rows (bulk)
    $stR = $pdo->prepare("
      INSERT INTO master_rows(kind, code4, company_name, row_json, updated_at)
      VALUES(:kind,:code4,:company,:row,:t)
      ON CONFLICT(kind, code4) DO UPDATE SET
        company_name=excluded.company_name,
        row_json=excluded.row_json,
        updated_at=excluded.updated_at
    ");

    $rowsByCode4 = $master['rowsByCode4'] ?? [];
    $cnt = 0;
    foreach ($rowsByCode4 as $code4 => $rec) {
      $rowJson = json_encode($rec['row'] ?? [], JSON_UNESCAPED_UNICODE);
      if ($rowJson === false) $rowJson = '[]';

      $stR->execute([
        ':kind' => $kind,
        ':code4' => (string)$code4,
        ':company' => (string)($rec['company'] ?? ''),
        ':row' => $rowJson,
        ':t' => $now,
      ]);
      $cnt++;
    }

    // meta
    $stM = $pdo->prepare("INSERT INTO meta(k,v) VALUES(:k,:v) ON CONFLICT(k) DO UPDATE SET v=excluded.v");
    $stM->execute([':k' => "updated_at_{$kind}", ':v' => (string)$now]);
    $stM->execute([':k' => "count_{$kind}", ':v' => (string)$cnt]);

    $pdo->commit();
    echo "[SAVE] kind={$kind} rows={$cnt}\n";
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

// =============================
// Helpers（※ scraping_common.php と名前衝突しないように suffix _ を付ける）
// =============================

function ensure_dir_export_(string $dir, int $mode): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, $mode, true) && !is_dir($dir)) {
      throw new RuntimeException("mkdir failed: {$dir}");
    }
  }
  // パーミッション調整（既存でも必要なら）
  @chmod($dir, $mode);
}

function atomic_replace_file_(string $from, string $to): void {
  // 同一FS内の rename は原子的
  $bak = $to . '.bak';
  if (is_file($to)) {
    @rename($to, $bak);
  }
  if (!@rename($from, $to)) {
    // 失敗したら戻す
    if (is_file($bak)) @rename($bak, $to);
    throw new RuntimeException("atomic replace failed: {$from} -> {$to}");
  }
  if (is_file($bak)) @unlink($bak);
  
  // bak削除の後あたりに追加
  @unlink($to . '-wal');
  @unlink($to . '-shm');
  @unlink($to . '-journal');

}

function find_spreadsheet_file_id_by_name_(Google\Service\Drive $drive, string $folderId, string $fileName): ?string {
  $q = sprintf(
    "name = '%s' and '%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.spreadsheet'",
    str_replace("'", "\\'", $fileName),
    $folderId
  );
  $res = $drive->files->listFiles([
    'q' => $q,
    'fields' => 'files(id,name)',
    'pageSize' => 5,
  ]);
  $files = $res->getFiles();
  if (!$files || count($files) === 0) return null;
  return $files[0]->getId();
}

function pick_sheet_title_(Google\Service\Sheets\Spreadsheet $ss, string $preferred): string {
  $all = $ss->getSheets() ?? [];
  foreach ($all as $sh) {
    $t = $sh->getProperties()->getTitle();
    if ($t === $preferred) return $t;
  }
  $sheet0 = $all[0] ?? null;
  if ($sheet0 === null) throw new RuntimeException("No sheets in spreadsheet");
  return (string)$sheet0->getProperties()->getTitle();
}

function find_header_row_index_(array $values2d, string $headerName, int $maxScanRows): int {
  $lim = min(count($values2d), max(1, $maxScanRows));
  for ($r = 0; $r < $lim; $r++) {
    $row = $values2d[$r] ?? [];
    if (!is_array($row)) continue;
    foreach ($row as $cell) {
      if (trim((string)$cell) === $headerName) return $r;
    }
  }
  return -1;
}

function normalize_code4_($x): string {
  $s = trim((string)($x ?? ''));
  $d = preg_replace('/[^0-9]/', '', $s);
  if ($d === null) $d = '';
  if ($d === '') return '';
  $d = str_pad($d, 4, '0', STR_PAD_LEFT);
  return substr($d, -4);
}
