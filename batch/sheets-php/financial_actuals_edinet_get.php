<?php
declare(strict_types=1);

require '/opt/invest/j_quants/conf/config.php';
require '/opt/invest/j_quants/lib/j_quants_common.php';

/**
 * financial_actuals_edinet_get.php
 *
 * EDINET 有価証券報告書 -> stocks.financial_actuals 本番登録版
 * PHP 7.4+
 *
 * 仕様:
 *   - 引数なし: 今日を含む直近31日を走査
 *   - --from=YYYY-MM-DD --to=YYYY-MM-DD: 手動期間指定
 *   - --code=XXXX: 指定した証券コードだけ処理
 *   - --force: 登録済みdocIDも再解析してUPSERT
 *   - --special_test: --code指定銘柄についてDB登録済みの最新EDINET docIDを直接再解析
 *   - 証券コードごとに対象期間内の最新の有価証券報告書だけ採用
 *   - financial_actuals に同じ EDINET docID があれば取得・解析をSKIP
 *   - 未登録docIDだけEDINET CSV ZIPを取得・解析し、financial_actualsへUPSERT
 *   - financial_actualsには取得元の事実値だけを保存し、派生指標はCSV生成側で計算する
 *   - EDINET側はJ-Quants Summaryで不足する重要項目へ役割を限定する
 *   - 現時点の取得対象: 売上高 / 売上原価 / 売上総利益 / 販管費
 *   - 営業利益・純利益・BS・CF等はEDINETでは無理に重複取得しない
 *   - 連結を優先し、連結Revenueがない場合のみ単体へフォールバック
 *   - 粗利率はGross Profit直接取得を優先し、安全な場合だけRevenue-Costで補完
 *
 * 実行例:
 *   php /opt/invest/sheets-php/financial_actuals_edinet_get.php
 *
 *   php /opt/invest/sheets-php/financial_actuals_edinet_get.php \
 *     --from=2025-03-14 --to=2026-09-14
 *
 *   # 登録済み有報も再解析・UPSERT
 *   php /opt/invest/sheets-php/financial_actuals_edinet_get.php --force
 *
 *   # 指定銘柄だけ処理
 *   php /opt/invest/sheets-php/financial_actuals_edinet_get.php --code=7203
 *
 *   # 指定銘柄を登録済みでも再解析・UPSERT
 *   php /opt/invest/sheets-php/financial_actuals_edinet_get.php --code=7203 --force
 *
 *   # 検証専用高速モード:
 *   # DBに登録済みの最新EDINET docIDを直接使い、通常の書類一覧走査を省略
 *   php /opt/invest/sheets-php/financial_actuals_edinet_get.php --code=7203 --special_test
 *
 */

date_default_timezone_set('Asia/Tokyo');

const EDINET_API_BASE = 'https://api.edinet-fsa.go.jp/api/v2';
const EDINET_API_KEY_FILE = '/opt/invest/secrets/edinet_api_key.txt';

/*
 * 日次運用時の既定走査期間。
 *
 * 引数なし:
 *   今日を含む直近31日。
 *
 * 過去の長期間を取得したい場合は、
 * --from / --to で対象期間を明示的に指定する。
 */
const DEFAULT_SCAN_DAYS = 30;

/*
 * 全銘柄実行では候補一覧やCSVヘッダーを表示しない。
 */
const DETAIL_OUTPUT = false;

/*
 * 本番版。
 * 全銘柄を処理するため検証銘柄固定モードは使用しない。
 */
const VALIDATION_MODE = false;

/*
 * 何社ごとに進捗を表示するか。
 */
const PROGRESS_EVERY = 50;

/*
 * Gross Profit + Cost of Sales と Revenue の整合性確認。
 * 0.5%以内の差を許容する。
 */
const RECONCILIATION_TOLERANCE = 0.005;

const API_INTERVAL_USEC = 500000; // 0.5秒
const EDINET_HTTP_TIMEOUT_SEC = 60;

// 有価証券報告書（docTypeCode=120）。対象期間内で銘柄ごとの最新提出だけ採用。
const ANNUAL_REPORT_DOC_TYPE_CODE = '120';

main();

