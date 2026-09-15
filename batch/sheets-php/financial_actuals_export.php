<?php
declare(strict_types=1);

/**
 * financial_actuals_export.php
 *
 * financial_actuals から分析用CSVを生成する。
 * PHP 7.4+
 *
 * 方針:
 *   - DBには保存しない派生指標をCSV生成時に計算する
 *   - FY / cumulative / status=OK|PARTIAL のみ対象
 *   - 銘柄ごとに最新FYを採用し、そのFY内では EDINET > TDNET を優先
 *   - 同一source内では disclosed_at が新しい文書を優先
 *   - 証券コードマスタから東証33業種を付加
 *   - 特殊銘柄（指数、ETF等、TOKYO PRO Market）は証券コードマスタ側で除外
 */

require '/opt/invest/j_quants/conf/config.php';
require '/opt/invest/j_quants/lib/j_quants_common.php';
require '/opt/invest/scraping/lib/scraping_common.php';
require_once '/opt/invest/scraping/vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

const OUTPUT_DIR = '/opt/invest/sheets-php/tmp';
const MASTER_FOLDER_PATH = ['投資', 'プログラミング', 'GAS', 'マスタ'];
const SECURITY_CODE_MASTER_NAME = '証券コードマスタ';
const CSV_PREFIX = '財務実績分析用データ';

try {
    $args = parseCommandLineArgs($_SERVER['argv'] ?? []);
    ensureDirLocal(OUTPUT_DIR);

    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    $outputPath = trim((string)($args['output'] ?? ''));
    if ($outputPath === '') {
        $outputPath = OUTPUT_DIR . '/' . CSV_PREFIX . '_' . $today . '.csv';
    }

    $pdo = jqBuildPdo();
    jqEnsurePdoAlive($pdo);

    echo "financial_actuals 分析用CSV生成\n";
    echo "出力先: {$outputPath}\n";
    echo str_repeat('=', 72) . "\n";

    $securityMap = loadSecurityCodeMasterMap();
    $preferredMap = fetchLatestPreferredFyMap($pdo);

    $summary = writeAnalysisCsv($outputPath, $securityMap, $preferredMap);

    echo "\n" . str_repeat('=', 72) . "\n";
    echo "[SUMMARY]\n";
    echo '証券コードマスタ対象銘柄数 : ' . number_format($summary['master_count']) . "\n";
    echo 'preferred取得銘柄数       : ' . number_format($summary['preferred_count']) . "\n";
    echo 'CSV出力銘柄数             : ' . number_format($summary['output_count']) . "\n";
    echo '財務実績なし              : ' . number_format($summary['missing_financial']) . "\n";
    echo 'EDINET採用                : ' . number_format($summary['source_edinet']) . "\n";
    echo 'TDNET採用                 : ' . number_format($summary['source_tdnet']) . "\n";
    echo '粗利率算出可能            : ' . number_format($summary['gross_margin_count']) . "\n";
    echo '販管費率算出可能          : ' . number_format($summary['sga_ratio_count']) . "\n";
    echo '営業利益率算出可能        : ' . number_format($summary['operating_margin_count']) . "\n";
    echo '出力ファイル              : ' . basename($outputPath) . "\n";
    echo str_repeat('=', 72) . "\n";

    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * 最新FY preferredレコードを銘柄ごとに1件取得する。
 *
 * 優先順:
 *   1. fiscal_period_end が最新
 *   2. 同じFYなら EDINET > TDNET
 *   3. 同一sourceなら disclosed_at が新しい
 *   4. 同一日時なら id が大きい
 *
 * status=OK/PARTIAL のみ。最新FYがPARTIALでも過去FYへは遡らない。
 */
function fetchLatestPreferredFyMap(PDO &$pdo): array
{
    jqEnsurePdoAlive($pdo);

    $sql = "
        SELECT
            id,
            security_code,
            edinet_code,
            company_name,
            fiscal_period_start,
            fiscal_period_end,
            period_type,
            period_basis,
            scope,
            accounting_standard,
            source,
            source_record_key,
            source_document_id,
            source_url,
            disclosure_title,
            disclosed_at,
            is_correction,
            revenue,
            cost_of_sales,
            gross_profit,
            sga,
            operating_profit,
            ordinary_profit,
            pretax_profit,
            net_income,
            total_assets,
            total_liabilities,
            equity,
            cash_and_equivalents,
            inventory,
            interest_bearing_debt,
            cfo,
            cfi,
            cff,
            extraction_method,
            reconciliation_ok,
            status,
            reason,
            updated_at
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
        throw new RuntimeException('financial_actuals の取得に失敗しました。');
    }

    $out = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $code4 = normalizeCode4((string)($row['security_code'] ?? ''));
        if ($code4 === '' || isset($out[$code4])) {
            continue;
        }

        $out[$code4] = $row;
    }

    return $out;
}

