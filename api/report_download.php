<?php
declare(strict_types=1);

/**
 * report_download.php
 *
 * 簡易投資分析レポート出力Webアプリ
 * PHP 7.4.30
 *
 * - プルダウンで投資分析レポートを選択
 * - 「出力」でCSVを直接ダウンロード（類似チャートパターン検索のみXLSX）
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

    'price_half_recovery_ma75_pullback'
        => '株価スクリーニング・半値暴落後75日線押し目',

    'similar_chart_pattern'
        => '類似チャートパターン検索',
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


        case 'price_half_recovery_ma75_pullback':
            downloadPriceHalfRecoveryMa75Pullback();
            return;


        case 'similar_chart_pattern':
            downloadSimilarChartPattern();
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


/**
 * 株価スクリーニング「半値暴落後75日線押し目」をCSV出力する。
 *
 * 基準日:
 * - prices_eod の MAX(asof_date)
 *
 * 条件:
 * 1. A期間（基準日の1年前～6カ月前）の最高値をピークとする。
 *    B期間（基準日の2年前～1年前未満）の最高値がピーク値を超えない。
 * 2. ピーク日より後に、安値がピーク値の50%以下となる日がある。
 * 3. 半値以下初回到達日より後に、終値 > MA25 > MA75 が初めて成立する。
 * 4. 3の初回成立日から基準日まで MA25 > MA75 を毎日維持し、
 *    基準日は 終値 >= MA75 かつ MA75乖離率が0～3%。
 *
 * MA25 / MA75:
 * - 当日を含む直近25 / 75営業日の終値単純平均。
 * - close がNULLの日は判定対象外。
 *
 * CSV:
 * - ヘッダーあり
 * - Excelで開きやすいようUTF-8 BOM付き
 */
