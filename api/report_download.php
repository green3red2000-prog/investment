<?php
declare(strict_types=1);

/**
 * report_download.php
 *
 * 簡易投資分析レポート出力Webアプリ
 * PHP 7.4.30
 *
 * - プルダウンで投資分析レポートを選択
 * - 「出力」でCSVを直接ダウンロード
 * - 投資分析レポート追加時は REPORTS と対応する生成関数を追加する
 *
 * 現在の投資分析レポート:
 *   industry33_operating_margin_distribution
 *   東証33業種別の営業利益率分布
 */

require '/opt/invest/j_quants/conf/config.php';
require '/opt/invest/j_quants/lib/j_quants_common.php';
require '/opt/invest/scraping/lib/scraping_common.php';
require_once '/opt/invest/scraping/vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

const MASTER_FOLDER_PATH = ['投資', 'プログラミング', 'GAS', 'マスタ'];
const SECURITY_CODE_MASTER_NAME = '証券コードマスタ';

/**
 * 投資分析レポート定義。
 * 今後投資分析レポートを追加するときは、ここへ1件追加し、
 * generateReport() の switch に生成処理を追加する。
 */
const REPORTS = [
    'industry33_operating_margin_distribution' => '東証33業種別の営業利益率分布',
];

try {
    $mode = strtolower(trim((string)($_GET['mode'] ?? '')));

    if ($mode === 'download') {
        $reportKey = trim((string)($_GET['report'] ?? ''));

        if (!isset(REPORTS[$reportKey])) {
            throw new RuntimeException('不正な投資分析レポートが指定されました。');
        }

        generateReport($reportKey);
        exit;
    }

    renderIndexPage();
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo renderErrorPage($e->getMessage());
    exit;
}

/**
 * 投資分析レポート生成ルータ。
 */
function generateReport(string $reportKey): void
{
    switch ($reportKey) {
        case 'industry33_operating_margin_distribution':
            downloadIndustry33OperatingMarginDistribution();
            return;

        default:
            throw new RuntimeException("未実装の投資分析レポートです: {$reportKey}");
    }
}

/**
 * 東証33業種別の営業利益率分布をCSV出力する。
 *
 * 営業利益率 = 最新の通期実績 OP / Sales * 100
 *
 * 採用する財務レコード:
 * - jquants_fins_summary
 * - CurPerType = FY
 * - Sales, OP がともにNULLでない
 * - 証券コードごとに DiscDate, DiscTime, DiscNo が最新の1件
 *
 * 業種:
 * - Google Drive「証券コードマスタ」の「33業種コード名」
 *
 * 除外:
 * - 指数（市場区分コード = -）
 * - ETF等（市場区分コード = 109）
 * - TOKYO PRO Market（市場区分コード = 105）
 * - Sales <= 0
 */
