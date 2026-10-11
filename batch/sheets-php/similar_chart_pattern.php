<?php
declare(strict_types=1);
/**
 * similar_chart_pattern.php - PHP 7.4.30 / Cron
 * report_download.php の類似チャート検索計算を移植。
 * 実行: /usr/bin/php /opt/invest/sheets-php/similar_chart_pattern.php [--noupload]
 */
require '/opt/invest/j_quants/conf/config.php';
require '/opt/invest/j_quants/lib/j_quants_common.php';
require '/opt/invest/scraping/lib/scraping_common.php';
require_once '/opt/invest/scraping/vendor/autoload.php';
date_default_timezone_set('Asia/Tokyo');
const JOB_NAME = '類似チャートパターン検索';
const OUTPUT_DIR = '/opt/invest/sheets-php/tmp';
const MASTER_FOLDER_PATH = ['投資', 'プログラミング', 'GAS', 'マスタ'];
const SECURITY_CODE_MASTER_NAME = '証券コードマスタ';
const CONDITIONS = [
    ['sheet'=>'4493_1','code'=>'4493','start'=>'2026-04-27','end'=>'2026-10-05'],
    ['sheet'=>'285A_1','code'=>'285A','start'=>'2026-02-02','end'=>'2026-04-23'],
    ['sheet'=>'285A_2','code'=>'285A','start'=>'2026-03-31','end'=>'2026-05-08'],
];

