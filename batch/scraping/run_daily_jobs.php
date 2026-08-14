<?php
declare(strict_types=1);

/**
 * 日次ジョブ管理プログラム
 *
 * 各PHPプログラムを順番に実行する。
 *
 * PHP 7.4.30
 *
 * デプロイ先:
 * /opt/invest/scraping/run_daily_jobs.php
 */

require '/opt/invest/scraping/lib/scraping_common.php';
require_once '/opt/invest/scraping/vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

// ============================================================
// 設定
// ============================================================

const JOB_NAME = '日次ジョブ管理';

const PHP_BIN = '/usr/bin/php';

const LOG_DIR = '/opt/invest/logs';
const LOCK_FILE = '/opt/invest/scraping/state/run_daily_jobs.lock';

// Google Drive上のカレンダーマスタ
const MASTER_FOLDER_PATH = [
    '投資',
    'プログラミング',
    'GAS',
    'マスタ',
];

const CALENDAR_MASTER_NAME = 'カレンダーマスタ';

const WAIT_MARKER_SCRIPT =
    '/opt/invest/scraping/wait_marker.php';

const DAILY_MARKET_SNAPSHOT_JOBS_SCRIPT =
    '/opt/invest/scraping/run_daily_market_snapshot_jobs.php';

const SECURITY_CODE_MASTER_SCRIPT =
    '/opt/invest/j_quants/security_code_master_get.php';

const MARKET_CALENDAR_SCRIPT =
    '/opt/invest/j_quants/market_calendar_get.php';

const ZENMEIGARA_HIASHI_SCRIPT =
    '/opt/invest/j_quants/zenmeigara_hiashi_get.php';

const ZENMEIGARA_BASICINFO_SCRIPT =
    '/opt/invest/j_quants/zenmeigara_basicinfo_get.php';

const ZENMEIGARA_ANALYSIS_SCRIPT =
    '/opt/invest/sheets-php/run_zenmeigara_analysis.php';

// ============================================================
// 初期化
// ============================================================

$startedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));

$targetDateYmd = $startedAt->format('Y-m-d');
$targetDateYmd8 = $startedAt->format('Ymd');

$logFile = LOG_DIR . '/run_daily_jobs.log';

$lockHandle = null;

// ============================================================
// メイン処理
// ============================================================