function main(): void
{
    $args = parseCommandLineArgs($_SERVER['argv'] ?? []);
    [$scanStart, $scanEnd] = resolveScanRange($args);

    $force = isset($args['force']);
    $specialTest = isset($args['special_test']);

    $targetCode = '';
    if (isset($args['code'])) {
        $targetCode = normalizeSecCode((string)$args['code']);

        if ($targetCode === '') {
            throw new RuntimeException(
                '--code は4桁の証券コードを指定してください: ' .
                (string)$args['code']
            );
        }
    }

    if ($specialTest && $targetCode === '') {
        throw new RuntimeException(
            '--special_test は --code=XXXX とセットで指定してください。'
        );
    }

    echo "EDINET financial_actuals 全銘柄DB登録\n";
    echo '対象期間: ' . $scanStart . ' ～ ' . $scanEnd . "\n";
    echo 'TARGET_CODE=' . ($targetCode !== '' ? $targetCode : 'ALL') . "\n";
    echo 'FORCE=' . ($force ? 'true' : 'false') . "\n";
    echo 'SPECIAL_TEST=' . ($specialTest ? 'true' : 'false') . "\n";
    echo str_repeat('=', 72) . "
";

    $apiKey = loadApiKey();

    /*
    * J-Quants DBから銘柄ごとの最新FY決算期を取得する。
    *
    * EDINET有報のperiodEndと比較し、
    * EDINET粗利率が現在の最新本決算に追いついているか判定する。
    */
    $pdo = jqBuildPdo();

    $jquantsLatestFyMap =
        fetchLatestJquantsFyEndMap($pdo);

    /*
     * 通常モード:
     *   EDINET書類一覧を走査し、証券コードごとの最新有報を保持する。
     *
     * --special_test:
     *   financial_actuals に登録済みの対象銘柄・最新EDINET行から
     *   docIDと書類メタデータを直接取得する。
     *   通常の書類一覧走査を省略するため、個別検証を高速化できる。
     */
    $documents = [];

    if ($specialTest) {

        $specialDoc = fetchLatestRegisteredEdinetDocument(
            $pdo,
            $targetCode
        );

        if ($specialDoc === null) {
            throw new RuntimeException(
                "financial_actuals に登録済みEDINET有報がありません: {$targetCode}"
            );
        }

        $documents = [
            $targetCode => $specialDoc,
        ];

        echo sprintf(
            "[SPECIAL_TEST] DB登録済み最新docIDを直接使用: code=%s docID=%s FY=%s\n",
            $targetCode,
            (string)($specialDoc['docID'] ?? ''),
            (string)($specialDoc['periodEnd'] ?? '')
        );

    } else {

        scanAllAnnualReports(
            $scanStart,
            $scanEnd,
            $apiKey,
            $documents
        );

        /*
         * --code 指定時は対象銘柄だけ残す。
         */
        if ($targetCode !== '') {

            if (!isset($documents[$targetCode])) {
                throw new RuntimeException(
                    "指定証券コードの有価証券報告書が対象期間内に見つかりません: {$targetCode}"
                );
            }

            $documents = [
                $targetCode => $documents[$targetCode],
            ];
        }
    }

    ksort($documents);


    $summary = [
        'documents' => count($documents),
        'processed' => 0,
        'skipped_existing' => 0,
        'ok' => 0,
        'gross_direct' => 0,
        'revenue_cost' => 0,
        'sga_selected' => 0,
        'scope_consolidated_ok' => 0,
        'scope_nonconsolidated_ok' => 0,

        'freshness_fresh' => 0,
        'freshness_stale' => 0,
        'freshness_edinet_ahead' => 0,
        'freshness_unknown' => 0,
        
        'ng_revenue' => 0,
        'ng_gross_cost' => 0,
        'ng_incompatible' => 0,
        'ng_reconciliation' => 0,
        'ng_other' => 0,

        'error' => 0,
        'db_saved' => 0,
        'db_insert' => 0,
        'db_update' => 0,
        'db_no_change' => 0,
            
        'record_ok' => 0,
        'record_partial' => 0,
        'record_ng' => 0,
    ];

    echo "\n";
    echo '[FOUND] 最新有価証券報告書: ' .
        number_format(count($documents)) .
        "銘柄\n";
    echo str_repeat('=', 72) . "\n";

    foreach ($documents as $code => $doc) {

        // PHPは数字だけの配列キーをintへ変換するため、証券コードは明示的にstringへ戻す。
        $code = (string)$code;

        $summary['processed']++;

        $docId = trim((string)($doc['docID'] ?? ''));

        if ($docId === '') {
            $summary['error']++;
            echo sprintf(
                "[ERROR] %s %s docIDが空です。\n",
                $code,
                (string)($doc['filerName'] ?? '')
            );
            continue;
        }

        /*
         * EDINETのdocIDをsource_record_keyとして保存しているため、
         * 既登録docIDはCSV ZIP取得前にSKIPする。
         */
        if (
            !$force &&
            !$specialTest &&
            financialActualSourceRecordExistsWithReconnect(
                $pdo,
                'EDINET',
                $docId
            )
        ) {
            $summary['skipped_existing']++;
            echo sprintf(
                "[SKIP] %s %s docID=%s は登録済みです。\n",
                $code,
                (string)($doc['filerName'] ?? ''),
                $docId
            );
            continue;
        }

        /*
        * EDINET有報の対象決算期と
        * J-Quants最新FY決算期を比較する。
        */
        $edinetFyEnd = normalizeDateText(
            (string)($doc['periodEnd'] ?? '')
        );

        $jquantsFyEnd =
            $jquantsLatestFyMap[$code] ?? '';

        $freshness = determineFreshness(
            $edinetFyEnd,
            $jquantsFyEnd
        );

        if ($freshness === 'FRESH') {
            $summary['freshness_fresh']++;

        } elseif ($freshness === 'STALE') {
            $summary['freshness_stale']++;

        } elseif ($freshness === 'EDINET_AHEAD') {
            $summary['freshness_edinet_ahead']++;

        } else {
            $summary['freshness_unknown']++;
        }

        try {
            $csvFiles = downloadAndExtractCsvData(
                $docId,
                $apiKey
            );

            if (count($csvFiles) === 0) {
                $summary['ng_other']++;

                echo sprintf(
                    "[NG] %s %s CSVなし\n",
                    $code,
                    (string)($doc['filerName'] ?? '')
                );

                continue;
            }

            $facts = loadEdinetCsvFacts($csvFiles);

            if (count($facts) === 0) {
                $summary['ng_other']++;

                echo sprintf(
                    "[NG] %s %s factなし\n",
                    $code,
                    (string)($doc['filerName'] ?? '')
                );

                continue;
            }

            $candidates = classifyCandidates($facts);

            if (DETAIL_OUTPUT) {
                printCandidateGroup(
                    'Revenue / Sales',
                    $candidates['revenue']
                );

                printCandidateGroup(
                    'Cost of Sales',
                    $candidates['cost']
                );

                printCandidateGroup(
                    'Gross Profit',
                    $candidates['gross']
                );
            }

            /*
             * 会社単位で使用するスコープを決定する。
             *
             * 連結当期Revenueが存在する場合:
             *   Revenue / Cost / Grossすべて連結から選択。
             *
             * 連結当期Revenueが存在しない場合:
             *   単体当期へフォールバック。
             *
             * 項目ごとに連結・単体を混在させない。
             */
            $factScope = detectFactScope(
                $candidates['revenue']
            );

            $selectedRevenue = selectBestFact(
                $candidates['revenue'],
                'revenue',
                $factScope
            );

            $selectedCost = selectBestFact(
                $candidates['cost'],
                'cost',
                $factScope
            );

            $selectedGross = selectBestFact(
                $candidates['gross'],
                'gross',
                $factScope
            );
            
            $selectedSga = selectBestFact(
                $candidates['sga'],
                'sga',
                $factScope
            );
            $result = evaluateGrossMargin(
                $selectedRevenue,
                $selectedCost,
                $selectedGross
            );

            $recordStatus = evaluateFinancialActualStatus(
                $result,
                $selectedSga
            );

            $record = buildEdinetFinancialActualRecord(
                $code,
                $doc,
                $factScope,
                $selectedRevenue,
                $selectedCost,
                $selectedGross,
                $selectedSga,
                $result,
                $recordStatus
            );

            $affected = upsertFinancialActualWithReconnect($pdo, $record);
            $summary['db_saved']++;
            if ($affected === 1) {
                $summary['db_insert']++;
                $dbAction = 'INSERT';
            } elseif ($affected === 2) {
                $summary['db_update']++;
                $dbAction = 'UPDATE';
            } else {
                $summary['db_no_change']++;
                $dbAction = 'NO_CHANGE';
            }
            
            if ($recordStatus['status'] === 'OK') {
                $summary['record_ok']++;
            } elseif ($recordStatus['status'] === 'PARTIAL') {
                $summary['record_partial']++;
            } else {
                $summary['record_ng']++;
            }

            if ($selectedSga !== null) {
                $summary['sga_selected']++;
            }
            
            echo sprintf(
                "[DB OK] financial_actuals %s affected_rows=%d\n",
                $dbAction,
                $affected
            );

            if ($result['status'] === 'OK') {

                $summary['ok']++;

                if ($result['method'] === 'gross_direct') {
                    $summary['gross_direct']++;
                } elseif ($result['method'] === 'revenue_cost') {
                    $summary['revenue_cost']++;
                }

                if ($factScope === 'nonconsolidated') {
                    $summary['scope_nonconsolidated_ok']++;
                } else {
                    $summary['scope_consolidated_ok']++;
                }
                echo sprintf(
                    "[OK] %s %s EDINET_FY=%s JQUANTS_FY=%s freshness=%s scope=%s gross_margin=%.2f method=%s docID=%s
",
                    $code,
                    (string)($doc['filerName'] ?? ''),
                    $edinetFyEnd !== '' ? $edinetFyEnd : '-',
                    $jquantsFyEnd !== '' ? $jquantsFyEnd : '-',
                    $freshness,
                    $factScope,
                    (float)$result['margin'],
                    (string)$result['method'],
                    $docId
                );

            } else {

                $reason = (string)$result['reason'];

                if ($reason === 'revenue_not_found') {
                    $summary['ng_revenue']++;

                } elseif ($reason === 'gross_cost_not_found') {
                    $summary['ng_gross_cost']++;

                } elseif ($reason === 'revenue_cost_incompatible') {
                    $summary['ng_incompatible']++;

                } elseif ($reason === 'reconciliation_error') {
                    $summary['ng_reconciliation']++;

                } else {
                    $summary['ng_other']++;
                }

                /*
                 * NGは後で原因を見るため1行出しておく。
                 */
                echo sprintf(
                    "[NG] %s %s EDINET_FY=%s JQUANTS_FY=%s freshness=%s scope=%s reason=%s revenue=%s cost=%s gross=%s\n",
                    $code,
                    (string)($doc['filerName'] ?? ''),
                    $edinetFyEnd !== '' ? $edinetFyEnd : '-',
                    $jquantsFyEnd !== '' ? $jquantsFyEnd : '-',
                    $freshness,
                    $factScope,
                    $reason,
                    factElementName($selectedRevenue),
                    factElementName($selectedCost),
                    factElementName($selectedGross)
                );
            }

        } catch (Throwable $e) {

            $summary['error']++;

            echo sprintf(
                "[ERROR] %s %s %s\n",
                $code,
                (string)($doc['filerName'] ?? ''),
                $e->getMessage()
            );
        }

        if (
            $summary['processed'] % PROGRESS_EVERY === 0
        ) {
            echo sprintf(
                "[PROGRESS] %d / %d  OK=%d NG=%d ERROR=%d\n",
                $summary['processed'],
                $summary['documents'],
                $summary['ok'],
                $summary['processed']
                    - $summary['ok']
                    - $summary['error'],
                $summary['error']
            );
        }
    }

    printAllCompanySummary($summary);
}


/**
 * CLI引数を解析する。
 *
 * 例:
 *   --from=2026-08-15
 *   --to=2026-09-14
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

/**
 * 走査期間を決定する。
 *
 * 引数なし:
 *   今日を含む直近31日
 *
 * 片方だけ指定:
 *   指定側を基準に31日窓を作る
 *
 * 両方指定:
 *   指定期間をそのまま使用
 */
