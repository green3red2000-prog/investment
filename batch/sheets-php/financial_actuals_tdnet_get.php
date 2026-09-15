<?php
declare(strict_types=1);

/**
 * financial_actuals_tdnet_get.php
 *
 * TDnet 決算短信 XBRL -> stocks.financial_actuals 本番登録版
 * PHP 7.4+
 *
 * 方針:
 *   - 指定期間の「通期決算短信」を全銘柄走査する
 *   - 訂正・再訂正も別文書として保存する
 *   - 四半期・中間決算短信は現段階では対象外
 *   - TDNET文書ごとに source_record_key を保持し、再実行はUPSERT
 *   - 既登録文書は通常スキップし、--force 指定時のみ再解析する
 *   - J-GAAP / IFRS、連結 / 非連結を同じ financial_actuals に保存する
 *   - financial_actualsには取得元の事実値だけを保存し、派生指標はCSV生成側で計算する
 *   - 将来の四半期対応を見据え、period_type / period_basis は既存スキーマを使用する
 *
 * 実行例:
 *   # 直近31日分を毎回走査（引数なしのCron向け既定動作）
 *   # 登録済みsource_record_keyはSKIPするため、停止期間の自動復旧を兼ねる
 *   php /opt/invest/sheets-php/financial_actuals_tdnet_get.php
 *
 *   # 期間指定
 *   php /opt/invest/sheets-php/financial_actuals_tdnet_get.php \
 *     --from=2026-08-10 --to=2026-09-08
 *
 *   # DBへ書かず取得確認
 *   php /opt/invest/sheets-php/financial_actuals_tdnet_get.php \
 *     --from=2026-09-08 --to=2026-09-08 --dry-run
 *
 *   # 既登録文書も再解析・UPSERT
 *   php /opt/invest/sheets-php/financial_actuals_tdnet_get.php \
 *     --from=2026-08-10 --to=2026-09-08 --force
 */

require '/opt/invest/j_quants/conf/config.php';
require '/opt/invest/j_quants/lib/j_quants_common.php';

date_default_timezone_set('Asia/Tokyo');

const TDNET_BASE = 'https://www.release.tdnet.info/inbs';
const TDNET_HTTP_TIMEOUT_SEC = 60;
const TDNET_REQUEST_INTERVAL_USEC = 500000; // 0.5秒
const TDNET_MAX_LIST_PAGES = 20;
const RECONCILIATION_TOLERANCE = 0.005; // 0.5%
const PROGRESS_EVERY = 50;
const DETAIL_CANDIDATES = false;
const DEFAULT_LOOKBACK_DAYS = 31; // 引数なしCron時: 今日を含む直近31日を毎回再走査

main();

function main(): void
{
    $args = parseCommandLineArgs($_SERVER['argv'] ?? []);
    $today = new DateTimeImmutable('today');
    $defaultTo = $today->format('Y-m-d');
    $defaultFrom = $today->modify('-' . (DEFAULT_LOOKBACK_DAYS - 1) . ' days')->format('Y-m-d');

    // 引数なしCronでは直近DEFAULT_LOOKBACK_DAYS日を毎回走査する。
    // source_record_keyで登録済み文書はSKIPされるため、サーバー停止や旅行中の未実行分も次回に自動回収できる。
    // --fromだけ指定した場合は従来どおりその1日、--toだけ指定した場合は既定開始日～指定日。
    $hasFrom = array_key_exists('from', $args);
    $hasTo = array_key_exists('to', $args);

    if (!$hasFrom && !$hasTo) {
        $fromRaw = $defaultFrom;
        $toRaw = $defaultTo;
    } elseif ($hasFrom && !$hasTo) {
        $fromRaw = (string)$args['from'];
        $toRaw = $fromRaw;
    } elseif (!$hasFrom && $hasTo) {
        $fromRaw = $defaultFrom;
        $toRaw = (string)$args['to'];
    } else {
        $fromRaw = (string)$args['from'];
        $toRaw = (string)$args['to'];
    }

    $from = normalizeCliDate($fromRaw, '--from');
    $to = normalizeCliDate($toRaw, '--to');
    $dryRun = isset($args['dry-run']);
    $force = isset($args['force']);

    if ($from > $to) {
        throw new RuntimeException('--from は --to 以下の日付を指定してください。');
    }

    echo "TDnet financial_actuals 全銘柄DB登録\n";
    echo "対象期間: {$from} ～ {$to}\n";
    echo "DRY_RUN=" . ($dryRun ? 'true' : 'false') . "\n";
    echo "FORCE=" . ($force ? 'true' : 'false') . "\n";
    echo str_repeat('=', 100) . "\n";

    $pdo = null;
    if (!$dryRun) {
        $pdo = jqBuildPdo();
        jqEnsurePdoAlive($pdo);
        echo "[DB] connection OK\n";
    }

    $disclosures = scanTdnetDisclosureRangeAll($from, $to);
    echo "\n[FOUND] 通期決算短信XBRL文書数: " . number_format(count($disclosures)) . "\n";
    echo str_repeat('=', 100) . "\n";

    $summary = [
        'documents' => count($disclosures),
        'processed' => 0,
        'skipped_existing' => 0,
        'xbrl_found' => 0,
        'record_ready' => 0,
        'ok' => 0,
        'partial' => 0,
        'db_saved' => 0,
        'insert' => 0,
        'update' => 0,
        'no_change' => 0,
        'correction' => 0,
        'error' => 0,
        'ng_no_files' => 0,
        'ng_no_facts' => 0,
        'ng_fy_end' => 0,
    ];

    foreach ($disclosures as $idx => $disclosure) {
        $summary['processed']++;
        $tmpDir = null;

        $code = (string)$disclosure['code'];
        $companyName = (string)$disclosure['company_name'];
        $sourceKey = tdnetSourceRecordKey((string)$disclosure['xbrl_url']);

        if (!empty($disclosure['is_correction'])) {
            $summary['correction']++;
        }

        echo "\n" . str_repeat('-', 100) . "\n";
        echo sprintf(
            "[TARGET] %d/%d code=%s name=%s date=%s time=%s correction=%s\n",
            $idx + 1,
            count($disclosures),
            $code,
            $companyName,
            (string)$disclosure['date'],
            (string)$disclosure['time'],
            !empty($disclosure['is_correction']) ? '1' : '0'
        );
        echo "[TITLE] " . (string)$disclosure['title'] . "\n";
        echo "[XBRL] " . (string)$disclosure['xbrl_url'] . "\n";

        try {
            if (
                !$dryRun &&
                !$force &&
                $pdo instanceof PDO &&
                financialActualSourceRecordExistsWithReconnect($pdo, 'TDNET', $sourceKey)
            ) {
                $summary['skipped_existing']++;
                echo "[SKIP] source_record_key={$sourceKey} は登録済みです。\n";
                continue;
            }

            $tmpDir = createTempDir($code);
            $xbrlFiles = downloadAndExtractTdnetXbrl(
                (string)$disclosure['xbrl_url'],
                $tmpDir
            );
            usleep(TDNET_REQUEST_INTERVAL_USEC);

            if (count($xbrlFiles) === 0) {
                $summary['ng_no_files']++;
                echo "[NG] ZIP内に解析対象XBRL/XML/Inline XBRLがありません。\n";
                continue;
            }
            $summary['xbrl_found']++;

            $facts = [];
            $contexts = [];
            foreach ($xbrlFiles as $file) {
                loadXbrlFactsFromFile($file, $facts, $contexts);
            }

            if (count($facts) === 0) {
                $summary['ng_no_facts']++;
                echo "[NG] 数値factを取得できませんでした。\n";
                continue;
            }

            $expectedFyEnd = detectExpectedFyEnd($contexts);
            if ($expectedFyEnd === '') {
                $summary['ng_fy_end']++;
                echo "[NG] 当期FY末を特定できませんでした。\n";
                continue;
            }

            $candidates = classifyFinancialCandidates($facts);

            $scope = detectFactScopeTdnet(
                $candidates['revenue'],
                $contexts,
                $expectedFyEnd
            );

            $selected = selectFinancialFacts(
                $candidates,
                $contexts,
                $expectedFyEnd,
                $scope
            );

            $record = buildFinancialActualRecord(
                $code,
                $companyName,
                $disclosure,
                $expectedFyEnd,
                $scope,
                $selected,
                $contexts
            );

            validateFinancialRecord($record);
            printFinancialRecord($record);

            if (DETAIL_CANDIDATES) {
                printMissingFactHints(
                    $facts,
                    $selected,
                    $contexts,
                    $expectedFyEnd,
                    $scope
                );
                printFinancialCandidateSummary($candidates);
            }

            $summary['record_ready']++;
            if ((string)$record['status'] === 'OK') {
                $summary['ok']++;
            } else {
                $summary['partial']++;
            }

            if ($dryRun) {
                echo "[DRY RUN] DB登録は実施していません。\n";
            } else {
                if (!($pdo instanceof PDO)) {
                    throw new RuntimeException('PDOが初期化されていません。');
                }

                $affected = upsertFinancialActualWithReconnect($pdo, $record);
                $summary['db_saved']++;

                if ($affected === 1) {
                    $summary['insert']++;
                    $action = 'INSERT';
                } elseif ($affected === 2) {
                    $summary['update']++;
                    $action = 'UPDATE';
                } else {
                    $summary['no_change']++;
                    $action = 'NO_CHANGE';
                }

                echo "[DB OK] financial_actuals {$action} affected_rows={$affected}\n";
            }
        } catch (Throwable $e) {
            $summary['error']++;
            echo '[ERROR] ' . $e->getMessage() . "\n";
        } finally {
            if ($tmpDir !== null) {
                removeDirectoryRecursive($tmpDir);
            }
        }

        if ($summary['processed'] % PROGRESS_EVERY === 0) {
            echo sprintf(
                "[PROGRESS] %d/%d saved=%d skip=%d partial=%d error=%d\n",
                $summary['processed'],
                $summary['documents'],
                $summary['db_saved'],
                $summary['skipped_existing'],
                $summary['partial'],
                $summary['error']
            );
        }
    }

    printProductionSummary($summary);
}

