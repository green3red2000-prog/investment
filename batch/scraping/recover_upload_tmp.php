<?php
declare(strict_types=1);

/**
 * recover_upload_tmp.php
 *
 * /opt/invest/scraping/tmp に溜まった出力ファイルを
 * Drive「投資/プログラミング/GAS/スクレイピング/出力結果」へ復旧アップロードする。
 *
 * - スクレイピングは一切しない
 * - CSV: Googleスプレッドシートとしてアップロード（拡張子なし）
 * - TXT: そのままアップロード（ファイル名そのまま）
 * - アップロード後のローカル削除はしない（手動削除前提）
 *
 * 実行:
 *   php /opt/invest/scraping/recover_upload_tmp.php
 *   php /opt/invest/scraping/recover_upload_tmp.php --dry-run
 *   php /opt/invest/scraping/recover_upload_tmp.php --token=/opt/invest/secrets/token_xxx.json
 *
 * 復旧手順:
 *   (1) まず一覧だけ確認（Driveへは送らない）
 *   php /opt/invest/scraping/recover_upload_tmp.php --dry-run
 *   
 *   (2) 実際にアップロード
 *   php /opt/invest/scraping/recover_upload_tmp.php
 *
 *   補足
 *   この復旧スクリプトは /opt/invest/scraping/tmp内のファイル名パターンに一致したものだけアップロードします
 *   *_YYYY-MM-DD.csv
 *   *_メッセージ_YYYY-MM-DD.txt
 *   
 *   削除はしません。
 *   アップロード確認後、手動で実施してください。
 *
 * JSONの作り直し手順：
 * (1) token_default_scraping.jsonを消す。
 *     rm /opt/invest/secrets/token_default_scraping.json
 * 
 * (2) ドライランで動かして認証する。
 *     php /opt/invest/scraping/recover_upload_tmp.php --dry-run
 *     === recover_upload_tmp ===
 *     tmp dir : /opt/invest/scraping/tmp
 *     token   : /opt/invest/secrets/token_default_scraping.json
 *     dry-run : YES
 *     1) Open this URL in your browser:
 *     https://accounts.google.com/o/oauth2/v2/auth?response_type=code&access_type=offline&client_id=347950769606-uhd9d68r6sfnokac0bovmi8a95brpanh.apps.googleusercontent.com&redirect_uri=http%3A%2F%2Flocalhost&state&scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fdrive&prompt=select_account%20consent
 *
 *     2) Approve access, then paste the verification code here: 
 * 
 * (3) URLをコピーしてブラウザアクセスして認証する。
 * 
 * (4) verification code を入力する。
 * 
 *     認証すると最後にブラウザが切り替わらずに以下のエラー画面になる。
 *     
 *     このサイトにアクセスできません
 *     localhost で接続が拒否されました。
 *   
 *     この時のブラウザのアドレスバーからcode=の部分をコピーして、
 *     http://localhost/?code=4/0ASc3gC1eMTMi4gCAWy1N2GeTqC0VCb0bJBV-QWqnl4vtUGknjDT6JsaTdxOgJ5uhfeWXoA&scope=https://www.googleapis.com/auth/drive
 *                           ↑ここの「4/0ASc3gC1eMTMi4gCAWy1N2GeTqC0VCb0bJBV-QWqnl4vtUGknjDT6JsaTdxOgJ5uhfeWXoA」の部分
 *     (2)のApprove access, then paste the verification code here: に張り付ける。
 *
 * (5)完了。
 *   opt/invest/secrets/token_default_scraping.jsonが作り直される。
 */

require __DIR__ . '/lib/scraping_common.php';

const TMP_DIR = '/opt/invest/scraping/tmp';

main($argv);