function resolveScanRange(array $args): array
{
    $today = new DateTimeImmutable('today');

    $hasFrom = isset($args['from']) && trim((string)$args['from']) !== '';
    $hasTo = isset($args['to']) && trim((string)$args['to']) !== '';

    /*
     * 引数なし:
     *   今日を含む直近31日。
     */
    if (!$hasFrom && !$hasTo) {
        $end = $today;
        $start = $end->modify('-' . DEFAULT_SCAN_DAYS . ' days');

        return [
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        ];
    }

    /*
     * 手動指定はfrom/toをセットで要求する。
     */
    if (!$hasFrom || !$hasTo) {
        throw new RuntimeException(
            '--from と --to は両方指定してください。'
        );
    }

    $startText = normalizeCliDate((string)$args['from'], '--from');
    $endText = normalizeCliDate((string)$args['to'], '--to');

    if ($startText > $endText) {
        throw new RuntimeException(
            '--from は --to 以下の日付を指定してください。'
        );
    }

    return [$startText, $endText];
}

function normalizeCliDate(string $value, string $name): string
{
    $value = trim($value);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        throw new RuntimeException(
            "{$name} は YYYY-MM-DD 形式で指定してください: {$value}"
        );
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if (
        $dt === false ||
        $dt->format('Y-m-d') !== $value
    ) {
        throw new RuntimeException(
            "{$name} の日付が不正です: {$value}"
        );
    }

    return $value;
}

function loadApiKey(): string
{
    if (!is_file(EDINET_API_KEY_FILE)) {
        throw new RuntimeException(
            'EDINET APIキーファイルがありません: ' . EDINET_API_KEY_FILE
        );
    }

    $apiKey = trim((string)file_get_contents(EDINET_API_KEY_FILE));

    if ($apiKey === '') {
        throw new RuntimeException('EDINET APIキーが空です。');
    }

    return $apiKey;
}
/**
 * jquants_fins_summaryから、
 * 証券コードごとの最新FY実績の決算期末を取得する。
 *
 * Sales / OP等の欠損有無には依存しない。
 * 鮮度判定では「最新のFY決算がいつか」だけを見るため。
 *
 * 戻り値:
 *   [
 *       '7203' => '2026-03-31',
 *       '3984' => '2026-06-30',
 *       ...
 *   ]
 */
function fetchLatestJquantsFyEndMap(PDO &$pdo): array
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
            CurFYEn
        FROM jquants_fins_summary
        WHERE CurPerType = 'FY'
          AND DocType LIKE 'FYFinancialStatements_%'
        ORDER BY
            Code ASC,
            DiscDate DESC,
            DiscTime DESC,
            DiscNo DESC
    ";

    $stmt = $pdo->query($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'jquants_fins_summary の最新FY取得に失敗しました。'
        );
    }

    $out = [];
    $seen = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $code = normalizeJquantsSecCode(
            (string)($row['Code'] ?? '')
        );

        if (
            $code === '' ||
            isset($seen[$code])
        ) {
            continue;
        }

        /*
         * 銘柄ごとの最新FYをここで確定する。
         * CurFYEnが欠損していても過去FYへ遡らない。
         */
        $seen[$code] = true;

        $fyEnd = normalizeDateText(
            (string)($row['CurFYEn'] ?? '')
        );

        if ($fyEnd === '') {
            continue;
        }

        $out[$code] = $fyEnd;
    }

    return $out;
}
/**
 * J-Quantsの証券コードを、
 * EDINET側で使用している4文字コードへ揃える。
 *
 * 72030 -> 7203
 * 290A0 -> 290A
 * 7203  -> 7203
 */
function normalizeJquantsSecCode(string $code): string
{
    $code = strtoupper(
        trim($code)
    );

    $code = preg_replace(
        '/[^0-9A-Z]/',
        '',
        $code
    ) ?? '';

    if (
        strlen($code) === 5 &&
        substr($code, -1) === '0'
    ) {
        return substr($code, 0, 4);
    }

    if (strlen($code) === 4) {
        return $code;
    }

    return '';
}
/**
 * YYYY-MM-DD / YYYYMMDD 等を
 * YYYY-MM-DDへ正規化する。
 */
function normalizeDateText(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (
        preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})$/',
            $value,
            $m
        )
    ) {
        return
            $m[1] . '-' .
            $m[2] . '-' .
            $m[3];
    }

    if (
        preg_match(
            '/^(\d{4})(\d{2})(\d{2})$/',
            $value,
            $m
        )
    ) {
        return
            $m[1] . '-' .
            $m[2] . '-' .
            $m[3];
    }

    return '';
}
/**
 * EDINET有報の決算期と、
 * J-Quants最新FY決算期を比較する。
 *
 * FRESH:
 *   同じ決算期
 *
 * STALE:
 *   J-Quantsの方が新しい
 *
 * EDINET_AHEAD:
 *   EDINETの方が新しい
 *
 * UNKNOWN:
 *   どちらかの決算期を取得できない
 */
function determineFreshness(
    string $edinetFyEnd,
    string $jquantsFyEnd
): string {

    if (
        $edinetFyEnd === '' ||
        $jquantsFyEnd === ''
    ) {
        return 'UNKNOWN';
    }

    if ($edinetFyEnd === $jquantsFyEnd) {
        return 'FRESH';
    }

    if ($edinetFyEnd < $jquantsFyEnd) {
        return 'STALE';
    }

    return 'EDINET_AHEAD';
}

/**
 * --special_test 用。
 *
 * financial_actuals に登録済みの対象銘柄EDINET行から、
 * 最新FYの書類メタデータを取得してEDINET API書類一覧と同じ形へ変換する。
 *
 * これにより、通常の documents.json 走査を省略して
 * 登録済みdocIDのCSV ZIPを直接再取得・再解析できる。
 */
function fetchLatestRegisteredEdinetDocument(
    PDO &$pdo,
    string $securityCode
): ?array {
    jqEnsurePdoAlive($pdo);

    $stmt = $pdo->prepare(
        "SELECT
            security_code,
            edinet_code,
            company_name,
            fiscal_period_start,
            fiscal_period_end,
            source_record_key,
            source_document_id,
            disclosure_title,
            disclosed_at
         FROM financial_actuals
         WHERE source = 'EDINET'
           AND security_code = :security_code
         ORDER BY
            fiscal_period_end DESC,
            disclosed_at DESC,
            id DESC
         LIMIT 1"
    );

    $stmt->execute([
        ':security_code' => $securityCode,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return null;
    }

    $docId = trim(
        (string)(
            $row['source_document_id']
            ?? $row['source_record_key']
            ?? ''
        )
    );

    if ($docId === '') {
        $docId = trim((string)($row['source_record_key'] ?? ''));
    }

    if ($docId === '') {
        throw new RuntimeException(
            "登録済みEDINET行のdocIDが空です: {$securityCode}"
        );
    }

    return [
        'docID' => $docId,
        'edinetCode' => (string)($row['edinet_code'] ?? ''),
        'secCode' => $securityCode,
        'filerName' => (string)($row['company_name'] ?? ''),
        'periodStart' => (string)($row['fiscal_period_start'] ?? ''),
        'periodEnd' => (string)($row['fiscal_period_end'] ?? ''),
        'submitDateTime' => (string)($row['disclosed_at'] ?? ''),
        'docDescription' => (string)($row['disclosure_title'] ?? ''),
        'docTypeCode' => ANNUAL_REPORT_DOC_TYPE_CODE,
    ];
}


function scanAllAnnualReports(
    string $startDate,
    string $endDate,
    string $apiKey,
    array &$documents
): void {
    echo "[SCAN] {$startDate} ～ {$endDate}\n";

    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);

    foreach (
        new DatePeriod(
            $start,
            new DateInterval('P1D'),
            $end->modify('+1 day')
        ) as $date
    ) {
        $dateText = $date->format('Y-m-d');

        $url =
            EDINET_API_BASE .
            '/documents.json?date=' .
            rawurlencode($dateText) .
            '&type=2&Subscription-Key=' .
            rawurlencode($apiKey);

        $json = httpGet($url);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new RuntimeException(
                "書類一覧JSONの解析に失敗しました: {$dateText}"
            );
        }

        $results = $data['results'] ?? [];

        if (!is_array($results)) {
            $results = [];
        }

        foreach ($results as $row) {

            if (!is_array($row)) {
                continue;
            }

            $docTypeCode = trim(
                (string)($row['docTypeCode'] ?? '')
            );

            if (
                $docTypeCode !==
                ANNUAL_REPORT_DOC_TYPE_CODE
            ) {
                continue;
            }

            $secCode = normalizeSecCode(
                (string)($row['secCode'] ?? '')
            );

            /*
             * 証券コードなしは今回の母集団外。
             */
            if ($secCode === '') {
                continue;
            }

            $docId = trim(
                (string)($row['docID'] ?? '')
            );

            if ($docId === '') {
                continue;
            }

            /*
             * 約1年間に複数の有報がある場合は、
             * 最も新しい提出を採用する。
             */
            if (
                !isset($documents[$secCode]) ||
                compareDocumentRecency(
                    $row,
                    $documents[$secCode]
                ) > 0
            ) {
                $documents[$secCode] = $row;
            }
        }

        usleep(API_INTERVAL_USEC);
    }
}