function scanTdnetDisclosureRangeAll(string $startDate, string $endDate): array
{
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);

    $bySourceKey = [];

    foreach (new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day')) as $date) {
        $dateText = $date->format('Y-m-d');
        $ymd = $date->format('Ymd');
        echo "[SCAN] {$dateText}\n";

        for ($page = 1; $page <= TDNET_MAX_LIST_PAGES; $page++) {
            $url = sprintf('%s/I_list_%03d_%s.html', TDNET_BASE, $page, $ymd);
            $response = httpGetWithStatus($url);

            if ($response['status'] === 404) {
                break;
            }
            if ($response['status'] !== 200) {
                echo sprintf(
                    "[LIST WARN] date=%s page=%d HTTP=%d\n",
                    $dateText,
                    $page,
                    $response['status']
                );
                break;
            }

            $found = parseTdnetListHtmlAllProduction(
                (string)$response['body'],
                $url,
                $dateText
            );

            foreach ($found as $row) {
                $sourceKey = tdnetSourceRecordKey((string)$row['xbrl_url']);
                $bySourceKey[$sourceKey] = $row;
            }

            usleep(TDNET_REQUEST_INTERVAL_USEC);
        }
    }

    $rows = array_values($bySourceKey);

    usort($rows, static function (array $a, array $b): int {
        $ka = (string)$a['date'] . ' ' . (string)$a['time'] . ' ' . (string)$a['code'];
        $kb = (string)$b['date'] . ' ' . (string)$b['time'] . ' ' . (string)$b['code'];
        return strcmp($ka, $kb);
    });

    return $rows;
}

function parseTdnetListHtmlAllProduction(
    string $html,
    string $pageUrl,
    string $dateText
): array {
    libxml_use_internal_errors(true);

    $encoding = mb_detect_encoding($html, ['UTF-8', 'SJIS-win', 'EUC-JP'], true);
    if ($encoding !== false && strtoupper($encoding) !== 'UTF-8') {
        $html = mb_convert_encoding($html, 'UTF-8', $encoding);
    }

    $dom = new DOMDocument();
    if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR)) {
        libxml_clear_errors();
        return [];
    }

    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//table[@id="main-list-table"]//tr');
    if ($rows === false || $rows->length === 0) {
        $rows = $xpath->query('//tr');
    }
    if ($rows === false) {
        libxml_clear_errors();
        return [];
    }

    $out = [];

    foreach ($rows as $row) {
        if (!($row instanceof DOMElement)) {
            continue;
        }

        $rowText = normalizeSpace($row->textContent);
        if ($rowText === '') {
            continue;
        }

        if (mb_strpos($rowText, '決算短信') === false) {
            continue;
        }

        // 現段階では通期のみ。訂正は保存対象だが、四半期・中間は将来対応。
        if (
            mb_strpos($rowText, '四半期') !== false ||
            mb_strpos($rowText, '中間') !== false
        ) {
            continue;
        }

        if (!preg_match('/(?:^|\s)([0-9A-Z]{4})0(?:\s|$)/u', $rowText, $m)) {
            continue;
        }
        $code = strtoupper((string)$m[1]);

        $xbrlUrl = '';
        $anchors = $xpath->query('.//a', $row);
        if ($anchors !== false) {
            foreach ($anchors as $anchor) {
                if (!($anchor instanceof DOMElement)) {
                    continue;
                }

                $href = trim($anchor->getAttribute('href'));
                $anchorText = normalizeSpace($anchor->textContent);
                if ($href === '') {
                    continue;
                }

                $candidateUrl = resolveUrl($pageUrl, $href);
                $candidateLower = strtolower($candidateUrl);

                if (
                    substr($candidateLower, -4) === '.zip' &&
                    (
                        mb_strpos($anchorText, 'XBRL') !== false ||
                        strpos($candidateLower, 'xbrl') !== false ||
                        substr($candidateLower, -4) === '.zip'
                    )
                ) {
                    $xbrlUrl = $candidateUrl;
                    break;
                }
            }
        }

        if ($xbrlUrl === '') {
            continue;
        }

        $time = '';
        if (preg_match('/\b([0-2]?\d:[0-5]\d)\b/u', $rowText, $tm)) {
            $time = (string)$tm[1];
        }

        $companyName = '';
        if (preg_match('/(?:^|\s)[0-9A-Z]{5}\s+(.+?)\s+\d{4}年/u', $rowText, $nm)) {
            $companyName = normalizeSpace((string)$nm[1]);
        }

        // タイトル形式差で会社名が取れない場合のフォールバック。
        if ($companyName === '') {
            $companyName = extractCompanyNameFromTdnetRow($rowText, $code);
        }

        $isCorrection = (
            mb_strpos($rowText, '訂正') !== false ||
            mb_strpos($rowText, '再訂正') !== false
        );

        $out[] = [
            'code' => $code,
            'company_name' => $companyName,
            'date' => $dateText,
            'time' => $time,
            'title' => $rowText,
            'xbrl_url' => $xbrlUrl,
            'is_correction' => $isCorrection,
        ];
    }

    libxml_clear_errors();
    return $out;
}

