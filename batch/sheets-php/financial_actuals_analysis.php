<?php
declare(strict_types=1);

/**
 * financial_actuals_analysis.php
 *
 * 財務実績分析 日次親ジョブ。
 *
 * 実行順:
 *   1. financial_actuals_tdnet_get.php
 *   2. financial_actuals_edinet_get.php
 *   3. financial_actuals_export.php
 *   4. TDnet / EDINET / export のSUMMARYを1つのTXTへ集約
 *   5. 分析用CSV + 集約TXTをGoogle Driveへアップロード
 *   6. アップロード成功後、CSV/TXTをローカル削除
 *
 * 各子PHPの詳細ログは /opt/invest/logs に個別保存する。
 *
 * 実行例:
 *   # 通常実行
 *   # CSV/TXTをGoogle Driveへアップロードし、アップロード成功後にローカル削除する
 *   php /opt/invest/sheets-php/financial_actuals_analysis.php
 *
 *   # アップロードしない
 *   # CSV/TXTをGoogle Driveへアップロードせず、ローカルファイルも削除しない
 *   php /opt/invest/sheets-php/financial_actuals_analysis.php --noupload
 */

require '/opt/invest/j_quants/conf/config.php';
require '/opt/invest/j_quants/lib/j_quants_common.php';
require '/opt/invest/scraping/lib/scraping_common.php';

date_default_timezone_set('Asia/Tokyo');

const JOB_NAME = '財務実績分析';
const OUTPUT_DIR = '/opt/invest/sheets-php/tmp';
const LOG_DIR = '/opt/invest/logs';
const PHP_BIN = '/usr/bin/php';

const TDNET_SCRIPT = '/opt/invest/sheets-php/financial_actuals_tdnet_get.php';
const EDINET_SCRIPT = '/opt/invest/sheets-php/financial_actuals_edinet_get.php';
const EXPORT_SCRIPT = '/opt/invest/sheets-php/financial_actuals_export.php';

try {
    $args = parseCommandLineArgs($_SERVER['argv'] ?? []);
    $noUpload = isset($args['noupload']);

    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    ensureDirLocal(OUTPUT_DIR);
    ensureDirLocal(LOG_DIR);

    $tdnetLog = LOG_DIR . '/financial_actuals_tdnet_get.log';
    $edinetLog = LOG_DIR . '/financial_actuals_edinet_get.log';
    $exportLog = LOG_DIR . '/financial_actuals_export.log';

    $csvPath = OUTPUT_DIR . '/財務実績分析用データ_' . $today . '.csv';
    $txtPath = OUTPUT_DIR . '/財務実績分析_メッセージ_' . $today . '.txt';

    echo str_repeat('=', 72) . PHP_EOL;
    echo JOB_NAME . PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;
    echo "date={$today}" . PHP_EOL;
    echo 'noupload=' . ($noUpload ? 'true' : 'false') . PHP_EOL;

    $results = [];

    // 1) TDnet
    $results['TDnet'] = runChildPhp(TDNET_SCRIPT, [], $tdnetLog);
    echo sprintf("TDnet exit=%d log=%s\n", $results['TDnet']['exit_code'], $tdnetLog);

    // 2) EDINET
    $results['EDINET'] = runChildPhp(EDINET_SCRIPT, [], $edinetLog);
    echo sprintf("EDINET exit=%d log=%s\n", $results['EDINET']['exit_code'], $edinetLog);

    // 取得処理のどちらかが異常終了した場合は、不完全データのCSVを配布しない。
    if ($results['TDnet']['exit_code'] !== 0 || $results['EDINET']['exit_code'] !== 0) {
        $body = buildCombinedSummaryBody($today, $results, null, [
            'TDnet' => $tdnetLog,
            'EDINET' => $edinetLog,
        ]);
        write_message_txt($txtPath, JOB_NAME . '：' . $today . '【ERROR】', $body);
        throw new RuntimeException('TDnet / EDINET の取得処理で異常終了しました。個別ログを確認してください。');
    }

    // 3) 分析用CSV
    $results['EXPORT'] = runChildPhp(
        EXPORT_SCRIPT,
        ['--output=' . $csvPath],
        $exportLog
    );
    echo sprintf("EXPORT exit=%d log=%s\n", $results['EXPORT']['exit_code'], $exportLog);

    if ($results['EXPORT']['exit_code'] !== 0) {
        $body = buildCombinedSummaryBody($today, $results, null, [
            'TDnet' => $tdnetLog,
            'EDINET' => $edinetLog,
            'EXPORT' => $exportLog,
        ]);
        write_message_txt($txtPath, JOB_NAME . '：' . $today . '【ERROR】', $body);
        throw new RuntimeException('分析用CSV生成で異常終了しました。個別ログを確認してください。');
    }

    if (!is_file($csvPath) || filesize($csvPath) === 0) {
        throw new RuntimeException('分析用CSVが作成されていません: ' . $csvPath);
    }

    // 4) SUMMARY集約TXT
    $body = buildCombinedSummaryBody($today, $results, $csvPath, [
        'TDnet' => $tdnetLog,
        'EDINET' => $edinetLog,
        'EXPORT' => $exportLog,
    ]);
    write_message_txt($txtPath, JOB_NAME . '：' . $today, $body);

    echo "ローカル出力完了:" . PHP_EOL;
    echo "- {$csvPath}" . PHP_EOL;
    echo "- {$txtPath}" . PHP_EOL;

    // 5) Driveアップロード → 6) ローカル削除
    if ($noUpload) {
        echo "NOUPLOAD: Google Driveへのアップロードをスキップしました。" . PHP_EOL;
        echo "ローカルファイルを残します:" . PHP_EOL;
        echo "- {$csvPath}" . PHP_EOL;
        echo "- {$txtPath}" . PHP_EOL;
    } else {
        upload_outputs_and_cleanup(
            JOB_NAME,
            $today,
            $csvPath,
            $txtPath
        );
    }

    echo "DONE." . PHP_EOL;
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * 子PHPを実行し、stdout/stderrを指定ログへ保存する。
 */
function runChildPhp(string $script, array $args, string $logPath): array
{
    if (!is_file($script)) {
        throw new RuntimeException('子PHPが見つかりません: ' . $script);
    }

    $parts = [escapeshellarg(PHP_BIN), escapeshellarg($script)];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg((string)$arg);
    }

    $command = implode(' ', $parts)
        . ' > ' . escapeshellarg($logPath)
        . ' 2>&1';

    $dummy = [];
    $exitCode = 0;
    exec($command, $dummy, $exitCode);

    return [
        'exit_code' => $exitCode,
        'summary' => extractSummaryFromLog($logPath),
    ];
}