function normalizeSecCode(string $secCode): string
{
    $secCode = strtoupper(
        trim($secCode)
    );

    /*
     * 新証券コードの英字にも対応。
     */
    $secCode = preg_replace(
        '/[^0-9A-Z]/',
        '',
        $secCode
    ) ?? '';

    /*
     * EDINETの証券コードは通常、
     * 4桁コード + 末尾0 の5文字。
     *
     * 例:
     * 72030 → 7203
     * 290A0 → 290A
     */
    if (
        strlen($secCode) === 5 &&
        substr($secCode, -1) === '0'
    ) {
        return substr($secCode, 0, 4);
    }

    if (strlen($secCode) === 4) {
        return $secCode;
    }

    return '';
}

function compareDocumentRecency(array $a, array $b): int
{
    return strcmp(documentDateTime($a), documentDateTime($b));
}

function documentDateTime(array $row): string
{
    $submitDateTime = trim(
        (string)($row['submitDateTime'] ?? '')
    );

    if ($submitDateTime !== '') {
        return $submitDateTime;
    }

    return trim((string)($row['periodEnd'] ?? ''));
}

function printDocumentInfo(array $doc): void
{
    echo "\n[DOCUMENT]\n";
    echo '会社名       : ' . (string)($doc['filerName'] ?? '') . "\n";
    echo '証券コード   : ' . normalizeSecCode((string)($doc['secCode'] ?? '')) . "\n";
    echo 'EDINET Code  : ' . (string)($doc['edinetCode'] ?? '') . "\n";
    echo 'DocID        : ' . (string)($doc['docID'] ?? '') . "\n";
    echo '提出日時     : ' . (string)($doc['submitDateTime'] ?? '') . "\n";
    echo '対象期間     : ' .
        (string)($doc['periodStart'] ?? '') .
        ' ～ ' .
        (string)($doc['periodEnd'] ?? '') .
        "\n";
    echo '書類名       : ' . (string)($doc['docDescription'] ?? '') . "\n";
}

/**
 * EDINET API 書類取得 type=5 のCSV ZIPを一時展開する。
 *
 * 戻り値:
 *   展開済みCSVファイルのパス配列
 *
 * register_shutdown_function() で一時ディレクトリを削除する。
 */
function downloadAndExtractCsvData(
    string $docId,
    string $apiKey
): array {
    $url =
        EDINET_API_BASE .
        '/documents/' .
        rawurlencode($docId) .
        '?type=5&Subscription-Key=' .
        rawurlencode($apiKey);

    $zipBinary = httpGet($url);

    if (strlen($zipBinary) < 4 || substr($zipBinary, 0, 2) !== 'PK') {
        throw new RuntimeException(
            "CSV ZIPを取得できませんでした。docID={$docId}"
        );
    }

    $tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $tmpDir =
        $tmpBase .
        DIRECTORY_SEPARATOR .
        'edinet_gp_' .
        $docId .
        '_' .
        bin2hex(random_bytes(4));

    if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        throw new RuntimeException(
            '一時ディレクトリを作成できません: ' . $tmpDir
        );
    }

    register_shutdown_function(
        static function () use ($tmpDir): void {
            removeDirectoryRecursive($tmpDir);
        }
    );

    $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'document.zip';

    if (file_put_contents($zipPath, $zipBinary) === false) {
        throw new RuntimeException('一時ZIPを書き込めませんでした。');
    }

    $zip = new ZipArchive();

    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException(
            'EDINET CSV ZIPを開けませんでした。'
        );
    }

    if (!$zip->extractTo($tmpDir)) {
        $zip->close();
        throw new RuntimeException(
            'EDINET CSV ZIPを展開できませんでした。'
        );
    }

    $zip->close();

    $csvFiles = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $tmpDir,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        if (strtolower($fileInfo->getExtension()) !== 'csv') {
            continue;
        }

        $csvFiles[] = $fileInfo->getPathname();
    }

    sort($csvFiles);

    return $csvFiles;
}

/**
 * EDINETのCSVを読み込み、検索可能な共通fact配列へ変換する。
 *
 * EDINET CSVの列名は版・ファイルで多少異なる可能性があるため、
 * ヘッダー名候補を柔軟に解決する。
 */
function loadEdinetCsvFacts(array $csvFiles): array
{
    $facts = [];

    foreach ($csvFiles as $csvPath) {

        $raw = file_get_contents($csvPath);

        if ($raw === false || $raw === '') {
            continue;
        }

        /*
         * EDINETのCSVはUTF-16LE＋タブ区切り。
         * そのままfgetcsv()すると、
         * ・日本語が文字化け
         * ・全行が1列扱い
         * になるためUTF-8へ変換してから解析する。
         */
        $utf8 = mb_convert_encoding(
            $raw,
            'UTF-8',
            'UTF-16LE'
        );

        /*
         * 改行コードを統一。
         */
        $utf8 = str_replace(
            ["\r\n", "\r"],
            "\n",
            $utf8
        );

        $lines = explode("\n", $utf8);

        if (count($lines) === 0) {
            continue;
        }

        /*
         * 先頭行をヘッダーとして取得。
         * EDINET CSVはタブ区切り。
         */
        $headerLine = array_shift($lines);

        if ($headerLine === null || trim($headerLine) === '') {
            continue;
        }

        $header = str_getcsv(
            $headerLine,
            "\t"
        );

        if (!is_array($header)) {
            continue;
        }

        $header = array_map(
            'normalizeCsvHeader',
            $header
        );

        /*
         * 一時デバッグ。
         * 実際のEDINET CSV列名を確認する。
         */
         if (DETAIL_OUTPUT) {

             echo "\n[CSV DEBUG]\n";
             echo "File: " . $csvPath . "\n";
             echo "Header columns: " .
                 count($header) .
                 "\n";

             foreach ($header as $i => $name) {
                 echo "  [{$i}] {$name}\n";
             }
         }

        /*
         * 実際のCSVヘッダー名に候補を合わせる。
         * この段階では広めに候補を持たせる。
         */
        $idxElement = findHeaderIndex(
            $header,
            [
                '要素ID',
                '要素ＩＤ',
                'Element ID',
                'ElementId'
            ]
        );

        $idxLabel = findHeaderIndex(
            $header,
            [
                '項目名',
                '標準ラベル（日本語）',
                '標準ラベル',
                'Label'
            ]
        );

        $idxContext = findHeaderIndex(
            $header,
            [
                'コンテキストID',
                'コンテキストＩＤ',
                'Context ID',
                'ContextId'
            ]
        );

        $idxValue = findHeaderIndex(
            $header,
            [
                '値',
                'Value'
            ]
        );

        $idxUnit = findHeaderIndex(
            $header,
            [
                '単位',
                'Unit'
            ]
        );

        /*
         * この時点ではヘッダー確認が目的なので、
         * Element IDまたは値列が見つからなければ
         * そのCSVはスキップする。
         */
        if (
            $idxElement === null ||
            $idxValue === null
        ) {
            echo "  [SKIP] 必要列を特定できませんでした。\n";

            echo "         idxElement=" .
                ($idxElement === null ? 'NULL' : (string)$idxElement) .
                "\n";

            echo "         idxValue=" .
                ($idxValue === null ? 'NULL' : (string)$idxValue) .
                "\n";

            continue;
        }

        foreach ($lines as $line) {

            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv(
                $line,
                "\t"
            );

            if (!is_array($row)) {
                continue;
            }

            $element = cell(
                $row,
                $idxElement
            );

            $label =
                $idxLabel === null
                    ? ''
                    : cell($row, $idxLabel);

            $context =
                $idxContext === null
                    ? ''
                    : cell($row, $idxContext);

            $valueRaw = cell(
                $row,
                $idxValue
            );

            $unit =
                $idxUnit === null
                    ? ''
                    : cell($row, $idxUnit);

            if (
                $element === '' ||
                $valueRaw === ''
            ) {
                continue;
            }

            $numericValue = parseNumericValue(
                $valueRaw
            );

            if ($numericValue === null) {
                continue;
            }

            $facts[] = [
                'element' => $element,
                'label' => $label,
                'context' => $context,
                'value' => $numericValue,
                'value_raw' => $valueRaw,
                'unit' => $unit,
                'source_file' => basename($csvPath),
            ];
        }
    }

    return $facts;
}
function normalizeCsvHeader($value): string
{
    $s = (string)$value;

    // UTF-8 BOM除去
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;

    $s = trim($s);

    /*
     * EDINET CSVでは先頭列名などに
     * ダブルクォートが文字として残る場合があるため除去。
     */
    $s = trim($s, "\"'");

    return trim($s);
}