function extractCompanyNameFromTdnetRow(string $rowText, string $code): string
{
    $needle = $code . '0';
    $pos = mb_strpos($rowText, $needle);
    if ($pos === false) {
        return '';
    }

    $tail = trim(mb_substr($rowText, $pos + mb_strlen($needle)));
    if ($tail === '') {
        return '';
    }

    if (preg_match('/^(.+?)\s+\d{4}年/u', $tail, $m)) {
        return normalizeSpace((string)$m[1]);
    }

    return '';
}

/**
 * Context群から当期通期の決算期末を推定する。
 *
 * 訂正短信では過年度FYが返ることがあるが、それは正しい。
 * 「開示日」ではなくXBRL自身のCurrentYearDurationを採用する。
 */
function detectExpectedFyEnd(array $contexts): string
{
    $ends = [];

    foreach ($contexts as $contextId => $context) {
        if (!is_array($context)) {
            continue;
        }

        $id = strtolower((string)$contextId);

        if (strpos($id, 'current') === false || strpos($id, 'duration') === false) {
            continue;
        }
        if (
            strpos($id, 'prior') !== false ||
            strpos($id, 'previous') !== false ||
            strpos($id, 'next') !== false ||
            strpos($id, 'forecast') !== false
        ) {
            continue;
        }

        $start = (string)($context['start'] ?? '');
        $end = (string)($context['end'] ?? '');

        if ($start === '' || $end === '') {
            continue;
        }

        $ends[$end] = true;
    }

    if (count($ends) === 0) {
        return '';
    }

    $dates = array_keys($ends);
    rsort($dates, SORT_STRING);

    return (string)$dates[0];
}

function tdnetSourceRecordKey(string $xbrlUrl): string
{
    $path = (string)(parse_url($xbrlUrl, PHP_URL_PATH) ?? '');
    $file = basename($path);
    return $file !== '' ? $file : sha1($xbrlUrl);
}