/**
 * ログ末尾の [SUMMARY] から後ろだけを返す。
 */
function extractSummaryFromLog(string $logPath): string
{
    if (!is_file($logPath)) {
        return '[SUMMARY] が取得できませんでした（ログファイルなし）';
    }

    $text = (string)file_get_contents($logPath);
    if ($text === '') {
        return '[SUMMARY] が取得できませんでした（ログ空）';
    }

    $pos = strrpos($text, '[SUMMARY]');
    if ($pos === false) {
        return '[SUMMARY] が見つかりませんでした。';
    }

    return trim(substr($text, $pos));
}

function buildCombinedSummaryBody(
    string $today,
    array $results,
    ?string $csvPath,
    array $logPaths
): string {
    $lines = [];
    $lines[] = '財務実績分析処理を終了しました。';
    $lines[] = '';
    $lines[] = '実行日：' . $today;
    if ($csvPath !== null) {
        $lines[] = '分析用CSV：' . basename($csvPath);
    }
    $lines[] = '';

    foreach (['TDnet', 'EDINET', 'EXPORT'] as $key) {
        if (!isset($results[$key])) continue;

        $lines[] = str_repeat('=', 68);
        $lines[] = '[' . $key . '] exit_code=' . (string)$results[$key]['exit_code'];
        if (isset($logPaths[$key])) {
            $lines[] = 'log=' . $logPaths[$key];
        }
        $lines[] = str_repeat('-', 68);
        $lines[] = (string)$results[$key]['summary'];
        $lines[] = '';
    }

    return implode("\n", $lines) . "\n";
}
/**
 * CLI引数を解析する。
 *
 * 例:
 *   --noupload
 */
function parseCommandLineArgs(array $argv): array
{
    $out = [];

    foreach ($argv as $idx => $arg) {
        if ($idx === 0) {
            continue;
        }

        if (preg_match('/^--([^=]+)=(.*)$/', (string)$arg, $m)) {
            $out[(string)$m[1]] = (string)$m[2];
            continue;
        }

        if (preg_match('/^--([^=]+)$/', (string)$arg, $m)) {
            $out[(string)$m[1]] = true;
        }
    }

    return $out;
}
function ensureDirLocal(string $dir): void
{
    if (is_dir($dir)) return;
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('mkdir failed: ' . $dir);
    }
}
