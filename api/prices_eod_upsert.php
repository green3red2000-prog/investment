<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/* ==========================
 * 設定
 * ========================== */
const API_TOKEN = 'x2K3a9nSZZhvnTR9JH3chRqdhjrzULK7SHuWrQwiaHd2T3vyS4BCH38pau8TtM8sub3FyWZ2FYHhzEFEsB8J6cc4ZYSFtvdHHYRm2VVBWqDWZZBWQW9NAUaX';

const DB_HOST = '127.0.0.1';
const DB_NAME = 'stocks';
const DB_USER = 'apiuser';
const DB_PASS = 'G&TgY7Ubq5weU365a6HgxGCshU&%75MKMun8m9kMAr3S&a';

/**
 * ★変更点
 * - 行数上限(MAX_BATCH)は廃止し、サイズ基準に変更
 * - HTTP 1回のまま、DB側は内部で分割して「まとめて upsert」
 */

// 受け付ける最大ボディサイズ（DoS/誤爆防止）
// 例：10MB（必要なら 20MB/50MB へ）
const MAX_BODY_BYTES = 10_000_000;

// 1回のSQLで投げる行数（SQL長/パケット/性能のバランス）
// 500&#12316;1000 くらいが無難。環境に合わせて調整OK
const SQL_CHUNK_ROWS = 800;

// 返す errors は最大何件まで（巨大になるのを防ぐ）
const MAX_ERROR_RETURN = 50;

/* ==========================
 * 共通エラー関数
 * ========================== */