try {
    ensureDirectory(LOG_DIR);

    $lockHandle = acquireLock(LOCK_FILE);

    logMessage(
        sprintf(
            '%sを開始します。target_date=%s',
            JOB_NAME,
            $targetDateYmd
        ),
        $logFile
    );

    /*
     * 0. PowerShellでスクレイピング
     *
     * 自宅PC側で実行するため、このPHPプログラムでは処理しない。
     */

    // --------------------------------------------------------
    // 1. 営業日の判定
    // --------------------------------------------------------

    $calendarRows = loadCalendarMasterForDailyJobs();

    $holidayDivision = findHolidayDivisionForDate(
        $calendarRows,
        $targetDateYmd
    );

    if (!in_array($holidayDivision, ['1', '2'], true)) {
        logMessage(
            sprintf(
                '本日は営業日ではないため、処理を終了します。' .
                'target_date=%s, holiday_division=%s',
                $targetDateYmd,
                $holidayDivision
            ),
            $logFile
        );

        exit(0);
    }

    logMessage(
        sprintf(
            '営業日であることを確認しました。' .
            'target_date=%s, holiday_division=%s',
            $targetDateYmd,
            $holidayDivision
        ),
        $logFile
    );

    // --------------------------------------------------------
    // 2. J-QuantsAPIで証券コードマスタ更新
    // --------------------------------------------------------

    $command = buildPhpCommand(
        SECURITY_CODE_MASTER_SCRIPT
    );

    runCommand(
        '2. J-QuantsAPIで証券コードマスタ更新',
        $command,
        $logFile
    );

    // --------------------------------------------------------
    // 3. 反映待ち（GAS：証券コードマスタ）
    // --------------------------------------------------------

    $securityMasterMarker = sprintf(
        'complete_security_master_%s.txt',
        $targetDateYmd8
    );

    $command = buildPhpCommand(
        WAIT_MARKER_SCRIPT,
        [
            '--gdrive=' . $securityMasterMarker,
            '--timeout=210',
            '--interval=30',
            '--optional=0',
        ]
    );

    runCommand(
        '3. 反映待ち（GAS：証券コードマスタ）',
        $command,
        $logFile
    );

    // --------------------------------------------------------
    // 4. J-QuantsAPIでカレンダー更新
    // --------------------------------------------------------

    $command = buildPhpCommand(
        MARKET_CALENDAR_SCRIPT
    );

    runCommand(
        '4. J-QuantsAPIでカレンダー更新',
        $command,
        $logFile
    );

    // --------------------------------------------------------
    // 5. 反映待ち（GAS：カレンダーマスタ）
    // --------------------------------------------------------

    $calendarMarker = sprintf(
        'complete_calendar_%s.txt',
        $targetDateYmd8
    );

    $command = buildPhpCommand(
        WAIT_MARKER_SCRIPT,
        [
            '--gdrive=' . $calendarMarker,
            '--timeout=210',
            '--interval=30',
            '--optional=0',
        ]
    );

    runCommand(
        '5. 反映待ち（GAS：カレンダーマスタ）',
        $command,
        $logFile
    );

    // --------------------------------------------------------
    // 6. J-QuantsAPIで全銘柄日足取得
    // --------------------------------------------------------

    $command = buildPhpCommand(
        ZENMEIGARA_HIASHI_SCRIPT
    );

    runCommand(
        '6. J-QuantsAPIで全銘柄日足取得',
        $command,
        $logFile
    );

    // --------------------------------------------------------
    // 7. スクレイピング待ち（000）
    // --------------------------------------------------------

    $snapshotMarkerPath000 = sprintf(
        '/opt/invest/scraping/state/upload/' .
        'complete_upload_daily_market_snapshot_%s_000.txt',
        $targetDateYmd8
    );

    $snapshotWaitCommand000 = buildPhpCommand(
        WAIT_MARKER_SCRIPT,
        [
            '--path=' . $snapshotMarkerPath000,
            '--timeout=210',
            '--interval=30',
            '--optional=1',
        ]
    );

    $snapshotWaitResult000 = runCommand(
        '7. スクレイピング待ち（000）',
        $snapshotWaitCommand000,
        $logFile,
        [0, 10]
    );

    if ($snapshotWaitResult000 === 10) {
        logMessage(
            'スクレイピング待ち（000）がoptionalタイムアウトしました。' .
            '指数データを反映できないため、後続処理を終了します。',
            $logFile,
            'WARN'
        );

        exit(0);
    }

    // --------------------------------------------------------
    // 8. スクレイピングデータの反映処理（000）
    // --------------------------------------------------------

    $command = buildPhpCommand(
        DAILY_MARKET_SNAPSHOT_JOBS_SCRIPT,
        ['000']
    );

    runCommand(
        '8. スクレイピングデータの反映処理（000）',
        $command,
        $logFile
    );

    // --------------------------------------------------------
    // 9. J-QuantsAPIで全銘柄基本情報取得
    // --------------------------------------------------------

    $command = buildPhpCommand(
        ZENMEIGARA_BASICINFO_SCRIPT
    );

    runCommand(
        '9. J-QuantsAPIで全銘柄基本情報取得',
        $command,
        $logFile
    );

    // --------------------------------------------------------
    // 10. スクレイピング待ち（001）
    // --------------------------------------------------------

    $snapshotMarkerPath001 = sprintf(
        '/opt/invest/scraping/state/upload/' .
        'complete_upload_daily_market_snapshot_%s_001.txt',
        $targetDateYmd8
    );

    $snapshotWaitCommand001 = buildPhpCommand(
        WAIT_MARKER_SCRIPT,
        [
            '--path=' . $snapshotMarkerPath001,
            '--timeout=210',
            '--interval=30',
            '--optional=1',
        ]
    );

    $snapshotWaitResult001 = runCommand(
        '10. スクレイピング待ち（001）',
        $snapshotWaitCommand001,
        $logFile,
        [0, 10]
    );

    // --------------------------------------------------------
    // 11. スクレイピングデータの反映処理（001）
    // --------------------------------------------------------

    if ($snapshotWaitResult001 === 0) {
        $command = buildPhpCommand(
            DAILY_MARKET_SNAPSHOT_JOBS_SCRIPT,
            ['001']
        );

        runCommand(
            '11. スクレイピングデータの反映処理（001）',
            $command,
            $logFile
        );
    } else {
        logMessage(
            '11. スクレイピングデータの反映処理（001）をスキップします。' .
            'スクレイピング待ち（001）がoptionalタイムアウトしました。',
            $logFile,
            'WARN'
        );
    }

    // --------------------------------------------------------
    // 12. 全銘柄日足分析
    // --------------------------------------------------------

    $command = buildPhpCommand(
        ZENMEIGARA_ANALYSIS_SCRIPT
    );

    runCommand(
        '12. 全銘柄日足分析',
        $command,
        $logFile
    );

    $finishedAt = new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Tokyo')
    );

    $elapsedSeconds = $finishedAt->getTimestamp()
        - $startedAt->getTimestamp();

    logMessage(
        sprintf(
            '%sが正常終了しました。elapsed=%s',
            JOB_NAME,
            formatElapsedTime($elapsedSeconds)
        ),
        $logFile
    );

    exit(0);
} catch (Throwable $e) {
    $message = sprintf(
        '%sが異常終了しました。%s: %s (%s:%d)',
        JOB_NAME,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );

    try {
        logMessage($message, $logFile, 'ERROR');
    } catch (Throwable $logError) {
        fwrite(STDERR, $message . PHP_EOL);
    }

    exit(1);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

// ============================================================
// 関数
// ============================================================

/**
 * PHPコマンドを組み立てる。
 *
 * @param string   $scriptPath
 * @param string[] $arguments
 *
 * @return string
 */
function buildPhpCommand(
    string $scriptPath,
    array $arguments = []
): string {
    $parts = [
        escapeshellarg(PHP_BIN),
        escapeshellarg($scriptPath),
    ];

    foreach ($arguments as $argument) {
        $parts[] = escapeshellarg($argument);
    }

    return implode(' ', $parts);
}

/**
 * 外部コマンドを実行する。
 *
 * 標準出力と標準エラーをリアルタイムで画面およびログへ出力する。
 *
 * @param string $stepName
 * @param string $command
 * @param string $logFile
 * @param int[]  $allowedExitCodes
 *
 * @return int
 */
function runCommand(
    string $stepName,
    string $command,
    string $logFile,
    array $allowedExitCodes = [0]
): int {
    logMessage(
        sprintf('[START] %s', $stepName),
        $logFile
    );

    logMessage(
        sprintf('[COMMAND] %s', $command),
        $logFile
    );

    $descriptorSpec = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        $command,
        $descriptorSpec,
        $pipes,
        null,
        null,
        [
            'bypass_shell' => false,
        ]
    );

    if (!is_resource($process)) {
        throw new RuntimeException(
            sprintf(
                'コマンドを開始できませんでした。step=%s',
                $stepName
            )
        );
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    try {
        while (true) {
            $read = [];

            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }

            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read !== []) {
                $write = null;
                $except = null;

                $changed = stream_select(
                    $read,
                    $write,
                    $except,
                    1,
                    0
                );

                if ($changed === false) {
                    throw new RuntimeException(
                        sprintf(
                            'コマンド出力の監視に失敗しました。step=%s',
                            $stepName
                        )
                    );
                }

                foreach ($read as $stream) {
                    $isStderr = ($stream === $pipes[2]);
                    outputStreamContents(
                        $stream,
                        $logFile,
                        $isStderr
                    );
                }
            } else {
                usleep(100000);
            }

            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }
        }

        outputStreamContents($pipes[1], $logFile, false);
        outputStreamContents($pipes[2], $logFile, true);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
    }

    $exitCode = proc_close($process);

    /*
     * 環境によって、proc_get_status()で終了状態を取得したあとに
     * proc_close()が-1を返す場合がある。
     */
    if ($exitCode === -1 && isset($status['exitcode'])) {
        $statusExitCode = (int)$status['exitcode'];

        if ($statusExitCode >= 0) {
            $exitCode = $statusExitCode;
        }
    }

    logMessage(
        sprintf(
            '[END] %s exit_code=%d',
            $stepName,
            $exitCode
        ),
        $logFile
    );

    if (!in_array($exitCode, $allowedExitCodes, true)) {
        throw new RuntimeException(
            sprintf(
                'ジョブが異常終了しました。step=%s, exit_code=%d',
                $stepName,
                $exitCode
            )
        );
    }

    return $exitCode;
}

