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
 *   shikiho_inf03_high_value_added
 *   四季報登録用・INF_03高付加価値
 *
 *   shikiho_inf04_cost_absorption
 *   四季報登録用・INF_04コスト吸収力
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
    'shikiho_inf03_high_value_added'
        => '四季報登録用・INF_03高付加価値',

    'shikiho_inf04_cost_absorption'
        => '四季報登録用・INF_04コスト吸収力',
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


        case 'shikiho_inf03_high_value_added':
            downloadShikihoInf03HighValueAdded();
            return;


        case 'shikiho_inf04_cost_absorption':
            downloadShikihoInf04CostAbsorption();
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
 * - DocType = FYFinancialStatements_*
 * - 証券コードごとに DiscDate, DiscTime, DiscNo が最新の1件
 * - 最新FY決算の Sales, OP がともにNULLでない
 * - Sales / OP 欠損時に過去FY決算への遡及は行わない
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

/**
 * 四季報オンライン Csv項目登録用「最新FY粗利率」をCSV出力する。
 *
 * CSV仕様:
 * - ヘッダーなし
 * - 1列目: 銘柄コード
 * - 2列目: 粗利率（数値）
 *
 * preferred選択:
 * - period_type = FY
 * - period_basis = cumulative
 * - status = OK / PARTIAL
 * - 銘柄ごとに最新 fiscal_period_end を採用
 * - 同じFYなら EDINET > TDNET
 * - 同一sourceなら disclosed_at が新しい文書を優先
 * - 同一日時なら id が大きいレコードを優先
 *
 * 粗利率:
 *   gross_profit / revenue * 100
 *
 * revenue / gross_profit が欠損、または revenue <= 0 の銘柄は出力しない。
 */

/**
 * 四季報オンライン Csv項目登録用「INF_03 高付加価値」をCSV出力する。
 *
 * 定義:
 * - 最新FYの粗利率 = gross_profit / revenue * 100
 * - 同じ東証33業種内で粗利率のパーセンタイル順位を算出
 * - 業種内パーセンタイルを1～10点へ連続変換
 * - P0 = 1点、P50 = 5点、P100 = 10点
 * - P0～P50: 1点 → 5点へ線形変換
 * - P50～P100: 5点 → 10点へ線形変換
 *
 * 対象外:
 * - revenue / gross_profit 欠損
 * - revenue <= 0
 * - 33業種情報なし
 * - 金融4業種
 *   銀行業 / 証券・商品先物取引業 / 保険業 / その他金融業
 *
 * CSV仕様:
 * - ヘッダーなし
 * - 1列目: 銘柄コード
 * - 2列目: INF_03スコア（1～10、6桁小数）
 */
