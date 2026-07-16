<?php
declare(strict_types=1);

/**
 * J-Quants API テスト
 *
 * 引数なしの場合は、以下の既存テストを実行する。
 *   ・日足APIテスト
 *   ・証券コードマスタAPIテスト
 *   ・カレンダーAPIテスト
 *
 * 【財務情報CSV出力】
 *
 * ソニーの場合：
 *   php JQuantsTest.php --out_fins_summary --code=67580
 *
 * 出力先：
 *   /opt/invest/j_quants/tmp/jquants_fins_summary_67580.csv
 *
 * オリックスの場合：
 *   php JQuantsTest.php --out_fins_summary --code=85910
 *
 * 出力先：
 *   /opt/invest/j_quants/tmp/jquants_fins_summary_85910.csv
 *
 * 【エラーになる実行】
 *
 * --codeを省略した場合：
 *   php JQuantsTest.php --out_fins_summary
 *
 * 出力：
 *   [ERROR] --out_fins_summary を指定する場合は、--code=(5桁コード)が必要です。
 *   Usage: php JQuantsTest.php --out_fins_summary --code=67580
 *
 * 4桁コードの場合：
 *   php JQuantsTest.php --out_fins_summary --code=6758
 *
 * 出力：
 *   [ERROR] --codeには数字または英大文字からなる5桁コードを指定してください: 6758
 *
 * 【引数なしの場合】
 *
 *   php JQuantsTest.php
 *
 * --out_fins_summaryが指定されていないため、
 * 従来の日足APIテスト、証券コードマスタAPIテスト、
 * カレンダーAPIテストを順番に実行する。
 */

require __DIR__ . '/conf/config.php';
require __DIR__ . '/lib/j_quants_common.php';

// =============================
// 設定
// =============================
const FINS_SUMMARY_OUTPUT_DIR = '/opt/invest/j_quants/tmp';
const FINS_SUMMARY_TARGET_DATE = '2026-07-03';
const FINS_SUMMARY_LIMIT = 10;

// =============================
// 引数判定
// =============================
$options = getopt('', [
    'out_fins_summary',
    'code:',
]);

if (isset($options['out_fins_summary'])) {
    $code = isset($options['code'])
        ? strtoupper(trim((string)$options['code']))
        : '';

    if ($code === '') {
        fwrite(
            STDERR,
            "[ERROR] --out_fins_summary を指定する場合は、--code=(5桁コード)が必要です。" . PHP_EOL
        );
        fwrite(
            STDERR,
            "Usage: php JQuantsTest.php --out_fins_summary --code=67580" . PHP_EOL
        );
        exit(1);
    }

    if (!preg_match('/^[0-9A-Z]{5}$/', $code)) {
        fwrite(
            STDERR,
            "[ERROR] --codeには数字または英大文字からなる5桁コードを指定してください: {$code}" . PHP_EOL
        );
        exit(1);
    }

    outputFinsSummaryCsv($code);
    exit(0);
}

const API_URL = 'https://api.jquants.com/v2/equities/bars/daily';

$params = [
//    'code' => '6758',
//    'from' => '2026-06-26',
    'date' => '2026-06-25',
];

$url = API_URL . '?' . http_build_query($params);

echo "=====================================================\n";
echo "J-Quants API Test\n";
echo "=====================================================\n";
echo "URL : {$url}\n\n";

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . JQUANTS_API_KEY,
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);