function downloadPriceHalfRecoveryMa75Pullback(): void
{
    $pdo = jqBuildPdo();
    $securityMap = loadSecurityCodeMasterMap();

    $stmt = $pdo->query(
        'SELECT MAX(asof_date) AS base_date FROM prices_eod'
    );
    $baseDate = trim((string)($stmt->fetchColumn() ?: ''));
    $stmt->closeCursor();

    if ($baseDate === '') {
        throw new RuntimeException('prices_eod に日足データがありません。');
    }

    $base = new DateTimeImmutable($baseDate);
    $aStart = $base->modify('-1 year')->format('Y-m-d');
    $aEnd = $base->modify('-6 months')->format('Y-m-d');
    $bStart = $base->modify('-2 years')->format('Y-m-d');

    /*
     * 証券コードマスタは小さいため、先に4桁コードをキーにして保持する。
     * 日足の非バッファ取得を開始した後は、同じPDO接続で別SQLを実行しない。
     */
    $masterByCode4 = [];

    foreach ($securityMap as $master) {
        $securityCode = trim(
            (string)($master['security_code'] ?? '')
        );
        $code4 = normalizeCode4FinancialActuals($securityCode);

        if ($code4 !== '') {
            $masterByCode4[$code4] = $master;
        }
    }

    /*
     * 全銘柄×2年分をPHP配列へ一括展開すると128MBを超えるため、
     * MySQLの結果を非バッファで1行ずつ受け取る。
     * ORDER BY code, asof_date により、1銘柄分だけをメモリへ保持して
     * 判定後すぐ破棄する。
     */
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

    $sql = <<<SQL
SELECT asof_date, code, high, low, close
FROM prices_eod
WHERE asof_date BETWEEN :from_date AND :to_date
ORDER BY code, asof_date
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':from_date' => $bStart,
        ':to_date' => $baseDate,
    ]);

    $resultRows = [];
    $currentCode4 = '';
    $currentPrices = [];

    /*
     * 1銘柄分の判定と結果追加を共通化する。
     * $currentPrices は最大でも約2年分の日足だけなので、
     * 全銘柄分を保持する場合に比べてメモリ使用量を大幅に抑えられる。
     */
    $processCurrent = static function (
        string $code4,
        array $prices
    ) use (
        &$resultRows,
        $masterByCode4,
        $baseDate,
        $aStart,
        $aEnd,
        $bStart
    ): void {
        if ($code4 === '' || !isset($masterByCode4[$code4])) {
            return;
        }

        $screened = screenHalfRecoveryMa75Pullback(
            $prices,
            $baseDate,
            $aStart,
            $aEnd,
            $bStart
        );

        if ($screened === null) {
            return;
        }

        $master = $masterByCode4[$code4];

        $resultRows[] = array_merge(
            [
                'security_code' => trim(
                    (string)($master['security_code'] ?? $code4)
                ),
                'company_name' => trim(
                    (string)($master['company_name'] ?? '')
                ),
            ],
            $screened
        );
    };

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $code4 = normalizeCode4FinancialActuals(
            (string)($row['code'] ?? '')
        );

        if ($code4 === '') {
            continue;
        }

        if ($currentCode4 !== '' && $code4 !== $currentCode4) {
            $processCurrent($currentCode4, $currentPrices);
            $currentPrices = [];
        }

        if ($code4 !== $currentCode4) {
            $currentCode4 = $code4;
        }

        /*
         * マスタに存在しないコードは日足を保持せず読み飛ばす。
         */
        if (!isset($masterByCode4[$code4])) {
            continue;
        }

        $currentPrices[] = [
            'date' => (string)$row['asof_date'],
            'high' => toFloatOrNullLocal($row['high'] ?? null),
            'low' => toFloatOrNullLocal($row['low'] ?? null),
            'close' => toFloatOrNullLocal($row['close'] ?? null),
        ];
    }

    /* 最後の1銘柄を処理する。 */
    if ($currentCode4 !== '') {
        $processCurrent($currentCode4, $currentPrices);
    }

    $stmt->closeCursor();

    /* 後続処理に備え、PDOのバッファ設定を元へ戻す。 */
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    usort(
        $resultRows,
        static function (array $a, array $b): int {
            $cmp = ((float)$a['ma75_gap_pct']) <=>
                ((float)$b['ma75_gap_pct']);

            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp(
                (string)$a['security_code'],
                (string)$b['security_code']
            );
        }
    );

    $fileName =
        '株価スクリーニング_半値暴落後75日線押し目_' .
        str_replace('-', '', $baseDate) .
        '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename*=UTF-8\'\'' .
        rawurlencode($fileName)
    );
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $fp = fopen('php://output', 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            'CSV出力ストリームを開けませんでした。'
        );
    }

    fwrite($fp, "\xEF\xBB\xBF");

    fputcsv($fp, [
        '証券コード',
        '銘柄名',
        '基準日',
        '終値',
        'MA25',
        'MA75',
        'MA75乖離率(%)',
        'ピーク日',
        'ピーク値',
        'ピークからの経過日数',
        '半値以下初回到達日',
        '半値以下初回到達安値',
        'ピーク後最安値日',
        'ピーク後最安値',
        'ピークからの最大下落率(%)',
        '終値>MA25>MA75 初回成立日',
    ]);

    foreach ($resultRows as $row) {
        fputcsv($fp, [
            (string)$row['security_code'],
            (string)$row['company_name'],
            (string)$row['base_date'],
            formatPriceReportNumber((float)$row['close']),
            formatPriceReportNumber((float)$row['ma25']),
            formatPriceReportNumber((float)$row['ma75']),
            number_format((float)$row['ma75_gap_pct'], 3, '.', ''),
            (string)$row['peak_date'],
            formatPriceReportNumber((float)$row['peak_price']),
            (string)$row['days_from_peak'],
            (string)$row['half_date'],
            formatPriceReportNumber((float)$row['half_low']),
            (string)$row['post_peak_low_date'],
            formatPriceReportNumber((float)$row['post_peak_low']),
            number_format(
                (float)$row['max_drawdown_pct'],
                3,
                '.',
                ''
            ),
            (string)$row['trend_date'],
        ]);
    }

    fclose($fp);
}

