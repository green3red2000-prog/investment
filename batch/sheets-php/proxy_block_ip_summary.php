<?php
declare(strict_types=1);

/**
 * ブロックIP集計
 *
 * proxy_url_failures.json を読み込み、
 * 1. URLごとの failures 別件数
 * 2. failures ごとの IP 別件数
 * を集計してTXT出力し、Google Driveへアップロードする。
 *
 * PHP 7.4.30 (cli)
 * デプロイ先: /opt/invest/sheets-php/proxy_block_ip_summary.php
 */

require '/opt/invest/scraping/lib/scraping_common.php';
require_once '/opt/invest/scraping/vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

// =======================================================
// 設定
// =======================================================
const JOB_NAME   = 'ブロックIP集計';
const INPUT_FILE = '/opt/invest/scraping/state/proxy_url_failures.json';
const OUTPUT_DIR = '/opt/invest/sheets-php/tmp';

// =======================================================
// メイン
// =======================================================
try {
    $todayIso = date('Y-m-d');

    ensureOutputDir(OUTPUT_DIR);

    $txtPath = OUTPUT_DIR . '/' . JOB_NAME . '_メッセージ_' . $todayIso . '.txt';

    echo "=====================================================" . PHP_EOL;
    echo JOB_NAME . PHP_EOL;
    echo "=====================================================" . PHP_EOL;
    echo "date={$todayIso}" . PHP_EOL;
    echo "input=" . INPUT_FILE . PHP_EOL;

    // 1) ブロックIPデータの取得
    $data = loadBlockIpData(INPUT_FILE);

    echo 'url_count=' . count($data) . PHP_EOL;

    // 2) 集計処理
    list($urlFailureCounts, $failureIpCounts) = aggregateBlockIpData($data);

    // 3) テキストファイル出力
    $subjectLine = JOB_NAME . '：' . $todayIso;
    $summaryText = buildSummaryText($urlFailureCounts, $failureIpCounts);

    $body =
        "ブロックIPの集計処理を終了しました。\n\n" .
        $summaryText;

    write_message_txt($txtPath, $subjectLine, $body);

    echo "ローカル出力完了:" . PHP_EOL;
    echo "- {$txtPath}" . PHP_EOL;

    // 4) Driveアップロード → 成功後ローカル削除
    uploadTxtAndCleanup($txtPath);

    echo "DONE." . PHP_EOL;
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// =======================================================
// 入力
// =======================================================

/**
 * proxy_url_failures.json を読み込む。
 *
 * @return array<string, mixed>
 */
function loadBlockIpData(string $file): array
{
    if (!is_readable($file)) {
        throw new RuntimeException("ブロックIPデータを読み込めません: {$file}");
    }

    $json = file_get_contents($file);
    if ($json === false) {
        throw new RuntimeException("ブロックIPデータの読み込みに失敗しました: {$file}");
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'ブロックIPデータのJSON解析に失敗しました: ' . json_last_error_msg()
        );
    }

    return $data;
}

// =======================================================
// 集計
// =======================================================

/**
 * 集計処理を実施する。
 *
 * $urlFailureCounts:
 *   URLごとの failures 別件数。
 *   URL順はJSON内の出現順を維持する。
 *
 * $failureIpCounts:
 *   failures ごとの IP 別件数。
 *   同じIPが複数URLに存在する場合は合算する。
 *
 * @param array<string, mixed> $data
 * @return array{0: array<string, array<int, int>>, 1: array<int, array<string, array{count:int, order:int}>>}
 */
