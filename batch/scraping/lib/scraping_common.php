<?php
declare(strict_types=1);

/**
 * scraping_common.php
 * - Webshare proxy: ランダム/セッション固定、圧縮、テキスト寄りヘッダでHTML取得
 * - Google Drive upload: CSV→Google Sheets化、TXTアップロード、リトライ、成功後ローカル削除
 *
 * 使い方：
 *   require __DIR__ . '/lib/scraping_common.php';
 *   set_token_json('/opt/invest/secrets/token_xxx.json');  // 処理ごと推奨
 *   http_session_begin(true);                              // 実行単位でプロキシ固定（推奨）
 *
 *   $html = http_get_text($url);
 *   $paths = build_output_paths('決算速報', '2026-01-15');
 *   write_message_txt($paths['txt'], '決算速報：01/15', $body);
 *   upload_outputs_and_cleanup('決算速報', '2026-01-15', $paths['csv'], $paths['txt']);
 */

// =======================================================
// 共通設定
// =======================================================

const SCRAPING_TMP_DIR = '/opt/invest/scraping/tmp';

// Drive上のアップロード先（固定）
const DRIVE_PATH_UPLOAD_OUT  = ['投資','プログラミング','GAS','スクレイピング','出力結果'];

// サービスアカウント用：アップロード先フォルダID（パス探索を使わない）
const DRIVE_UPLOAD_FOLDER_ID = '18urGUpzqgA3A0q9NojP-iY0DlKmbXVw2';

// OAuth（本人）共通：client_secret は共通でOK
const OAUTH_CLIENT_JSON = '/opt/invest/secrets/client_secret_347950769606-uhd9d68r6sfnokac0bovmi8a95brpanh.apps.googleusercontent.com.json';

// token のデフォルト（set_token_json() を呼ばない場合はこれを使う）
const DEFAULT_TOKEN_JSON = '/opt/invest/secrets/token_default_scraping.json';

// token は「処理ごとに分離」できるように差し替え（未指定ならDEFAULT_TOKEN_JSON）
$GLOBALS['SCRAPING_TOKEN_JSON'] = DEFAULT_TOKEN_JSON;


const BROWSER_FETCH_NODE_BIN = '/usr/bin/node';
const BROWSER_FETCH_SCRIPT   = '/opt/invest/scraping/js/fetch_dom.js';

// アップロード リトライ設定
const UPLOAD_RETRY_MAX   = 3;
const UPLOAD_RETRY_SLEEP = 360;

// HTTP リトライ（403/429/503/timeout）
const HTTP_RETRY_MAX_DEFAULT      = 7;
const HTTP_RETRY_SLEEP_MS_DEFAULT = 1200;

// ===== Service Account (通常運用はこれで固定) =====
const SERVICE_ACCOUNT_JSON = '/opt/invest/secrets/sheets-php-483500-54a070e9ff1c.json';

// -------------------------------------------------------
// Webshare proxy 設定（テキストファイルから読み込む）
// -------------------------------------------------------
// 形式: host:port:user:pass（空行OK, #コメントOK）
const WEBSHARE_PROXY_FILE = '/opt/invest/conf/webshare_proxies.txt';

/**
 * Webshare proxy をテキストファイルから読み込み、従来の配列形式へ整形
 * @return array<int, array{proxy:string,user:string,pass:string}>
 */
function load_webshare_proxies_from_file(string $file): array {
  if (!is_readable($file)) {
    throw new RuntimeException("Proxy file not readable: {$file}");
  }

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    throw new RuntimeException("Proxy file read failed: {$file}");
  }

  $proxies = [];

  foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line === '' || strpos($line, '#') === 0) continue;

    // host:port:user:pass
    $parts = explode(':', $line);
    if (count($parts) !== 4) {
      // フォーマット不正はスキップ（ただしログで気づけるように）
      error_log("[WARN] Invalid proxy format skipped: {$line}");
      continue;
    }

    [$host, $port, $user, $pass] = $parts;
    $host = trim($host);
    $port = trim($port);
    $user = trim($user);
    $pass = trim($pass);

    if ($host === '' || $port === '' || $user === '' || $pass === '') {
      error_log("[WARN] Invalid proxy (empty field) skipped: {$line}");
      continue;
    }
    if (!ctype_digit($port)) {
      error_log("[WARN] Invalid proxy port skipped: {$line}");
      continue;
    }

    // 既存実装に合わせて proxy は http://host:port 形式にする
    $proxies[] = [
      'proxy' => 'http://' . $host . ':' . $port,
      'user'  => $user,
      'pass'  => $pass,
    ];
  }

  if (count($proxies) === 0) {
    throw new RuntimeException("No valid proxies loaded from {$file}");
  }

  return $proxies;
}