function downloadIndustry33OperatingMarginDistribution(): void
{
    $pdo = jqBuildPdo();

    // 証券コード5桁 => 業種情報
    $securityMap = loadSecurityCodeMasterMap();

    // DBから各銘柄の最新FY実績を取得
    $latestFyRows = fetchLatestActualFyRows($pdo);

    // 業種ごとに営業利益率を格納
    $groups = [];
    $usedCompanyCount = 0;

    foreach ($latestFyRows as $row) {
        $code5 = normalizeCode5((string)($row['Code'] ?? ''));
        if ($code5 === '' || !isset($securityMap[$code5])) {
            continue;
        }

        $master = $securityMap[$code5];
        $industryCode = trim((string)($master['industry33_code'] ?? ''));
        $industryName = trim((string)($master['industry33_name'] ?? ''));

        if ($industryName === '') {
            continue;
        }

        $sales = toFloatOrNullLocal($row['Sales'] ?? null);
        $op = toFloatOrNullLocal($row['OP'] ?? null);

        if ($sales === null || $op === null || $sales <= 0.0) {
            continue;
        }

        $margin = ($op / $sales) * 100.0;

        if (!is_finite($margin)) {
            continue;
        }

        if (!isset($groups[$industryName])) {
            $groups[$industryName] = [
                'industry_code' => $industryCode,
                'values' => [],
            ];
        }

        $groups[$industryName]['values'][] = $margin;
        $usedCompanyCount++;
    }

    // 業種コード順。コードが空の場合は業種名順へ。
    uasort($groups, function (array $a, array $b): int {
        $ca = (string)($a['industry_code'] ?? '');
        $cb = (string)($b['industry_code'] ?? '');

        if ($ca === $cb) {
            return 0;
        }
        if ($ca === '') {
            return 1;
        }
        if ($cb === '') {
            return -1;
        }

        return strcmp($ca, $cb);
    });

    $today = date('Y-m-d');
    $fileName = '東証33業種別_営業利益率分布_' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename*=UTF-8\'\''
        . rawurlencode($fileName)
    );
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $fp = fopen('php://output', 'wb');
    if ($fp === false) {
        throw new RuntimeException('CSV出力ストリームを開けませんでした。');
    }

    // Excel向けUTF-8 BOM
    fwrite($fp, "\xEF\xBB\xBF");

    // 投資分析レポート情報
    fputcsv($fp, ['投資分析レポート名', REPORTS['industry33_operating_margin_distribution']]);
    fputcsv($fp, ['出力日', $today]);
    fputcsv($fp, ['営業利益率', 'OP ÷ Sales × 100']);
    fputcsv($fp, ['財務データ', 'jquants_fins_summary の銘柄別最新FY実績']);
    fputcsv($fp, ['集計対象銘柄数', (string)$usedCompanyCount]);
    fputcsv($fp, []);

    fputcsv($fp, [
        '33業種コード',
        '33業種コード名',
        '銘柄数',
        '平均(%)',
        '標準偏差',
        '最小値(%)',
        'P10(%)',
        'P25(%)',
        '中央値P50(%)',
        'P75(%)',
        'P90(%)',
        '最大値(%)',
        '営業黒字率(%)',
        '営業利益率5%以上率(%)',
        '営業利益率10%以上率(%)',
        '営業利益率20%以上率(%)',
    ]);

    foreach ($groups as $industryName => $group) {
        $values = $group['values'];
        sort($values, SORT_NUMERIC);

        $n = count($values);
        if ($n === 0) {
            continue;
        }

        $mean = array_sum($values) / $n;
        $stddev = populationStdDev($values, $mean);

        $positiveCount = countIf($values, function (float $v): bool {
            return $v > 0.0;
        });
        $ge5Count = countIf($values, function (float $v): bool {
            return $v >= 5.0;
        });
        $ge10Count = countIf($values, function (float $v): bool {
            return $v >= 10.0;
        });
        $ge20Count = countIf($values, function (float $v): bool {
            return $v >= 20.0;
        });

        fputcsv($fp, [
            (string)$group['industry_code'],
            $industryName,
            (string)$n,
            fmt($mean),
            fmt($stddev),
            fmt($values[0]),
            fmt(percentile($values, 0.10)),
            fmt(percentile($values, 0.25)),
            fmt(percentile($values, 0.50)),
            fmt(percentile($values, 0.75)),
            fmt(percentile($values, 0.90)),
            fmt($values[$n - 1]),
            fmt(($positiveCount / $n) * 100.0),
            fmt(($ge5Count / $n) * 100.0),
            fmt(($ge10Count / $n) * 100.0),
            fmt(($ge20Count / $n) * 100.0),
        ]);
    }

    fclose($fp);
}

/**
 * jquants_fins_summary から銘柄ごとの最新FY実績を取得する。
 *
 * MariaDB 10.3でも動かしやすいよう、ウィンドウ関数には依存せず、
 * Code順・最新順で取得してPHP側で先頭1件を採用する。
 */
function fetchLatestActualFyRows(PDO &$pdo): array
{
    jqEnsurePdoAlive($pdo);

    $sql = "
        SELECT
            Code,
            DiscDate,
            DiscTime,
            DiscNo,
            DocType,
            CurPerType,
            CurFYSt,
            CurFYEn,
            Sales,
            OP
        FROM jquants_fins_summary
        WHERE CurPerType = 'FY'
          AND Sales IS NOT NULL
          AND OP IS NOT NULL
        ORDER BY
            Code ASC,
            DiscDate DESC,
            DiscTime DESC,
            DiscNo DESC
    ";

    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        throw new RuntimeException('jquants_fins_summary の取得に失敗しました。');
    }

    $out = [];
    $seen = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $code5 = normalizeCode5((string)($row['Code'] ?? ''));
        if ($code5 === '' || isset($seen[$code5])) {
            continue;
        }

        $seen[$code5] = true;
        $out[] = $row;
    }

    return $out;
}

/**
 * 証券コードマスタを読み、
 * 証券コード5桁をキーに33業種を返す。
 */