/**
 * パイプに残っている内容を出力する。
 *
 * @param resource $stream
 */
function outputStreamContents(
    $stream,
    string $logFile,
    bool $isStderr
): void {
    while (true) {
        $line = fgets($stream);

        if ($line === false) {
            break;
        }

        $line = rtrim($line, "\r\n");

        writeRawLogLine(
            $line,
            $logFile,
            $isStderr
        );
    }
}

/**
 * 子プロセスの出力を、そのまま画面とログに書き込む。
 */
function writeRawLogLine(
    string $message,
    string $logFile,
    bool $isStderr = false
): void {
    $line = sprintf(
        '[%s] %s%s',
        date('Y-m-d H:i:s'),
        $message,
        PHP_EOL
    );

    $output = $isStderr ? STDERR : STDOUT;
    fwrite($output, $line);

    $result = file_put_contents(
        $logFile,
        $line,
        FILE_APPEND | LOCK_EX
    );

    if ($result === false) {
        throw new RuntimeException(
            sprintf(
                'ログファイルへの書き込みに失敗しました。file=%s',
                $logFile
            )
        );
    }
}

/**
 * 管理プログラム自身のログを出力する。
 */
function logMessage(
    string $message,
    string $logFile,
    string $level = 'INFO'
): void {
    $line = sprintf(
        '[%s] [%s] %s%s',
        date('Y-m-d H:i:s'),
        $level,
        $message,
        PHP_EOL
    );

    $output = ($level === 'ERROR') ? STDERR : STDOUT;
    fwrite($output, $line);

    $result = file_put_contents(
        $logFile,
        $line,
        FILE_APPEND | LOCK_EX
    );

    if ($result === false) {
        throw new RuntimeException(
            sprintf(
                'ログファイルへの書き込みに失敗しました。file=%s',
                $logFile
            )
        );
    }
}

