<?php
declare(strict_types=1);

 /**
  * 日次HTML取込 親ジョブ
  *
  * 上位親ジョブから起動
  *
  * 通常起動：
  *   php run_daily_market_snapshot_jobs.php 000
  *   php run_daily_market_snapshot_jobs.php 001
  *
  *   モード000：
  *   - /opt/invest/scraping/state/upload/complete_upload_daily_market_snapshot_YYYYMMDD_000.txt を確認
  *   - 中身が本日YYYY-MM-DDでなければ終了
  *   - index_eod_import_from_saved_html.php を実行
  *
  *   モード001：
  *   - /opt/invest/scraping/state/upload/complete_upload_daily_market_snapshot_YYYYMMDD_001.txt を確認
  *   - 中身が本日YYYY-MM-DDでなければ終了
  *   - 以下の子PHPを順番に実行
  *     daily_market_snapshot.php
  *     kabuhoyu_sokuhou.php
  *     tekiji_disclosure.php
  *     kessan_sokuhou.php
  *     pts_morning_news.php
  *     zenmeigara_shikiho.php --mode=check
  *     market_data_extract.php --source=file --target=ALL
  *
  *   - 完了マーカーファイルは削除せず、そのまま残す
  *
  * リカバリ起動例：
  *   php run_daily_market_snapshot_jobs.php 000 2026-06-15
  *   php run_daily_market_snapshot_jobs.php 001 2026-06-15
  *
  * リカバリ起動時：
  *   モード000：
  *     index_eod_import_from_saved_html.php --target_date=2026-06-15
  *
  *   モード001：
  *     daily_market_snapshot.php --date=2026-06-15 --force
  *     kabuhoyu_sokuhou.php 2026-06-15
  *     tekiji_disclosure.php 2026-06-15
  *     kessan_sokuhou.php 2026-06-15
  *     pts_morning_news.php 2026-06-15 --force
  *     zenmeigara_shikiho.php 2026-06-15 --mode=check
  *     market_data_extract.php --date=2026-06-15 --source=file --target=ALL
  *   
  *   ※リカバリ起動時は、日付付きの完了マーカーファイルが無くても動作します。
  * ログ：
  *   上位親ジョブ側でリダイレクトして出力する
  */

date_default_timezone_set('Asia/Tokyo');

$baseDir = __DIR__;
$uploadStateDir = '/opt/invest/scraping/state/upload';

function logMsg(string $msg): void {
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
  echo $line;
}

function readTrimmedFile(string $path): ?string {
  if (!is_file($path)) {
    return null;
  }

  $s = file_get_contents($path);
  if ($s === false) {
    return null;
  }

  return trim($s);
}

function runChild(string $scriptPath, array $args = []): void {

  $cmdParts = [
    PHP_BINARY,
    $scriptPath,
    ...$args,
  ];
  $cmd = implode(' ', array_map('escapeshellarg', $cmdParts)) . ' 2>&1';

  logMsg("START: {$cmd}");

  $output = [];
  $exitCode = 0;
  exec($cmd, $output, $exitCode);

  foreach ($output as $line) {
    logMsg("  {$line}");
  }

  if ($exitCode !== 0) {
    throw new RuntimeException("child failed: {$scriptPath}, exitCode={$exitCode}");
  }

  logMsg("OK: {$scriptPath}");
}

$mode = $argv[1] ?? '';
$argDate = $argv[2] ?? '';

if (!in_array($mode, ['000', '001'], true)) {
  throw new InvalidArgumentException(
    "起動モードは000または001を指定してください: {$mode}"
  );
}

$isRecovery = ($argDate !== '');

$today = (new DateTimeImmutable(
  'now',
  new DateTimeZone('Asia/Tokyo')
))->format('Y-m-d');

$targetDate = $isRecovery ? $argDate : $today;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    throw new InvalidArgumentException(
        "日付はyyyy-MM-dd形式で指定してください: {$targetDate}"
    );
}
$todayCompact = str_replace('-', '', $today);
$uploadCompleteFile = "{$uploadStateDir}/complete_upload_daily_market_snapshot_{$todayCompact}_{$mode}.txt";

logMsg(
  "===== parent job start: mode={$mode}, targetDate={$targetDate}, recovery=" .
  ($isRecovery ? 'yes' : 'no') .
  " ====="
);
  
if (!$isRecovery) {
  logMsg("check upload complete file: {$uploadCompleteFile}");

  $uploadedDate = readTrimmedFile($uploadCompleteFile);

  if ($uploadedDate === null) {
    logMsg("upload complete file not found. exit.");
    exit(0);
  }

  if ($uploadedDate !== $today) {
    logMsg(
      "upload date mismatch. uploaded={$uploadedDate}, today={$today}. exit."
    );
    exit(0);
  }

  logMsg("upload complete file confirmed: {$uploadCompleteFile}");
}

if ($mode === '000') {

  if ($isRecovery) {
    $jobs = [
      [
        'index_eod_import_from_saved_html.php',
        ["--target_date={$targetDate}"]
      ],
    ];
  } else {
    $jobs = [
      [
        'index_eod_import_from_saved_html.php',
        []
      ],
    ];
  }

} else {

  if ($isRecovery) {
    $jobs = [
      [
        'daily_market_snapshot.php',
        ["--date={$targetDate}", '--force']
      ],

      [
        'kabuhoyu_sokuhou.php',
        [$targetDate]
      ],

      [
        'tekiji_disclosure.php',
        [$targetDate]
      ],

      [
        'kessan_sokuhou.php',
        [$targetDate]
      ],

      [
        'pts_morning_news.php',
        [$targetDate, '--force']
      ],

      [
        'zenmeigara_shikiho.php',
        [$targetDate, '--mode=check']
      ],
      	  
      [
        'market_data_extract.php',
        [
          "--date={$targetDate}",
          '--source=file',
          '--target=ALL'
        ]
      ],
    ];
  } else {
    $jobs = [
      [
        'daily_market_snapshot.php',
        []
      ],

      [
        'kabuhoyu_sokuhou.php',
        []
      ],

      [
        'tekiji_disclosure.php',
        []
      ],

      [
        'kessan_sokuhou.php',
        []
      ],

      [
        'pts_morning_news.php',
        []
      ],

      [
        'zenmeigara_shikiho.php',
        ['--mode=check']
      ],
      	  
      [
        'market_data_extract.php',
        [
          '--source=file',
          '--target=ALL'
        ]
      ],
    ];
  }
}

try {
  foreach ($jobs as [$script, $extraArgs]) {
    $scriptPath = "{$baseDir}/{$script}";

    if (!is_file($scriptPath)) {
      throw new RuntimeException("script not found: {$scriptPath}");
    }

    runChild($scriptPath, $extraArgs);
  }

  logMsg("===== parent job done: mode={$mode} =====");
  exit(0);

} catch (Throwable $e) {
  logMsg("ERROR: " . $e->getMessage());
  logMsg("===== parent job failed: mode={$mode} =====");
  exit(1);
}