function loadSecurityCodeMasterMap(): array
{
    $values = loadSpreadsheetValuesLocal(SECURITY_CODE_MASTER_NAME);
    if (count($values) < 2) {
        throw new RuntimeException('証券コードマスタにデータがありません。');
    }

    $header = normalizeHeaderLocal($values[0]);

    $code5Idx = requireHeaderIndexLocal(
        $header,
        '証券コード5桁',
        SECURITY_CODE_MASTER_NAME
    );
    $marketCodeIdx = requireHeaderIndexLocal(
        $header,
        '市場区分コード',
        SECURITY_CODE_MASTER_NAME
    );
    $industryCodeIdx = requireHeaderIndexLocal(
        $header,
        '33業種コード',
        SECURITY_CODE_MASTER_NAME
    );
    $industryNameIdx = requireHeaderIndexLocal(
        $header,
        '33業種コード名',
        SECURITY_CODE_MASTER_NAME
    );

    $out = [];

    for ($i = 1; $i < count($values); $i++) {
        $row = $values[$i];

        $code5 = normalizeCode5((string)($row[$code5Idx] ?? ''));
        if ($code5 === '') {
            continue;
        }

        $marketCode = trim((string)($row[$marketCodeIdx] ?? ''));

        // 全銘柄基本情報取得と同じ考え方で特殊銘柄を除外
        if (
            $marketCode === '-' ||
            $marketCode === '109' ||
            $marketCode === '105'
        ) {
            continue;
        }

        $out[$code5] = [
            'industry33_code' => trim((string)($row[$industryCodeIdx] ?? '')),
            'industry33_name' => trim((string)($row[$industryNameIdx] ?? '')),
        ];
    }

    return $out;
}

function loadSpreadsheetValuesLocal(string $fileName): array
{
    $client = build_oauth_client_();
    $drive = new Google\Service\Drive($client);
    $sheets = new Google\Service\Sheets($client);

    $folderId = resolveFolderIdByPathLocal($drive, MASTER_FOLDER_PATH);
    $fileId = findSpreadsheetFileIdByNameLocal($drive, $folderId, $fileName);

    if ($fileId === null) {
        throw new RuntimeException(
            'マスタスプレッドシートが見つかりません: ' . $fileName
        );
    }

    $ss = $sheets->spreadsheets->get($fileId);
    $sheet0 = $ss->getSheets()[0] ?? null;

    if ($sheet0 === null) {
        throw new RuntimeException(
            'マスタのシート取得に失敗: ' . $fileName
        );
    }

    $title = $sheet0->getProperties()->getTitle();

    $resp = $sheets->spreadsheets_values->get(
        $fileId,
        $title . '!A:Z'
    );

    return $resp->getValues() ?? [];
}

function resolveFolderIdByPathLocal(
    Google\Service\Drive $drive,
    array $folders
): string {
    $parent = 'root';

    foreach ($folders as $name) {
        $name = (string)$name;
        if ($name === '') {
            continue;
        }

        $q = sprintf(
            "name = '%s' and '%s' in parents and trashed = false "
            . "and mimeType = 'application/vnd.google-apps.folder'",
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
            throw new RuntimeException(
                'Folder not found: ' . implode('/', $folders)
            );
        }

        $parent = $files[0]->getId();
    }

    return $parent;
}

function findSpreadsheetFileIdByNameLocal(
    Google\Service\Drive $drive,
    string $folderId,
    string $fileName
): ?string {
    $q = sprintf(
        "name = '%s' and '%s' in parents and trashed = false "
        . "and mimeType = 'application/vnd.google-apps.spreadsheet'",
        str_replace("'", "\\'", $fileName),
        $folderId
    );

    $res = $drive->files->listFiles([
        'q' => $q,
        'fields' => 'files(id,name)',
        'pageSize' => 10,
    ]);

    $files = $res->getFiles();
    if (!$files || count($files) === 0) {
        return null;
    }

    return $files[0]->getId();
}

function percentile(array $sortedValues, float $p): float
{
    $n = count($sortedValues);

    if ($n === 0) {
        return NAN;
    }
    if ($n === 1) {
        return (float)$sortedValues[0];
    }

    // 線形補間: index = (n - 1) * p
    $index = ($n - 1) * $p;
    $lower = (int)floor($index);
    $upper = (int)ceil($index);

    if ($lower === $upper) {
        return (float)$sortedValues[$lower];
    }

    $weight = $index - $lower;

    return
        ((float)$sortedValues[$lower] * (1.0 - $weight)) +
        ((float)$sortedValues[$upper] * $weight);
}