function findHeaderIndex(array $header, array $candidates): ?int
{
    foreach ($candidates as $candidate) {
        $idx = array_search($candidate, $header, true);

        if ($idx !== false) {
            return (int)$idx;
        }
    }

    return null;
}

function cell(array $row, int $idx): string
{
    return trim((string)($row[$idx] ?? ''));
}

function parseNumericValue(string $value): ?float
{
    $s = trim($value);

    if ($s === '') {
        return null;
    }

    $s = str_replace([',', '，', ' '], '', $s);

    // 括弧マイナスにも対応
    if (preg_match('/^\((.+)\)$/', $s, $m)) {
        $s = '-' . $m[1];
    }

    if (!is_numeric($s)) {
        return null;
    }

    $n = (float)$s;

    return is_finite($n) ? $n : null;
}

/**
 * 候補抽出。
 *
 * PoCなので狭く決め打ちせず、Element ID と日本語ラベルの両方から
 * 候補を広めに拾い、コンソールで確認できるようにする。
 */

function classifyCandidates(array $facts): array
{
    $out = [
        'revenue' => [],
        'cost' => [],
        'gross' => [],
        'sga' => [],
    ];

    foreach ($facts as $fact) {
        $element = strtolower((string)$fact['element']);
        $label = mb_strtolower((string)$fact['label'], 'UTF-8');

        if (
            containsAny($element, [
                'revenue',
                'netsales',
                'sales',
                'operatingrevenue',
            ]) ||
            containsAny($label, [
                '売上高',
                '売上収益',
                '営業収益',
                '収益',
            ])
        ) {
            $out['revenue'][] = $fact;
        }

        if (
            containsAny($element, [
                'costofsales',
                'costofrevenue',
                'costofgoods',
            ]) ||
            containsAny($label, [
                '売上原価',
                '売上コスト',
            ])
        ) {
            $out['cost'][] = $fact;
        }

        if (
            containsAny($element, [
                'grossprofit',
                'grossloss',
            ]) ||
            containsAny($label, [
                '売上総利益',
                '売上総損失',
            ])
        ) {
            $out['gross'][] = $fact;
        }

        if (
            containsAny($element, [
                'sellinggeneralandadministrativeexpenses',
                'sellinggeneralandadministrativeexpense',
            ]) ||
            containsAny($label, [
                '販売費及び一般管理費',
                '販売費及び一般管理費合計',
                '販管費',
            ])
        ) {
            $out['sga'][] = $fact;
        }
    }

    return $out;
}

function containsAny(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
            return true;
        }
    }

    return false;
}
/**
 * 粗利率算出に使用する財務諸表スコープを決定する。
 *
 * 原則:
 *   連結当期Revenueが存在すれば連結。
 *   存在しない場合のみ単体へフォールバック。
 *
 * Revenue / Cost / Grossを個別にフォールバックさせると
 * 連結売上高と単体粗利などを混在させる危険があるため、
 * 会社単位でスコープを固定する。
 */
function detectFactScope(array $revenueFacts): string
{
    foreach ($revenueFacts as $fact) {

        if (
            isEligibleFact(
                $fact,
                'revenue',
                'consolidated'
            )
        ) {
            return 'consolidated';
        }
    }

    return 'nonconsolidated';
}
/**
 * 候補から、指定されたスコープで
 * EDINET取得対象（粗利関連・販管費）に使用してよいfactだけを選ぶ。
 *
 * ・連結/単体はscopeで固定
 * ・セグメント等の追加Member付きContextを除外
 * ・当期以外を除外
 * ・kindごとの信頼対象外Elementを除外
 */
function selectBestFact(
    array $facts,
    string $kind,
    string $scope
): ?array
{
    if (count($facts) === 0) {
        return null;
    }

    $scored = [];

    foreach ($facts as $fact) {

        if (
            !isEligibleFact(
                $fact,
                $kind,
                $scope
            )
        ) {
            continue;
        }

        $score = scoreFact($fact, $kind);

        $fact['_score'] = $score;
        $scored[] = $fact;
    }

    if (count($scored) === 0) {
        return null;
    }

    usort(
        $scored,
        static function (array $a, array $b): int {
            if ($a['_score'] === $b['_score']) {
                return 0;
            }

            return $a['_score'] > $b['_score'] ? -1 : 1;
        }
    );

    return $scored[0];
}
/**
 * EDINET取得対象（粗利関連・販管費）として採用してよいfactか判定する。
 *
 * $scope:
 *   consolidated    = 連結当期
 *   nonconsolidated = 単体当期
 */
function isEligibleFact(
    array $fact,
    string $kind,
    string $scope
): bool {
    $element = strtolower(
        (string)$fact['element']
    );

    $context = strtolower(
        (string)$fact['context']
    );
    if (strpos($context, 'currentyearduration') === false) {
        return false;
    }

    $isNonConsolidated =
        strpos(
            $context,
            'nonconsolidated'
        ) !== false ||
        strpos(
            $context,
            'non-consolidated'
        ) !== false;

    if ($scope === 'consolidated') {

        if ($isNonConsolidated) {
            return false;
        }

    } elseif ($scope === 'nonconsolidated') {

        if (!$isNonConsolidated) {
            return false;
        }

    } else {
        return false;
    }

    if ($scope === 'consolidated') {

        if (
            strpos($context, 'member') !== false ||
            strpos($context, 'segment') !== false
        ) {
            return false;
        }

    } else {

        $contextWithoutNonConsolidated =
            str_replace(
                [
                    'nonconsolidatedmember',
                    'non-consolidatedmember',
                ],
                '',
                $context
            );

        if (
            strpos(
                $contextWithoutNonConsolidated,
                'member'
            ) !== false ||
            strpos(
                $contextWithoutNonConsolidated,
                'segment'
            ) !== false
        ) {
            return false;
        }
    }

    if ($kind === 'gross') {
        return (
            strpos(
                $element,
                'grossprofit'
            ) !== false ||
            strpos(
                $element,
                'grossloss'
            ) !== false
        );
    }

    if ($kind === 'cost') {
        return (
            strpos(
                $element,
                'costofsales'
            ) !== false ||
            strpos(
                $element,
                'costofrevenue'
            ) !== false
        );
    }

    if ($kind === 'revenue') {
        return true;
    }

    if ($kind === 'sga') {
        return containsAny($element, [
            'sellinggeneralandadministrativeexpenses',
            'sellinggeneralandadministrativeexpense',
        ]);
    }

    return false;
}