function writeAnalysisCsv(string $path, array $securityMap, array $preferredMap): array
{
    $fp = fopen($path, 'wb');
    if ($fp === false) {
        throw new RuntimeException("CSV作成に失敗: {$path}");
    }

    $summary = [
        'master_count' => count($securityMap),
        'preferred_count' => count($preferredMap),
        'output_count' => 0,
        'missing_financial' => 0,
        'source_edinet' => 0,
        'source_tdnet' => 0,
        'gross_margin_count' => 0,
        'sga_ratio_count' => 0,
        'operating_margin_count' => 0,
    ];

    try {
        // Excel / ChatGPT双方で扱いやすいUTF-8 BOM付きCSV。
        fwrite($fp, "\xEF\xBB\xBF");

        fputcsv($fp, [
            '証券コード',
            '更新日',
            'ステータス',
            '理由',
            '連結区分',
            '会計基準',
            '採用ソース',
            '開示日時',
            '訂正フラグ',
            '33業種コード',
            '33業種コード名',
            '売上高',
            '売上原価',
            '売上総利益',
            '販管費',
            '粗利率(%)',
            '販管費率(%)',
            '営業利益率(%)',
        ]);

        foreach ($securityMap as $code5 => $master) {
            $code4 = normalizeCode4((string)($master['security_code'] ?? ''));
            if ($code4 === '') {
                continue;
            }

            $row = $preferredMap[$code4] ?? null;
            if ($row === null) {
                $summary['missing_financial']++;
                continue;
            }

            $revenue = toFloatOrNull($row['revenue'] ?? null);
            $grossProfit = toFloatOrNull($row['gross_profit'] ?? null);
            $sga = toFloatOrNull($row['sga'] ?? null);
            $operatingProfit = toFloatOrNull($row['operating_profit'] ?? null);

            $grossMargin = safeRatioPercent($grossProfit, $revenue);
            $sgaRatio = safeRatioPercent($sga, $revenue);
            $operatingMargin = safeRatioPercent($operatingProfit, $revenue);

            if ($grossMargin !== null) $summary['gross_margin_count']++;
            if ($sgaRatio !== null) $summary['sga_ratio_count']++;
            if ($operatingMargin !== null) $summary['operating_margin_count']++;

            $source = strtoupper(trim((string)($row['source'] ?? '')));
            if ($source === 'EDINET') {
                $summary['source_edinet']++;
            } elseif ($source === 'TDNET') {
                $summary['source_tdnet']++;
            }

            fputcsv($fp, [
                $code4,
                strvalOrEmpty($row['updated_at'] ?? null),
                strvalOrEmpty($row['status'] ?? null),
                strvalOrEmpty($row['reason'] ?? null),
                strvalOrEmpty($row['scope'] ?? null),
                strvalOrEmpty($row['accounting_standard'] ?? null),
                $source,
                strvalOrEmpty($row['disclosed_at'] ?? null),
                strvalOrEmpty($row['is_correction'] ?? null),
                (string)($master['industry33_code'] ?? ''),
                (string)($master['industry33_name'] ?? ''),
                fmtNullable(toFloatOrNull($row['revenue'] ?? null)),
                fmtNullable(toFloatOrNull($row['cost_of_sales'] ?? null)),
                fmtNullable($grossProfit),
                fmtNullable($sga),
                fmtNullable($grossMargin, 6),
                fmtNullable($sgaRatio, 6),
                fmtNullable($operatingMargin, 6),
            ]);

            $summary['output_count']++;
        }
    } finally {
        fclose($fp);
    }

    return $summary;
}

/**
 * 証券コードマスタをGoogle Driveから読み込む。
 */