/**
 * 1銘柄分の「半値暴落後75日線押し目」を判定する。
 */
function screenHalfRecoveryMa75Pullback(
    array $prices,
    string $baseDate,
    string $aStart,
    string $aEnd,
    string $bStart
): ?array {
    $count = count($prices);

    if ($count < 75) {
        return null;
    }

    /*
     * 終値のローリング合計からMA25 / MA75を計算する。
     * NULL終値を含む窓はMAを未計算とする。
     */
    $sum25 = 0.0;
    $sum75 = 0.0;
    $valid25 = 0;
    $valid75 = 0;

    for ($i = 0; $i < $count; $i++) {
        $close = $prices[$i]['close'];

        if ($close !== null) {
            $sum25 += $close;
            $sum75 += $close;
            $valid25++;
            $valid75++;
        }

        if ($i >= 25) {
            $old = $prices[$i - 25]['close'];
            if ($old !== null) {
                $sum25 -= $old;
                $valid25--;
            }
        }

        if ($i >= 75) {
            $old = $prices[$i - 75]['close'];
            if ($old !== null) {
                $sum75 -= $old;
                $valid75--;
            }
        }

        $prices[$i]['ma25'] =
            ($i >= 24 && $valid25 === 25)
                ? $sum25 / 25.0
                : null;

        $prices[$i]['ma75'] =
            ($i >= 74 && $valid75 === 75)
                ? $sum75 / 75.0
                : null;
    }

    /* A期間の最高値。最高値が同値なら、より新しい日をピークとする。 */
    $peakPrice = null;
    $peakDate = null;
    $peakIndex = null;

    /* B期間最高値。 */
    $bHigh = null;

    foreach ($prices as $i => $row) {
        $date = (string)$row['date'];
        $high = $row['high'];

        if ($high === null) {
            continue;
        }

        if ($date >= $bStart && $date < $aStart) {
            if ($bHigh === null || $high > $bHigh) {
                $bHigh = $high;
            }
        }

        if ($date >= $aStart && $date <= $aEnd) {
            if (
                $peakPrice === null ||
                $high > $peakPrice ||
                ($high == $peakPrice && $date > (string)$peakDate)
            ) {
                $peakPrice = $high;
                $peakDate = $date;
                $peakIndex = $i;
            }
        }
    }

    if (
        $peakPrice === null ||
        $peakPrice <= 0.0 ||
        $peakDate === null ||
        $peakIndex === null ||
        $bHigh === null ||
        $bHigh > $peakPrice
    ) {
        return null;
    }

    /*
     * ピーク後について、
     * - 半値以下の初回到達
     * - ピーク後最安値
     * を取得する。
     */
    $halfDate = null;
    $halfLow = null;
    $halfIndex = null;
    $postPeakLow = null;
    $postPeakLowDate = null;

    for ($i = $peakIndex + 1; $i < $count; $i++) {
        $row = $prices[$i];
        $date = (string)$row['date'];

        if ($date > $baseDate) {
            break;
        }

        $low = $row['low'];
        if ($low === null) {
            continue;
        }

        if ($postPeakLow === null || $low < $postPeakLow) {
            $postPeakLow = $low;
            $postPeakLowDate = $date;
        }

        if (
            $halfIndex === null &&
            $low <= $peakPrice * 0.50
        ) {
            $halfDate = $date;
            $halfLow = $low;
            $halfIndex = $i;
        }
    }

    if (
        $halfIndex === null ||
        $halfDate === null ||
        $halfLow === null ||
        $postPeakLow === null ||
        $postPeakLowDate === null
    ) {
        return null;
    }

    /* 半値以下初回到達後、最初の「終値 > MA25 > MA75」を探す。 */
    $trendIndex = null;
    $trendDate = null;

    for ($i = $halfIndex + 1; $i < $count; $i++) {
        $row = $prices[$i];
        $date = (string)$row['date'];

        if ($date > $baseDate) {
            break;
        }

        $close = $row['close'];
        $ma25 = $row['ma25'];
        $ma75 = $row['ma75'];

        if (
            $close !== null &&
            $ma25 !== null &&
            $ma75 !== null &&
            $close > $ma25 &&
            $ma25 > $ma75
        ) {
            $trendIndex = $i;
            $trendDate = $date;
            break;
        }
    }

    if ($trendIndex === null || $trendDate === null) {
        return null;
    }

    /* ③成立日から基準日まで MA25 > MA75 を毎日維持する。 */
    for ($i = $trendIndex; $i < $count; $i++) {
        $row = $prices[$i];
        $date = (string)$row['date'];

        if ($date > $baseDate) {
            break;
        }

        $ma25 = $row['ma25'];
        $ma75 = $row['ma75'];

        if (
            $ma25 === null ||
            $ma75 === null ||
            $ma25 <= $ma75
        ) {
            return null;
        }
    }

    /* 基準日の日足を取得する。 */
    $last = null;

    for ($i = $count - 1; $i >= 0; $i--) {
        if ((string)$prices[$i]['date'] === $baseDate) {
            $last = $prices[$i];
            break;
        }
    }

    if ($last === null) {
        return null;
    }

    $close = $last['close'];
    $ma25 = $last['ma25'];
    $ma75 = $last['ma75'];

    if (
        $close === null ||
        $ma25 === null ||
        $ma75 === null ||
        $ma75 <= 0.0 ||
        $ma25 <= $ma75 ||
        $close < $ma75
    ) {
        return null;
    }

    $ma75GapPct = (($close - $ma75) / $ma75) * 100.0;

    if ($ma75GapPct < 0.0 || $ma75GapPct > 3.0) {
        return null;
    }

    $maxDrawdownPct =
        (($postPeakLow - $peakPrice) / $peakPrice) * 100.0;

    $peakDt = new DateTimeImmutable($peakDate);
    $baseDt = new DateTimeImmutable($baseDate);
    $daysFromPeak = (int)$peakDt->diff($baseDt)->format('%a');

    return [
        'base_date' => $baseDate,
        'close' => $close,
        'ma25' => $ma25,
        'ma75' => $ma75,
        'ma75_gap_pct' => $ma75GapPct,
        'peak_date' => $peakDate,
        'peak_price' => $peakPrice,
        'days_from_peak' => $daysFromPeak,
        'half_date' => $halfDate,
        'half_low' => $halfLow,
        'post_peak_low_date' => $postPeakLowDate,
        'post_peak_low' => $postPeakLow,
        'max_drawdown_pct' => $maxDrawdownPct,
        'trend_date' => $trendDate,
    ];
}