// ★ここは従来「ハードコード」していたが、ファイル読み込みに変更
$GLOBALS['WEBSHARE_PROXIES'] = load_webshare_proxies_from_file(WEBSHARE_PROXY_FILE);

// 実行中セッション（sticky用）
$GLOBALS['SCRAPE_HTTP_SESSION_PROXY'] = null;

// 収集：エラーになったプロキシ（host:port）
$GLOBALS['SCRAPE_BAD_PROXIES'] = [];

// =======================================================
// Public API
// =======================================================

/**
 * token.json のパスを処理ごとに差し替える（推奨）
 */
function set_token_json(string $tokenJsonPath): void {
  $GLOBALS['SCRAPING_TOKEN_JSON'] = $tokenJsonPath;
}

/**
 * 1回の実行中は同じプロキシを使いたい時に呼ぶ（推奨）
 * - sticky=true: 実行開始時に1回だけプロキシ選択、その後固定
 * - sticky=false: 固定を解除（毎回ランダム）
 */
function http_session_begin(bool $sticky = true): void {
  if (!$sticky) {
    $GLOBALS['SCRAPE_HTTP_SESSION_PROXY'] = null;
    return;
  }
  $GLOBALS['SCRAPE_HTTP_SESSION_PROXY'] = pick_random_proxy_credential();
}

/**
 * sticky中に「出口を変えたい」場合
 */
function http_session_reset_proxy(): void {
  $GLOBALS['SCRAPE_HTTP_SESSION_PROXY'] = pick_random_proxy_credential();
}

/**
 * 出力先パスを統一： (処理名)_yyyy-MM-dd.csv / (処理名)_メッセージ_yyyy-MM-dd.txt
 */
function build_output_paths(string $jobName, string $isoDate): array {
  ensure_dir(SCRAPING_TMP_DIR);
  return [
    'csv' => SCRAPING_TMP_DIR . "/{$jobName}_{$isoDate}.csv",
    'txt' => SCRAPING_TMP_DIR . "/{$jobName}_メッセージ_{$isoDate}.txt",
  ];
}

/**
 * メッセージTXTを書き出し
 */
function write_message_txt(string $txtPath, string $subjectLine, string $body): void {
  $content = rtrim($subjectLine) . "\n\n" . rtrim($body) . "\n";
  if (file_put_contents($txtPath, $content) === false) {
    throw new RuntimeException("TXT作成に失敗: {$txtPath}");
  }
}

/**
 * Webshare proxy 経由で HTMLを取得
 * - 圧縮: CURLOPT_ENCODING=''（gzip/br等に自動対応）
 * - HTML本文のみ（画像/動画は別リクエストなので取得されない）
 * - stickyが有効なら「実行中は同一プロキシ」。403等のときだけ差し替え。
 */