try {
    $args = array_slice($_SERVER['argv'] ?? [], 1);
    foreach ($args as $arg) {
        if ($arg !== '--noupload') throw new RuntimeException('未対応の引数: ' . $arg);
    }
    $noUpload = in_array('--noupload', $args, true);
    if (!is_dir(OUTPUT_DIR) && !mkdir(OUTPUT_DIR, 0700, true) && !is_dir(OUTPUT_DIR)) {
        throw new RuntimeException('出力ディレクトリを作成できません。');
    }
    $today = date('Y-m-d');
    $xlsxPath = OUTPUT_DIR . '/' . JOB_NAME . '_' . $today . '.xlsx';
    $txtPath = OUTPUT_DIR . '/' . JOB_NAME . '_メッセージ_' . $today . '.txt';
    $masters = loadSecurityCodeMasterMap();
    $byCode = [];
    foreach ($masters as $master) {
        $code = normalizeCode4FinancialActuals((string)($master['security_code'] ?? ''));
        if ($code !== '') $byCode[$code] = $master;
    }
    $pdo = jqBuildPdo();
    jqEnsurePdoAlive($pdo);
    $sheets = [];
    $summary = [];
    foreach (CONDITIONS as $condition) {
        $name = $condition['sheet'];
        echo '[START] ' . $name . PHP_EOL;
        $result = searchSimilarPattern($pdo, $byCode, $condition['code'], $condition['start'], $condition['end']);
        $sheets[$name] = $result['rows'];
        $summary[] = sprintf('%s code=%s %s～%s 基準足数=%d 比較終点=%s 出力=%d件',
            $name, $condition['code'], $condition['start'], $condition['end'],
            $result['bars'], $result['latest'], count($result['rows']));
        echo '[OK] ' . end($summary) . PHP_EOL;
    }
    writeSimilarXlsx($xlsxPath, $sheets);
    $message = '類似チャートパターン検索を終了しました。' . "\n\n" . implode("\n", $summary) . "\n";
    write_message_txt($txtPath, JOB_NAME . '：' . $today, $message);
    echo '[SUMMARY]' . PHP_EOL . implode(PHP_EOL, $summary) . PHP_EOL;
    if ($noUpload) {
        echo '[NOUPLOAD] ' . $xlsxPath . PHP_EOL . '[NOUPLOAD] ' . $txtPath . PHP_EOL;
    } else {
        // XLSXをGoogleスプレッドシートに変換してアップロードする。
        uploadSimilarOutputs($xlsxPath, $txtPath, $today);
    }
    echo 'DONE.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function searchSimilarPattern(PDO $pdo, array $byCode, string $code, string $start, string $end): array
{
    if (!isset($byCode[$code])) throw new RuntimeException('基準銘柄が証券コードマスタに存在しません: ' . $code);
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
    $results = array_slice($results, 0, 30);

    // Cは上位30社だけ算出し、ランキングには使用しない。
    // 旧週次と新日次の混在を、各ローソク足の日付以前の最新残高で補完する。
    $marginStmt = $pdo->prepare('SELECT data_date, shrt_std_vol, long_std_vol FROM margin_interest WHERE code = :code AND data_date BETWEEN :start AND :end ORDER BY data_date');
    $baseMargin = patternMarginAligned($marginStmt, $code, $baseRows);
    // 上位30銘柄についてのみ直近N本を再取得する。
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

    return ['rows' => $results, 'bars' => $n, 'latest' => $latest];

}



function writeSimilarXlsx(string $path, array $sheets): void
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive拡張が必要です。');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('XLSX作成に失敗しました: ' . $path);
    }
    try {
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $officeRel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $pkgRel = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $book = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . $main . '" xmlns:r="' . $officeRel . '"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="' . $pkgRel . '">';
        $headers = ['証券コード','会社名','D.類似加重幾何平均値','A.4本足','B.出来高','C.信用残'];
        $index = 0;
        foreach ($sheets as $name => $rows) {
            $index++;
            $types .= '<Override PartName="/xl/worksheets/sheet' . $index . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $book .= '<sheet name="' . xmlEsc($name) . '" sheetId="' . $index . '" r:id="rId' . $index . '"/>';
            $rels .= '<Relationship Id="rId' . $index . '" Type="' . $officeRel . '/worksheet" Target="worksheets/sheet' . $index . '.xml"/>';
            $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $main . '">'
                . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                . '<cols><col min="1" max="1" width="16" customWidth="1"/><col min="2" max="2" width="32" customWidth="1"/><col min="3" max="6" width="25" customWidth="1"/></cols><sheetData>';
            $all = array_merge([$headers], $rows);
            foreach ($all as $i => $values) {
                $rowNo = $i + 1;
                $xml .= '<row r="' . $rowNo . '">';
                foreach (array_slice($values, 0, 6) as $j => $value) {
                    $cell = chr(65 + $j) . $rowNo;
                    if ($i > 0 && $j >= 2 && $value !== null && is_numeric($value)) {
                        $xml .= '<c r="' . $cell . '" s="1"><v>' . sprintf('%.12f', (float)$value) . '</v></c>';
                    } else {
                        $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . xmlEsc($value === null ? '' : (string)$value) . '</t></is></c>';
                    }
                }
                $xml .= '</row>';
            }
            $xml .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet' . $index . '.xml', $xml);
        }
        $types .= '</Types>';
        $book .= '</sheets></workbook>';
        $rels .= '<Relationship Id="rId' . ($index + 1) . '" Type="' . $officeRel . '/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('[Content_Types].xml', $types);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="' . $pkgRel . '"><Relationship Id="rId1" Type="' . $officeRel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $styles = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<styleSheet xmlns="' . $main . '">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.000"/></numFmts>'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->addFromString('xl/workbook.xml', $book);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    } finally {
        if (!$zip->close()) throw new RuntimeException('XLSX書き込みに失敗しました。');
    }
}
function xmlEsc(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function uploadSimilarOutputs(string $xlsxPath, string $txtPath, string $today): void
{
    $drive = new Google\Service\Drive(build_oauth_client_());
    $folderId = DRIVE_UPLOAD_FOLDER_ID;
    $sheetName = JOB_NAME . '_' . $today;
    $xlsxMime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $sheetMime = 'application/vnd.google-apps.spreadsheet';
    $content = file_get_contents($xlsxPath);
    if ($content === false || substr($content, 0, 2) !== 'PK') {
        throw new RuntimeException('XLSXの読み込みまたは形式確認に失敗しました: ' . $xlsxPath);
    }

    $uploadedSheet = null;
    for ($try = 1; $try <= UPLOAD_RETRY_MAX; $try++) {
        try {
            $existing = find_drive_file_by_name_in_folder_($drive, $folderId, $sheetName);
            if ($existing !== null && $existing->getMimeType() !== $sheetMime) {
                throw new RuntimeException('同名のファイルがGoogleスプレッドシートではありません: ' . $sheetName);
            }
            $meta = new Google\Service\Drive\DriveFile(['name' => $sheetName]);
            $params = [
                'data' => $content,
                'mimeType' => $xlsxMime,
                'uploadType' => 'multipart',
                'fields' => 'id,name,mimeType',
            ];
            if ($existing !== null) {
                echo '[UPLOAD][XLSX->SHEET][UPDATE] ' . $sheetName . PHP_EOL;
                $uploadedSheet = $drive->files->update($existing->getId(), $meta, $params);
            } else {
                echo '[UPLOAD][XLSX->SHEET][CREATE] ' . $sheetName . PHP_EOL;
                $meta->setParents([$folderId]);
                $meta->setMimeType($sheetMime);
                $uploadedSheet = $drive->files->create($meta, $params);
            }
            if ($uploadedSheet->getMimeType() !== $sheetMime) {
                throw new RuntimeException('アップロード後のMIMEタイプが不正です: ' . $uploadedSheet->getMimeType());
            }
            break;
        } catch (Google\Service\Exception $e) {
            $code = (int)$e->getCode();
            $retryable = in_array($code, [429, 500, 502, 503, 504], true)
                || stripos($e->getMessage(), 'timeout') !== false;
            if (!$retryable || $try >= UPLOAD_RETRY_MAX) throw $e;
            fwrite(STDERR, '[UPLOAD][XLSX][RETRY] ' . $try . '/' . UPLOAD_RETRY_MAX . PHP_EOL);
            sleep(UPLOAD_RETRY_SLEEP);
        }
    }
    if ($uploadedSheet === null) throw new RuntimeException('XLSXのアップロードに失敗しました。');
    echo 'Uploaded/updated Google Sheet: ' . $uploadedSheet->getName() . ' (' . $uploadedSheet->getId() . ')' . PHP_EOL;

    $txtName = JOB_NAME . '_メッセージ_' . $today . '.txt';
    $uploadedTxt = upload_file_with_retry_($drive, $txtPath, $txtName, 'text/plain', $folderId);
    echo 'Uploaded/updated TXT: ' . $uploadedTxt->getName() . ' (' . $uploadedTxt->getId() . ')' . PHP_EOL;

    @unlink($xlsxPath);
    @unlink($txtPath);
    echo 'DONE: uploaded & local files removed.' . PHP_EOL;
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