function errorResponse(int $code, string $msg, array $extra = []) : void {
    http_response_code($code);
    echo json_encode(array_merge([
        'status'  => 'error',
        'message' => $msg
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ==========================
 * tokenチェック
 * ========================== */
$token = $_GET['token'] ?? $_POST['token'] ?? null;
if (!$token) errorResponse(401, 'Missing token');
if (!hash_equals(API_TOKEN, $token)) errorResponse(401, 'Invalid token');

/* ==========================
 * JSON読み込み（サイズ基準 + gzip任意対応）
 * ========================== */
$raw = file_get_contents('php://input');
if ($raw === false) errorResponse(400, 'Failed to read body');

$rawLen = strlen($raw);
if ($rawLen === 0) errorResponse(400, 'Empty payload');
if ($rawLen > MAX_BODY_BYTES) {
    errorResponse(413, 'Payload too large', [
        'max_bytes' => MAX_BODY_BYTES,
        'got_bytes' => $rawLen
    ]);
}

// Content-Encoding: gzip に対応（クライアント側がgzipにしてもOKにする）
$enc = $_SERVER['HTTP_CONTENT_ENCODING'] ?? '';
if ($enc && stripos($enc, 'gzip') !== false) {
    $decoded = @gzdecode($raw);
    if ($decoded === false) errorResponse(400, 'Invalid gzip body');
    $raw = $decoded;

    $rawLen = strlen($raw);
    if ($rawLen > MAX_BODY_BYTES) {
        errorResponse(413, 'Payload too large (decoded)', [
            'max_bytes' => MAX_BODY_BYTES,
            'got_bytes' => $rawLen
        ]);
    }
}

$data = json_decode($raw, true);
if ($data === null) errorResponse(400, 'Invalid JSON', ['detail' => json_last_error_msg()]);

$items = [];
if (is_array($data) && array_keys($data) === range(0, count($data) - 1)) {
    // 配列
    $items = $data;
} elseif (is_array($data)) {
    // 1件（オブジェクト）
    $items = [$data];
} else {
    errorResponse(400, 'JSON must be object or array');
}

if (count($items) === 0) errorResponse(400, 'Empty items');

/* ==========================
 * バリデーション
 * ========================== */
function isValidDate(string $s): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    $t = strtotime($s);
    return $t !== false;
}
function isValidCode($v): bool {
    if ($v === null) return false;
    $s = (string)$v;
    return (bool)preg_match('/^[0-9A-Za-z_\-]{1,8}$/', $s);
}
function toNullableFloat($v) {
    if ($v === null || $v === '') return null;
    if (!is_numeric($v)) return null;
    return (float)$v;
}
function toNullableInt($v) {
    if ($v === null || $v === '') return null;
    if (!is_numeric($v)) return null;
    return (int)$v;
}

/* ==========================
 * DB接続
 * ========================== */
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    errorResponse(500, 'DB connection failed', ['detail' => $e->getMessage()]);
}

/* ==========================
 * 受信データを検証して「有効行」だけ作る
 *  - ここでエラー行は skipped 扱い
 *  - (code, asof_date) が重複していたら「最後の行を採用」(last-wins)
 * ========================== */
$received = count($items);
$validMap = [];  // key: "code|asof_date" => row
$skipped = 0;
$errors = [];

foreach ($items as $idx => $row) {
    if (!is_array($row)) {
        $skipped++;
        if (count($errors) < MAX_ERROR_RETURN) $errors[] = ['index' => $idx, 'error' => 'Row is not an object'];
        continue;
    }

    $asof_date = $row['asof_date'] ?? null;
    $code      = $row['code'] ?? null;

    if (!is_string($asof_date) || !isValidDate($asof_date)) {
        $skipped++;
        if (count($errors) < MAX_ERROR_RETURN) $errors[] = ['index' => $idx, 'error' => 'Invalid asof_date', 'value' => $asof_date];
        continue;
    }
    if (!isValidCode($code)) {
        $skipped++;
        if (count($errors) < MAX_ERROR_RETURN) $errors[] = ['index' => $idx, 'error' => 'Invalid code', 'value' => $code];
        continue;
    }

    $open   = toNullableFloat($row['open']  ?? null);
    $high   = toNullableFloat($row['high']  ?? null);
    $low    = toNullableFloat($row['low']   ?? null);
    $close  = toNullableFloat($row['close'] ?? null);
    $volume = toNullableInt($row['volume']  ?? null);

    $norm = [
        'asof_date' => $asof_date,
        'code'      => (string)$code,
        'open'      => $open,
        'high'      => $high,
        'low'       => $low,
        'close'     => $close,
        'volume'    => $volume,
    ];

    $key = $norm['code'] . '|' . $norm['asof_date'];
    $validMap[$key] = $norm; // last-wins
}

$validItems = array_values($validMap);
$validCount = count($validItems);
if ($validCount === 0) {
    echo json_encode([
        'status'   => 'ok',
        'mode'     => ($received === 1 ? 'upsert_single' : 'upsert_batch'),
        'received' => $received,
        'valid'    => 0,
        'upserted' => 0,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'note'     => 'No valid rows'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ==========================
 * UPSERT（内部で分割してまとめて実行）
 *  - INSERT ... VALUES (...),(...),... ON DUPLICATE KEY UPDATE ...
 *  - SQLは chunk ごとに生成（HTTPは1回でもDBは複数回でOK）
 * ========================== */
function buildChunkSql(int $n): string {
    // (:asof_date0, :code0, ...), (:asof_date1, :code1, ...), ...
    $values = [];
    for ($i = 0; $i < $n; $i++) {
        $values[] = "(:asof_date{$i}, :code{$i}, :open{$i}, :high{$i}, :low{$i}, :close{$i}, :volume{$i})";
    }
    $valuesSql = implode(",\n", $values);

    return <<<SQL
INSERT INTO prices_eod
(asof_date, code, open, high, low, close, volume)
VALUES
$valuesSql
ON DUPLICATE KEY UPDATE
open   = VALUES(open),
high   = VALUES(high),
low    = VALUES(low),
close  = VALUES(close),
volume = VALUES(volume)
SQL;
}

$upserted = 0;
$chunks = 0;

try {
    $pdo->beginTransaction();

    for ($offset = 0; $offset < $validCount; $offset += SQL_CHUNK_ROWS) {
        $chunk = array_slice($validItems, $offset, SQL_CHUNK_ROWS);
        $n = count($chunk);
        if ($n === 0) break;

        $sql = buildChunkSql($n);
        $stmt = $pdo->prepare($sql);

        $params = [];
        for ($i = 0; $i < $n; $i++) {
            $r = $chunk[$i];
            $params[":asof_date{$i}"] = $r['asof_date'];
            $params[":code{$i}"]      = $r['code'];
            $params[":open{$i}"]      = $r['open'];
            $params[":high{$i}"]      = $r['high'];
            $params[":low{$i}"]       = $r['low'];
            $params[":close{$i}"]     = $r['close'];
            $params[":volume{$i}"]    = $r['volume'];
        }

        $stmt->execute($params);

        $upserted += $n;
        $chunks++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    errorResponse(500, 'Upsert failed', [
        'detail' => $e->getMessage(),
        'received' => $received,
        'valid' => $validCount,
        'chunks' => $chunks
    ]);
}

/* ==========================
 * 正常終了
 * ========================== */
echo json_encode([
    'status'   => 'ok',
    'mode'     => ($received === 1 ? 'upsert_single' : 'upsert_batch'),
    'received' => $received,
    'valid'    => $validCount,
    'upserted' => $upserted,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'chunks'   => $chunks,
    'limits'   => [
        'max_body_bytes' => MAX_BODY_BYTES,
        'sql_chunk_rows' => SQL_CHUNK_ROWS
    ]
], JSON_UNESCAPED_UNICODE);