function http_get_text(string $url, array $opt = []): string {

  $timeout  = (int)($opt['timeout'] ?? 30);
  $retryMax = (int)($opt['retry_max'] ?? HTTP_RETRY_MAX_DEFAULT);
  $sleepMs  = (int)($opt['retry_sleep_ms'] ?? HTTP_RETRY_SLEEP_MS_DEFAULT);

  // Browser fallback 有効
  $enableBrowserFallback = (bool)($opt['browser_fallback'] ?? true);

  $lastErr = null;

  for ($try = 1; $try <= $retryMax; $try++) {
  	
  	$randSleep = random_int(300, 1800);
    usleep($randSleep * 1000);

    // --------------------------------------------------
    // proxy選択
    // --------------------------------------------------
    $cred = $GLOBALS['SCRAPE_HTTP_SESSION_PROXY']
      ?? pick_random_proxy_credential();

    $proxyHp = proxy_hostport_((string)$cred['proxy']);

    // --------------------------------------------------
    // cURL
    // --------------------------------------------------
    $ch = curl_init();

    curl_setopt_array($ch, [

      CURLOPT_URL => $url,

      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,

      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,

      CURLOPT_ENCODING => '',

      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,

      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,

      CURLOPT_PROXY => $cred['proxy'],
      CURLOPT_PROXYUSERPWD => $cred['user'] . ':' . $cred['pass'],

      CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: ja,en-US;q=0.9,en;q=0.8',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
      ],

      CURLOPT_REFERER => 'https://kabutan.jp/',

      CURLOPT_USERAGENT =>
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
        'AppleWebKit/537.36 (KHTML, like Gecko) ' .
        'Chrome/136.0.0.0 Safari/537.36',
    ]);

    $body  = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    // ==================================================
    // cURL ERROR
    // ==================================================
    if ($errNo !== 0) {

      $lastErr = "curl error({$errNo}): {$err}";

      $isTimeout =
        stripos($lastErr, 'timed out') !== false ||
        stripos($lastErr, 'timeout') !== false ||
        $errNo === 28;

      $isProxy407 =
        stripos($lastErr, 'HTTP code 407') !== false ||
        stripos($lastErr, ' 407 ') !== false;

      $isProxyTransient =
        stripos($lastErr, 'after CONNECT') !== false ||
        stripos($lastErr, 'HTTP code 502') !== false ||
        stripos($lastErr, 'HTTP code 503') !== false ||
        stripos($lastErr, 'Proxy CONNECT aborted') !== false ||
        stripos($lastErr, 'Connection reset by peer') !== false ||
        $errNo === 56 ||
        $errNo === 52 ||
        $errNo === 35 ||
        $errNo === 28;

      if (($isTimeout || $isProxy407 || $isProxyTransient)
          && $try < $retryMax) {

        remember_bad_proxy_((string)$cred['proxy']);

        fwrite(STDERR,
          "[HTTP][retry {$try}/{$retryMax}] " .
          "curl/proxy error via {$proxyHp}. " .
          "rotate proxy. sleep {$sleepMs}ms: {$url}\n"
        );

        usleep($sleepMs * 1000);

        if ($GLOBALS['SCRAPE_HTTP_SESSION_PROXY'] !== null) {
          http_session_reset_proxy();
        }

        continue;
      }

      break;
    }

    // ==================================================
    // HTMLブロック判定
    // ==================================================
    $bodyStr = is_string($body) ? $body : '';

    $isBlockedHtml =
      $bodyStr === '' ||
      stripos($bodyStr, 'Access Denied') !== false ||
      stripos($bodyStr, 'Forbidden') !== false ||
      stripos($bodyStr, 'Request blocked') !== false ||
      stripos($bodyStr, 'captcha') !== false ||
      stripos($bodyStr, 'Cloudflare') !== false ||
      stripos($bodyStr, 'cf-browser-verification') !== false;

    // ==================================================
    // 成功
    // ==================================================
    if ($code === 200 && !$isBlockedHtml) {
      return $bodyStr;
    }

    // ==================================================
    // HTTP RETRY
    // ==================================================
    $retryableCodes = [
      403,
      405,
      406,
      408,
      409,
      425,
      429,
      500,
      502,
      503,
      504,
      521,
      522,
      523,
      524,
    ];

    if (
      (
        in_array($code, $retryableCodes, true)
        || $isBlockedHtml
      )
      && $try < $retryMax
    ) {

      remember_bad_proxy_((string)$cred['proxy']);

      fwrite(STDERR,
        "[HTTP][retry {$try}/{$retryMax}] " .
        "HTTP {$code} blocked/retry via {$proxyHp}. " .
        "rotate proxy. sleep {$sleepMs}ms: {$url}\n"
      );

      usleep($sleepMs * 1000);

      if ($GLOBALS['SCRAPE_HTTP_SESSION_PROXY'] !== null) {
        http_session_reset_proxy();
      }

      // ----------------------------------------------
      // Browser fallback
      // ----------------------------------------------
      if ($enableBrowserFallback && $try >= 3) {

        fwrite(STDERR,
          "[HTTP][browser-fallback] {$url}\n"
        );

        try {

          return http_get_text_browser($url, [
            'timeout' => 60,
            'retry_max' => 2,
            'retry_sleep_ms' => 3000,
          ]);

        } catch (\Throwable $e) {

          fwrite(STDERR,
            "[HTTP][browser-fallback-failed] " .
            $e->getMessage() . "\n"
          );
        }
      }

      continue;
    }

    // ==================================================
    // fatal
    // ==================================================
    $lastErr =
      "HTTP {$code} error via proxy {$proxyHp}: {$url}";

    break;
  }

  throw new RuntimeException(
    $lastErr ?? "HTTP unknown error: {$url}"
  );
}