/**
 * 株価レポート用の数値表示。
 * 整数は整数、小数がある場合は不要な末尾0を除去する。
 */
function formatPriceReportNumber(float $value): string
{
    return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
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
    flex-wrap:wrap;
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

        <select name="report" id="report" required onchange="togglePatternFields()">
            {$options}
        </select>

        <div id="pattern-fields" style="display:none;gap:8px;flex-wrap:wrap">
            <label>証券コード <input name="security_code" type="text" pattern="[0-9A-Za-z]{4}" maxlength="4" placeholder="285A" disabled></label>
            <label>始点日 <input name="start_date" type="text" placeholder="YYYY-MM-DD" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}" disabled></label>
            <label>終点日 <input name="end_date" type="text" placeholder="YYYY-MM-DD" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}" disabled></label>
        </div>

        <button type="submit">出力</button>
    </form>

    <div class="hint">
        投資分析レポートを選択して「出力」を押すとCSVをダウンロードします。
    </div>
</div>
<script>
function togglePatternFields() {
    const active = document.getElementById('report').value === 'similar_chart_pattern';
    const fields = document.getElementById('pattern-fields');
    fields.style.display = active ? 'flex' : 'none';
    fields.querySelectorAll('input').forEach(function(input) {
        input.disabled = !active;
        input.required = active;
    });
}
togglePatternFields();
</script>
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