function populationStdDev(array $values, float $mean): float
{
    $n = count($values);
    if ($n === 0) {
        return NAN;
    }

    $sum = 0.0;

    foreach ($values as $value) {
        $d = ((float)$value) - $mean;
        $sum += $d * $d;
    }

    return sqrt($sum / $n);
}

function countIf(array $values, callable $fn): int
{
    $count = 0;

    foreach ($values as $value) {
        if ($fn((float)$value)) {
            $count++;
        }
    }

    return $count;
}

function fmt(float $value): string
{
    if (!is_finite($value)) {
        return '';
    }

    return number_format($value, 2, '.', '');
}

function toFloatOrNullLocal($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        $n = (float)$value;
        return is_finite($n) ? $n : null;
    }

    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }

    $s = str_replace(',', '', $s);

    if (!is_numeric($s)) {
        return null;
    }

    $n = (float)$s;

    return is_finite($n) ? $n : null;
}

function normalizeCode5(string $code): string
{
    $code = trim($code);
    $code = preg_replace('/\.0$/', '', $code);
    $code = preg_replace('/[^0-9A-Za-z]/', '', $code);

    if ($code === null || $code === '') {
        return '';
    }

    $code = strtoupper($code);

    if (strlen($code) === 4) {
        return $code . '0';
    }
    if (strlen($code) >= 5) {
        return substr($code, 0, 5);
    }

    return str_pad($code, 5, '0', STR_PAD_LEFT);
}

function normalizeHeaderLocal(array $header): array
{
    return array_map(function ($v) {
        return trim((string)$v);
    }, $header);
}

function requireHeaderIndexLocal(
    array $header,
    string $name,
    string $sheetName
): int {
    $idx = array_search($name, $header, true);

    if ($idx === false) {
        throw new RuntimeException(
            "{$sheetName} に「{$name}」列が見つかりません。"
        );
    }

    return (int)$idx;
}

function renderIndexPage(): void
{
    $self = htmlspecialchars(
        (string)($_SERVER['PHP_SELF'] ?? '/report_download.php'),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $options = '';

    foreach (REPORTS as $key => $label) {
        $keyEsc = htmlspecialchars(
            $key,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $labelEsc = htmlspecialchars(
            $label,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $options .=
            '<option value="' . $keyEsc . '">' .
            $labelEsc .
            '</option>';
    }

    header('Content-Type: text/html; charset=UTF-8');

    echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>投資分析レポート出力</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,
    "Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    padding:24px;
    line-height:1.6;
}
.wrap{
    max-width:820px;
    margin:auto;
}
h1{
    font-size:22px;
    margin:0 0 16px;
}
form{
    display:flex;
    gap:10px;
    align-items:center;
}
select{
    font-size:16px;
    padding:10px;
    min-width:420px;
}
button{
    font-size:16px;
    padding:10px 18px;
    cursor:pointer;
}
.hint{
    color:#666;
    font-size:13px;
    margin-top:14px;
}
</style>
</head>
<body>
<div class="wrap">
    <h1>投資分析レポート出力</h1>

    <form method="get" action="{$self}">
        <input type="hidden" name="mode" value="download">

        <select name="report" required>
            {$options}
        </select>

        <button type="submit">出力</button>
    </form>

    <div class="hint">
        投資分析レポートを選択して「出力」を押すとCSVをダウンロードします。
    </div>
</div>
</body>
</html>
HTML;
}

function renderErrorPage(string $message): string
{
    $self = htmlspecialchars(
        (string)($_SERVER['PHP_SELF'] ?? '/report_download.php'),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $messageEsc = htmlspecialchars(
        $message,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    return <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>投資分析レポート出力エラー</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,
    "Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    padding:24px;
    line-height:1.6;
}
.wrap{
    max-width:820px;
    margin:auto;
}
pre{
    white-space:pre-wrap;
    word-break:break-word;
    background:#f7f7f7;
    border:1px solid #ddd;
    padding:12px;
}
</style>
</head>
<body>
<div class="wrap">
    <h1>投資分析レポート出力エラー</h1>
    <pre>{$messageEsc}</pre>
    <p><a href="{$self}">← 戻る</a></p>
</div>
</body>
</html>
HTML;
}
