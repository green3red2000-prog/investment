<?php
declare(strict_types=1);

/**
 * 日次HTML取込 親ジョブ
 *
 * cron起動：10分毎
 *
 * 通常起動：
 *   - /opt/invest/scraping/data/complete_upload_daily_market_snapshot.txt を確認
 *   - 中身が本日YYYY-MM-DDでなければ終了
 *   - 本日YYYY-MM-DDを引数にして子PHPを順番に実行
 *   - 全て成功したら complete_upload_daily_market_snapshot.txt を削除
 *
 * リカバリ起動例：
 *   php run_daily_market_snapshot_jobs.php YYYY-MM-DD
 *
 * リカバリ起動時：以下を順次実行。
 *   daily_market_snapshot.php --date=2026-06-15 --force
 *   kabuhoyu_sokuhou.php 2026-06-15
 *   tekiji_disclosure.php 2026-06-15
 *   kessan_sokuhou.php 2026-06-15
 *   pts_morning_news.php 2026-06-15 --force
 *   
 * 	 ※リカバリ起動時は、complete_upload_daily_market_snapshot.txtが無くても動作します。
 * ログ：
 *   cron側でリダイレクトして出力する
 */

date_default_timezone_set('Asia/Tokyo');

$baseDir = __DIR__;
$dataDir = '/opt/invest/scraping/data';

$uploadCompleteFile = "{$dataDir}/complete_upload_daily_market_snapshot.txt";


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

function runChild(string $scriptPath,array $args = []): void {

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

$argDate = $argv[1] ?? '';
$isRecovery = ($argDate !== '');

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$targetDate = $isRecovery ? $argDate : $today;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    throw new InvalidArgumentException(
        "日付はyyyy-MM-dd形式で指定してください: {$targetDate}"
    );
}

logMsg("===== parent job start: targetDate={$targetDate}, recovery=" . ($isRecovery ? 'yes' : 'no') . " =====");

if (!$isRecovery) {
  $uploadedDate = readTrimmedFile($uploadCompleteFile);

  if ($uploadedDate === null) {
    logMsg("upload complete file not found. exit.");
    exit(0);
  }

  if ($uploadedDate !== $today) {
    logMsg("upload date mismatch. uploaded={$uploadedDate}, today={$today}. exit.");
    exit(0);
  }
}

$jobs = [
  [
    'daily_market_snapshot.php',
    array_merge(
      ["--date={$targetDate}"],
      $isRecovery ? ['--force'] : []
    )
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
    array_merge(
      [$targetDate],
      $isRecovery ? ['--force'] : []
    )
  ],
];

try {
  foreach ($jobs as [$script, $extraArgs]) {
    $scriptPath = "{$baseDir}/{$script}";

    if (!is_file($scriptPath)) {
      throw new RuntimeException("script not found: {$scriptPath}");
    }

    runChild($scriptPath, $extraArgs);
  }

  if (!$isRecovery && is_file($uploadCompleteFile)) {
    unlink($uploadCompleteFile);
    logMsg("upload complete file deleted: {$uploadCompleteFile}");
  }

  logMsg("===== parent job done =====");
  exit(0);

} catch (Throwable $e) {
  logMsg("ERROR: " . $e->getMessage());
  logMsg("===== parent job failed =====");
  exit(1);
}
