<?php
declare(strict_types=1);

/**
 * カレンダー取得（J-Quants 取引カレンダー → CSV/TXT出力 → Driveアップロード）
 *
 * - PHP 7.4.30 (cli)
 * - デプロイ先: /opt/invest/j_quants
 * - APIキー: /opt/invest/j_quants/conf/config.php の JQUANTS_API_KEY を利用
 * - Driveアップロード: /opt/invest/scraping/lib/scraping_common.php を利用
 */

require __DIR__ . '/conf/config.php';
require __DIR__ . '/lib/j_quants_common.php';
require '/opt/invest/scraping/lib/scraping_common.php';

date_default_timezone_set('Asia/Tokyo');

// =============================
// 設定
// =============================
const JOB_NAME = 'カレンダー取得';
const OUTPUT_DIR = '/opt/invest/j_quants/tmp';

// =============================
// メイン
// =============================
try {
    if (!defined('JQUANTS_API_KEY') || trim((string)JQUANTS_API_KEY) === '') {
        throw new RuntimeException('JQUANTS_API_KEY が未定義、または空です。');
    }

    $today = new DateTime('now');
    $todayIso = $today->format('Y-m-d');

    $fromDate = getFirstDayOfMonth((clone $today)->modify('-3 months'));
    $toDate   = getLastDayOfMonth((clone $today)->modify('+3 months'));

    $fromApi = $fromDate->format('Ymd');
    $toApi   = $toDate->format('Ymd');
    $fromIso = $fromDate->format('Y-m-d');
    $toIso   = $toDate->format('Y-m-d');

    jqEnsureDir(OUTPUT_DIR);

    $csvPath = OUTPUT_DIR . '/' . JOB_NAME . '_' . $todayIso . '.csv';
    $txtPath = OUTPUT_DIR . '/' . JOB_NAME . '_メッセージ_' . $todayIso . '.txt';

    echo "=====================================================" . PHP_EOL;
    echo JOB_NAME . PHP_EOL;
    echo "=====================================================" . PHP_EOL;
    echo "date={$todayIso}" . PHP_EOL;
    echo "from={$fromIso}" . PHP_EOL;
    echo "to={$toIso}" . PHP_EOL;

    // 1) J-Quants 取引カレンダーを取得
    $rows = fetchMarketCalendar($fromApi, $toApi);
    $count = count($rows);

    if ($count === 0) {
        throw new RuntimeException('J-Quants 取引カレンダーの取得結果が0件でした。');
    }

    echo "count={$count}" . PHP_EOL;

    // 2) CSV出力
    writeMarketCalendarCsv($csvPath, $rows);

    // 3) TXT出力
    $subjectLine = JOB_NAME . '：' . $todayIso;
    $body =
        "カレンダーの取得処理を終了しました。\n\n" .
        "日付（FROM）：{$fromIso}\n" .
        "日付（TO）：{$toIso}\n" .
        "取得数: {$count} 件\n";

    write_message_txt($txtPath, $subjectLine, $body);

    echo "ローカル出力完了:" . PHP_EOL;
    echo "- {$csvPath}" . PHP_EOL;
    echo "- {$txtPath}" . PHP_EOL;

    // 4) Driveアップロード → ローカル削除
    upload_outputs_and_cleanup(JOB_NAME, $todayIso, $csvPath, $txtPath);

    echo "DONE." . PHP_EOL;
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// =============================
// J-Quants API
// =============================

/**
 * J-Quants 取引カレンダーを取得する。
 *
 * @return array<int, array<string, mixed>>
 */
function fetchMarketCalendar(string $from, string $to): array
{
    $params = [
        'from' => $from,
        'to'   => $to,
    ];

    return jquantsGetAll('/v2/markets/calendar', $params);
}

// =============================
// CSV / TXT helpers
// =============================

/**
 * @param array<int, array<string, mixed>> $rows
 */
function writeMarketCalendarCsv(string $csvPath, array $rows): void
{
    $fp = fopen($csvPath, 'wb');
    if ($fp === false) {
        throw new RuntimeException("CSV作成に失敗: {$csvPath}");
    }

    try {
        // Excel向け UTF-8 BOM
        fwrite($fp, "\xEF\xBB\xBF");

        fputcsv($fp, [
            '日付',
            '日本市場休日区分',
            '日本市場休日区分名',
        ]);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = formatIsoDate(strvalOrEmpty($row['Date'] ?? ''));
            $holDiv = strvalOrEmpty($row['HolDiv'] ?? '');

            fputcsv($fp, [
                $date,
                $holDiv,
                getHolDivName($holDiv),
            ]);
        }
    } finally {
        fclose($fp);
    }
}

function getHolDivName(string $holDiv): string
{
    switch ($holDiv) {
        case '0':
            return '非営業日';
        case '1':
            return '営業日';
        case '2':
            return '東証の半日立会日';
        case '3':
            return '祝日取引のある非営業日';
        default:
            return '';
    }
}

function getFirstDayOfMonth(DateTime $date): DateTime
{
    $date->modify('first day of this month');
    $date->setTime(0, 0, 0);
    return $date;
}

function getLastDayOfMonth(DateTime $date): DateTime
{
    $date->modify('last day of this month');
    $date->setTime(0, 0, 0);
    return $date;
}

function formatIsoDate(string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y/m/d', $date);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    return $date;
}
function strvalOrEmpty($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_scalar($value)) {
        return trim((string)$value);
    }
    return '';
}

function jqEnsureDir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }

    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("mkdir failed: {$dir}");
    }
}
