<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void {
  http_response_code($statusCode);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// ===== İ’è =====
const API_TOKEN = 'x2K3a9nSZZhvnTR9JH3chRqdhjrzULK7SHuWrQwiaHd2T3vyS4BCH38pau8TtM8sub3FyWZ2FYHhzEFEsB8J6cc4ZYSFtvdHHYRm2VVBWqDWZZBWQW9NAUaX';

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'stocks';
const DB_USER = 'apiuser';
const DB_PASS = 'G&TgY7Ubq5weU365a6HgxGCshU&%75MKMun8m9kMAr3S&a';

// ===== “ü—Í =====
$token = $_GET['token'] ?? '';
if ($token !== API_TOKEN) {
  respond(401, ['status' => 'error', 'message' => 'Invalid token']);
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

  // w’èSQL
  // select code,max(asof_date) from stocks.prices_eod group by code;
  $sql = "SELECT code, MAX(asof_date) AS latest_asof_date
          FROM prices_eod
          GROUP BY code";

  $rows = $pdo->query($sql)->fetchAll();

  respond(200, [
    'status' => 'ok',
    'count'  => count($rows),
    'rows'   => $rows, // [{code: "...", latest_asof_date: "YYYY-MM-DD"}, ...]
  ]);

} catch (Throwable $e) {
  respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