if ($response === false) {
    fwrite(STDERR, "[ERROR] curl error : " . curl_error($ch) . PHP_EOL);
    curl_close($ch);
    exit(1);
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status : {$httpCode}\n\n";

$json = json_decode($response, true);

if ($json === null) {
    echo "JSON decode error.\n";
    echo $response . PHP_EOL;
    exit(1);
}

if ($httpCode < 200 || $httpCode >= 300) {
    echo "----- ERROR BODY -----\n";
    echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$data = $json['data'] ?? [];

echo "Data Count : " . count($data) . PHP_EOL;

if (isset($json['pagination_key'])) {
    echo "pagination_key : " . $json['pagination_key'] . PHP_EOL;
} else {
    echo "pagination_key : none" . PHP_EOL;
}

echo PHP_EOL;
echo "Date,Code,AdjO,AdjH,AdjL,AdjC,AdjVo" . PHP_EOL;

foreach ($data as $row) {
    printf(
        "%s,%s,%s,%s,%s,%s,%s\n",
        $row['Date']  ?? '',
        $row['Code']  ?? '',
        $row['AdjO']  ?? '',
        $row['AdjH']  ?? '',
        $row['AdjL']  ?? '',
        $row['AdjC']  ?? '',
        $row['AdjVo'] ?? ''
    );
}

echo "Data Count : " . count($data) . PHP_EOL;

echo PHP_EOL;
echo "Done.\n";

echo "\n";
echo "=====================================================\n";
echo "J-Quants Equities Master Test\n";
echo "=====================================================\n";

//$masterDate = date('Y-m-d', strtotime('-10 days'));
$masterDate = date('Y-m-d');
$masterUrl = 'https://api.jquants.com/v2/equities/master?' . http_build_query([
    'date' => $masterDate,
]);

echo "URL : {$masterUrl}\n\n";

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $masterUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . JQUANTS_API_KEY,
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);

if ($response === false) {
    fwrite(STDERR, "[ERROR] master curl error : " . curl_error($ch) . PHP_EOL);
    curl_close($ch);
    exit(1);
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status : {$httpCode}\n\n";

$json = json_decode($response, true);

if ($json === null) {
    echo "Master JSON decode error.\n";
    echo $response . PHP_EOL;
    exit(1);
}

if ($httpCode < 200 || $httpCode >= 300) {
    echo "----- MASTER ERROR BODY -----\n";
    echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$masterData = $json['data'] ?? [];

echo "Master Date : {$masterDate}\n";
echo "Master Data Count : " . count($masterData) . PHP_EOL;
echo PHP_EOL;

echo "Date,Code,CoName,MktNm,ProdCat,S33,S33Nm,S17,S17Nm,ScaleCat,Mrgn,MrgnNm" . PHP_EOL;

foreach ($masterData as $row) {
    printf(
        "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
        $row['Date']     ?? '',
        $row['Code']     ?? '',
        $row['CoName']   ?? '',
        $row['MktNm']    ?? '',
        $row['ProdCat']  ?? '',
        $row['S33']      ?? '',
        $row['S33Nm']    ?? '',
        $row['S17']      ?? '',
        $row['S17Nm']    ?? '',
        $row['ScaleCat'] ?? '',
        $row['Mrgn']     ?? '',
        $row['MrgnNm']   ?? ''
    );
}

echo "Master Data Count : " . count($masterData) . PHP_EOL;
echo PHP_EOL;



echo "\n";
echo "=====================================================\n";
echo "J-Quants Market Calendar Test\n";
echo "=====================================================\n";

$calendarFrom = date('Y-m-d', strtotime('-10 days'));
$calendarUrl = 'https://api.jquants.com/v2/markets/calendar?' . http_build_query([
    'from' => $calendarFrom,
]);

echo "URL : {$calendarUrl}\n\n";

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $calendarUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . JQUANTS_API_KEY,
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);

if ($response === false) {
    fwrite(STDERR, "[ERROR] calendar curl error : " . curl_error($ch) . PHP_EOL);
    curl_close($ch);
    exit(1);
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status : {$httpCode}\n\n";

$json = json_decode($response, true);

if ($json === null) {
    echo "Calendar JSON decode error.\n";
    echo $response . PHP_EOL;
    exit(1);
}

if ($httpCode < 200 || $httpCode >= 300) {
    echo "----- CALENDAR ERROR BODY -----\n";
    echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$calendarData = $json['data'] ?? [];

echo "Calendar From : {$calendarFrom}\n";
echo "Calendar Data Count : " . count($calendarData) . PHP_EOL;
echo PHP_EOL;

echo "Date,HolDiv" . PHP_EOL;

foreach ($calendarData as $row) {
    printf(
        "%s,%s\n",
        $row['Date']   ?? '',
        $row['HolDiv'] ?? ''
    );
}

echo "Calendar Data Count : " . count($calendarData) . PHP_EOL;
echo PHP_EOL;


/**
 * jquants_fins_summaryから指定銘柄の財務情報を取得し、CSV出力する。
 *
 * @param string $code 5桁の証券コード
 */
function outputFinsSummaryCsv(string $code): void
{
    echo "=====================================================" . PHP_EOL;
    echo "J-Quants Fins Summary CSV Output" . PHP_EOL;
    echo "=====================================================" . PHP_EOL;
    echo "Code        : {$code}" . PHP_EOL;
    echo "Target Date : " . FINS_SUMMARY_TARGET_DATE . PHP_EOL;

    try {
        $pdo = jqBuildPdo();
        $sql = '
            SELECT *
            FROM stocks.jquants_fins_summary
            WHERE Code = :code
              AND DiscDate <= :target_date
            ORDER BY
                DiscDate DESC,
                DiscTime DESC,
                DiscNo DESC
            LIMIT ' . FINS_SUMMARY_LIMIT;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':code'        => $code,
            ':target_date' => FINS_SUMMARY_TARGET_DATE,
        ]);

        $rows = $stmt->fetchAll();

        if (!$rows) {
            fwrite(
                STDERR,
                "[ERROR] 対象データが見つかりませんでした。Code={$code}" . PHP_EOL
            );
            exit(1);
        }

        if (!is_dir(FINS_SUMMARY_OUTPUT_DIR)) {
            if (
                !mkdir(FINS_SUMMARY_OUTPUT_DIR, 0775, true)
                && !is_dir(FINS_SUMMARY_OUTPUT_DIR)
            ) {
                throw new RuntimeException(
                    '出力ディレクトリを作成できませんでした: '
                    . FINS_SUMMARY_OUTPUT_DIR
                );
            }
        }

        if (!is_writable(FINS_SUMMARY_OUTPUT_DIR)) {
            throw new RuntimeException(
                '出力ディレクトリに書き込みできません: '
                . FINS_SUMMARY_OUTPUT_DIR
            );
        }

        $outputPath = FINS_SUMMARY_OUTPUT_DIR
            . '/jquants_fins_summary_'
            . $code
            . '.csv';

        $fp = fopen($outputPath, 'wb');

        if ($fp === false) {
            throw new RuntimeException(
                'CSVファイルを開けませんでした: ' . $outputPath
            );
        }

        try {
            // Excel等で文字化けしないようUTF-8 BOMを付与
            fwrite($fp, "\xEF\xBB\xBF");

            // SELECT *で取得した列名を、そのままCSV見出しとして出力
            fputcsv($fp, array_keys($rows[0]));

            foreach ($rows as $row) {
                fputcsv(
                    $fp,
                    array_map(
                        static function ($value) {
                            return $value === null ? '' : $value;
                        },
                        array_values($row)
                    )
                );
            }
        } finally {
            fclose($fp);
        }

        echo "Data Count  : " . count($rows) . PHP_EOL;
        echo "Output File : {$outputPath}" . PHP_EOL;
        echo "Done." . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(
            STDERR,
            "[ERROR] 財務情報CSV出力に失敗しました: "
            . $e->getMessage()
            . PHP_EOL
        );
        exit(1);
    }
}