function financialActualSourceRecordExistsWithReconnect(
    PDO &$pdo,
    string $source,
    string $sourceRecordKey
): bool {
    try {
        jqEnsurePdoAlive($pdo);
        return financialActualSourceRecordExists($pdo, $source, $sourceRecordKey);
    } catch (Throwable $e) {
        if (!jqIsReconnectableDbError($e)) {
            throw $e;
        }

        fwrite(STDERR, '[DB] reconnect and retry exists check: ' . $e->getMessage() . "\n");
        jqReconnectPdo($pdo);
        jqEnsurePdoAlive($pdo);

        return financialActualSourceRecordExists($pdo, $source, $sourceRecordKey);
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

function printProductionSummary(array $s): void
{
    echo "\n" . str_repeat('=', 100) . "\n";
    echo "[SUMMARY]\n";
    echo '発見文書数            : ' . $s['documents'] . "\n";
    echo '処理文書数            : ' . $s['processed'] . "\n";
    echo '登録済みSKIP          : ' . $s['skipped_existing'] . "\n";
    echo 'XBRL取得              : ' . $s['xbrl_found'] . "\n";
    echo 'レコード生成          : ' . $s['record_ready'] . "\n";
    echo '  OK                   : ' . $s['ok'] . "\n";
    echo '  PARTIAL              : ' . $s['partial'] . "\n";
    echo '訂正文書              : ' . $s['correction'] . "\n";
    echo 'DB保存                : ' . $s['db_saved'] . "\n";
    echo '  INSERT               : ' . $s['insert'] . "\n";
    echo '  UPDATE               : ' . $s['update'] . "\n";
    echo '  NO_CHANGE            : ' . $s['no_change'] . "\n";
    echo 'NG XBRLファイルなし   : ' . $s['ng_no_files'] . "\n";
    echo 'NG 数値factなし       : ' . $s['ng_no_facts'] . "\n";
    echo 'NG FY末特定不可       : ' . $s['ng_fy_end'] . "\n";
    echo 'ERROR                 : ' . $s['error'] . "\n";
    echo str_repeat('=', 100) . "\n";
}

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

function normalizeCliDate(string $value, string $name): string
{
    $value = trim($value);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        throw new RuntimeException("{$name} は YYYY-MM-DD 形式で指定してください: {$value}");
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($dt === false || $dt->format('Y-m-d') !== $value) {
        throw new RuntimeException("{$name} の日付が不正です: {$value}");
    }

    return $value;
}

/**
 * 財務項目ごとの候補factを分類する。
 * 原則として標準タクソノミのlocal-nameを厳格に採用する。
 */
function classifyFinancialCandidates(array $facts): array
{
    $aliases = financialElementAliases();
    $out = [];
    foreach ($aliases as $kind => $_) {
        $out[$kind] = [];
    }
    $out['_all_facts'] = $facts;

    foreach ($facts as $fact) {
        $local = strtolower((string)($fact['local_name'] ?? ''));
        if ($local === '') continue;

        foreach ($aliases as $kind => $names) {
            if (in_array($local, $names, true)) {
                $out[$kind][] = $fact;
            }
        }
    }

    return $out;
}

function financialElementAliases(): array
{
    return [
        'revenue' => [
            'netsales', 'revenue', 'revenueifrs', 'operatingrevenue',
            'operatingrevenue1', 'sales',
        ],
        'cost_of_sales' => [
            'costofsales', 'costofsalesifrs', 'costofrevenue', 'costofrevenueifrs',
        ],
        'gross_profit' => [
            'grossprofit', 'grossprofitifrs', 'grossloss', 'grossprofitloss',
        ],
        'sga' => [
            'sellinggeneralandadministrativeexpenses',
            'sellinggeneralandadministrativeexpensesifrs',
            'sellinggeneraladministrativeexpenses',
        ],
        'operating_profit' => [
            'operatingincome', 'operatingprofitloss', 'operatingprofitlossifrs',
            'operatingincomeloss',
        ],
        'ordinary_profit' => [
            'ordinaryincome', 'ordinaryincomeloss',
        ],
        'pretax_profit' => [
            'incomebeforeincometaxes', 'incomebeforeincometaxesifrs',
            'profitlossbeforeincometaxes', 'profitlossbeforetaxifrs',
            'profitbeforetax', 'profitbeforetaxifrs',
        ],
        'net_income' => [
            'profitlossattributabletoownersofparent',
            'profitlossattributabletoownersofparentifrs',
            'netincome', 'netincomeloss',
            'profitloss', 'profitlossifrs',
        ],
        'total_assets' => [
            'assets', 'totalassets', 'assetsifrs',
        ],
        'total_liabilities' => [
            'liabilities', 'totalliabilities', 'liabilitiesifrs',
        ],
        'equity' => [
            'netassets', 'equity', 'equityifrs',
            'equityattributabletoownersofparent',
            'equityattributabletoownersofparentifrs',
            'shareholdersequity',
        ],
        'cash_and_equivalents' => [
            'cashandcashequivalents', 'cashandcashequivalentsifrs',
            'cashanddeposits',
        ],
        'inventory' => [
            'inventories', 'inventoriesifrs', 'inventoriescaifrs', 'inventory',
        ],
        'interest_bearing_debt' => [
            'interestbearingdebt', 'interestbearingliabilities',
            'borrowings', 'borrowingsifrs',
            'bondsandborrowingsifrs',
        ],
        'cfo' => [
            'netcashprovidedbyusedinoperatingactivities',
            'netcashprovidedbyusedinoperatingactivitiesifrs',
            'cashflowsfromusedinoperatingactivities',
            'cashflowsfromusedinoperatingactivitiesifrs',
        ],
        'cfi' => [
            'netcashprovidedbyusedininvestingactivities',
            'netcashprovidedbyusedininvestmentactivities',
            'netcashprovidedbyusedininvestingactivitiesifrs',
            'netcashprovidedbyusedininvestmentactivitiesifrs',
            'cashflowsfromusedininvestingactivities',
            'cashflowsfromusedininvestingactivitiesifrs',
        ],
        'cff' => [
            'netcashprovidedbyusedinfinancingactivities',
            'netcashprovidedbyusedinfinancingactivitiesifrs',
            'cashflowsfromusedinfinancingactivities',
            'cashflowsfromusedinfinancingactivitiesifrs',
        ],
    ];
}

function selectFinancialFacts(
    array $candidates,
    array $contexts,
    string $fyEnd,
    string $scope
): array {
    $durationKinds = [
        'revenue', 'cost_of_sales', 'gross_profit', 'sga',
        'operating_profit', 'ordinary_profit', 'pretax_profit',
        'net_income', 'cfo', 'cfi', 'cff',
    ];
    $instantKinds = [
        'total_assets', 'total_liabilities', 'equity',
        'cash_and_equivalents', 'inventory', 'interest_bearing_debt',
    ];

    $selected = [];

    foreach ($durationKinds as $kind) {
        $selected[$kind] = selectBestFinancialFact(
            $candidates[$kind] ?? [],
            $kind,
            'duration',
            $contexts,
            $fyEnd,
            $scope
        );
    }

    foreach ($instantKinds as $kind) {
        $selected[$kind] = selectBestFinancialFact(
            $candidates[$kind] ?? [],
            $kind,
            'instant',
            $contexts,
            $fyEnd,
            $scope
        );
    }

    // 直接取得できない場合のみ、安全な構成科目から補完する。
    if (($selected['inventory'] ?? null) === null) {
        $selected['inventory'] = deriveInventoryFact($candidates, $contexts, $fyEnd, $scope);
    }

    if (($selected['interest_bearing_debt'] ?? null) === null) {
        $selected['interest_bearing_debt'] = deriveInterestBearingDebtFact($candidates, $contexts, $fyEnd, $scope);
    }

    return $selected;
}

function deriveInventoryFact(
    array $candidates,
    array $contexts,
    string $fyEnd,
    string $scope
): ?array {
    // まずIFRS/J-GAAPの単一集計タグは classifyFinancialCandidates 側で直接取得済み。
    // ここではJ-GAAPの代表的な棚卸資産構成科目を重複しない形で合算する。
    $componentNames = [
        'merchandiseandfinishedgoods',
        'merchandise',
        'finishedgoods',
        'workinprocess',
        'rawmaterial',
        'rawmaterialsandsupplies',
        'supplies',
    ];

    $facts = [];
    foreach (flattenCandidateFacts($candidates) as $fact) {
        $local = strtolower((string)($fact['local_name'] ?? ''));
        if (!in_array($local, $componentNames, true)) continue;

        $contextId = (string)($fact['context'] ?? '');
        $ctx = $contexts[$contextId] ?? null;
        if (!is_array($ctx)) continue;
        if (!isEligibleInstantContext($ctx, $fyEnd, $scope)) continue;

        $facts[] = $fact;
    }

    if (count($facts) === 0) return null;

    // MerchandiseAndFinishedGoods がある場合、Merchandise / FinishedGoods を同時加算しない。
    $locals = array_map(static function (array $f): string {
        return strtolower((string)($f['local_name'] ?? ''));
    }, $facts);

    $skip = [];
    if (in_array('merchandiseandfinishedgoods', $locals, true)) {
        $skip['merchandise'] = true;
        $skip['finishedgoods'] = true;
    }
    if (in_array('rawmaterialsandsupplies', $locals, true)) {
        $skip['rawmaterial'] = true;
        $skip['supplies'] = true;
    }

    $sum = 0.0;
    $elements = [];
    $used = 0;
    foreach ($facts as $fact) {
        $local = strtolower((string)($fact['local_name'] ?? ''));
        if (isset($skip[$local])) continue;
        $sum += (float)$fact['value'];
        $elements[] = (string)$fact['element'];
        $used++;
    }

    if ($used === 0) return null;

    return [
        'element' => 'DERIVED:' . implode('+', $elements),
        'local_name' => 'derived_inventory',
        'namespace' => '',
        'context' => (string)$facts[0]['context'],
        'value' => $sum,
        'unit' => (string)($facts[0]['unit'] ?? ''),
        'decimals' => '',
        'source_file' => 'derived',
        '_score' => 0,
        '_derived' => true,
    ];
}

function deriveInterestBearingDebtFact(
    array $candidates,
    array $contexts,
    string $fyEnd,
    string $scope
): ?array {
    $allFacts = flattenCandidateFacts($candidates);

    // Strategy 1: IFRS aggregate "BondsAndBorrowings" current + non-current.
    $strategies = [
        [
            'bondsandborrowingsclifrs',
            'bondsandborrowingsnclifrs',
        ],
        // Strategy 2: IFRS borrowings current + non-current.
        [
            'borrowingsclifrs',
            'borrowingsnclifrs',
        ],
        // Strategy 3: J-GAAP debt components. Loans receivable are intentionally excluded.
        [
            'shorttermloanspayable',
            'currentportionoflongtermloanspayable',
            'currentportionofbondspayable',
            'bondspayable',
            'longtermloanspayable',
        ],
    ];

    foreach ($strategies as $names) {
        $sum = 0.0;
        $elements = [];
        $contextId = '';
        $found = 0;

        foreach ($allFacts as $fact) {
            $local = strtolower((string)($fact['local_name'] ?? ''));
            if (!in_array($local, $names, true)) continue;

            $ctxId = (string)($fact['context'] ?? '');
            $ctx = $contexts[$ctxId] ?? null;
            if (!is_array($ctx)) continue;
            if (!isEligibleInstantContext($ctx, $fyEnd, $scope)) continue;

            $sum += (float)$fact['value'];
            $elements[] = (string)$fact['element'];
            if ($contextId === '') $contextId = $ctxId;
            $found++;
        }

        if ($found > 0) {
            return [
                'element' => 'DERIVED:' . implode('+', $elements),
                'local_name' => 'derived_interest_bearing_debt',
                'namespace' => '',
                'context' => $contextId,
                'value' => $sum,
                'unit' => '',
                'decimals' => '',
                'source_file' => 'derived',
                '_score' => 0,
                '_derived' => true,
            ];
        }
    }

    return null;
}

function flattenCandidateFacts(array $candidates): array
{
    // classifyFinancialCandidates は既知タグしか保持しないため、
    // 派生用の構成科目候補を別途拾えるよう、後段で追加する。
    return $candidates['_all_facts'] ?? [];
}

function selectBestFinancialFact(
    array $facts,
    string $kind,
    string $contextType,
    array $contexts,
    string $fyEnd,
    string $scope
): ?array {
    $scored = [];

    foreach ($facts as $fact) {
        $contextId = (string)($fact['context'] ?? '');
        $context = $contexts[$contextId] ?? null;
        if (!is_array($context)) continue;

        if ($contextType === 'duration') {
            if (!isEligibleDurationContext($context, $fyEnd, $scope)) continue;
        } else {
            if (!isEligibleInstantContext($context, $fyEnd, $scope)) continue;
        }

        $score = scoreFinancialFact($fact, $kind, $contextId, $contextType);
        $fact['_score'] = $score;
        $scored[] = $fact;
    }

    if (count($scored) === 0) return null;

    usort($scored, static function (array $a, array $b): int {
        return ((int)$b['_score']) <=> ((int)$a['_score']);
    });

    return $scored[0];
}

function isEligibleDurationContext(array $context, string $fyEnd, string $scope): bool
{
    $start = (string)($context['start'] ?? '');
    $end = (string)($context['end'] ?? '');
    if ($start === '' || $end === '') return false;
    if ($end !== $fyEnd) return false;
    return isEligibleCompanyScope($context, $scope);
}

function isEligibleInstantContext(array $context, string $fyEnd, string $scope): bool
{
    $instant = (string)($context['instant'] ?? '');
    if ($instant === '' || $instant !== $fyEnd) return false;
    return isEligibleCompanyScope($context, $scope);
}

function isEligibleCompanyScope(array $context, string $scope): bool
{
    $members = $context['members'] ?? [];
    if (!is_array($members)) $members = [];

    $hasNonConsolidated = false;
    $hasOtherMember = false;

    foreach ($members as $member) {
        $m = strtolower((string)$member);
        if (
            strpos($m, 'nonconsolidated') !== false ||
            strpos($m, 'non-consolidated') !== false
        ) {
            $hasNonConsolidated = true;
            continue;
        }

        if ($m !== '') {
            $hasOtherMember = true;
        }
    }

    if ($hasOtherMember) return false;
    if ($scope === 'consolidated') return !$hasNonConsolidated;
    if ($scope === 'nonconsolidated') return $hasNonConsolidated;
    return false;
}

function scoreFinancialFact(
    array $fact,
    string $kind,
    string $contextId,
    string $contextType
): int {
    $score = 0;
    $id = strtolower($contextId);
    $local = strtolower((string)($fact['local_name'] ?? ''));

    if (strpos($id, 'current') !== false) $score += 40;
    if ($contextType === 'duration' && strpos($id, 'duration') !== false) $score += 20;
    if ($contextType === 'instant' && strpos($id, 'instant') !== false) $score += 20;
    if (strpos($id, 'prior') !== false || strpos($id, 'previous') !== false) $score -= 100;
    if (strpos($id, 'forecast') !== false || strpos($id, 'next') !== false) $score -= 100;

    // より代表的な標準タグを少し優先。
    $preferred = [
        'revenue' => ['netsales', 'revenueifrs', 'revenue'],
        'cost_of_sales' => ['costofsales', 'costofsalesifrs'],
        'gross_profit' => ['grossprofit', 'grossprofitifrs'],
        'sga' => ['sellinggeneralandadministrativeexpenses'],
        'operating_profit' => ['operatingincome', 'operatingprofitlossifrs', 'operatingprofitloss'],
        'ordinary_profit' => ['ordinaryincome'],
        'pretax_profit' => ['incomebeforeincometaxes', 'profitbeforetaxifrs', 'profitbeforetax'],
        'net_income' => ['profitlossattributabletoownersofparent', 'netincome', 'profitlossifrs'],
        'total_assets' => ['assets', 'totalassets'],
        'total_liabilities' => ['liabilities', 'totalliabilities'],
        'equity' => ['netassets', 'equityattributabletoownersofparent', 'equity'],
        'cash_and_equivalents' => ['cashandcashequivalents', 'cashanddeposits'],
        'inventory' => ['inventories'],
        'interest_bearing_debt' => ['interestbearingdebt'],
        'cfo' => ['netcashprovidedbyusedinoperatingactivities'],
        'cfi' => ['netcashprovidedbyusedininvestingactivities'],
        'cff' => ['netcashprovidedbyusedinfinancingactivities'],
    ];

    $order = $preferred[$kind] ?? [];
    $pos = array_search($local, $order, true);
    if ($pos !== false) {
        $score += max(1, 20 - ((int)$pos * 3));
    }

    return $score;
}

/**
 * Revenue候補を使って連結/単体を決定。
 * 連結の当期通期Revenueがあれば連結優先、なければ単体。
 */
function detectFactScopeTdnet(array $revenueFacts, array $contexts, string $expectedFyEnd): string
{
    foreach (['consolidated', 'nonconsolidated'] as $scope) {
        foreach ($revenueFacts as $fact) {
            $contextId = (string)($fact['context'] ?? '');
            $context = $contexts[$contextId] ?? null;
            if (!is_array($context)) continue;
            if (isEligibleDurationContext($context, $expectedFyEnd, $scope)) {
                return $scope;
            }
        }
    }

    // Revenueが取れない場合でも後段でPARTIALとして調査できるよう単体を既定値にする。
    return 'nonconsolidated';
}

function buildFinancialActualRecord(
    string $code,
    string $companyName,
    array $disclosure,
    string $fyEnd,
    string $scope,
    array $selected,
    array $contexts
): array {
    $values = [];
    foreach ($selected as $kind => $fact) {
        $values[$kind] = $fact === null ? null : (float)$fact['value'];
    }

    $revenue = $values['revenue'] ?? null;
    $cost = $values['cost_of_sales'] ?? null;
    $gross = $values['gross_profit'] ?? null;
    $op = $values['operating_profit'] ?? null;
    $sga = $values['sga'] ?? null;

    $reconciliationOk = null;
    $methodParts = [];

    // Gross Profitが直接ない場合だけ Revenue - Cost を安全に補完。
    if ($gross === null && $revenue !== null && $cost !== null) {
        $gross = $revenue - $cost;
        $values['gross_profit'] = $gross;
        $methodParts[] = 'gross_revenue_minus_cost';
    } elseif ($gross !== null) {
        $methodParts[] = 'gross_direct';
    }

    if ($revenue !== null && $cost !== null && $gross !== null) {
        $base = max(abs($revenue), 1.0);
        $diffRate = abs(($revenue - $cost) - $gross) / $base;
        $reconciliationOk = $diffRate <= RECONCILIATION_TOLERANCE ? 1 : 0;
    }

    // SGAが直接ない場合、Gross - OP が成立するJ-GAAP型に限って補助値を作る。
    // IFRSでは営業利益までに他の営業損益が入ることがあるため自動補完しない。
    $accountingStandard = detectAccountingStandard($selected);

    foreach (['inventory', 'interest_bearing_debt'] as $derivedKind) {
        if (
            isset($selected[$derivedKind]) &&
            is_array($selected[$derivedKind]) &&
            !empty($selected[$derivedKind]['_derived'])
        ) {
            $methodParts[] = $derivedKind . '_derived';
        }
    }

    if (
        $sga === null &&
        $gross !== null &&
        $op !== null &&
        $accountingStandard === 'JGAAP'
    ) {
        $derivedSga = $gross - $op;
        if ($derivedSga >= 0) {
            $sga = $derivedSga;
            $values['sga'] = $sga;
            $methodParts[] = 'sga_gross_minus_op';
        }
    }

    $periodStart = detectFiscalPeriodStart($selected, $contexts, $fyEnd);

    $xbrlUrl = (string)$disclosure['xbrl_url'];
    $path = (string)(parse_url($xbrlUrl, PHP_URL_PATH) ?? '');
    $docFile = basename($path);
    $docId = preg_replace('/\.zip$/i', '', $docFile) ?: $docFile;
    $sourceKey = $docFile !== '' ? $docFile : sha1($xbrlUrl);

    $date = (string)$disclosure['date'];
    $time = trim((string)$disclosure['time']);
    $disclosedAt = $date . ' ' . ($time !== '' ? $time . ':00' : '00:00:00');

    $important = [
        'revenue', 'gross_profit', 'operating_profit',
        'total_assets', 'equity',
    ];
    $missingImportant = [];
    foreach ($important as $kind) {
        if (($values[$kind] ?? null) === null) {
            $missingImportant[] = $kind;
        }
    }

    $status = count($missingImportant) === 0 ? 'OK' : 'PARTIAL';
    $reason = count($missingImportant) === 0
        ? null
        : 'missing:' . implode(',', $missingImportant);

    $record = [
        'security_code' => $code,
        'edinet_code' => null,
        'company_name' => $companyName,
        'fiscal_period_start' => $periodStart,
        'fiscal_period_end' => $fyEnd,
        'period_type' => 'FY',
        'period_basis' => 'cumulative',
        'scope' => $scope,
        'accounting_standard' => $accountingStandard,
        'source' => 'TDNET',
        'source_record_key' => $sourceKey,
        'source_document_id' => $docId,
        'source_url' => $xbrlUrl,
        'disclosure_title' => (string)$disclosure['title'],
        'disclosed_at' => $disclosedAt,
        'is_correction' => !empty($disclosure['is_correction']) ? 1 : 0,
        'revenue' => $values['revenue'] ?? null,
        'cost_of_sales' => $values['cost_of_sales'] ?? null,
        'gross_profit' => $values['gross_profit'] ?? null,
        'sga' => $values['sga'] ?? null,
        'operating_profit' => $values['operating_profit'] ?? null,
        'ordinary_profit' => $values['ordinary_profit'] ?? null,
        'pretax_profit' => $values['pretax_profit'] ?? null,
        'net_income' => $values['net_income'] ?? null,
        'total_assets' => $values['total_assets'] ?? null,
        'total_liabilities' => $values['total_liabilities'] ?? null,
        'equity' => $values['equity'] ?? null,
        'cash_and_equivalents' => $values['cash_and_equivalents'] ?? null,
        'inventory' => $values['inventory'] ?? null,
        'interest_bearing_debt' => $values['interest_bearing_debt'] ?? null,
        'cfo' => $values['cfo'] ?? null,
        'cfi' => $values['cfi'] ?? null,
        'cff' => $values['cff'] ?? null,
        'revenue_element' => selectedElement($selected['revenue'] ?? null),
        'cost_of_sales_element' => selectedElement($selected['cost_of_sales'] ?? null),
        'gross_profit_element' => selectedElement($selected['gross_profit'] ?? null),
        'sga_element' => selectedElement($selected['sga'] ?? null),
        'operating_profit_element' => selectedElement($selected['operating_profit'] ?? null),
        'ordinary_profit_element' => selectedElement($selected['ordinary_profit'] ?? null),
        'pretax_profit_element' => selectedElement($selected['pretax_profit'] ?? null),
        'net_income_element' => selectedElement($selected['net_income'] ?? null),
        'total_assets_element' => selectedElement($selected['total_assets'] ?? null),
        'total_liabilities_element' => selectedElement($selected['total_liabilities'] ?? null),
        'equity_element' => selectedElement($selected['equity'] ?? null),
        'cash_and_equivalents_element' => selectedElement($selected['cash_and_equivalents'] ?? null),
        'inventory_element' => selectedElement($selected['inventory'] ?? null),
        'interest_bearing_debt_element' => selectedElement($selected['interest_bearing_debt'] ?? null),
        'cfo_element' => selectedElement($selected['cfo'] ?? null),
        'cfi_element' => selectedElement($selected['cfi'] ?? null),
        'cff_element' => selectedElement($selected['cff'] ?? null),
        'extraction_method' => implode('+', $methodParts) ?: 'direct',
        'reconciliation_ok' => $reconciliationOk,
        'status' => $status,
        'reason' => $reason,
    ];

    return $record;
}

function validateFinancialRecord(array $record): void
{
    $revenue = $record['revenue'];
    if ($revenue !== null && !is_finite((float)$revenue)) {
        throw new RuntimeException('Revenueが有限数ではありません。');
    }


    // Revenue-Cost-Grossの三者が揃っていて整合NGなら誤採用の可能性があるためPARTIAL化。
    if ($record['reconciliation_ok'] === 0) {
        echo "[WARN] Revenue-Cost-Grossの整合誤差が0.5%を超えています。\n";
    }
}

function detectFiscalPeriodStart(array $selected, array $contexts, string $fyEnd): ?string
{
    foreach (['revenue', 'operating_profit', 'net_income'] as $kind) {
        $fact = $selected[$kind] ?? null;
        if (!is_array($fact)) continue;
        $ctx = $contexts[(string)$fact['context']] ?? null;
        if (!is_array($ctx)) continue;
        $start = (string)($ctx['start'] ?? '');
        $end = (string)($ctx['end'] ?? '');
        if ($start !== '' && $end === $fyEnd) {
            return $start;
        }
    }
    return null;
}

function detectAccountingStandard(array $selected): string
{
    foreach ($selected as $fact) {
        if (!is_array($fact)) continue;
        $element = strtolower((string)($fact['element'] ?? ''));
        $namespace = strtolower((string)($fact['namespace'] ?? ''));
        if (strpos($element, 'ifrs') !== false || strpos($namespace, 'ifrs') !== false) {
            return 'IFRS';
        }
    }
    return 'JGAAP';
}

function selectedElement(?array $fact): ?string
{
    if ($fact === null) return null;
    $v = trim((string)($fact['element'] ?? ''));
    return $v === '' ? null : $v;
}

function printFinancialRecord(array $r): void
{
    echo sprintf(
        "[META] fy=%s start=%s scope=%s standard=%s status=%s reason=%s\n",
        (string)$r['fiscal_period_end'],
        (string)($r['fiscal_period_start'] ?? '-'),
        (string)$r['scope'],
        (string)$r['accounting_standard'],
        (string)$r['status'],
        (string)($r['reason'] ?? '-')
    );

    $labels = [
        'revenue' => '売上高/収益',
        'cost_of_sales' => '売上原価',
        'gross_profit' => '売上総利益',
        'sga' => '販管費',
        'operating_profit' => '営業利益',
        'ordinary_profit' => '経常利益',
        'pretax_profit' => '税引前利益',
        'net_income' => '純利益',
        'total_assets' => '総資産',
        'total_liabilities' => '負債',
        'equity' => '純資産/資本',
        'cash_and_equivalents' => '現金同等物',
        'inventory' => '棚卸資産',
        'interest_bearing_debt' => '有利子負債',
        'cfo' => '営業CF',
        'cfi' => '投資CF',
        'cff' => '財務CF',
    ];

    echo "[VALUES]\n";
    foreach ($labels as $key => $label) {
        $value = $r[$key] ?? null;
        $elementKey = $key . '_element';
        $element = $r[$elementKey] ?? null;
        printf(
            "  %-16s : %20s  element=%s\n",
            $label,
            $value === null ? '-' : formatNumber((float)$value),
            $element === null ? '-' : (string)$element
        );
    }

    echo "[RATIOS]\n";
    echo '  整合確認     : ' . (
        $r['reconciliation_ok'] === null ? '-' :
        ((int)$r['reconciliation_ok'] === 1 ? 'OK' : 'NG')
    ) . "\n";
    echo '  取得方法     : ' . (string)$r['extraction_method'] . "\n";
}


/**
 * 未取得項目について、XBRL内に存在する近似タグを診断表示する。
 * DB登録用の採用ロジックには使わず、10社PoCでalias不足やContext差を確認するためだけに使う。
 */
function printMissingFactHints(
    array $facts,
    array $selected,
    array $contexts,
    string $fyEnd,
    string $scope
): void {
    $keywordMap = [
        'pretax_profit' => ['beforetax', 'beforeincometax'],
        'inventory' => ['invent', 'merchandise', 'workinprocess', 'rawmaterial'],
        'interest_bearing_debt' => ['interestbearing', 'borrow', 'loan', 'debt', 'bond'],
        'cfo' => ['operatingactivities', 'operatingcash'],
        'cfi' => ['investingactivities', 'investmentactivities', 'investingcash'],
        'cff' => ['financingactivities', 'financingcash'],
    ];

    $missing = [];
    foreach ($keywordMap as $kind => $_keywords) {
        if (($selected[$kind] ?? null) === null) {
            $missing[] = $kind;
        }
    }

    if (count($missing) === 0) {
        return;
    }

    echo "[MISSING FACT HINTS]\n";

    foreach ($missing as $kind) {
        $keywords = $keywordMap[$kind];
        $matches = [];
        $seen = [];

        foreach ($facts as $fact) {
            $local = strtolower((string)($fact['local_name'] ?? ''));
            $element = strtolower((string)($fact['element'] ?? ''));
            if ($local === '' && $element === '') continue;

            $hit = false;
            foreach ($keywords as $kw) {
                if (strpos($local, $kw) !== false || strpos($element, $kw) !== false) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) continue;

            $contextId = (string)($fact['context'] ?? '');
            $ctx = $contexts[$contextId] ?? [];
            if (!is_array($ctx)) $ctx = [];

            $start = (string)($ctx['start'] ?? '');
            $end = (string)($ctx['end'] ?? '');
            $instant = (string)($ctx['instant'] ?? '');
            $members = $ctx['members'] ?? [];
            if (!is_array($members)) $members = [];

            // 当期FY末と無関係な過年度factを大量表示しない。
            if ($end !== $fyEnd && $instant !== $fyEnd) {
                continue;
            }

            $key = (string)($fact['element'] ?? '') . '|' . $contextId;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $matches[] = [
                'element' => (string)($fact['element'] ?? ''),
                'context' => $contextId,
                'value' => (float)($fact['value'] ?? 0),
                'start' => $start,
                'end' => $end,
                'instant' => $instant,
                'members' => implode(',', $members),
            ];
        }

        echo '  ' . $kind . ': ' . count($matches) . " hint(s)\n";

        foreach (array_slice($matches, 0, 20) as $m) {
            echo sprintf(
                "    element=%s context=%s value=%s start=%s end=%s instant=%s members=%s\n",
                $m['element'],
                $m['context'],
                formatNumber((float)$m['value']),
                $m['start'] !== '' ? $m['start'] : '-',
                $m['end'] !== '' ? $m['end'] : '-',
                $m['instant'] !== '' ? $m['instant'] : '-',
                $m['members'] !== '' ? $m['members'] : '-'
            );
        }
    }
}

function formatNullableRatio($value): string
{
    if ($value === null) return '-';
    return number_format((float)$value, 2, '.', ',') . ' %';
}

function printFinancialCandidateSummary(array $candidates): void
{
    echo "[CANDIDATES]\n";
    foreach ($candidates as $kind => $facts) {
        if ($kind === '_all_facts') continue;
        echo '  ' . $kind . ': ' . count($facts) . "\n";
        foreach (array_slice($facts, 0, 10) as $fact) {
            echo sprintf(
                "    %s context=%s value=%s\n",
                (string)$fact['element'],
                (string)$fact['context'],
                formatNumber((float)$fact['value'])
            );
        }
    }
}

/**
 * financial_actuals UPSERT。
 *
 * zenmeigara_hiashi_get.php と同じく、
 * jqEnsurePdoAlive() -> reconnectable error時に再接続して1回だけ再試行する。
 */
function upsertFinancialActualWithReconnect(PDO &$pdo, array $r): int
{
    try {
        jqEnsurePdoAlive($pdo);
        return upsertFinancialActual($pdo, $r);
    } catch (Throwable $e) {
        if (!jqIsReconnectableDbError($e)) {
            throw $e;
        }

        fwrite(
            STDERR,
            '[DB] reconnect and retry upsert: ' . $e->getMessage() . "\n"
        );

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
        'interest_bearing_debt_element',
        'cfo_element', 'cfi_element', 'cff_element',
        'extraction_method', 'reconciliation_ok', 'status', 'reason',
    ];

    $insertCols = implode(', ', array_map(static function (string $c): string {
        return '`' . $c . '`';
    }, $columns));

    $placeholders = implode(', ', array_map(static function (string $c): string {
        return ':' . $c;
    }, $columns));

    $updates = implode(",\n        ", array_map(static function (string $c): string {
        if (in_array($c, [
            'security_code', 'source', 'source_record_key',
            'fiscal_period_end', 'period_type', 'period_basis', 'scope'
        ], true)) {
            return '';
        }
        return '`' . $c . '` = VALUES(`' . $c . '`)';
    }, $columns));
    $updates = implode(",\n        ", array_values(array_filter(explode(",\n        ", $updates), static function (string $s): bool {
        return trim($s) !== '';
    })));

    $sql = "
        INSERT INTO financial_actuals ({$insertCols})
        VALUES ({$placeholders})
        ON DUPLICATE KEY UPDATE
        {$updates},
        updated_at = CURRENT_TIMESTAMP
    ";

    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($columns as $c) {
        $params[':' . $c] = $r[$c] ?? null;
    }
    $stmt->execute($params);

    return $stmt->rowCount();
}


function resolveUrl(string $baseUrl, string $href): string
{
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }

    if (strpos($href, '//') === 0) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $href;
    }

    $parts = parse_url($baseUrl);

    if (!is_array($parts)) {
        return $href;
    }

    $scheme = (string)($parts['scheme'] ?? 'https');
    $host = (string)($parts['host'] ?? '');

    if ($host === '') {
        return $href;
    }

    if (substr($href, 0, 1) === '/') {
        return $scheme . '://' . $host . $href;
    }

    $path = (string)($parts['path'] ?? '/');
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');

    return $scheme . '://' . $host . $dir . '/' . $href;
}