function main(array $argv): void {
  $opt = parse_args_($argv);
  $dryRun = (bool)($opt['dry_run'] ?? false);
  $tokenJson = (string)($opt['token'] ?? '/opt/invest/secrets/token_default_scraping.json');

  if (!is_dir(TMP_DIR)) {
    fwrite(STDERR, "[ERROR] tmp dir not found: " . TMP_DIR . "\n");
    exit(1);
  }

  // token 指定
  set_token_json($tokenJson);

  echo "=== recover_upload_tmp ===\n";
  echo "tmp dir : " . TMP_DIR . "\n";
  echo "token   : {$tokenJson}\n";
  echo "dry-run : " . ($dryRun ? 'YES' : 'NO') . "\n";

  $targets = collect_targets_(TMP_DIR);
  if (count($targets) === 0) {
    echo "[INFO] 対象ファイルがありません。\n";
    exit(0);
  }

  // アップロード先フォルダ解決（1回だけ）
  require_once __DIR__ . '/vendor/autoload.php';
  $client = build_drive_client_oauth_interactive_(); // scraping_common.php 内部関数
  $drive  = new Google\Service\Drive($client);
  $uploadFolderId = resolve_folder_id_by_path_($drive, DRIVE_PATH_UPLOAD_OUT);

  // mtime 昇順で処理（古い順）
  usort($targets, function($a, $b) {
    return ($a['mtime'] <=> $b['mtime']);
  });

  echo "[INFO] 対象件数: " . count($targets) . "\n";

  $ok = 0; $ng = 0;

  foreach ($targets as $t) {
    $type = $t['type']; // csv|txt
    $path = $t['path'];
    $base = basename($path);

    echo "----\n";
    echo "[TARGET] {$type} : {$base}\n";

    if ($dryRun) {
      echo "[DRY] skip upload\n";
      $ok++;
      continue;
    }

    try {
      if ($type === 'csv') {
        // CSV -> Google Sheets（拡張子なし名にする）
        $sheetName = $t['job'] . '_' . $t['date']; // 例: 本日の株価動向_2026-01-23
        echo "[UPLOAD][CSV->SHEET] name={$sheetName}\n";
        $created = upload_csv_as_google_sheet_with_retry_($drive, $path, $sheetName, $uploadFolderId);
        echo "[OK] Created Sheet: {$created->getName()} ({$created->getId()})\n";

      } elseif ($type === 'txt') {
        // TXT はそのまま（ファイル名そのまま）
        $driveName = $base;
        echo "[UPLOAD][TXT] name={$driveName}\n";
        $created = upload_file_with_retry_($drive, $path, $driveName, 'text/plain', $uploadFolderId);
        echo "[OK] Uploaded TXT: {$created->getName()} ({$created->getId()})\n";

      } else {
        throw new RuntimeException("unknown type: {$type}");
      }

      $ok++;

    } catch (Throwable $e) {
      $ng++;
      fwrite(STDERR, "[NG] {$base} : " . $e->getMessage() . "\n");
      // 続行（止めない）
    }
  }

  echo "====\n";
  echo "[DONE] ok={$ok} ng={$ng}\n";
  echo "※ローカル削除は行っていません（手動で削除してください）\n";
}

/**
 * tmp 配下から対象ファイルを収集
 * 対象:
 *   (JOB)_YYYY-MM-DD.csv
 *   (JOB)_メッセージ_YYYY-MM-DD.txt
 */
function collect_targets_(string $dir): array {
  $files = scandir($dir);
  if ($files === false) return [];

  $out = [];

  foreach ($files as $f) {
    if ($f === '.' || $f === '..') continue;
    $path = $dir . '/' . $f;
    if (!is_file($path)) continue;

    // CSV
    if (preg_match('/^(.*)_(\d{4}-\d{2}-\d{2})\.csv$/u', $f, $m)) {
      $job  = (string)$m[1];
      $date = (string)$m[2];
      $out[] = [
        'type' => 'csv',
        'path' => $path,
        'job'  => $job,
        'date' => $date,
        'mtime'=> (int)filemtime($path),
      ];
      continue;
    }

    // TXT（メッセージ）
    if (preg_match('/^(.*)_メッセージ_(\d{4}-\d{2}-\d{2})\.txt$/u', $f, $m)) {
      $job  = (string)$m[1];
      $date = (string)$m[2];
      $out[] = [
        'type' => 'txt',
        'path' => $path,
        'job'  => $job,
        'date' => $date,
        'mtime'=> (int)filemtime($path),
      ];
      continue;
    }
  }

  return $out;
}

function parse_args_(array $argv): array {
  $opt = ['dry_run' => false];

  foreach ($argv as $i => $a) {
    if ($i === 0) continue;

    if ($a === '--dry-run') {
      $opt['dry_run'] = true;
      continue;
    }

    if (strpos($a, '--token=') === 0) {
      $opt['token'] = substr($a, strlen('--token='));
      continue;
    }
  }

  return $opt;
}
