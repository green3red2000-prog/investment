<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void {
  http_response_code($statusCode);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// ===== 設定 =====
// 固定トークン（クライアント側と一致必須）
const API_TOKEN = 'x2K3a9nSZZhvnTR9JH3chRqdhjrzULK7SHuWrQwiaHd2T3vyS4BCH38pau8TtM8sub3FyWZ2FYHhzEFEsB8J6cc4ZYSFtvdHHYRm2VVBWqDWZZBWQW9NAUaX';

// DB接続（apiuser は localhost 限定の想定）
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'stocks';
const DB_USER = 'apiuser';
const DB_PASS = 'G&TgY7Ubq5weU365a6HgxGCshU&%75MKMun8m9kMAr3S&a';

// ===== 入力 =====
// GETパラメータ
$token = $_GET['token'] ?? '';
$code  = $_GET['code'] ?? null;
$daysRaw = $_GET['days'] ?? '1'; // デフォルトは 1

if ($token !== API_TOKEN) {
  respond(401, ['status' => 'error', 'message' => 'Invalid token']);
}

if ($code === null || trim((string)$code) === '') {
  respond(400, ['status' => 'error', 'message' => 'Missing required parameter: code']);
}
$code = trim((string)$code);

// days の検証
if (!preg_match('/^-?\d+$/', (string)$daysRaw)) {
  respond(400, ['status' => 'error', 'message' => 'Invalid parameter: days must be integer']);
}
$days = (int)$daysRaw;
if ($days < 0) {
  respond(400, ['status' => 'error', 'message' => 'Invalid parameter: days must be >= 0']);
}

try {
  $dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    DB_HOST,
    DB_PORT,
    DB_NAME
  );

  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  if ($days === 0) {
    // 全件（古い→新しい順にしたい場合は ASC に変更）
    $sql = "SELECT asof_date, code, open, high, low, close, volume
            FROM prices_eod
            WHERE code = :code
            ORDER BY asof_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':code' => $code]);
  } else {
    // 直近N日（= 直近N行）
    $sql = "SELECT asof_date, code, open, high, low, close, volume
            FROM prices_eod
            WHERE code = :code
            ORDER BY asof_date DESC
            LIMIT :lim";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':code', $code, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $days, PDO::PARAM_INT);
    $stmt->execute();
  }

  $rows = $stmt->fetchAll();

  respond(200, [
    'status' => 'ok',
    'code'   => $code,
    'days'   => $days,
    'count'  => count($rows),
    'rows'   => $rows,
  ]);

} catch (Throwable $e) {
  // 例外メッセージは運用時は隠したいなら固定文言にしてOK
  respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