function createTempDir(string $code): string
{
    $tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $tmpDir = $tmpBase . DIRECTORY_SEPARATOR .
        'tdnet_gp_' . $code . '_' . bin2hex(random_bytes(4));

    if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        throw new RuntimeException(
            '一時ディレクトリを作成できません: ' . $tmpDir
        );
    }

    return $tmpDir;
}

/**
 * XBRL ZIPを取得して一時展開し、解析対象ファイル一覧を返す。
 */
function downloadAndExtractTdnetXbrl(
    string $xbrlUrl,
    string $tmpDir
): array {
    $response = httpGetWithStatus($xbrlUrl);

    if ($response['status'] !== 200) {
        throw new RuntimeException(
            'XBRL ZIP取得失敗 HTTP ' . $response['status'] .
            ' url=' . $xbrlUrl
        );
    }

    $body = (string)$response['body'];

    if (strlen($body) < 4 || substr($body, 0, 2) !== 'PK') {
        throw new RuntimeException(
            '取得内容がZIPではありません。url=' . $xbrlUrl
        );
    }

    $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'tdnet_xbrl.zip';

    if (file_put_contents($zipPath, $body) === false) {
        throw new RuntimeException('一時ZIPを書き込めませんでした。');
    }

    $zip = new ZipArchive();

    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('TDnet XBRL ZIPを開けませんでした。');
    }

    if (!$zip->extractTo($tmpDir)) {
        $zip->close();
        throw new RuntimeException('TDnet XBRL ZIPを展開できませんでした。');
    }

    $zip->close();

    $files = [];

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

        $ext = strtolower($fileInfo->getExtension());
        $name = strtolower($fileInfo->getFilename());

        if (
            $ext === 'xbrl' ||
            $ext === 'xml' ||
            $ext === 'htm' ||
            $ext === 'html'
        ) {
            // taxonomy/linkbase HTML等も混じるため、後段でfact有無を確認する。
            $files[] = $fileInfo->getPathname();
        }

        // ixbrl拡張子が付かないケースもファイル名で拾う。
        if (
            strpos($name, 'ixbrl') !== false &&
            !in_array($fileInfo->getPathname(), $files, true)
        ) {
            $files[] = $fileInfo->getPathname();
        }
    }

    sort($files);
    return array_values(array_unique($files));
}