/**
 * 類似チャートパターン検索：基準期間と直近N本のローソク足を比較。
 * Cは参考値。ランキングはA^0.8 * B^0.2で決定する。
 */
function downloadSimilarChartPattern(): void
{
    $code = strtoupper(trim((string)($_GET['security_code'] ?? '')));
    $start = trim((string)($_GET['start_date'] ?? ''));
    $end = trim((string)($_GET['end_date'] ?? ''));
    if (!preg_match('/^[0-9A-Z]{4}$/D', $code)) {
        throw new RuntimeException('証券コードは英数字4桁で指定してください。');
    }
    foreach ([$start, $end] as $date) {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new RuntimeException('日付はYYYY-MM-DD形式で指定してください。');
        }
    }
    if ($start > $end) throw new RuntimeException('始点日は終点日以前にしてください。');
    if ((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 365) {
        throw new RuntimeException('比較期間は365日以内にしてください。');
    }

    $masters = loadSecurityCodeMasterMap();
    $byCode = [];
    foreach ($masters as $master) {
        $c = strtoupper(trim((string)($master['security_code'] ?? '')));
        if (preg_match('/^[0-9A-Z]{4}$/D', $c)) $byCode[$c] = $master;
    }
    if (!isset($byCode[$code])) throw new RuntimeException('基準銘柄が証券コードマスタに存在しません。');

    $pdo = jqBuildPdo();
    $baseStmt = $pdo->prepare('SELECT asof_date, `open`, high, low, `close`, volume FROM prices_eod WHERE code = :code AND asof_date BETWEEN :start AND :end ORDER BY asof_date');
    $baseStmt->execute([':code'=>$code, ':start'=>$start, ':end'=>$end]);
    $baseRows = $baseStmt->fetchAll(PDO::FETCH_ASSOC);
    $baseStmt->closeCursor();
    $n = count($baseRows);
    if ($n < 3) throw new RuntimeException('基準銘柄の日足が3本未満です。');
    $baseA = patternPriceVector($baseRows);
    $baseB = patternVolumeVector($baseRows);
    if ($baseA === null || $baseB === null) throw new RuntimeException('基準銘柄の4本値または出来高に欠損があります。');

    $latest = (string)$pdo->query('SELECT MAX(asof_date) FROM prices_eod')->fetchColumn();
    if ($latest === '') throw new RuntimeException('prices_eodに最新日がありません。');
    // 最大N本分をカバーする暦日幅を確保（長期休場・上場停止は対象外）。
    $lookbackDays = max(90, $n * 4);
    $from = (new DateTimeImmutable($latest))->modify('-' . $lookbackDays . ' days')->format('Y-m-d');
    $results = [];
    $currentCode = '';
    $rows = [];
    $evaluate = static function (string $c, array $data) use (&$results, $byCode, $n, $latest, $baseA, $baseB): void {
        if (!isset($byCode[$c]) || count($data) < $n) return;
        $window = array_slice($data, -$n);
        // 全銘柄で同じ最新日を終点にする（更新遅れ銘柄を除外）。
        if ((string)$window[$n-1]['asof_date'] !== $latest) return;
        $aVec = patternPriceVector($window);
        $bVec = patternVolumeVector($window);
        if ($aVec === null || $bVec === null) return;
        $a = patternWeightedPriceSimilarity($baseA, $aVec);
        $b = patternSimilarity($baseB, $bVec);
        $d = pow($a, 0.8) * pow($b, 0.2);
        $results[] = [$c, (string)$byCode[$c]['company_name'], $d, $a, $b, null];
    };

    // 非バッファ取得。全銘柄の全期間をPHP配列に積まない。
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        $stmt = $pdo->prepare('SELECT code, asof_date, `open`, high, low, `close`, volume FROM prices_eod WHERE asof_date BETWEEN :start AND :end ORDER BY code, asof_date');
        $stmt->execute([':start'=>$from, ':end'=>$latest]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $c = normalizeCode4FinancialActuals((string)$r['code']);
            if ($c !== $currentCode) {
                if ($currentCode !== '') $evaluate($currentCode, $rows);
                $currentCode = $c;
                $rows = [];
            }
            if (!isset($byCode[$c])) continue;
            $rows[] = $r;
            if (count($rows) > $n) array_shift($rows);
        }
        if ($currentCode !== '') $evaluate($currentCode, $rows);
        $stmt->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    usort($results, static function (array $x, array $y): int {
        return ($y[2] <=> $x[2]) ?: strcmp($x[0], $y[0]);
    });
    $results = array_slice($results, 0, 100);

    // Cは上位100社だけ算出し、ランキングには使用しない。
    // 旧週次と新日次の混在を、各ローソク足の日付以前の最新残高で補完する。
    $marginStmt = $pdo->prepare('SELECT data_date, shrt_std_vol, long_std_vol FROM margin_interest WHERE code = :code AND data_date BETWEEN :start AND :end ORDER BY data_date');
    $baseMargin = patternMarginAligned($marginStmt, $code, $baseRows);
    // 上位100銘柄についてのみ直近N本を再取得する。
    // 全銘柄のローソク足をresultsに保持しないことで128MB制限に対応。
    $topPriceStmt = $pdo->prepare(
        'SELECT asof_date FROM prices_eod WHERE code = :code AND asof_date <= :end ORDER BY asof_date DESC LIMIT ' . (int)$n
    );
    foreach ($results as &$result) {
        $topPriceStmt->execute([':code' => $result[0], ':end' => $latest]);
        $topRows = $topPriceStmt->fetchAll(PDO::FETCH_ASSOC);
        $topPriceStmt->closeCursor();
        if (count($topRows) !== $n) {
            $result[5] = null;
            continue;
        }
        $topRows = array_reverse($topRows);
        $result[5] = patternMarginScore(
            $baseMargin,
            patternMarginAligned($marginStmt, $result[0], $topRows)
        );
    }
    unset($result);

    downloadSimilarChartXlsx($results, $code, $start, $end, $n);

}


