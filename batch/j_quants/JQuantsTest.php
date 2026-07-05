<?php
declare(strict_types=1);

/**
 * J-Quants API テスト
 *
 * 6758（ソニーグループ）の
 * 2026-01-01 以降の日足データを取得し、
 * 件数・先頭レコード・末尾レコード・pagination_key を確認する。
 */

require __DIR__ . '/conf/config.php';

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
echo "Date,AdjO,AdjH,AdjL,AdjC,AdjVo" . PHP_EOL;

foreach ($data as $row) {
    printf(
        "%s,%s,%s,%s,%s,%s\n",
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