/**
 * XBRL instance / Inline XBRLからContextと数値factを読み込む。
 */
function loadXbrlFactsFromFile(
    string $path,
    array &$facts,
    array &$contexts
): void {
    $raw = file_get_contents($path);

    if ($raw === false || trim($raw) === '') {
        return;
    }

    /*
     * contextRef / Context定義がファイル先頭にあるとは限らないため、
     * ファイル全体を大文字小文字無視で検索する。
     */
    if (
        stripos($raw, 'contextref') === false &&
        stripos($raw, '<xbrli:context') === false &&
        stripos($raw, '<context') === false
    ) {
        return;
    }

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $loaded = @$dom->loadXML(
        $raw,
        LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
    );

    if (!$loaded) {
        // Inline XBRLがHTMLとしてしか読めない場合のフォールバック。
        $loaded = @$dom->loadHTML(
            $raw,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );
    }

    if (!$loaded) {
        libxml_clear_errors();
        return;
    }

    $xpath = new DOMXPath($dom);

    loadContextsFromDom($xpath, $contexts);
    loadNumericFactsFromDom($xpath, $path, $facts);

    libxml_clear_errors();
}

function loadContextsFromDom(DOMXPath $xpath, array &$contexts): void
{
    $nodes = $xpath->query('//*[local-name()="context"]');

    if ($nodes === false) {
        return;
    }

    foreach ($nodes as $node) {
        if (!($node instanceof DOMElement)) {
            continue;
        }

        $id = trim($node->getAttribute('id'));

        if ($id === '') {
            continue;
        }

        $start = xpathFirstText($xpath, './/*[local-name()="startDate"]', $node);
        $end = xpathFirstText($xpath, './/*[local-name()="endDate"]', $node);
        $instant = xpathFirstText($xpath, './/*[local-name()="instant"]', $node);

        $members = [];
        $memberNodes = $xpath->query(
            './/*[local-name()="explicitMember" or local-name()="typedMember"]',
            $node
        );

        if ($memberNodes !== false) {
            foreach ($memberNodes as $memberNode) {
                $text = normalizeSpace($memberNode->textContent);
                if ($text !== '') {
                    $members[] = $text;
                }
            }
        }

        $contexts[$id] = [
            'start' => normalizeDateText($start),
            'end' => normalizeDateText($end),
            'instant' => normalizeDateText($instant),
            'members' => $members,
            'raw' => normalizeSpace($node->textContent),
        ];
    }
}