// =======================================================
// Browser (Playwright) fetch
// =======================================================

/**
 * Playwright(Chromium) で DOM を取得して HTML を返す
 * - プロキシは scraping_common.php の WEBSHARE_PROXIES から選ぶ（=一元管理）
 * - sticky が有効なら同一プロキシを使う（403等は http_session_reset_proxy() で回転）
 */
function http_get_text_browser(string $url, array $opt = []): string {
  $timeoutSec = (int)($opt['timeout'] ?? 60);
  $retryMax   = (int)($opt['retry_max'] ?? 3);
  $sleepMs    = (int)($opt['retry_sleep_ms'] ?? 1200);

  $lastErr = null;

  for ($try = 1; $try <= $retryMax; $try++) {
    $cred = $GLOBALS['SCRAPE_HTTP_SESSION_PROXY'] ?? pick_random_proxy_credential();

    $outDir = $opt['out_dir'] ?? '/opt/invest/scraping/tmp';
    ensure_dir($outDir);
    $outPath = $outDir . '/pw_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.html';

    // Node にプロキシを env で渡す（=二元管理を排除）
    $env = [
      'WS_PROXY_SERVER' => $cred['proxy'],
      'WS_PROXY_USER'   => $cred['user'],
      'WS_PROXY_PASS'   => $cred['pass'],
    ];

    $cmd = sprintf(
      '%s %s %s %s',
      escapeshellcmd(BROWSER_FETCH_NODE_BIN),
      escapeshellarg(BROWSER_FETCH_SCRIPT),
      escapeshellarg($url),
      escapeshellarg($outPath)
    );

    // proc_open で env を付けて実行
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes, null, array_merge($_ENV, $env));
    if (!is_resource($proc)) {
      throw new RuntimeException("proc_open failed: {$cmd}");
    }

    fclose($pipes[0]);
    stream_set_timeout($pipes[1], $timeoutSec);
    stream_set_timeout($pipes[2], $timeoutSec);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);

    if ($exitCode === 0 && file_exists($outPath)) {
      $html = file_get_contents($outPath);
      @unlink($outPath);

      if ($html === false || $html === '') {
        $lastErr = "Playwright returned empty html: {$url}";
      } else {
        return $html;
      }
    } else {
      $lastErr = "Playwright failed(exit={$exitCode}) url={$url} stderr=" . trim($stderr);
    }

    // リトライ：proxy rotate（sticky時のみ）＆sleep
    fwrite(STDERR, "[BROWSER][retry {$try}/{$retryMax}] {$lastErr}\n");
    usleep($sleepMs * 1000);
    if ($GLOBALS['SCRAPE_HTTP_SESSION_PROXY'] !== null) {
      http_session_reset_proxy();
    }
  }

  throw new RuntimeException($lastErr ?? "Playwright error: {$url}");
}

/**
 * （処理名）_yyyy-MM-dd.csv をGoogleスプレッドシート化してアップロードし、
 * （処理名）_メッセージ_yyyy-MM-dd.txt もアップロード。
 * 成功後ローカル削除。
 */