/**
 * 類似チャート検索結果をExcel XLSX形式で返す。
 * ZipArchiveを使ってOffice Open XMLを直接作成するため、追加ライブラリ不要。
 */
function downloadSimilarChartXlsx(array $results, string $code, string $start, string $end, int $n): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('XLSX出力にはPHPのZipArchive拡張が必要です。');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'similar_chart_');
    if ($tmp === false) throw new RuntimeException('一時ファイルを作成できません。');
    $zip = new ZipArchive();
    $zipOpen = false;
    try {
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('XLSXのZIPファイルを作成できません。');
        }
        $zipOpen = true;
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="類似チャートパターン検索" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3"><font><sz val="11"/><name val="Meiryo"/></font>'
            . '<font><b/><sz val="11"/><name val="Meiryo"/></font>'
            . '<font><u/><color rgb="FF0563C1"/><sz val="11"/><name val="Meiryo"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>');

        $esc = static function (string $v): string {
            return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };
        $strCell = static function (string $col, int $row, string $value, int $style = 0) use ($esc): string {
            return '<c r="' . $col . $row . '" s="' . $style . '" t="inlineStr"><is><t>' . $esc($value) . '</t></is></c>';
        };
        $headers = ['証券コード','会社名','D.類似加重幾何平均値','A.4本足','B.出来高','C.信用残','株探','四季','銘偵','全銘'];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="A1:J' . (count($results) + 1) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols><col min="1" max="1" width="15" customWidth="1"/><col min="2" max="2" width="30" customWidth="1"/>'
            . '<col min="3" max="6" width="23" customWidth="1"/><col min="7" max="10" width="10" customWidth="1"/></cols><sheetData><row r="1">';
        foreach ($headers as $i => $header) {
            $xml .= $strCell(chr(65 + $i), 1, $header, 1);
        }
        $xml .= '</row>';
        $links = '';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $relId = 1;
        foreach ($results as $index => $r) {
            $row = $index + 2;
            $stockCode = (string)$r[0];
            $xml .= '<row r="' . $row . '">';
            $xml .= $strCell('A', $row, $stockCode);
            $xml .= $strCell('B', $row, (string)$r[1]);
            foreach (['C'=>2, 'D'=>3, 'E'=>4, 'F'=>5] as $col => $idx) {
                if ($r[$idx] !== null && is_numeric($r[$idx])) {
                    $xml .= '<c r="' . $col . $row . '" s="3"><v>' . number_format((float)$r[$idx], 6, '.', '') . '</v></c>';
                }
            }
            $urls = [
                'G' => ['株', 'https://kabutan.jp/stock/chart?code=' . rawurlencode($stockCode)],
                'H' => ['季', 'https://shikiho.toyokeizai.net/stocks/' . rawurlencode($stockCode)],
                'I' => ['銘', 'https://monex.ifis.co.jp/index.php?sa=find&ta=e&wd=' . rawurlencode($stockCode) . '&x=0&y=0'],
                'J' => ['全', 'http://133.18.243.68/api/master_view.php?mode=api&text=' . rawurlencode($stockCode)],
            ];
            foreach ($urls as $col => $item) {
                $xml .= $strCell($col, $row, $item[0], 2);
                $rid = 'rId' . $relId++;
                $links .= '<hyperlink ref="' . $col . $row . '" r:id="' . $rid . '"/>';
                $rels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . $esc($item[1]) . '" TargetMode="External"/>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData><autoFilter ref="A1:J' . (count($results) + 1) . '"/>'
            . '<hyperlinks>' . $links . '</hyperlinks></worksheet>';
        $rels .= '</Relationships>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $rels);
        $closed = $zip->close();
        $zipOpen = false;
        if (!$closed) throw new RuntimeException('XLSXの書き込みに失敗しました。');
        $filename = '類似チャートパターン検索_' . $code . '_' . str_replace('-', '', $start)
            . '_' . str_replace('-', '', $end) . '_直近' . $n . '本.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="similar_chart.xlsx"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        readfile($tmp);
    } finally {
        if ($zipOpen) $zip->close();
        @unlink($tmp);
    }
}