function loadNumericFactsFromDom(
    DOMXPath $xpath,
    string $path,
    array &$facts
): void {
    // 通常XBRL: contextRef属性を持つ要素。
    $nodes = $xpath->query('//*[@contextRef or @contextref]');

    if ($nodes === false) {
        return;
    }

    foreach ($nodes as $node) {
        if (!($node instanceof DOMElement)) {
            continue;
        }

        $contextRef = trim(
            $node->getAttribute('contextRef') !== ''
                ? $node->getAttribute('contextRef')
                : $node->getAttribute('contextref')
        );

        if ($contextRef === '') {
            continue;
        }

        $localName = $node->localName ?: $node->nodeName;
        $namespaceUri = (string)($node->namespaceURI ?? '');
        $nodeName = $node->nodeName;

        // Inline XBRLのix:nonFractionでは実勘定科目がname属性に入る。
        if (
            strtolower((string)$node->prefix) === 'ix' ||
            strtolower($localName) === 'nonfraction'
        ) {
            $nameAttr = trim($node->getAttribute('name'));
            if ($nameAttr !== '') {
                $nodeName = $nameAttr;
                $localName = elementLocalName($nameAttr);
            }
        }

        $valueText = normalizeSpace($node->textContent);

        if ($valueText === '') {
            continue;
        }

        $value = parseNumericValue($valueText);

        if ($value === null) {
            continue;
        }

        $scaleAttr = trim($node->getAttribute('scale'));

        if ($scaleAttr !== '' && preg_match('/^-?\d+$/', $scaleAttr)) {
            $value *= pow(10, (int)$scaleAttr);
        }

        $signAttr = trim($node->getAttribute('sign'));
        if ($signAttr === '-' && $value > 0) {
            $value *= -1;
        }

        $facts[] = [
            'element' => $nodeName,
            'local_name' => $localName,
            'namespace' => $namespaceUri,
            'context' => $contextRef,
            'value' => $value,
            'unit' => trim($node->getAttribute('unitRef')),
            'decimals' => trim($node->getAttribute('decimals')),
            'source_file' => basename($path),
        ];
    }
}