function downloadShikihoInf03HighValueAdded(): void
{
    $pdo = jqBuildPdo();
    $securityMap = loadSecurityCodeMasterMap();
    $preferredMap = fetchLatestPreferredFinancialActualsMap($pdo);

    /*
     * 金融4業種は粗利率の意味が一般事業会社と異なるため対象外。
     * 業種コードを主判定とし、名称も念のため補助判定する。
     */
    $excludedIndustryCodes = [
        '7050', // 銀行業
        '7100', // 証券、商品先物取引業
        '7150', // 保険業
        '7200', // その他金融業
    ];

    $excludedIndustryNames = [
        '銀行業',
        '証券、商品先物取引業',
        '証券・商品先物取引業',
        '保険業',
        'その他金融業',
    ];

    $groups = [];
    $companies = [];

    foreach ($securityMap as $code5 => $master) {
        $securityCode = trim(
            (string)($master['security_code'] ?? '')
        );

        if ($securityCode === '') {
            continue;
        }

        $industryCode = trim(
            (string)($master['industry33_code'] ?? '')
        );

        $industryName = trim(
            (string)($master['industry33_name'] ?? '')
        );

        if (
            $industryName === '' ||
            in_array($industryCode, $excludedIndustryCodes, true) ||
            in_array($industryName, $excludedIndustryNames, true)
        ) {
            continue;
        }

        $code4 = normalizeCode4FinancialActuals($securityCode);

        if (
            $code4 === '' ||
            !isset($preferredMap[$code4])
        ) {
            continue;
        }

        $row = $preferredMap[$code4];

        $revenue = toFloatOrNullLocal(
            $row['revenue'] ?? null
        );

        $grossProfit = toFloatOrNullLocal(
            $row['gross_profit'] ?? null
        );

        if (
            $revenue === null ||
            $grossProfit === null ||
            $revenue <= 0.0
        ) {
            continue;
        }

        $grossMargin =
            ($grossProfit / $revenue) * 100.0;

        if (!is_finite($grossMargin)) {
            continue;
        }

        /*
         * 同名業種でグループ化。
         * 33業種コードも同じはずだが、名称を母集団キーとする。
         */
        if (!isset($groups[$industryName])) {
            $groups[$industryName] = [];
        }

        $groups[$industryName][] = $grossMargin;

        $companies[] = [
            'security_code' => $securityCode,
            'code5' => $code5,
            'industry_code' => $industryCode,
            'industry_name' => $industryName,
            'gross_margin' => $grossMargin,
        ];
    }

    /*
     * percentileRank() はソート済み配列を前提とするため、
     * 業種ごとに一度だけソートする。
     */
    foreach ($groups as $industryName => $values) {
        sort($values, SORT_NUMERIC);
        $groups[$industryName] = $values;
    }

    $rows = [];

    foreach ($companies as $company) {
        $industryName =
            (string)$company['industry_name'];

        $values = $groups[$industryName] ?? [];

        if (count($values) === 0) {
            continue;
        }

        $percentileRank = percentileRank(
            $values,
            (float)$company['gross_margin']
        );

        if (!is_finite($percentileRank)) {
            continue;
        }

        $score = grossMarginRelativeScore(
            $percentileRank
        );

        $rows[] = [
            'security_code'
                => (string)$company['security_code'],
            'score'
                => $score,
        ];
    }

    usort(
        $rows,
        static function (array $a, array $b): int {
            return strcmp(
                (string)$a['security_code'],
                (string)$b['security_code']
            );
        }
    );

    $fileName =
        '四季報登録用_INF_03高付加価値_' .
        date('Ymd') .
        '.csv';

    header('Content-Type: text/csv; charset=UTF-8');

    header(
        'Content-Disposition: attachment; filename*=UTF-8\'\''
        . rawurlencode($fileName)
    );

    header(
        'Cache-Control: no-store, no-cache, must-revalidate'
    );

    $fp = fopen('php://output', 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            'CSV出力ストリームを開けませんでした。'
        );
    }

    /*
     * 四季報Csv項目の登録形式に合わせてヘッダーなし。
     */
    foreach ($rows as $row) {
        fputcsv(
            $fp,
            [
                (string)$row['security_code'],
                number_format((float)$row['score'], 6, '.', ''),
            ]
        );
    }

    fclose($fp);
}


/**
 * 粗利率の業種内パーセンタイルを1～10点へ連続変換する。
 *
 * P0   = 1点
 * P25  = 3点
 * P50  = 5点
 * P75  = 7.5点
 * P90  = 9点
 * P100 = 10点
 *
 * P0～P50:
 *   score = 1 + 4 * percentile / 50
 *
 * P50～P100:
 *   score = 5 + 5 * (percentile - 50) / 50
 *
 * これにより5点が業種内中央値P50に一致する。
 */
function grossMarginRelativeScore(
    float $percentileRank
): float {
    $p = max(
        0.0,
        min(100.0, $percentileRank)
    );

    if ($p <= 50.0) {
        // P0=1 → P50=5
        $score =
            1.0 +
            (4.0 * $p / 50.0);
    } else {
        // P50=5 → P100=10
        $score =
            5.0 +
            (5.0 * ($p - 50.0) / 50.0);
    }

    return max(
        1.0,
        min(10.0, $score)
    );
}


/**
 * 四季報オンライン Csv項目登録用「INF_04 コスト吸収力」をCSV出力する。
 *
 * 定義:
 * - 最新2FYの実績から売上高増減率と営業利益増減率を算出
 * - 営業レバレッジ = 営業利益増減率 - 売上高増減率
 *
 * スコア:
 * - 売上高増減率 <= 0: 4点
 * - 売上高増減率 > 0:
 *     営業レバレッジ <= -20pt : 1点
 *     -20pt ～ 0pt            : 1点 → 5点へ線形変換
 *      0pt ～ +50pt           : 5点 → 10点へ線形変換
 *     +50pt以上                : 10点
 *
 * これにより5点以上は、
 * 「増収かつ営業利益増加率が売上高増加率以上」を意味する。
 *
 * 対象外:
 * - 比較可能なFY実績が2期未満
 * - 最新FY / 前FYのSalesまたはOP欠損
 * - 最新FY / 前FY Sales <= 0
 * - 前FY OP <= 0
 *
 * 前FY OP <= 0 は営業利益増減率の基準値として不適切なため評価対象外。
 *
 * CSV仕様:
 * - ヘッダーなし
 * - 1列目: 銘柄コード
 * - 2列目: INF_04スコア（1～10、6桁小数）
 */