function patternPriceVector(array $rows): ?array
{
    $first = toFloatOrNullLocal($rows[0]['close'] ?? null);
    if ($first === null || $first <= 0) return null;
    $values = [];
    foreach ($rows as $row) {
        foreach (['open','high','low','close'] as $col) {
            $v = toFloatOrNullLocal($row[$col] ?? null);
            if ($v === null || $v <= 0) return null;
            $values[] = $v / $first;
        }
    }
    return $values;
}

function patternVolumeVector(array $rows): ?array
{
    $values = [];
    foreach ($rows as $row) {
        $v = toFloatOrNullLocal($row['volume'] ?? null);
        if ($v === null || $v < 0) return null;
        $values[] = $v;
    }
    $mean = array_sum($values) / count($values);
    if ($mean <= 0) return null;
    return array_map(static function (float $v) use ($mean): float { return $v / $mean; }, $values);
}

function patternMarginAligned(PDOStatement $stmt, string $code, array $priceRows): ?array
{
    if (!$priceRows) return null;
    $firstDate = (string)$priceRows[0]['asof_date'];
    $lastDate = (string)$priceRows[count($priceRows)-1]['asof_date'];
    $stmt->execute([':code'=>$code, ':start'=>(new DateTimeImmutable($firstDate))->modify('-370 days')->format('Y-m-d'), ':end'=>$lastDate]);
    $margins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$margins) return null;
    $aligned = [];
    $j = 0;
    $last = null;
    foreach ($priceRows as $p) {
        $date = (string)$p['asof_date'];
        while ($j < count($margins) && (string)$margins[$j]['data_date'] <= $date) {
            $last = $margins[$j++];
        }
        if ($last === null) return null;
        $aligned[] = $last;
    }
    return $aligned;
}