/**
 * 会社単位の当期通期Contextだけを許可する。
 * consolidated = Memberなし
 * nonconsolidated = NonConsolidatedMemberのみ許可
 */
/**
 * Context群から当期通期の決算期末を推定する。
 */
/**
 * 連結の当期Revenueがあれば連結を優先し、なければ単体へフォールバック。
 */
function isCompatibleRevenueCostPair(
    array $revenueFact,
    array $costFact
): bool {
    $revenueLocal = strtolower((string)$revenueFact['local_name']);
    $costLocal = strtolower((string)$costFact['local_name']);

    $allowedRevenue = [
        'netsales', 'revenue', 'netsalesifrs', 'revenueifrs',
    ];

    $allowedCost = [
        'costofsales', 'costofrevenue', 'costofsalesifrs', 'costofrevenueifrs',
    ];

    return
        in_array($revenueLocal, $allowedRevenue, true) &&
        in_array($costLocal, $allowedCost, true);
}

function xpathFirstText(
    DOMXPath $xpath,
    string $expression,
    DOMNode $contextNode
): string {
    $nodes = $xpath->query($expression, $contextNode);

    if ($nodes === false || $nodes->length === 0) {
        return '';
    }

    return trim((string)$nodes->item(0)->textContent);
}

function parseNumericValue(string $value): ?float
{
    $s = trim($value);

    if ($s === '') {
        return null;
    }

    $s = str_replace([
        ',',
        '，',
        ' ',
        "\xc2\xa0",
        '△',
        '▲',
    ], [
        '',
        '',
        '',
        '',
        '-',
        '-',
    ], $s);

    if (preg_match('/^\((.+)\)$/', $s, $m)) {
        $s = '-' . $m[1];
    }

    // Inline XBRL由来の装飾文字を最低限除去。
    $s = preg_replace('/[^0-9eE+\-.]/', '', $s) ?? '';

    if ($s === '' || !is_numeric($s)) {
        return null;
    }

    $n = (float)$s;
    return is_finite($n) ? $n : null;
}

function normalizeDateText(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    return '';
}

function elementLocalName(string $element): string
{
    $pos = strrpos($element, ':');

    if ($pos === false) {
        return $element;
    }

    return substr($element, $pos + 1);
}

function containsAny(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && strpos($haystack, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function normalizeSpace(string $value): string
{
    $value = preg_replace('/[\x{00A0}\s]+/u', ' ', $value) ?? $value;
    return trim($value);
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
 * HTTP statusも必要なためbodyとstatusを返す。
 */
function httpGetWithStatus(string $url): array
{
    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('curl_initに失敗しました。');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => TDNET_HTTP_TIMEOUT_SEC,
        CURLOPT_USERAGENT => 'invest-tdnet-gross-profit-poc/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml,application/zip,*/*',
        ],
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP取得に失敗しました: ' . $error);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => (string)$body,
    ];
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