function scoreFact(array $fact, string $kind): int
{
    $element = strtolower((string)$fact['element']);
    $label = mb_strtolower((string)$fact['label'], 'UTF-8');
    $context = strtolower((string)$fact['context']);

    $score = 0;

    // 当期Durationを優先
    if (
        strpos($context, 'currentyearduration') !== false ||
        strpos($context, 'currentyearinstant') !== false
    ) {
        $score += 100;
    } elseif (
        strpos($context, 'currentyear') !== false &&
        strpos($context, 'duration') !== false
    ) {
        $score += 80;
    }

    // 前期等は強く減点
    if (
        strpos($context, 'prior') !== false ||
        strpos($context, 'previous') !== false
    ) {
        $score -= 120;
    }

    // 項目そのものの優先順位
    if ($kind === 'gross') {
        if (strpos($element, 'grossprofit') !== false) {
            $score += 50;
        }
        if (mb_strpos($label, '売上総利益') !== false) {
            $score += 50;
        }
    } elseif ($kind === 'cost') {
        if (strpos($element, 'costofsales') !== false) {
            $score += 50;
        }
        if (mb_strpos($label, '売上原価') !== false) {
            $score += 50;
        }
    } elseif ($kind === 'revenue') {

        if (strpos($element, 'revenue') !== false) {
            $score += 35;
        }

        if (strpos($element, 'netsales') !== false) {
            $score += 35;
        }

        if (mb_strpos($label, '売上高') !== false) {
            $score += 35;
       }

        if (mb_strpos($label, '売上収益') !== false) {
            $score += 35;
        }

        /*
         * 「経営指標等」用のサマリー値より、
         * 財務諸表本体のPL項目を優先する。
         */
        if (
            strpos($element, 'keyfinancialdata') !== false ||
            strpos($element, 'summaryofbusinessresults') !== false
        ) {
            $score -= 30;
        }
    }
    
    $local = elementLocalName($element);

    if (
        $kind === 'sga' &&
        in_array(
            $local,
            [
                'sellinggeneralandadministrativeexpenses',
                'sellinggeneralandadministrativeexpense',
                'sellinggeneralandadministrativeexpensesifrs',
                'sellinggeneralandadministrativeexpenseifrs',
            ],
            true
        )
    ) {
        $score += 50;
    }

    return $score;
}

function printCandidateGroup(string $title, array $facts): void
{
    echo "\n[CANDIDATES: {$title}] count=" . count($facts) . "\n";

    if (count($facts) === 0) {
        echo "  (none)\n";
        return;
    }

    $limit = min(30, count($facts));

    for ($i = 0; $i < $limit; $i++) {
        $fact = $facts[$i];

        echo sprintf(
            "  #%d element=%s | label=%s | context=%s | value=%s | unit=%s\n",
            $i + 1,
            (string)$fact['element'],
            (string)$fact['label'],
            (string)$fact['context'],
            formatNumber((float)$fact['value']),
            (string)$fact['unit']
        );
    }

    if (count($facts) > $limit) {
        echo '  ... +' . (count($facts) - $limit) . " candidates\n";
    }
}

function printSelectedFact(string $title, ?array $fact): void
{
    echo "\n[SELECTED: {$title}]\n";

    if ($fact === null) {
        echo "  (not selected)\n";
        return;
    }

    echo '  Element : ' . (string)$fact['element'] . "\n";
    echo '  Label   : ' . (string)$fact['label'] . "\n";
    echo '  Context : ' . (string)$fact['context'] . "\n";
    echo '  Value   : ' . formatNumber((float)$fact['value']) . "\n";
    echo '  Unit    : ' . (string)$fact['unit'] . "\n";
    echo '  Score   : ' . (string)$fact['_score'] . "\n";
    echo '  Source  : ' . (string)$fact['source_file'] . "\n";
}
/**
 * Revenue - Cost of Sales の組合せが、
 * PoC段階で安全に粗利として扱えるか確認する。
 *
 * 誤計算を避けるため、ブラックリストではなく
 * 明示的なホワイトリスト方式とする。
 */
function isCompatibleRevenueCostPair(
    array $revenueFact,
    array $costFact
): bool {
    $revenueElement = strtolower(
        (string)$revenueFact['element']
    );

    $costElement = strtolower(
        (string)$costFact['element']
    );

    $revenueLocal = elementLocalName($revenueElement);
    $costLocal = elementLocalName($costElement);

    /*
     * Cost側。
     *
     * PoCでは明示的な売上原価だけを許可する。
     */
    $allowedCost = [
        'costofsalesifrs',
        'costofrevenueifrs',
        'costofsales',
        'costofrevenue',
    ];

    if (!in_array($costLocal, $allowedCost, true)) {
        return false;
    }

    /*
     * Revenue側。
     *
     * 企業独自のOperatingRevenue系や
     * SalesOfProducts、ExternalCustomers等は
     * 費用範囲との一致を保証できないため使わない。
     *
     * 必要なタグは今後の検証で追加する。
     */
    $allowedRevenue = [
        'netsalesifrs',
        'revenueifrs',
        'netsales',
        'revenue',
    ];

    if (!in_array($revenueLocal, $allowedRevenue, true)) {
        return false;
    }

    return true;
}

/**
 * "namespace:ElementName" からElementName部分だけを取得する。
 */
function elementLocalName(string $element): string
{
    $pos = strrpos($element, ':');

    if ($pos === false) {
        return strtolower($element);
    }

    return strtolower(substr($element, $pos + 1));
}
function evaluateGrossMargin(
    ?array $revenueFact,
    ?array $costFact,
    ?array $grossFact
): array {
    if ($revenueFact === null) {
        return [
            'status' => 'NG',
            'reason' => 'revenue_not_found',
            'method' => '',
            'margin' => null,
        ];
    }

    $revenue = (float)$revenueFact['value'];

    if ($revenue <= 0.0) {
        return [
            'status' => 'NG',
            'reason' => 'invalid_revenue',
            'method' => '',
            'margin' => null,
        ];
    }

    /*
     * 最優先:
     * Gross Profit直接取得。
     */
    if ($grossFact !== null) {

        $gross = (float)$grossFact['value'];

        /*
         * Revenue / Cost / Gross の3つが揃っている場合は、
         *
         * Revenue ≒ Cost + Gross
         *
         * になっていることまで確認する。
         *
         * これにより、別範囲のRevenueを誤選択したケースを
         * 全銘柄処理でも検出できる。
         */
        if ($costFact !== null) {

            $cost = (float)$costFact['value'];

            $reconstructed =
                $cost + $gross;

            $diffRate =
                abs($revenue - $reconstructed)
                / abs($revenue);

            if (
                $diffRate >
                RECONCILIATION_TOLERANCE
            ) {
                return [
                    'status' => 'NG',
                    'reason' => 'reconciliation_error',
                    'method' => '',
                    'margin' => null,
                ];
            }
        }

        return [
            'status' => 'OK',
            'reason' => '',
            'method' => 'gross_direct',
            'margin' =>
                ($gross / $revenue) * 100.0,
        ];
    }

    /*
     * Gross Profitがない場合だけ、
     * Revenue - Costを検討。
     */
    if ($costFact === null) {
        return [
            'status' => 'NG',
            'reason' => 'gross_cost_not_found',
            'method' => '',
            'margin' => null,
        ];
    }

    if (
        !isCompatibleRevenueCostPair(
            $revenueFact,
            $costFact
        )
    ) {
        return [
            'status' => 'NG',
            'reason' => 'revenue_cost_incompatible',
            'method' => '',
            'margin' => null,
        ];
    }

    $cost = (float)$costFact['value'];
    $gross = $revenue - $cost;

    return [
        'status' => 'OK',
        'reason' => '',
        'method' => 'revenue_cost',
        'margin' =>
            ($gross / $revenue) * 100.0,
    ];
}
/**
 * financial_actuals レコード全体の取得状態を判定する。
 *
 * OK:
 *   粗利関連の取得・検証まで正常完了。
 *
 * PARTIAL:
 *   粗利関連はNGだが、その他の財務実績値を1項目以上取得できた。
 *
 * NG:
 *   有効な財務実績値をほぼ取得できなかった。
 */