function upload_outputs_and_cleanup(string $jobName, string $isoDate, string $csvPath, string $txtPath): void {
  require_once __DIR__ . '/../vendor/autoload.php';

  if (!file_exists($csvPath)) throw new RuntimeException("CSVがありません: {$csvPath}");
  if (!file_exists($txtPath)) throw new RuntimeException("TXTがありません: {$txtPath}");

  $client = build_oauth_client_();
  $drive  = new Google\Service\Drive($client);

  $uploadFolderId = DRIVE_UPLOAD_FOLDER_ID;
  
  if ($uploadFolderId === '' || !preg_match('/^[A-Za-z0-9_-]{10,}$/', $uploadFolderId)) {
    throw new RuntimeException("Invalid DRIVE_UPLOAD_FOLDER_ID: {$uploadFolderId}");
  }

  // CSV -> Google Sheets（拡張子なし名）
  $sheetName = "{$jobName}_{$isoDate}";
  $createdSheet = upload_csv_as_google_sheet_with_retry_($drive, $csvPath, $sheetName, $uploadFolderId);
  echo "Created Google Sheet: {$createdSheet->getName()} ({$createdSheet->getId()})\n";

  // TXT（そのまま）
  $txtName = "{$jobName}_メッセージ_{$isoDate}.txt";
  $createdTxt = upload_file_with_retry_($drive, $txtPath, $txtName, 'text/plain', $uploadFolderId);
  echo "Uploaded TXT: {$createdTxt->getName()} ({$createdTxt->getId()})\n";

  // ローカル削除
  @unlink($csvPath);
  @unlink($txtPath);

  echo "DONE: uploaded & local files removed.\n";
}

// =======================================================
// Internal helpers
// =======================================================

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
      throw new RuntimeException("mkdir failed: {$dir}");
    }
  }
}

/**
 * Webshare プロキシをランダムで1つ返す
 * @return array{proxy:string,user:string,pass:string}
 */
function pick_random_proxy_credential(): array {

  $list = $GLOBALS['WEBSHARE_PROXIES'] ?? [];

  if (!is_array($list) || count($list) === 0) {
    throw new RuntimeException(
      "WEBSHARE_PROXIES が空です。"
    );
  }

  // ------------------------------------------
  // bad proxy除外
  // ------------------------------------------
  $bad = $GLOBALS['SCRAPE_BAD_PROXIES'] ?? [];

  $filtered = [];

  foreach ($list as $p) {

    $hp = proxy_hostport_((string)$p['proxy']);

    if (!isset($bad[$hp])) {
      $filtered[] = $p;
    }
  }

  // 全滅したら復活
  if (count($filtered) === 0) {

    fwrite(STDERR,
      "[PROXY] all proxies marked bad. reset blacklist.\n"
    );

    $GLOBALS['SCRAPE_BAD_PROXIES'] = [];

    $filtered = $list;
  }

  $p = $filtered[array_rand($filtered)];

  foreach (['proxy','user','pass'] as $k) {
    if (!isset($p[$k]) || $p[$k] === '') {
      throw new RuntimeException(
        "プロキシ設定が不正です（{$k}が空）"
      );
    }
  }

  return $p;
}
function build_oauth_client_(): Google\Client {
  // サービスアカウント固定：互換維持のため名前は残すが、通常運用はOAuth運用
  //return build_drive_client_service_account_();
  return build_drive_client_oauth_interactive_();
}

function build_drive_client_service_account_(): Google\Client {
  if (!file_exists(SERVICE_ACCOUNT_JSON)) {
    throw new RuntimeException("Service account json not found: " . SERVICE_ACCOUNT_JSON);
  }

  $client = new Google\Client();
  $client->setApplicationName('invest-scraping-php');
  $client->setAuthConfig(SERVICE_ACCOUNT_JSON);
  $client->setScopes([Google\Service\Drive::DRIVE]); // Drive書き込み
  return $client;
}