function downloadShikihoInf04CostAbsorption(): void
{
    $pdo = jqBuildPdo();
    $securityMap = loadSecurityCodeMasterMap();
    $twoFyMap = fetchLatestTwoActualFyRowsByCode($pdo);

    $rows = [];

    foreach ($securityMap as $code5 => $master) {
        $securityCode = trim(
            (string)($master['security_code'] ?? '')
        );

        if ($securityCode === '') {
            continue;
        }

        $fyRows = $twoFyMap[$code5] ?? [];

        if (count($fyRows) < 2) {
            continue;
        }

        $latest = $fyRows[0];
        $previous = $fyRows[1];

        $latestSales = toFloatOrNullLocal(
            $latest['Sales'] ?? null
        );

        $previousSales = toFloatOrNullLocal(
            $previous['Sales'] ?? null
        );

        $latestOp = toFloatOrNullLocal(
            $latest['OP'] ?? null
        );

        $previousOp = toFloatOrNullLocal(
            $previous['OP'] ?? null
        );

        if (
            $latestSales === null ||
            $previousSales === null ||
            $latestOp === null ||
            $previousOp === null ||
            $latestSales <= 0.0 ||
            $previousSales <= 0.0 ||
            $previousOp <= 0.0
        ) {
            continue;
        }

        $salesGrowth =
            (($latestSales / $previousSales) - 1.0) * 100.0;

        $opGrowth =
            (($latestOp / $previousOp) - 1.0) * 100.0;

        if (
            !is_finite($salesGrowth) ||
            !is_finite($opGrowth)
        ) {
            continue;
        }

        if ($salesGrowth <= 0.0) {
            $score = 4.0;
        } else {
            $operatingLeverage =
                $opGrowth - $salesGrowth;

            if (!is_finite($operatingLeverage)) {
                continue;
            }

            $score = operatingLeverageCostAbsorptionScore(
                $operatingLeverage
            );
        }

        $rows[] = [
            'security_code' => $securityCode,
            'score' => $score,
        ];
    }

    usort(
        $rows,
        static function (array $a, array $b): int {
            return strcmp(
                (string)$a['security_code'],
                (string)$b['security_code']
            );
        }
    );

    $fileName =
        '四季報登録用_INF_04コスト吸収力_' .
        date('Ymd') .
        '.csv';

    header('Content-Type: text/csv; charset=UTF-8');

    header(
        'Content-Disposition: attachment; filename*=UTF-8\'\''
        . rawurlencode($fileName)
    );

    header(
        'Cache-Control: no-store, no-cache, must-revalidate'
    );

    $fp = fopen('php://output', 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            'CSV出力ストリームを開けませんでした。'
        );
    }

    // 四季報Csv項目の登録形式に合わせてヘッダーなし。
    foreach ($rows as $row) {
        fputcsv(
            $fp,
            [
                (string)$row['security_code'],
                number_format(
                    (float)$row['score'],
                    6,
                    '.',
                    ''
                ),
            ]
        );
    }

    fclose($fp);
}


/**
 * 増収企業の営業レバレッジをINF_04の1～10点へ変換する。
 *
 * -20pt以下 = 1点
 * -20pt～0pt = 1点 → 5点
 * 0pt～50pt = 5点 → 10点
 * 50pt以上 = 10点
 */
function operatingLeverageCostAbsorptionScore(
    float $operatingLeverage
): float {
    if ($operatingLeverage <= -20.0) {
        return 1.0;
    }

    if ($operatingLeverage < 0.0) {
        // -20pt=1 → 0pt=5
        $score =
            1.0 +
            4.0 *
            (($operatingLeverage + 20.0) / 20.0);

        return max(
            1.0,
            min(5.0, $score)
        );
    }

    if ($operatingLeverage >= 50.0) {
        return 10.0;
    }

    // 0pt=5 → 50pt=10
    $score =
        5.0 +
        5.0 *
        ($operatingLeverage / 50.0);

    return max(
        5.0,
        min(10.0, $score)
    );
}