function evaluateFinancialActualStatus(
    array $grossResult,
    ?array $sgaFact
): array {

    /*
     * EDINET側は粗利関連を主目的とし、販管費を補完項目として扱う。
     *
     * OK:
     *   粗利関連の取得・検証まで正常完了。
     *
     * PARTIAL:
     *   粗利関連はNGだが、販管費は取得できた。
     *
     * NG:
     *   粗利関連・販管費のいずれも取得できなかった。
     */
    if (($grossResult['status'] ?? '') === 'OK') {
        return [
            'status' => 'OK',
            'reason' => null,
        ];
    }

    if ($sgaFact !== null) {
        return [
            'status' => 'PARTIAL',
            'reason' => (string)($grossResult['reason'] ?? 'gross_unavailable'),
        ];
    }

    return [
        'status' => 'NG',
        'reason' => (string)($grossResult['reason'] ?? 'financial_data_not_found'),
    ];
}

function printGrossMarginResult(
    ?array $revenueFact,
    ?array $costFact,
    ?array $grossFact
): void {
    echo "\n[RESULT]\n";

    if ($revenueFact === null) {
        echo "[NG] Revenue / Salesを確定できませんでした。\n";
        return;
    }

    $revenue = (float)$revenueFact['value'];

    if ($revenue <= 0.0) {
        echo "[NG] Revenue / Sales <= 0 のため粗利率を算出できません。\n";
        return;
    }

    $gross = null;
    $cost = null;
    $method = '';

    if ($grossFact !== null) {
        $gross = (float)$grossFact['value'];
        $method = 'Gross Profit直接取得';

        if ($costFact !== null) {
            $cost = (float)$costFact['value'];
        }
    } elseif ($costFact !== null) {

        if (
            !isCompatibleRevenueCostPair(
                $revenueFact,
                $costFact
            )
        ) {
            echo "[NG] RevenueとCost of Salesの範囲一致を確認できないため、粗利率を算出しません。\n";
            echo 'Revenue Element : ' .
                (string)$revenueFact['element'] .
                "\n";
            echo 'Cost Element    : ' .
                (string)$costFact['element'] .
                "\n";
            return;
        }

        $cost = (float)$costFact['value'];
        $gross = $revenue - $cost;
        $method = 'Revenue - Cost of Sales';
    } else {
        echo "[NG] Gross ProfitもCost of Salesも確定できませんでした。\n";
        return;
    }

    $margin = ($gross / $revenue) * 100.0;

    echo '売上高     : ' . formatNumber($revenue) . "\n";
    echo '売上原価   : ' .
        ($cost === null ? '(not selected)' : formatNumber($cost)) .
        "\n";
    echo '売上総利益 : ' . formatNumber($gross) . "\n";
    echo '粗利率     : ' . number_format($margin, 2, '.', ',') . " %\n";
    echo '算出方法   : ' . $method . "\n";
    echo "[OK]\n";
}

function formatNumber(float $value): string
{
    if (!is_finite($value)) {
        return '';
    }

    if (abs($value) >= 1.0) {
        return number_format($value, 0, '.', ',');
    }

    return number_format($value, 6, '.', ',');
}


/**
 * EDINET有報から取得した財務実績factを financial_actuals へ保存する本番レコード。
 * 派生指標は保存せず、EDINETから取得した事実値と採用Elementを保存する。
 *
 * EDINET側の役割は以下へ限定する:
 *   - 売上高
 *   - 売上原価
 *   - 売上総利益
 *   - 販管費
 *
 * J-Quantsで取得できる標準財務項目はEDINETでは無理に重複取得しない。
 */
function buildEdinetFinancialActualRecord(
    string $code,
    array $doc,
    string $scope,
    ?array $revenueFact,
    ?array $costFact,
    ?array $grossFact,
    ?array $sgaFact,
    array $result,
    array $recordStatus
): array {
    $revenue = $revenueFact === null ? null : (float)$revenueFact['value'];
    $cost = $costFact === null ? null : (float)$costFact['value'];
    $gross = $grossFact === null ? null : (float)$grossFact['value'];
    $sga = $sgaFact === null ? null : (float)$sgaFact['value'];

    if (
        $gross === null &&
        $revenue !== null &&
        $cost !== null &&
        $result['status'] === 'OK'
    ) {
        $gross = $revenue - $cost;
    }

    $docId = trim((string)($doc['docID'] ?? ''));
    $submit = trim((string)($doc['submitDateTime'] ?? ''));
    $fyStart = normalizeDateText((string)($doc['periodStart'] ?? ''));
    $fyEnd = normalizeDateText((string)($doc['periodEnd'] ?? ''));
    $edinetCode = trim((string)($doc['edinetCode'] ?? ''));
    $title = trim((string)($doc['docDescription'] ?? ''));

    return [
        'security_code' => $code,
        'edinet_code' => $edinetCode !== '' ? $edinetCode : null,
        'company_name' => (string)($doc['filerName'] ?? ''),
        'fiscal_period_start' => $fyStart !== '' ? $fyStart : null,
        'fiscal_period_end' => $fyEnd !== '' ? $fyEnd : null,
        'period_type' => 'FY',
        'period_basis' => 'cumulative',
        'scope' => $scope,
        'accounting_standard' => detectEdinetAccountingStandard(
            $revenueFact,
            $costFact,
            $grossFact,
            $sgaFact
        ),
        'source' => 'EDINET',
        'source_record_key' => $docId,
        'source_document_id' => $docId,
        'source_url' => $docId !== ''
            ? EDINET_API_BASE . '/documents/' . rawurlencode($docId)
            : null,
        'disclosure_title' => $title !== '' ? $title : null,
        'disclosed_at' => $submit !== '' ? $submit : null,
        'is_correction' => 0,

        'revenue' => $revenue,
        'cost_of_sales' => $cost,
        'gross_profit' => $gross,
        'sga' => $sga,

        // J-Quants等で広く安定取得できる項目はEDINETで無理に重複取得しない
        'operating_profit' => null,
        'ordinary_profit' => null,
        'pretax_profit' => null,
        'net_income' => null,
        'total_assets' => null,
        'total_liabilities' => null,
        'equity' => null,
        'cash_and_equivalents' => null,

        'inventory' => null,

        // 現時点では対象外
        'interest_bearing_debt' => null,

        'cfo' => null,
        'cfi' => null,
        'cff' => null,

        'revenue_element' => factElementName($revenueFact),
        'cost_of_sales_element' => factElementName($costFact),
        'gross_profit_element' => factElementName($grossFact),
        'sga_element' => factElementName($sgaFact),

        'operating_profit_element' => null,
        'ordinary_profit_element' => null,
        'pretax_profit_element' => null,
        'net_income_element' => null,
        'total_assets_element' => null,
        'total_liabilities_element' => null,
        'equity_element' => null,
        'cash_and_equivalents_element' => null,
        'inventory_element' => null,
        'interest_bearing_debt_element' => null,
        'cfo_element' => null,
        'cfi_element' => null,
        'cff_element' => null,

        'extraction_method' => (string)($result['method'] ?? ''),
        'reconciliation_ok' => $result['status'] === 'OK' ? 1 : null,
        'status' => (string)$recordStatus['status'],
        'reason' => ($recordStatus['reason'] ?? null) !== null
            ? (string)$recordStatus['reason']
            : null,
    ];
}

function detectEdinetAccountingStandard(?array ...$facts): string
{
    foreach ($facts as $fact) {
        if ($fact === null) continue;
        $element = strtolower((string)($fact['element'] ?? ''));
        if (strpos($element, 'ifrs') !== false || strpos($element, 'jpigp_cor:') !== false) {
            return 'IFRS';
        }
    }
    return 'JGAAP';
}


function financialActualSourceRecordExistsWithReconnect(
    PDO &$pdo,
    string $source,
    string $sourceRecordKey
): bool {
    try {
        jqEnsurePdoAlive($pdo);

        return financialActualSourceRecordExists(
            $pdo,
            $source,
            $sourceRecordKey
        );

    } catch (Throwable $e) {
        if (!jqIsReconnectableDbError($e)) {
            throw $e;
        }

        fwrite(
            STDERR,
            '[DB] reconnect and retry exists check: ' .
            $e->getMessage() .
            "\n"
        );

        jqReconnectPdo($pdo);
        jqEnsurePdoAlive($pdo);

        return financialActualSourceRecordExists(
            $pdo,
            $source,
            $sourceRecordKey
        );
    }
}