function build_drive_client_oauth_interactive_(): Google\Client {
  $tokenJson = (string)($GLOBALS['SCRAPING_TOKEN_JSON'] ?? '');
  if ($tokenJson === '') $tokenJson = DEFAULT_TOKEN_JSON;

  if (!file_exists(OAUTH_CLIENT_JSON)) {
    throw new RuntimeException("OAuth client json not found: " . OAUTH_CLIENT_JSON);
  }

  $client = new Google\Client();
  $client->setApplicationName('invest-scraping-php');
  $client->setAuthConfig(OAUTH_CLIENT_JSON);
  $client->setScopes([Google\Service\Drive::DRIVE]);
  $client->setAccessType('offline');
  $client->setPrompt('select_account consent');
  $client->setRedirectUri('http://localhost');

  $oldToken = null;
  if (file_exists($tokenJson)) {
    // ★ 読み取りも LOCK_SH（共有ロック）
    $oldToken = read_token_json_locked_($tokenJson);
    if (is_array($oldToken)) $client->setAccessToken($oldToken);
  }

  if ($client->isAccessTokenExpired()) {
    try {
      if ($client->getRefreshToken()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
      } else {
        $client = reauthorize_interactive_($client);
      }
    } catch (Throwable $e) {
      $msg = $e->getMessage();
      if (stripos($msg, 'invalid_grant') !== false || stripos($msg, 'expired or revoked') !== false) {
        // ★ 退避も LOCK_EX（排他ロック）で競合回避 + bak掃除
        $bak = move_token_to_backup_locked_($tokenJson, 3);
        if ($bak) {
          fwrite(STDERR, "[OAUTH] token revoked/expired. moved token to: {$bak}\n");
        } else {
          fwrite(STDERR, "[OAUTH] token revoked/expired. token move skipped/failed.\n");
        }

        $client = reauthorize_interactive_($client);
      } else {
        throw $e;
      }
    }

    // ★保存時に refresh_token を保持（更新レスポンスに入らない事がある）
    $newToken = $client->getAccessToken();
    if (is_array($newToken) && empty($newToken['refresh_token'])) {
      if (is_array($oldToken) && !empty($oldToken['refresh_token'])) {
        $newToken['refresh_token'] = $oldToken['refresh_token'];
      } elseif ($client->getRefreshToken()) {
        $newToken['refresh_token'] = $client->getRefreshToken();
      }
    }

    ensure_dir(dirname($tokenJson));
    // ★ 保存は LOCK_EX + 原子的更新
    save_token_json_with_lock_($tokenJson, $newToken);
  }

  return $client;
}


/**
 * token.json を「排他ロック + 原子的更新」で保存する
 * - 同時実行（cronがかぶる等）でも token.json が壊れにくい
 * - 書き込みは tmp に出して rename（同一FSなら原子的）
 */
function save_token_json_with_lock_(string $path, array $token): void {
  ensure_dir(dirname($path));

  $lockPath = $path . '.lock';
  $lockFp = @fopen($lockPath, 'c');
  if ($lockFp === false) {
    // ロックできないなら最悪 direct write（環境依存のため）
    file_put_contents($path, json_encode($token, JSON_UNESCAPED_SLASHES));
    return;
  }

  try {
    if (!flock($lockFp, LOCK_EX)) {
      file_put_contents($path, json_encode($token, JSON_UNESCAPED_SLASHES));
      return;
    }

    $json = json_encode($token, JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException("json_encode failed for token");

    $tmp = $path . '.tmp_' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json) === false) {
      throw new RuntimeException("token tmp write failed: {$tmp}");
    }

    if (!@rename($tmp, $path)) {
      @unlink($tmp);
      throw new RuntimeException("token rename failed: {$tmp} -> {$path}");
    }

  } finally {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
  }
}



function reauthorize_interactive_(Google\Client $client): Google\Client {
  $authUrl = $client->createAuthUrl();
  echo "1) Open this URL in your browser:\n{$authUrl}\n\n";
  echo "2) Approve access, then paste the verification code here: ";
  $authCode = trim((string)fgets(STDIN));

  $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
  if (isset($accessToken['error'])) {
    throw new RuntimeException('OAuth error: ' . $accessToken['error']);
  }
  $client->setAccessToken($accessToken);
  return $client;
}