function aggregateBlockIpData(array $data): array
{
    $urlFailureCounts = [];
    $failureIpCounts  = [];

    // IPの同件数時に、元データで先に出現したIPを先にするための順序番号。
    $ipOrder = 0;

    foreach ($data as $url => $ipEntries) {
        if (!is_array($ipEntries)) {
            continue;
        }

        $urlFailureCounts[(string)$url] = [];

        foreach ($ipEntries as $ip => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!array_key_exists('failures', $entry)) {
                continue;
            }

            $failures = (int)$entry['failures'];

            // failures は正の値のみ集計対象とする。
            if ($failures <= 0) {
                continue;
            }

            // 2-1) URLごとの failures 件数
            if (!isset($urlFailureCounts[(string)$url][$failures])) {
                $urlFailureCounts[(string)$url][$failures] = 0;
            }
            $urlFailureCounts[(string)$url][$failures]++;

            // 2-2) failuresごとのIP件数
            if (!isset($failureIpCounts[$failures])) {
                $failureIpCounts[$failures] = [];
            }

            $ipKey = (string)$ip;

            if (!isset($failureIpCounts[$failures][$ipKey])) {
                $failureIpCounts[$failures][$ipKey] = [
                    'count' => 0,
                    'order' => $ipOrder++,
                ];
            }

            $failureIpCounts[$failures][$ipKey]['count']++;
        }

        // URL内は failures の降順。
        krsort($urlFailureCounts[(string)$url], SORT_NUMERIC);
    }

    // failures 自体も降順。
    krsort($failureIpCounts, SORT_NUMERIC);

    // failures 内は IP件数の降順。
    // 件数が同じ場合は、元データでの初出順を維持する。
    foreach ($failureIpCounts as $failures => $ipCounts) {
        uasort(
            $ipCounts,
            static function (array $a, array $b): int {
                if ($a['count'] === $b['count']) {
                    return $a['order'] <=> $b['order'];
                }

                return $b['count'] <=> $a['count'];
            }
        );

        $failureIpCounts[$failures] = $ipCounts;
    }

    return [$urlFailureCounts, $failureIpCounts];
}

// =======================================================
// テキスト生成
// =======================================================

/**
 * 集計結果の本文を生成する。
 *
 * @param array<string, array<int, int>> $urlFailureCounts
 * @param array<int, array<string, array{count:int, order:int}>> $failureIpCounts
 */
function buildSummaryText(array $urlFailureCounts, array $failureIpCounts): string
{
    $lines = [];

    $lines[] = '■URLごとのfailures件数';
    $lines[] = '';

    foreach ($urlFailureCounts as $url => $failureCounts) {
        $lines[] = 'URL：' . $url;

        if (count($failureCounts) === 0) {
            $lines[] = 'failures：該当なし';
        } else {
            foreach ($failureCounts as $failures => $count) {
                $lines[] = 'failures：' . $failures . '：' . $count . '件';
            }
        }

        $lines[] = '';
    }

    $lines[] = '■failuresごとのIP件数';
    $lines[] = '';

    foreach ($failureIpCounts as $failures => $ipCounts) {
        $lines[] = 'failures：' . $failures;

        foreach ($ipCounts as $ip => $info) {
            $lines[] = $ip . '：' . $info['count'] . '件';
        }

        $lines[] = '';
    }

    return rtrim(implode("\n", $lines)) . "\n";
}

// =======================================================
// Driveアップロード
// =======================================================

/**
 * TXTのみGoogle Driveへアップロードし、成功後にローカルTXTを削除する。
 */
function uploadTxtAndCleanup(string $txtPath): void
{
    if (!file_exists($txtPath)) {
        throw new RuntimeException("TXTがありません: {$txtPath}");
    }

    $uploadFolderId = DRIVE_UPLOAD_FOLDER_ID;

    if (
        $uploadFolderId === '' ||
        !preg_match('/^[A-Za-z0-9_-]{10,}$/', $uploadFolderId)
    ) {
        throw new RuntimeException("Invalid DRIVE_UPLOAD_FOLDER_ID: {$uploadFolderId}");
    }

    $client = build_oauth_client_();
    $drive  = new Google\Service\Drive($client);

    $txtName = basename($txtPath);

    $uploadedTxt = upload_file_with_retry_(
        $drive,
        $txtPath,
        $txtName,
        'text/plain',
        $uploadFolderId
    );

    echo
        'Uploaded/updated TXT: ' .
        $uploadedTxt->getName() .
        ' (' .
        $uploadedTxt->getId() .
        ')' . PHP_EOL;

    if (!unlink($txtPath)) {
        throw new RuntimeException("アップロード後のローカルTXT削除に失敗しました: {$txtPath}");
    }

    echo "DONE: uploaded & local TXT removed." . PHP_EOL;
}

// =======================================================
// ローカル出力先
// =======================================================

function ensureOutputDir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }

    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("mkdir failed: {$dir}");
    }
}
