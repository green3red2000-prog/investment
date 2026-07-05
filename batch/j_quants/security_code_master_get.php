<?php
declare(strict_types=1);

/**
 * 証券コード取得（J-Quants 上場銘柄一覧 → CSV/TXT出力 → Driveアップロード）
 *
 * - PHP 7.4.30 (cli)
 * - デプロイ先: /opt/invest/j_quants
 * - APIキー: /opt/invest/j_quants/conf/config.php の JQUANTS_API_KEY を利用
 * - Driveアップロード: /opt/invest/scraping/lib/scraping_common.php を利用
 */

require __DIR__ . '/conf/config.php';
require '/opt/invest/scraping/lib/scraping_common.php';

date_default_timezone_set('Asia/Tokyo');

// =============================
// 設定
// =============================
const JOB_NAME = '証券コード取得';
const JQUANTS_EQUITIES_MASTER_URL = 'https://api.jquants.com/v2/equities/master';
const OUTPUT_DIR = '/opt/invest/j_quants/tmp';
const HTTP_TIMEOUT_SEC = 60;

// =============================
// メイン
// =============================
try {
    if (!defined('JQUANTS_API_KEY') || trim((string)JQUANTS_API_KEY) === '') {
        throw new RuntimeException('JQUANTS_API_KEY が未定義、または空です。');
    }

    $today = (new DateTime('now'))->format('Y-m-d');

    jqEnsureDir(OUTPUT_DIR);

    $csvPath = OUTPUT_DIR . '/' . JOB_NAME . '_' . $today . '.csv';
    $txtPath = OUTPUT_DIR . '/' . JOB_NAME . '_メッセージ_' . $today . '.txt';

    echo "=====================================================" . PHP_EOL;
    echo JOB_NAME . PHP_EOL;
    echo "=====================================================" . PHP_EOL;
    echo "date={$today}" . PHP_EOL;

    // 1) J-Quants 上場銘柄一覧を取得
    $rows = fetchEquitiesMaster($today);
    $count = count($rows);

    if ($count === 0) {
        throw new RuntimeException('J-Quants 上場銘柄一覧の取得結果が0件でした。');
    }

    // レスポンス上の情報適用年月日。通常は全行同じ想定。
    $asofDate = getRepresentativeDate($rows, $today);

    echo "asof_date={$asofDate}" . PHP_EOL;
    echo "count={$count}" . PHP_EOL;

    // 2) CSV出力
    writeEquitiesMasterCsv($csvPath, $rows);

    // 3) TXT出力
    $subjectLine = JOB_NAME . '：' . $today;
    $body =
        "証券コードの取得処理を終了しました。\n\n" .
        "情報適用年月日：{$asofDate}\n" .
        "銘柄数: {$count} 件\n";

    write_message_txt($txtPath, $subjectLine, $body);

    echo "ローカル出力完了:" . PHP_EOL;
    echo "- {$csvPath}" . PHP_EOL;
    echo "- {$txtPath}" . PHP_EOL;

    // 4) Driveアップロード → ローカル削除
    upload_outputs_and_cleanup(JOB_NAME, $today, $csvPath, $txtPath);

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
 * J-Quants 上場銘柄一覧を取得する。
 *
 * @return array<int, array<string, mixed>>
 */
function fetchEquitiesMaster(string $date): array
{
    $params = [
        'date' => $date,
    ];

    $url = JQUANTS_EQUITIES_MASTER_URL . '?' . http_build_query($params);

    echo "URL : {$url}" . PHP_EOL;

    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('curl_init に失敗しました。');
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT_SEC,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . JQUANTS_API_KEY,
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('[J-Quants] curl error: ' . $err);
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP Status : {$httpCode}" . PHP_EOL;

    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        throw new RuntimeException('[J-Quants] JSON decode error: ' . json_last_error_msg());
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        throw new RuntimeException("[J-Quants] HTTP {$httpCode}: {$body}");
    }

    $data = $json['data'] ?? [];
    if (!is_array($data)) {
        throw new RuntimeException('[J-Quants] レスポンス data が配列ではありません。');
    }

    return $data;
}

// =============================
// CSV / TXT helpers
// =============================

/**
 * @param array<int, array<string, mixed>> $rows
 */
function writeEquitiesMasterCsv(string $csvPath, array $rows): void
{
    $fp = fopen($csvPath, 'wb');
    if ($fp === false) {
        throw new RuntimeException("CSV作成に失敗: {$csvPath}");
    }

    try {
        // Excel向け UTF-8 BOM
        fwrite($fp, "\xEF\xBB\xBF");

        fputcsv($fp, [
            '情報適用年月日',
            '証券コード',
            '証券コード5桁',
            '銘柄名',
            '銘柄名(英語)',
            '17業種コード',
            '17業種コード名',
            '33業種コード',
            '33業種コード名',
            '規模コード',
            '市場区分コード',
            '市場区分名',
            '貸借信用区分',
            '貸借信用区分名',
            '商品区分コード',
        ]);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code5 = strvalOrEmpty($row['Code'] ?? '');
            $code4 = toFourDigitCode($code5);
            $date = normalizeDateHyphen(strvalOrEmpty($row['Date'] ?? ''));

            fputcsv($fp, [
                $date,
                $code4,
                $code5,
                strvalOrEmpty($row['CoName'] ?? ''),
                strvalOrEmpty($row['CoNameEn'] ?? ''),
                strvalOrEmpty($row['S17'] ?? ''),
                strvalOrEmpty($row['S17Nm'] ?? ''),
                strvalOrEmpty($row['S33'] ?? ''),
                strvalOrEmpty($row['S33Nm'] ?? ''),
                strvalOrEmpty($row['ScaleCat'] ?? ''),
                strvalOrEmpty($row['Mkt'] ?? ''),
                strvalOrEmpty($row['MktNm'] ?? ''),
                strvalOrEmpty($row['Mrgn'] ?? ''),
                strvalOrEmpty($row['MrgnNm'] ?? ''),
                strvalOrEmpty($row['ProdCat'] ?? ''),
            ]);
        }
    } finally {
        fclose($fp);
    }
}

/**
 * J-QuantsのCodeが5桁（例: 86970）の場合、通常の証券コード4桁（例: 8697）を返す。
 * 5桁以外の場合は、そのまま返す。
 */
function toFourDigitCode(string $code): string
{
    $code = trim($code);
    if (preg_match('/^[0-9A-Za-z]{5}$/', $code) === 1) {
        return substr($code, 0, 4);
    }
    return $code;
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function getRepresentativeDate(array $rows, string $fallback): string
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = strvalOrEmpty($row['Date'] ?? '');
        if ($date !== '') {
            return $date;
        }
    }
    return $fallback;
}

function normalizeDateHyphen(string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return '';
    }

    if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $date) === 1) {
        return str_replace('/', '-', $date);
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