/**
 * ディレクトリがなければ作成する。
 */
function ensureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(
            sprintf(
                'ディレクトリを作成できませんでした。directory=%s',
                $directory
            )
        );
    }
}

/**
 * 多重起動防止用のロックを取得する。
 *
 * @return resource
 */
function acquireLock(string $lockFile)
{
    ensureDirectory(dirname($lockFile));

    $handle = fopen($lockFile, 'c');

    if ($handle === false) {
        throw new RuntimeException(
            sprintf(
                'ロックファイルを開けませんでした。file=%s',
                $lockFile
            )
        );
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        throw new RuntimeException(
            sprintf(
                '%sは既に実行されています。',
                JOB_NAME
            )
        );
    }

    if (!ftruncate($handle, 0)) {
        flock($handle, LOCK_UN);
        fclose($handle);

        throw new RuntimeException(
            'ロックファイルの初期化に失敗しました。'
        );
    }

    $lockInfo = sprintf(
        "pid=%d\nstarted_at=%s\n",
        getmypid(),
        date('Y-m-d H:i:s')
    );

    if (fwrite($handle, $lockInfo) === false) {
        flock($handle, LOCK_UN);
        fclose($handle);

        throw new RuntimeException(
            'ロックファイルへの書き込みに失敗しました。'
        );
    }

    fflush($handle);

    return $handle;
}