/**
 * jquants_fins_summary から銘柄ごとに異なるCurFYEnの最新2FY実績を返す。
 *
 * 同一FYの訂正・再開示が複数ある場合は、
 * DiscDate / DiscTime / DiscNo が最新の1件だけを採用する。
 *
 * Sales / OP の欠損はこの関数では除外しない。
 */
function fetchLatestTwoActualFyRowsByCode(PDO &$pdo): array
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
          AND DocType LIKE 'FYFinancialStatements_%'
        ORDER BY
            Code ASC,
            CurFYEn DESC,
            DiscDate DESC,
            DiscTime DESC,
            DiscNo DESC
    ";

    $stmt = $pdo->query($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'jquants_fins_summary の最新2FY取得に失敗しました。'
        );
    }

    $out = [];
    $seenFy = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $code5 = normalizeCode5(
            (string)($row['Code'] ?? '')
        );

        $fyEnd = trim(
            (string)($row['CurFYEn'] ?? '')
        );

        if ($code5 === '' || $fyEnd === '') {
            continue;
        }

        if (!isset($out[$code5])) {
            $out[$code5] = [];
            $seenFy[$code5] = [];
        }

        if (isset($seenFy[$code5][$fyEnd])) {
            continue;
        }

        if (count($out[$code5]) >= 2) {
            continue;
        }

        $seenFy[$code5][$fyEnd] = true;
        $out[$code5][] = $row;
    }

    return $out;
}


/**
 * financial_actuals から銘柄ごとの最新FY preferredレコードを1件返す。
 */
function fetchLatestPreferredFinancialActualsMap(PDO &$pdo): array
{
    jqEnsurePdoAlive($pdo);

    $sql = "
        SELECT
            id,
            security_code,
            fiscal_period_end,
            scope,
            source,
            disclosed_at,
            revenue,
            gross_profit,
            status
        FROM financial_actuals
        WHERE period_type = 'FY'
          AND period_basis = 'cumulative'
          AND status IN ('OK', 'PARTIAL')
        ORDER BY
            security_code ASC,
            fiscal_period_end DESC,
            CASE source
                WHEN 'EDINET' THEN 1
                WHEN 'TDNET'  THEN 2
                ELSE 9
            END ASC,
            COALESCE(disclosed_at, '1000-01-01 00:00:00') DESC,
            id DESC
    ";

    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        throw new RuntimeException('financial_actuals のpreferred取得に失敗しました。');
    }

    $out = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $code4 = normalizeCode4FinancialActuals(
            (string)($row['security_code'] ?? '')
        );

        if ($code4 === '' || isset($out[$code4])) {
            continue;
        }

        $out[$code4] = $row;
    }

    return $out;
}

function normalizeCode4FinancialActuals(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^0-9A-Z]/', '', $code) ?? '';

    if (strlen($code) === 5 && substr($code, -1) === '0') {
        return substr($code, 0, 4);
    }

    return strlen($code) === 4 ? $code : '';
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

    $codeIdx = requireHeaderIndexLocal(
        $header,
        '証券コード',
        SECURITY_CODE_MASTER_NAME
    );

    $companyNameIdx = requireHeaderIndexLocal(
        $header,
        '銘柄名',
        SECURITY_CODE_MASTER_NAME
    );

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
            'security_code'
                => trim((string)($row[$codeIdx] ?? '')),
            'company_name'
                => trim((string)($row[$companyNameIdx] ?? '')),
            'industry33_code'
                => trim((string)($row[$industryCodeIdx] ?? '')),
            'industry33_name'
                => trim((string)($row[$industryNameIdx] ?? '')),
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


/**
 * ソート済み配列の中でvalueが何パーセンタイルに位置するかを返す。
 *
 * 同値が複数ある場合は同順位群の中央順位を採用する。
 * 戻り値: 0～100
 */
function percentileRank(
    array $sortedValues,
    float $value
): float {
    $n = count($sortedValues);

    if ($n === 0) {
        return NAN;
    }

    if ($n === 1) {
        return 50.0;
    }

    $below = 0;
    $equal = 0;

    foreach ($sortedValues as $v) {
        $v = (float)$v;

        if ($v < $value) {
            $below++;
            continue;
        }

        if (abs($v - $value) < 0.0000001) {
            $equal++;
        }
    }

    // 同値群の中央順位
    $rankIndex =
        $below +
        (($equal > 0 ? $equal : 1) - 1) / 2.0;

    $percentile = ($rankIndex / ($n - 1)) * 100.0;

    return max(
        0.0,
        min(100.0, $percentile)
    );
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