function financialActualSourceRecordExists(
    PDO $pdo,
    string $source,
    string $sourceRecordKey
): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM financial_actuals
         WHERE source = :source
           AND source_record_key = :source_record_key
         LIMIT 1"
    );

    $stmt->execute([
        ':source' => $source,
        ':source_record_key' => $sourceRecordKey,
    ]);

    return $stmt->fetchColumn() !== false;
}

function upsertFinancialActualWithReconnect(PDO &$pdo, array $r): int
{
    try {
        jqEnsurePdoAlive($pdo);
        return upsertFinancialActual($pdo, $r);
    } catch (Throwable $e) {
        if (!jqIsReconnectableDbError($e)) throw $e;
        fwrite(STDERR, '[DB] reconnect and retry upsert: ' . $e->getMessage() . "\n");
        jqReconnectPdo($pdo);
        jqEnsurePdoAlive($pdo);
        return upsertFinancialActual($pdo, $r);
    }
}

function upsertFinancialActual(PDO $pdo, array $r): int
{
    $columns = [
        'security_code', 'edinet_code', 'company_name',
        'fiscal_period_start', 'fiscal_period_end', 'period_type',
        'period_basis', 'scope', 'accounting_standard',
        'source', 'source_record_key', 'source_document_id',
        'source_url', 'disclosure_title', 'disclosed_at', 'is_correction',
        'revenue', 'cost_of_sales', 'gross_profit', 'sga',
        'operating_profit', 'ordinary_profit', 'pretax_profit', 'net_income',
        'total_assets', 'total_liabilities', 'equity',
        'cash_and_equivalents', 'inventory', 'interest_bearing_debt',
        'cfo', 'cfi', 'cff',
        'revenue_element', 'cost_of_sales_element', 'gross_profit_element',
        'sga_element', 'operating_profit_element', 'ordinary_profit_element',
        'pretax_profit_element', 'net_income_element',
        'total_assets_element', 'total_liabilities_element', 'equity_element',
        'cash_and_equivalents_element', 'inventory_element',
        'interest_bearing_debt_element', 'cfo_element', 'cfi_element', 'cff_element',
        'extraction_method', 'reconciliation_ok', 'status', 'reason',
    ];

    $insertCols = implode(', ', array_map(static function (string $c): string {
        return '`' . $c . '`';
    }, $columns));
    $placeholders = implode(', ', array_map(static function (string $c): string {
        return ':' . $c;
    }, $columns));

    $skipUpdate = [
        'security_code', 'source', 'source_record_key',
        'fiscal_period_end', 'period_type', 'period_basis', 'scope',
    ];
    $updates = [];
    foreach ($columns as $c) {
        if (in_array($c, $skipUpdate, true)) continue;
        $updates[] = '`' . $c . '` = VALUES(`' . $c . '`)';
    }

    $sql = "INSERT INTO financial_actuals ({$insertCols}) VALUES ({$placeholders})\n" .
        "ON DUPLICATE KEY UPDATE\n        " . implode(",\n        ", $updates) .
        ",\n        updated_at = CURRENT_TIMESTAMP";

    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($columns as $c) $params[':' . $c] = $r[$c] ?? null;
    $stmt->execute($params);
    return $stmt->rowCount();
}

function httpGet(string $url): string
{
    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('curl_initに失敗しました。');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => EDINET_HTTP_TIMEOUT_SEC,
        CURLOPT_USERAGENT => 'invest-edinet-gross-profit-poc/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
        ],
    ]);

    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);

        throw new RuntimeException(
            'HTTP取得に失敗しました: ' . $error
        );
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        $snippet = substr((string)$body, 0, 500);

        throw new RuntimeException(
            "EDINET API HTTP {$status}: {$snippet}"
        );
    }

    return (string)$body;
}

function removeDirectoryRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            removeDirectoryRecursive($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}
function factElementName(?array $fact): string
{
    if ($fact === null) {
        return '-';
    }

    return (string)($fact['element'] ?? '-');
}
function printAllCompanySummary(
    array $summary
): void {
    echo "\n";
    echo str_repeat('=', 72) . "\n";
    echo "[SUMMARY]\n\n";

    $processed =
        (int)$summary['processed'];

    $ok =
        (int)$summary['ok'];

    $rate =
        $processed > 0
            ? ($ok / $processed) * 100.0
            : 0.0;

    echo '有報発見銘柄数           : ' .
        number_format(
            (int)$summary['documents']
        ) .
        "\n";

    echo '処理完了                 : ' .
        number_format($processed) .
        "\n";

    echo '登録済みSKIP             : ' .
        number_format((int)$summary['skipped_existing']) .
        "\n";

    echo '粗利取得成功             : ' .
        number_format($ok) .
        "\n";

    echo '  Gross Profit直接取得    : ' .
        number_format(
            (int)$summary['gross_direct']
        ) .
        "\n";

    echo '  Revenue - Cost算出      : ' .
        number_format(
            (int)$summary['revenue_cost']
        ) .
        "\n";

    $sgaSelected = (int)$summary['sga_selected'];
    $sgaRate =
        $processed > 0
            ? ($sgaSelected / $processed) * 100.0
            : 0.0;

    echo "\n[販管費取得]\n";
    echo '販管費取得               : ' .
        number_format($sgaSelected) .
        "\n";
    echo '販管費取得率             : ' .
        number_format($sgaRate, 2) .
        " %\n";


    echo "\n[粗利取得スコープ]\n";

    echo '連結                     : ' .
        number_format(
            (int)$summary['scope_consolidated_ok']
        ) .
        "\n";

    echo '単体フォールバック       : ' .
        number_format(
            (int)$summary['scope_nonconsolidated_ok']
        ) .
        "\n";

    echo '粗利取得率               : ' .
        number_format($rate, 2) .
        " %\n";

    echo "\n[鮮度判定]\n";

    echo '最新FY一致 FRESH         : ' .
        number_format(
            (int)$summary['freshness_fresh']
        ) .
        "\n";

    echo 'EDINET 1期以上遅れ STALE : ' .
        number_format(
            (int)$summary['freshness_stale']
        ) .
        "\n";

    echo 'EDINETの方が新しい       : ' .
        number_format(
            (int)$summary['freshness_edinet_ahead']
        ) .
        "\n";

    echo '判定不能 UNKNOWN         : ' .
        number_format(
            (int)$summary['freshness_unknown']
        ) .
        "\n";

    echo "\n[NG内訳]\n";

    echo 'Revenue確定不可          : ' .
        number_format(
            (int)$summary['ng_revenue']
        ) .
        "\n";

    echo 'Gross Profit / Costなし  : ' .
        number_format(
            (int)$summary['ng_gross_cost']
        ) .
        "\n";

    echo 'Revenue-Cost範囲不一致   : ' .
        number_format(
            (int)$summary['ng_incompatible']
        ) .
        "\n";

    echo 'Revenue/Cost/Gross不整合 : ' .
        number_format(
            (int)$summary['ng_reconciliation']
        ) .
        "\n";

    echo 'その他NG                 : ' .
        number_format(
            (int)$summary['ng_other']
        ) .
        "\n";

    echo '処理エラー               : ' .
        number_format(
            (int)$summary['error']
        ) .
        "\n";
    echo "\n[レコード状態]\n";

    echo 'OK                       : ' .
        number_format((int)$summary['record_ok']) .
        "\n";

    echo 'PARTIAL                  : ' .
        number_format((int)$summary['record_partial']) .
        "\n";

    echo 'NG                       : ' .
        number_format((int)$summary['record_ng']) .
        "\n";

    echo "\n[DB保存]\n";
    echo 'DB保存                   : ' . number_format((int)$summary['db_saved']) . "\n";
    echo '  INSERT                 : ' . number_format((int)$summary['db_insert']) . "\n";
    echo '  UPDATE                 : ' . number_format((int)$summary['db_update']) . "\n";
    echo '  NO_CHANGE              : ' . number_format((int)$summary['db_no_change']) . "\n";

    echo str_repeat('=', 72) . "\n";
}