<?php
// commit_test.php
header('Content-Type: application/json; charset=utf-8');

// === DB接続（prices_eod_upsert.php と同じ設定にする）===
$mysqli = new mysqli(
    '127.0.0.1',   // host
    'apiuser',     // user
    'G&TgY7Ubq5weU365a6HgxGCshU&%75MKMun8m9kMAr3S&a',    // password
    'stocks'       // database
);

if ($mysqli->connect_errno) {
    echo json_encode([
        'status' => 'ng',
        'error'  => $mysqli->connect_error,
    ]);
    exit;
}

$mysqli->set_charset('utf8mb4');

// ===== テスト開始 =====

// autocommit 状態確認
$autocommit = $mysqli->query("SELECT @@autocommit")->fetch_row()[0];

// 明示的にトランザクション開始
$mysqli->begin_transaction();

// テスト用の一意なキー
$code = 'ZZZZ';
$asof_date = '2099-12-31';

// INSERT（確実に1行）
$sql = "
INSERT INTO prices_eod (code, asof_date, open, high, low, close, volume)
VALUES ('$code', '$asof_date', 1, 1, 1, 1, 1)
ON DUPLICATE KEY UPDATE close = VALUES(close)
";

$ok = $mysqli->query($sql);
$affected = $mysqli->affected_rows;
$errno_before_commit = $mysqli->errno;
$error_before_commit = $mysqli->error;

// COMMIT
$commit_ok = $mysqli->commit();

// COMMIT後に即SELECT
$res = $mysqli->query("
    SELECT COUNT(*) 
    FROM prices_eod 
    WHERE code='$code' AND asof_date='$asof_date'
");
$count_after = $res ? $res->fetch_row()[0] : null;

// ===== 結果出力 =====
echo json_encode([
    'status' => 'ok',
    'autocommit' => $autocommit,
    'insert_ok' => $ok,
    'affected_rows' => $affected,
    'errno_before_commit' => $errno_before_commit,
    'error_before_commit' => $error_before_commit,
    'commit_ok' => $commit_ok,
    'exists_after_commit' => $count_after,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