function loadSecurityCodeMasterMap(): array
{
    $values = loadSpreadsheetValuesLocal(SECURITY_CODE_MASTER_NAME);
    if (count($values) < 2) {
        throw new RuntimeException('証券コードマスタにデータがありません。');
    }

    $header = array_map(static function ($v): string {
        return trim((string)$v);
    }, $values[0]);

    $codeIdx = requireHeaderIndexLocal($header, '証券コード');
    $companyIdx = requireHeaderIndexLocal($header, '銘柄名');
    $code5Idx = requireHeaderIndexLocal($header, '証券コード5桁');
    $marketIdx = requireHeaderIndexLocal($header, '市場区分コード');
    $industryCodeIdx = requireHeaderIndexLocal($header, '33業種コード');
    $industryNameIdx = requireHeaderIndexLocal($header, '33業種コード名');

    $out = [];

    for ($i = 1; $i < count($values); $i++) {
        $row = $values[$i];
        $code5 = normalizeCode5((string)($row[$code5Idx] ?? ''));
        if ($code5 === '') continue;

        $market = trim((string)($row[$marketIdx] ?? ''));
        if ($market === '-' || $market === '109' || $market === '105') {
            continue;
        }

        $out[$code5] = [
            'security_code' => trim((string)($row[$codeIdx] ?? '')),
            'company_name' => trim((string)($row[$companyIdx] ?? '')),
            'industry33_code' => trim((string)($row[$industryCodeIdx] ?? '')),
            'industry33_name' => trim((string)($row[$industryNameIdx] ?? '')),
        ];
    }

    ksort($out, SORT_STRING);
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
        throw new RuntimeException('マスタスプレッドシートが見つかりません: ' . $fileName);
    }

    $ss = $sheets->spreadsheets->get($fileId);
    $sheet0 = $ss->getSheets()[0] ?? null;
    if ($sheet0 === null) {
        throw new RuntimeException('マスタのシート取得に失敗: ' . $fileName);
    }

    $title = $sheet0->getProperties()->getTitle();
    $resp = $sheets->spreadsheets_values->get($fileId, $title . '!A:Z');
    return $resp->getValues() ?? [];
}

function resolveFolderIdByPathLocal(Google\Service\Drive $drive, array $folders): string
{
    $parent = 'root';
    foreach ($folders as $name) {
        $name = (string)$name;
        if ($name === '') continue;

        $q = sprintf(
            "name = '%s' and '%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder'",
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
            throw new RuntimeException('Folder not found: ' . implode('/', $folders));
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
        "name = '%s' and '%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.spreadsheet'",
        str_replace("'", "\\'", $fileName),
        $folderId
    );

    $res = $drive->files->listFiles([
        'q' => $q,
        'fields' => 'files(id,name)',
        'pageSize' => 10,
    ]);
    $files = $res->getFiles();
    return (!$files || count($files) === 0) ? null : $files[0]->getId();
}

function requireHeaderIndexLocal(array $header, string $name): int
{
    $idx = array_search($name, $header, true);
    if ($idx === false) {
        throw new RuntimeException("証券コードマスタに「{$name}」列が見つかりません。");
    }
    return (int)$idx;
}

function safeRatioPercent(?float $numerator, ?float $denominator): ?float
{
    if ($numerator === null || $denominator === null || $denominator == 0.0) {
        return null;
    }
    $v = ($numerator / $denominator) * 100.0;
    return is_finite($v) ? $v : null;
}

function toFloatOrNull($value): ?float
{
    if ($value === null || $value === '') return null;
    if (is_int($value) || is_float($value)) {
        $n = (float)$value;
        return is_finite($n) ? $n : null;
    }
    $s = str_replace(',', '', trim((string)$value));
    if ($s === '' || !is_numeric($s)) return null;
    $n = (float)$s;
    return is_finite($n) ? $n : null;
}

function fmtNullable(?float $value, int $decimals = 2): string
{
    if ($value === null || !is_finite($value)) return '';
    return number_format($value, $decimals, '.', '');
}

function strvalOrEmpty($value): string
{
    if ($value === null) return '';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_scalar($value)) return trim((string)$value);
    return '';
}

function normalizeCode4(string $code): string
{
    $code = strtoupper(preg_replace('/[^0-9A-Z]/', '', trim($code)) ?? '');
    if (strlen($code) === 5 && substr($code, -1) === '0') {
        return substr($code, 0, 4);
    }
    return strlen($code) === 4 ? $code : '';
}

function normalizeCode5(string $code): string
{
    $code4 = normalizeCode4($code);
    return $code4 === '' ? '' : $code4 . '0';
}

function parseCommandLineArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $idx => $arg) {
        if ($idx === 0) continue;
        if (preg_match('/^--([^=]+)=(.*)$/', (string)$arg, $m)) {
            $out[(string)$m[1]] = (string)$m[2];
        } elseif (preg_match('/^--([^=]+)$/', (string)$arg, $m)) {
            $out[(string)$m[1]] = true;
        }
    }
    return $out;
}

function ensureDirLocal(string $dir): void
{
    if (is_dir($dir)) return;
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("mkdir failed: {$dir}");
    }
}