function resolve_folder_id_by_path_(Google\Service\Drive $drive, array $folders): string {
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

function upload_csv_as_google_sheet_(
  Google\Service\Drive $drive,
  string $localCsvPath,
  string $sheetName,
  string $folderId
): Google\Service\Drive\DriveFile {

  $fileMeta = new Google\Service\Drive\DriveFile([
    'name' => $sheetName,
    'parents' => [$folderId],
    'mimeType' => 'application/vnd.google-apps.spreadsheet',
  ]);

  $content = file_get_contents($localCsvPath);
  if ($content === false) throw new RuntimeException("CSV read failed: {$localCsvPath}");

  return $drive->files->create($fileMeta, [
    'data' => $content,
    'mimeType' => 'text/csv',
    'uploadType' => 'multipart',
    'fields' => 'id,name,mimeType,parents',
  ]);
}

function upload_file_(
  Google\Service\Drive $drive,
  string $localPath,
  string $driveName,
  string $mimeType,
  string $folderId
): Google\Service\Drive\DriveFile {

  $fileMeta = new Google\Service\Drive\DriveFile([
    'name' => $driveName,
    'parents' => [$folderId],
  ]);

  $content = file_get_contents($localPath);
  if ($content === false) throw new RuntimeException("file read failed: {$localPath}");

  return $drive->files->create($fileMeta, [
    'data' => $content,
    'mimeType' => $mimeType,
    'uploadType' => 'multipart',
    'fields' => 'id,name,mimeType,parents',
  ]);
}

function upload_csv_as_google_sheet_with_retry_(
  Google\Service\Drive $drive,
  string $localCsvPath,
  string $sheetName,
  string $folderId
): Google\Service\Drive\DriveFile {

  $lastErr = null;

  for ($try = 1; $try <= UPLOAD_RETRY_MAX; $try++) {
    try {
      echo "[UPLOAD][CSV->SHEET] try {$try}/" . UPLOAD_RETRY_MAX . "\n";
      return upload_csv_as_google_sheet_($drive, $localCsvPath, $sheetName, $folderId);

    } catch (Google\Service\Exception $e) {
      $lastErr = $e;
      $code = (int)$e->getCode();
      $msg  = $e->getMessage();
      $retryable = ($code === 503 || $code === 429 || stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false);

      if ($retryable && $try < UPLOAD_RETRY_MAX) {
        fwrite(STDERR, "[UPLOAD][CSV][retry {$try}/" . UPLOAD_RETRY_MAX . "] retryable (code={$code}). sleep " . UPLOAD_RETRY_SLEEP . "s\n");
        sleep(UPLOAD_RETRY_SLEEP);
        continue;
      }
      break;

    } catch (Throwable $e) {
      $lastErr = $e;
      $msg = $e->getMessage();
      $retryable = (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false);

      if ($retryable && $try < UPLOAD_RETRY_MAX) {
        fwrite(STDERR, "[UPLOAD][CSV][retry {$try}/" . UPLOAD_RETRY_MAX . "] timeout-like. sleep " . UPLOAD_RETRY_SLEEP . "s\n");
        sleep(UPLOAD_RETRY_SLEEP);
        continue;
      }
      break;
    }
  }

  fwrite(STDERR, "Google側（Drive/Sheets API）の一時的なバックエンド障害／過負荷のため終了\n");
  if ($lastErr) fwrite(STDERR, "[UPLOAD][CSV][FAILED] " . $lastErr->getMessage() . "\n");
  exit(1);
}

function upload_file_with_retry_(
  Google\Service\Drive $drive,
  string $localPath,
  string $driveName,
  string $mimeType,
  string $folderId
): Google\Service\Drive\DriveFile {

  $lastErr = null;

  for ($try = 1; $try <= UPLOAD_RETRY_MAX; $try++) {
    try {
      echo "[UPLOAD][FILE] try {$try}/" . UPLOAD_RETRY_MAX . "\n";
      return upload_file_($drive, $localPath, $driveName, $mimeType, $folderId);

    } catch (Google\Service\Exception $e) {
      $lastErr = $e;
      $code = (int)$e->getCode();
      $msg  = $e->getMessage();
      $retryable = ($code === 503 || $code === 429 || stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false);

      if ($retryable && $try < UPLOAD_RETRY_MAX) {
        fwrite(STDERR, "[UPLOAD][FILE][retry {$try}/" . UPLOAD_RETRY_MAX . "] retryable (code={$code}). sleep " . UPLOAD_RETRY_SLEEP . "s\n");
        sleep(UPLOAD_RETRY_SLEEP);
        continue;
      }
      break;

    } catch (Throwable $e) {
      $lastErr = $e;
      $msg = $e->getMessage();
      $retryable = (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false);

      if ($retryable && $try < UPLOAD_RETRY_MAX) {
        fwrite(STDERR, "[UPLOAD][FILE][retry {$try}/" . UPLOAD_RETRY_MAX . "] timeout-like. sleep " . UPLOAD_RETRY_SLEEP . "s\n");
        sleep(UPLOAD_RETRY_SLEEP);
        continue;
      }
      break;
    }
  }

  fwrite(STDERR, "Google側（Drive/Sheets API）の一時的なバックエンド障害／過負荷のため終了\n");
  if ($lastErr) fwrite(STDERR, "[UPLOAD][FILE][FAILED] " . $lastErr->getMessage() . "\n");
  exit(1);
}
/**
 * token json 読み取り（共有ロック）
 * - 書き込みと競合して JSON が一瞬壊れる事故を避ける
 */
function read_token_json_locked_(string $tokenJson): ?array {
  if (!file_exists($tokenJson)) return null;

  $lockPath = $tokenJson . '.lock';
  $lockFp = @fopen($lockPath, 'c');
  if ($lockFp === false) {
    // ロックできない場合も読むだけは試す（最悪事故るが、環境依存で止めない）
    $raw = @file_get_contents($tokenJson);
    if (!is_string($raw) || $raw === '') return null;
    $a = json_decode($raw, true);
    return is_array($a) ? $a : null;
  }

  try {
    if (!flock($lockFp, LOCK_SH)) {
      $raw = @file_get_contents($tokenJson);
      if (!is_string($raw) || $raw === '') return null;
      $a = json_decode($raw, true);
      return is_array($a) ? $a : null;
    }

    $raw = @file_get_contents($tokenJson);
    if (!is_string($raw) || $raw === '') return null;
    $a = json_decode($raw, true);
    return is_array($a) ? $a : null;

  } finally {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
  }
}

/**
 * invalid_grant 時の token 退避（排他ロックで競合回避）
 * - tokenJson を .bak_YYYYmmdd_His に rename
 * - 退避後に bak を掃除
 */
function move_token_to_backup_locked_(string $tokenJson, int $keep = 3): ?string {
  if (!file_exists($tokenJson)) return null;

  $lockPath = $tokenJson . '.lock';
  $lockFp = @fopen($lockPath, 'c');
  if ($lockFp === false) {
    $bak = $tokenJson . '.bak_' . date('Ymd_His');
    @rename($tokenJson, $bak);
    prune_token_backups_($tokenJson, $keep);
    return $bak;
  }

  try {
    if (!flock($lockFp, LOCK_EX)) {
      $bak = $tokenJson . '.bak_' . date('Ymd_His');
      @rename($tokenJson, $bak);
      prune_token_backups_($tokenJson, $keep);
      return $bak;
    }

    if (!file_exists($tokenJson)) return null; // ロック取得までに消えた

    $bak = $tokenJson . '.bak_' . date('Ymd_His');
    if (@rename($tokenJson, $bak)) {
      prune_token_backups_($tokenJson, $keep);
      return $bak;
    }
    return null;

  } finally {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
  }
}

/**
 * token の .bak_YYYYmmdd_His を最新 keep 世代だけ残す
 */
function prune_token_backups_(string $tokenJson, int $keep = 3): void {
  $dir  = dirname($tokenJson);
  $base = basename($tokenJson);

  $pattern = $dir . '/' . $base . '.bak_*';
  $files = glob($pattern);
  if ($files === false || count($files) === 0) return;

  // 新しい順に並べて keep 以外を削除
  usort($files, function($a, $b) {
    return filemtime($b) <=> filemtime($a);
  });

  $toDelete = array_slice($files, $keep);
  foreach ($toDelete as $f) {
    @unlink($f);
  }
}

/**
 * proxy文字列(http://host:port)から host:port を返す
 */
function proxy_hostport_(string $proxyUrl): string {
  $p = parse_url($proxyUrl);
  $host = $p['host'] ?? '';
  $port = $p['port'] ?? '';
  if ($host !== '' && $port !== '') return $host . ':' . $port;
  // parse_url が失敗するケースに備えたフォールバック
  return preg_replace('#^https?://#', '', $proxyUrl);
}

/**
 * 不良プロキシを収集（重複排除）
 */
function remember_bad_proxy_(string $proxyUrl): void {
  $hp = proxy_hostport_($proxyUrl);
  if ($hp === '') return;
  $GLOBALS['SCRAPE_BAD_PROXIES'][$hp] = true;
}

/**
 * 収集した不良プロキシ一覧を返す
 * @return string[] host:port の配列
 */
function get_bad_proxies(): array {
  $m = $GLOBALS['SCRAPE_BAD_PROXIES'] ?? [];
  if (!is_array($m)) return [];
  $keys = array_keys($m);
  sort($keys);
  return $keys;
}