/**
 * 経過秒数を時分秒形式へ変換する。
 */
function formatElapsedTime(int $seconds): string
{
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainingSeconds = $seconds % 60;

    return sprintf(
        '%02d:%02d:%02d',
        $hours,
        $minutes,
        $remainingSeconds
    );
}

/**
 * Google Drive上のカレンダーマスタを読み込む。
 *
 * @return array<int, array{date:string, holiday_division:string}>
 */
function loadCalendarMasterForDailyJobs(): array
{
    $values = loadSpreadsheetValuesForDailyJobs(
        CALENDAR_MASTER_NAME
    );

    if (count($values) < 2) {
        throw new RuntimeException(
            'カレンダーマスタにデータがありません。'
        );
    }

    $header = normalizeHeaderForDailyJobs($values[0]);

    $dateIndex = requireHeaderIndexForDailyJobs(
        $header,
        '日付',
        CALENDAR_MASTER_NAME
    );

    $holidayDivisionIndex = requireHeaderIndexForDailyJobs(
        $header,
        '日本市場休日区分',
        CALENDAR_MASTER_NAME
    );

    $rows = [];

    for ($i = 1; $i < count($values); $i++) {
        $sourceRow = $values[$i];

        $date = normalizeCalendarDateForDailyJobs(
            (string)($sourceRow[$dateIndex] ?? '')
        );

        if ($date === null) {
            continue;
        }

        $holidayDivision = trim(
            (string)($sourceRow[$holidayDivisionIndex] ?? '')
        );

        $rows[] = [
            'date' => $date,
            'holiday_division' => $holidayDivision,
        ];
    }

    if ($rows === []) {
        throw new RuntimeException(
            'カレンダーマスタから有効な日付を取得できませんでした。'
        );
    }

    return $rows;
}

/**
 * 指定日の日本市場休日区分を取得する。
 */
function findHolidayDivisionForDate(
    array $calendarRows,
    string $targetDate
): string {
    foreach ($calendarRows as $row) {
        if (($row['date'] ?? '') !== $targetDate) {
            continue;
        }

        $holidayDivision = trim(
            (string)($row['holiday_division'] ?? '')
        );

        if ($holidayDivision === '') {
            throw new RuntimeException(
                sprintf(
                    'カレンダーマスタの日本市場休日区分が空です。date=%s',
                    $targetDate
                )
            );
        }

        return $holidayDivision;
    }

    throw new RuntimeException(
        sprintf(
            'カレンダーマスタに現在日付が存在しません。date=%s',
            $targetDate
        )
    );
}

/**
 * Google Drive上のスプレッドシートを読み込む。
 */
function loadSpreadsheetValuesForDailyJobs(
    string $fileName
): array {
    $client = build_oauth_client_();

    $drive = new Google\Service\Drive($client);
    $sheets = new Google\Service\Sheets($client);

    $folderId = resolveFolderIdByPathForDailyJobs(
        $drive,
        MASTER_FOLDER_PATH
    );

    $fileId = findSpreadsheetFileIdByNameForDailyJobs(
        $drive,
        $folderId,
        $fileName
    );

    if ($fileId === null) {
        throw new RuntimeException(
            sprintf(
                'マスタスプレッドシートが見つかりません。file=%s',
                $fileName
            )
        );
    }

    $spreadsheet = $sheets->spreadsheets->get($fileId);
    $firstSheet = $spreadsheet->getSheets()[0] ?? null;

    if ($firstSheet === null) {
        throw new RuntimeException(
            sprintf(
                'マスタのシート取得に失敗しました。file=%s',
                $fileName
            )
        );
    }

    $sheetTitle = $firstSheet
        ->getProperties()
        ->getTitle();

    $range = "'" .
        str_replace("'", "''", $sheetTitle) .
        "'!A:Z";

    $response = $sheets->spreadsheets_values->get(
        $fileId,
        $range
    );

    return $response->getValues() ?? [];
}