function patternMarginScore(?array $base, ?array $other): ?float
{
    if ($base === null || $other === null || count($base) !== count($other)) return null;
    $scores = [];
    foreach (['shrt_std_vol','long_std_vol'] as $col) {
        $a = []; $b = [];
        foreach ($base as $i => $row) {
            $x = toFloatOrNullLocal($row[$col] ?? null);
            $y = toFloatOrNullLocal($other[$i][$col] ?? null);
            if ($x === null || $y === null || $x < 0 || $y < 0) return null;
            $a[] = $x; $b[] = $y;
        }
        $meanA = array_sum($a) / count($a);
        $meanB = array_sum($b) / count($b);
        if ($meanA <= 0 || $meanB <= 0) return null;
        $scores[] = patternSimilarity(array_map(static function ($v) use ($meanA) { return $v / $meanA; }, $a), array_map(static function ($v) use ($meanB) { return $v / $meanB; }, $b));
    }
    return array_sum($scores) / count($scores);
}

function patternSimilarity(array $reference, array $candidate): float
{
    if (count($reference) !== count($candidate) || !$reference) return 0.0;
    $sum = 0.0;
    foreach ($reference as $i => $v) {
        $other = $candidate[$i];
        $sum += abs($v - $other) / max(0.01, abs($v), abs($other));
    }
    return max(0.0, min(1.0, 1.0 - $sum / count($reference)));
}

/**
 * 4本足の複合類似度（0～1）。
 * 全期間30%、後半50%を20%、直近20本30%、直近5本騰落率20%。
 * 期間が短い場合は利用可能な本数に縮小する。
 */
function patternWeightedPriceSimilarity(array $reference, array $candidate): float
{
    $count = count($reference);
    if ($count === 0 || $count !== count($candidate) || $count % 4 !== 0) return 0.0;
    $n = intdiv($count, 4);
    if ($n < 3) return 0.0;

    $full = patternSimilarity($reference, $candidate);
    $halfStart = intdiv($n, 2);
    $half = patternSimilarity(array_slice($reference, $halfStart * 4), array_slice($candidate, $halfStart * 4));
    $last20 = min(20, $n);
    $recent = patternSimilarity(array_slice($reference, -$last20 * 4), array_slice($candidate, -$last20 * 4));

    // 終値（各日の4番目）から直近5本の日次騰落率を作成する。
    $returnsRef = [];
    $returnsCandidate = [];
    $start = max(1, $n - 5);
    for ($i = $start; $i < $n; $i++) {
        $prevRef = (float)$reference[($i - 1) * 4 + 3];
        $prevCandidate = (float)$candidate[($i - 1) * 4 + 3];
        if ($prevRef <= 0 || $prevCandidate <= 0) return 0.0;
        $returnsRef[] = (float)$reference[$i * 4 + 3] / $prevRef - 1.0;
        $returnsCandidate[] = (float)$candidate[$i * 4 + 3] / $prevCandidate - 1.0;
    }
    // 日次騰落率の差を5%でスケーリング。5%ptの平均乖離で0となる。
    $difference = 0.0;
    foreach ($returnsRef as $i => $v) {
        $difference += abs($v - $returnsCandidate[$i]);
    }
    $momentum = max(0.0, 1.0 - $difference / (count($returnsRef) * 0.05));
    return max(0.0, min(1.0, 0.30 * $full + 0.20 * $half + 0.30 * $recent + 0.20 * $momentum));
}
