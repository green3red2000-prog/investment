<?php
declare(strict_types=1);

/**
 * wait_marker.php
 *
 * PHP 7.4
 *
 * 終了コード:
 *   0  = 正常終了
 *   1  = 異常終了
 *   10 = タイムアウト(optional)
 */

const EXIT_OK = 0;
const EXIT_ERROR = 1;
const EXIT_TIMEOUT_OPTIONAL = 10;

const LOCAL_MARKER_DIR = '/opt/invest/scraping/state/upload';

const GDRIVE_STATE_PATH = [
    '投資',
    'プログラミング',
    'GAS',
    'バッチ処理',
    '状態管理',
];

const OAUTH_CLIENT_JSON = '/opt/invest/secrets/client_secret_347950769606-uhd9d68r6sfnokac0bovmi8a95brpanh.apps.googleusercontent.com.json';
const DEFAULT_TOKEN_JSON = '/opt/invest/secrets/token_default_scraping.json';

main($argv);

function main(array $argv): void
{
    try {
        $opt = getopt('', [
            'path:',
            'gdrive:',
            'timeout:',
            'interval:',
            'optional:',
            'token::',
        ]);

        $pathName = isset($opt['path']) ? trim((string)$opt['path']) : '';
        $gdriveName = isset($opt['gdrive']) ? trim((string)$opt['gdrive']) : '';

        if (($pathName === '' && $gdriveName === '') || ($pathName !== '' && $gdriveName !== '')) {
            throw new RuntimeException('--path または --gdrive のどちらか一方だけを指定してください。');
        }

        $timeoutMin = parse_positive_int($opt['timeout'] ?? null, '--timeout');
        $intervalSec = parse_positive_int($opt['interval'] ?? null, '--interval');

        $optional = parse_int_value($opt['optional'] ?? null, '--optional');
        if ($optional !== 0 && $optional !== 1) {
            throw new RuntimeException('--optional は 0 または 1 を指定してください。');
        }

        $timeoutSec = $timeoutMin * 60;
        $deadline = time() + $timeoutSec;

        echo "[INFO] timeout={$timeoutMin}min interval={$intervalSec}sec optional={$optional}\n";

        if ($pathName !== '') {
            $target = LOCAL_MARKER_DIR . '/' . basename($pathName);
            echo "[INFO] wait local marker: {$target}\n";

            $existsFunc = function () use ($target): bool {
                return is_file($target);
            };
        } else {
            $tokenJson = isset($opt['token']) ? trim((string)$opt['token']) : DEFAULT_TOKEN_JSON;
            echo "[INFO] wait Google Drive marker: {$gdriveName}\n";

            require_once __DIR__ . '/vendor/autoload.php';

            $client = build_google_client($tokenJson);
            $drive = new Google\Service\Drive($client);
            $folderId = resolve_folder_id_by_path($drive, GDRIVE_STATE_PATH);

            $existsFunc = function () use ($drive, $folderId, $gdriveName): bool {
                return gdrive_file_exists($drive, $folderId, $gdriveName);
            };
        }
        
        while (true) {
            if ($existsFunc()) {
                echo "[OK] marker found.\n";
                exit(EXIT_OK);
            }

            if (time() >= $deadline) {
                if ($optional === 1) {
                    echo "[TIMEOUT] marker not found. optional timeout.\n";
                    exit(EXIT_TIMEOUT_OPTIONAL);
                }

                fwrite(STDERR, "[ERROR] marker not found. required timeout.\n");
                exit(EXIT_ERROR);
            }

            sleep($intervalSec);
        }

    } catch (Throwable $e) {
        fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
        exit(EXIT_ERROR);
    }
}

function parse_positive_int($value, string $name): int
{
    $n = parse_int_value($value, $name);
    if ($n <= 0) {
        throw new RuntimeException("{$name} は1以上の整数を指定してください。");
    }
    return $n;
}

function parse_int_value($value, string $name): int
{
    if ($value === null || $value === '') {
        throw new RuntimeException("{$name} を指定してください。");
    }

    $s = (string)$value;
    if (!ctype_digit($s)) {
        throw new RuntimeException("{$name} は整数で指定してください。");
    }

    return (int)$s;
}

function build_google_client(string $tokenJson): Google\Client
{
    if (!file_exists(OAUTH_CLIENT_JSON)) {
        throw new RuntimeException('OAuth client json not found: ' . OAUTH_CLIENT_JSON);
    }

    if (!file_exists($tokenJson)) {
        throw new RuntimeException('Token json not found: ' . $tokenJson);
    }

    $client = new Google\Client();
    $client->setApplicationName('invest-wait-marker-php');
    $client->setAuthConfig(OAUTH_CLIENT_JSON);
    $client->setScopes([Google\Service\Drive::DRIVE_READONLY]);
    $client->setAccessType('offline');

    $token = read_json_file($tokenJson);
    $client->setAccessToken($token);

    if ($client->isAccessTokenExpired()) {
        if (!$client->getRefreshToken()) {
            throw new RuntimeException('refresh_token がありません。token json を再作成してください。');
        }

        $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());

        if (isset($newToken['error'])) {
            throw new RuntimeException('OAuth refresh error: ' . (string)$newToken['error']);
        }

        if (empty($newToken['refresh_token']) && !empty($token['refresh_token'])) {
            $newToken['refresh_token'] = $token['refresh_token'];
        }

        save_json_file_atomic($tokenJson, $newToken);
        $client->setAccessToken($newToken);
    }

    return $client;
}

function read_json_file(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('JSON read failed: ' . $path);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON: ' . $path);
    }

    return $json;
}

function save_json_file_atomic(string $path, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('json_encode failed.');
    }

    $tmp = $path . '.tmp_' . getmypid();

    if (file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Token tmp write failed: ' . $tmp);
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Token rename failed: ' . $path);
    }
}

function resolve_folder_id_by_path(Google\Service\Drive $drive, array $folders): string
{
    $parent = 'root';

    foreach ($folders as $name) {
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
            throw new RuntimeException('Google Drive folder not found: ' . implode('/', $folders));
        }

        $parent = $files[0]->getId();
    }

    return $parent;
}

function gdrive_file_exists(Google\Service\Drive $drive, string $folderId, string $fileName): bool
{
    $q = sprintf(
        "name = '%s' and '%s' in parents and trashed = false",
        str_replace("'", "\\'", $fileName),
        $folderId
    );

    $res = $drive->files->listFiles([
        'q' => $q,
        'fields' => 'files(id,name)',
        'pageSize' => 1,
    ]);

    $files = $res->getFiles();

    return $files && count($files) > 0;
}