/**
 * Google DriveのフォルダパスからフォルダIDを取得する。
 */
function resolveFolderIdByPathForDailyJobs(
    Google\Service\Drive $drive,
    array $folders
): string {
    $parentId = 'root';

    foreach ($folders as $folderName) {
        $folderName = trim((string)$folderName);

        if ($folderName === '') {
            continue;
        }

        $query = sprintf(
            "name = '%s' and '%s' in parents " .
            "and trashed = false " .
            "and mimeType = 'application/vnd.google-apps.folder'",
            str_replace("'", "\\'", $folderName),
            $parentId
        );

        $response = $drive->files->listFiles([
            'q' => $query,
            'fields' => 'files(id,name)',
            'pageSize' => 10,
        ]);

        $files = $response->getFiles();

        if (!$files || count($files) === 0) {
            throw new RuntimeException(
                sprintf(
                    'Google Driveのフォルダが見つかりません。path=%s',
                    implode('/', $folders)
                )
            );
        }

        $parentId = $files[0]->getId();
    }

    return $parentId;
}

/**
 * 指定フォルダ内のGoogleスプレッドシートIDを取得する。
 */
function findSpreadsheetFileIdByNameForDailyJobs(
    Google\Service\Drive $drive,
    string $folderId,
    string $fileName
): ?string {
    $query = sprintf(
        "name = '%s' and '%s' in parents " .
        "and trashed = false " .
        "and mimeType = 'application/vnd.google-apps.spreadsheet'",
        str_replace("'", "\\'", $fileName),
        $folderId
    );

    $response = $drive->files->listFiles([
        'q' => $query,
        'fields' => 'files(id,name)',
        'pageSize' => 10,
    ]);

    $files = $response->getFiles();

    if (!$files || count($files) === 0) {
        return null;
    }

    return $files[0]->getId();
}

/**
 * スプレッドシートの見出しを正規化する。
 *
 * @param mixed[] $header
 *
 * @return string[]
 */
function normalizeHeaderForDailyJobs(array $header): array
{
    return array_map(
        static function ($value): string {
            return trim((string)$value);
        },
        $header
    );
}

/**
 * 必須見出しの列番号を取得する。
 */
function requireHeaderIndexForDailyJobs(
    array $header,
    string $columnName,
    string $sheetName
): int {
    $index = array_search(
        $columnName,
        $header,
        true
    );

    if ($index === false) {
        throw new RuntimeException(
            sprintf(
                '%sに「%s」列が見つかりません。',
                $sheetName,
                $columnName
            )
        );
    }

    return (int)$index;
}

/**
 * カレンダーマスタの日付をYYYY-MM-DDへ正規化する。
 */
function normalizeCalendarDateForDailyJobs(
    string $date
): ?string {
    $date = trim($date);

    if ($date === '') {
        return null;
    }

    if (preg_match('/^\d{8}$/', $date) === 1) {
        return substr($date, 0, 4) . '-' .
            substr($date, 4, 2) . '-' .
            substr($date, 6, 2);
    }

    if (
        preg_match(
            '/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}$/',
            $date
        ) === 1
    ) {
        $normalized = str_replace('/', '-', $date);

        $dateTime = DateTimeImmutable::createFromFormat(
            '!Y-n-j',
            $normalized,
            new DateTimeZone('Asia/Tokyo')
        );

        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime->format('Y-m-d');
        }
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}