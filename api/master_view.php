<?php
declare(strict_types=1);

/**
 * master_view.php（SQLiteローカル読み取り・超高速版）
 * - PHP 7.4.30
 * - export_masters.php が作成した SQLite を読むだけ（Google APIなし）
 * - basic/daily を縦表示
 * - 可能なら APCu で headers をキャッシュ（さらに高速）
 *
 * 使い方:
 *   /master_view.php
 *   /master_view.php?mode=api&text=4183
 *   /master_view.php?debug=1
 */

date_default_timezone_set('Asia/Tokyo');

// =============================
// 設定（ここだけ環境に合わせればOK）
// =============================
const SQLITE_PATH = '/opt/invest/master_cache/masters.sqlite';
const MASTER_CODE_HEADER = '証券コード';

// APCu headers キャッシュTTL（mtime一致なら実質無期限だが保険）
const APCU_HEADERS_TTL_SEC = 3600;

// ===== 表示フォーマット定義（GAS版から移植）=====
// 小数点第2位まで表示する対象（ヘッダ完全一致）
const DEC2_HEADERS = [
'(7)直近の終値の前日比率',
  '(9)直近の出来高の前日比率',
  '(10)終値の直近5日間の回帰係数',
  '(11)終値の直近10日間の回帰係数',
  '(12)終値の直近22日間の回帰係数',
  '(13)終値の直近66日間の回帰係数',
  '(14)終値の直近132日間の回帰係数',
  '(105)終値の直近200日間の回帰係数',
  '(15)出来高の直近5日間の回帰係数',
  '(16)出来高の直近10日間の回帰係数',
  '(17)出来高の直近22日間の回帰係数',
  '(18)出来高の直近66日間の回帰係数',
  '(19)出来高の直近132日間の回帰係数',
  '(106)出来高の直近200日間の回帰係数',
  '(20)終値5日移動平均',
  '(21)終値10日移動平均',
  '(22)終値22日移動平均',
  '(23)終値66日移動平均',
  '(24)終値132日移動平均',
  '(107)終値200日移動平均',
  '(25)出来高5日移動平均',
  '(26)出来高10日移動平均',
  '(27)出来高22日移動平均',
  '(28)出来高66日移動平均',
  '(29)出来高132日移動平均',
  '(108)出来高200日移動平均',
  '(30)終値5日移動平均の直近5日の回帰係数',
  '(31)終値10日移動平均の直近10日の回帰係数',
  '(32)終値22日移動平均の直近10日の回帰係数',
  '(33)終値66日移動平均の直近10日の回帰係数',
  '(34)終値132日移動平均の直近10日の回帰係数',
  '(109)終値200日移動平均の直近10日の回帰係数',
  '(35)出来高5日移動平均の直近5日の回帰係数',
  '(36)出来高10日移動平均の直近10日の回帰係数',
  '(37)出来高22日移動平均の直近10日の回帰係数',
  '(38)出来高66日移動平均の直近10日の回帰係数',
  '(39)出来高132日移動平均の直近10日の回帰係数',
  '(110)出来高200日移動平均の直近10日の回帰係数',
  '(40)200日分の高値-安値の一日の値幅平均',
  '(41)直近5日間の高値-安値の値幅のボラティリティ',
  '(42)直近10日間の高値-安値の値幅のボラティリティ',
  '(43)直近22日間の高値-安値の値幅のボラティリティ',
  '(44)直近66日間の高値-安値の値幅のボラティリティ',
  '(45)直近132日間の高値-安値の値幅のボラティリティ',
  '(111)直近200日間の高値-安値の値幅のボラティリティ',
  '(46)直近5日間の高値-安値の値幅のボラティリティの直近5日間の回帰係数',
  '(47)直近10日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数',
  '(48)直近22日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数',
  '(49)直近66日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数',
  '(50)直近132日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数',
  '(112)直近200日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数',
  '(51)終値5日移動平均と終値の移動平均乖離率',
  '(52)終値10日移動平均と終値の移動平均乖離率',
  '(53)終値22日移動平均と終値の移動平均乖離率',
  '(55)終値66日移動平均と終値の移動平均乖離率',
  '(56)終値132日移動平均と終値の移動平均乖離率',
  '(113)終値200日移動平均と終値の移動平均乖離率',
  '(57)出来高5日移動平均と出来高の移動平均乖離率',
  '(58)出来高10日移動平均と出来高の移動平均乖離率',
  '(59)出来高22日移動平均と出来高の移動平均乖離率',
  '(60)出来高66日移動平均と出来高の移動平均乖離率',
  '(61)出来高132日移動平均と出来高の移動平均乖離率',
  '(114)出来高200日移動平均と出来高の移動平均乖離率',
  '(62)終値5日移動平均の移動平均乖離率の直近5日間の回帰係数',
  '(63)終値10日移動平均の移動平均乖離率の直近10日間の回帰係数',
  '(64)終値22日移動平均の移動平均乖離率の直近10日間の回帰係数',
  '(65)終値66日移動平均の移動平均乖離率の直近10日間の回帰係数',
  '(66)終値132日移動平均の移動平均乖離率の直近10日間の回帰係数',
  '(115)終値200日移動平均の移動平均乖離率の直近10日間の回帰係数',
  '(67)出来高5日移動平均の移動平均乖離率の直近5日間の回帰係数',
  '(68)出来高10日移動平均の移動平均乖離率の直近10日間の回帰係数',
  '(69)出来高22日移動平均の移動平均乖離率の直近10日間の回帰係数',
  '(70)出来高66日移動平均の移動平均乖離率の直近10日間の回帰係数',
  '(71)出来高132日移動平均の移動平均乖離率の直近10日間の回帰係数',
  '(116)出来高200日移動平均の移動平均乖離率の直近10日間の回帰係数',
  '(72)β',
  '(73)相関',
  '(74)相対ボラ',
  '(75)残差ボラ',
  '(76)アップサイドβ',
  '(77)ダウンサイドβ',
  '(78)Up Capture',
  '(79)Down Capture',
  '(80)RSI',
  '(81)RSIの直近22日間の回帰係数',
  '(82)MACD',
  '(83)MACDの直近22日間の回帰係数',
  '(85)5日間上昇率',
  '(86)10日間上昇率',
  '(88)22日間上昇率',
  '(89)5日間下落率',
  '(90)10日間下落率',
  '(91)22日間下落率',
  '(98)信用買い残日数',
  '(99)直近5日間の値幅不安定率',
  '(100)直近10日間の値幅不安定率',
  '(101)直近22日間の値幅不安定率',
  '(102)直近66日間の値幅不安定率',
  '(103)直近132日間の値幅不安定率',
  '(124)直近の週足の終値の前週比率',
  '(126)直近の週足の出来高の前週比率',
  '(127)RSIの直近5日間の回帰係数',
  '(128)週足RSI',
  '(129)週足RSIの直近5週間の回帰係数',
  '(130)ストキャスティクス%K',
  '(131)ストキャスティクス%D',
  '(132)週足ストキャスティクス%K',
  '(133)週足ストキャスティクス%D',
  '(134)ストキャスティクス%Kの直近5日間の回帰係数',
  '(135)ストキャスティクス%Dの直近5日間の回帰係数',
  '(136)週足ストキャスティクス%Kの直近5週間の回帰係数',
  '(137)週足ストキャスティクス%Dの直近5週間の回帰係数',
  '(138)ボリンジャーバンドのσ値',
];

// 末尾に % を付ける（値はそのまま）
const PCT_SUFFIX_HEADERS = [
  '(7)直近の終値の前日比率',
  '(9)直近の出来高の前日比率',
  '(85)5日間上昇率',
  '(86)10日間上昇率',
  '(88)22日間上昇率',
  '(89)5日間下落率',
  '(90)10日間下落率',
  '(91)22日間下落率',
];

// 100倍して % を付ける（乖離率/キャプチャ/不安定率）
const PCT_X100_SUFFIX_HEADERS = [
  '(51)終値5日移動平均と終値の移動平均乖離率',
  '(52)終値10日移動平均と終値の移動平均乖離率',
  '(53)終値22日移動平均と終値の移動平均乖離率',
  '(55)終値66日移動平均と終値の移動平均乖離率',
  '(56)終値132日移動平均と終値の移動平均乖離率',
  '(57)出来高5日移動平均と出来高の移動平均乖離率',
  '(58)出来高10日移動平均と出来高の移動平均乖離率',
  '(59)出来高22日移動平均と出来高の移動平均乖離率',
  '(60)出来高66日移動平均と出来高の移動平均乖離率',
  '(61)出来高132日移動平均と出来高の移動平均乖離率',
  '(78)Up Capture',
  '(79)Down Capture',
  '(99)直近5日間の値幅不安定率',
  '(100)直近10日間の値幅不安定率',
  '(101)直近22日間の値幅不安定率',
  '(102)直近66日間の値幅不安定率',
  '(103)直近132日間の値幅不安定率',
];

// =============================
// ルーティング
// =============================
if (isset($_GET['debug']) && (string)$_GET['debug'] === '1') {
  header('Content-Type: text/plain; charset=UTF-8');
  echo "PHP_SELF=" . ($_SERVER['PHP_SELF'] ?? '') . "\n";
  echo "SQLITE_PATH=" . SQLITE_PATH . "\n";
  echo "exists=" . (is_file(SQLITE_PATH) ? 'yes' : 'no') . "\n";
  echo "readable=" . (is_readable(SQLITE_PATH) ? 'yes' : 'no') . "\n";
  echo "mtime=" . (is_file(SQLITE_PATH) ? date('Y-m-d H:i:s', (int)filemtime(SQLITE_PATH)) : 'N/A') . "\n";
  exit;
}

$mode = strtolower(trim((string)($_GET['mode'] ?? '')));
if ($mode === 'api') {
  $text = trim((string)($_GET['text'] ?? ''));

  // 値セルの背景色指定
  // 例:
  //   red=PER,46,134
  //   blue=PBR,yield,116
  $redTargets = parseHighlightTargets((string)($_GET['red'] ?? ''));
  $blueTargets = parseHighlightTargets((string)($_GET['blue'] ?? ''));

  if (preg_match('/^[0-9A-Za-z]{4}$/', $text)) {
    try {
      $code4 = $text;

      $basic = fetchMasterRowByCode_sqlite(
        'basic',
        $code4,
        $redTargets,
        $blueTargets
      );

      $daily = fetchMasterRowByCode_sqlite(
        'daily',
        $code4,
        $redTargets,
        $blueTargets
      );
      
      header('Content-Type: text/html; charset=UTF-8');
      echo renderStackedPage($code4, $basic, $daily);
      exit;

    } catch (Throwable $e) {
      header('Content-Type: text/html; charset=UTF-8');
      echo renderErrorPage($text, $e->getMessage());
      exit;
    }
  }

  header('Content-Type: text/html; charset=UTF-8');
  echo renderSimpleMsgPage("「{$text}」を受け取りました");
  exit;
}

// default: フォーム
header('Content-Type: text/html; charset=UTF-8');
echo renderIndexPage();
exit;

// =============================
// SQLite 読み取り：マスタ行取得
// =============================

/**
 * kind: basic|daily
 * @return array{meta:string, rowsHtml:string, companyName?:string, debug?:string}
 */
function fetchMasterRowByCode_sqlite(string $kind,string $code4,array $redTargets = [], array $blueTargets = []): array {
  $kind = strtolower($kind);
  if ($kind !== 'basic' && $kind !== 'daily') {
    throw new InvalidArgumentException("invalid kind: {$kind}");
  }

  $codeKey = normalizeCode4($code4);

  $db = open_sqlite_readonly_();

  // headers（APCu優先）
  $headersPack = load_headers_cached_($db, $kind);
  $headers = $headersPack['headers'];
  $meta = $headersPack['meta'];

  // row
  $st = $db->prepare(
    "SELECT company_name, row_json
     FROM master_rows
     WHERE kind = :k AND code4 = :c"
  );
  $st->bindValue(':k', $kind, SQLITE3_TEXT);
  $st->bindValue(':c', $codeKey, SQLITE3_TEXT);

  $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
  if (!$row) {
    return [
      'meta' => $meta . " / 証券コード={$codeKey}（一致行なし）",
      'rowsHtml' => '<tr><td>（該当なし）</td></tr>',
      'debug' => '',
    ];
  }

  $company = (string)($row['company_name'] ?? '');
  $rowJson = (string)($row['row_json'] ?? '[]');
  $cells = json_decode($rowJson, true);
  if (!is_array($cells)) $cells = [];

  return [
    'meta' => $meta . " / 証券コード={$codeKey}",
    'rowsHtml' => buildVerticalRowsHtml($headers,$cells,$kind,$redTargets,$blueTargets),
    'companyName' => $company,
    'debug' => '',
  ];
}

/**
 * SQLite read-only open (per-request)
 * - query_only + temp_store=MEMORY で「readonlyなのに書こうとして死ぬ」を回避
 */
function open_sqlite_readonly_(): SQLite3 {
  if (!is_file(SQLITE_PATH)) {
    throw new RuntimeException("SQLite が見つかりません: " . SQLITE_PATH . "（export_masters.php を先に実行してください）");
  }
  if (!is_readable(SQLITE_PATH)) {
    throw new RuntimeException("SQLite を読めません: " . SQLITE_PATH . "（apache権限を確認してください）");
  }

  $db = new SQLite3(SQLITE_PATH, SQLITE3_OPEN_READONLY);
  $db->busyTimeout(500);

  // 「readonlyでも内部で書き込みを試みる」系を抑止
  $db->exec("PRAGMA query_only=ON;");
  $db->exec("PRAGMA temp_store=MEMORY;");
  $db->exec("PRAGMA cache_size=-200000;"); // 約200MB（好みで調整）

  return $db;
}

/**
 * headers を kind ごとに取得（APCu + mtime一致で爆速）
 * @return array{headers:array<int,string>, meta:string}
 */
function load_headers_cached_(SQLite3 $db, string $kind): array {
  $mtime = @filemtime(SQLITE_PATH);
  $mtime = is_int($mtime) ? $mtime : 0;

  $apcuKey = "master_headers_{$kind}_v1_m{$mtime}";
  if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
    $hit = false;
    $val = apcu_fetch($apcuKey, $hit);
    if ($hit && is_array($val) && isset($val['headers']) && is_array($val['headers']) && isset($val['meta'])) {
      return $val;
    }
  }

  $st = $db->prepare(
    "SELECT file_name, sheet_title, headers_json, updated_at
     FROM master_headers
     WHERE kind = :k"
  );
  $st->bindValue(':k', $kind, SQLITE3_TEXT);

  $r = $st->execute()->fetchArray(SQLITE3_ASSOC);
  if (!$r) {
    throw new RuntimeException("master_headers が見つかりません(kind={$kind})。export_masters.php を確認してください。");
  }

  $headersJson = (string)($r['headers_json'] ?? '[]');
  $headers = json_decode($headersJson, true);
  if (!is_array($headers)) $headers = [];

  $fileName = (string)($r['file_name'] ?? '');
  $sheetTitle = (string)($r['sheet_title'] ?? '');
  $updatedAt = (int)($r['updated_at'] ?? 0);

  $meta = trim($fileName . " / sheet=" . $sheetTitle);
  if ($updatedAt > 0) {
    $meta .= " / updated_at=" . date('Y-m-d H:i:s', $updatedAt);
  }

  $pack = ['headers' => $headers, 'meta' => $meta];

  if (function_exists('apcu_store') && ini_get('apc.enabled')) {
    apcu_store($apcuKey, $pack, APCU_HEADERS_TTL_SEC);
  }

  return $pack;
}

// =============================
// 表示（あなたの現行 master_view.php に合わせた体裁）
// =============================
function renderIndexPage(): string {
  $self = h($_SERVER['PHP_SELF'] ?? '/master_view.php');

  $exists = is_file(SQLITE_PATH);
  $mtime = $exists ? @date('Y-m-d H:i:s', (int)@filemtime(SQLITE_PATH)) : 'N/A';
  $pathEsc = h(SQLITE_PATH);
  $status = $exists ? 'OK' : 'NG';

  return <<<HTML
<!doctype html>
<html><head>
  <meta charset="UTF-8">
  <title>シンプル検索（マスタ高速：SQLiteローカル版）</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial;padding:24px;line-height:1.6}
    .wrap{max-width:820px;margin:auto}
    h1{font-size:22px;margin:0 0 16px}
    input[type=text]{font-size:18px;padding:10px;width:240px}
    button{font-size:18px;padding:10px 14px;cursor:pointer}
    .hint{color:#666;font-size:13px;margin-top:10px}
    .status{margin-top:14px;font-size:12px;color:#555;background:#fafafa;border:1px solid #eee;padding:10px;border-radius:8px}
    .status code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
  </style>
</head>
<body>
<div class="wrap">
  <h1>シンプル検索（マスタ高速：SQLiteローカル版）</h1>
  <form method="get" action="{$self}">
    <input type="hidden" name="mode" value="api">
    <input type="text" name="text" placeholder="例: 4183" autocomplete="off">
    <button type="submit">表示</button>
  </form>

  <div class="hint">
    - 4桁英数のみ「マスタ縦表示」<br>
    - WebはGoogle APIを呼びません（SQLite を読むだけ）
  </div>

  <div class="status">
    <div><strong>SQLite</strong></div>
    <div>status: <strong>{$status}</strong></div>
    <div>path: <code>{$pathEsc}</code></div>
    <div style="margin-top:6px">mtime: {$mtime}</div>
  </div>
</div>
</body></html>
HTML;
}

function renderStackedPage(string $code4, array $basic, array $daily): string {
  $codeEsc = h($code4);
  $self = h($_SERVER['PHP_SELF'] ?? '/master_view.php');

  $basicRows = $basic['rowsHtml'] ?? '<tr><td>（該当なし）</td></tr>';
  $dailyRows = $daily['rowsHtml'] ?? '<tr><td>（該当なし）</td></tr>';

  $basicMeta = h($basic['meta'] ?? '');
  $dailyMeta = h($daily['meta'] ?? '');

  return <<<HTML
<!doctype html>
<html><head>
  <meta charset="UTF-8">
  <title>マスタ抽出結果 ({$codeEsc})</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial;padding:24px;line-height:1.6}
    .wrap{max-width:1100px;margin:auto}
    h1{font-size:22px;margin:0 0 16px}
    h2{font-size:18px;margin:24px 0 8px}
    .meta{font-size:12px;color:#555;margin:6px 0 12px}
    table{width:100%;border-collapse:collapse}
    td,th{border:1px solid #eee;padding:6px;vertical-align:top;text-align:left}
    th{background:#fafafa}
    th, td{ text-align:left; } /* 既定：左揃え（明示） */
    table.kv th, table.kv td{ text-align:left; } /* マスタ縦表は確実に左揃え */
    thead th{ background:#f3f3f3; }
    table.kv.kv-daily th{ width: 520px; }  /* 日足分析マスタの見出し列 */
    .section{margin-bottom:28px}
    .back{margin-top:20px}
  </style>
</head>
<body>
<div class="wrap">
  <h1>マスタ抽出結果（{$codeEsc}）</h1>

  <div class="section">
    <h2>全銘柄基本情報マスタ</h2>
    <div class="meta">{$basicMeta}</div>
    <table class="kv"><tbody>
      {$basicRows}
    </tbody></table>
  </div>

  <div class="section">
    <h2>全銘柄日足分析マスタ</h2>
    <div class="meta">{$dailyMeta}</div>
    <table class="kv kv-daily"><tbody>
      {$dailyRows}
    </tbody></table>
  </div>

  <div class="back"><a href="{$self}" target="_top">← 入力に戻る</a></div>
</div>
</body></html>
HTML;
}

function renderSimpleMsgPage(string $msg): string {
  $self = h($_SERVER['PHP_SELF'] ?? '/master_view.php');
  $m = h($msg);
  return <<<HTML
<!doctype html><html><head>
  <meta charset="UTF-8"><title>結果</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial;padding:24px;line-height:1.6}
    .wrap{max-width:720px;margin:auto}
    h1{font-size:22px;margin:0 0 16px}
    p{font-size:18px}
  </style>
</head><body>
  <div class="wrap">
    <h1>結果</h1>
    <p>{$m}</p>
    <p><a href="{$self}" target="_top">← 戻る</a></p>
  </div>
</body></html>
HTML;
}

function renderErrorPage(string $code4, string $errMsg): string {
  $self = h($_SERVER['PHP_SELF'] ?? '/master_view.php');
  $c = h($code4);
  $e = h($errMsg);
  return <<<HTML
<!doctype html><html><head>
  <meta charset="UTF-8"><title>取得エラー</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial;padding:24px;line-height:1.6}
    .wrap{max-width:720px;margin:auto}
    pre{white-space:pre-wrap;word-break:break-word;background:#f7f7f7;padding:10px;border:1px solid #ddd}
  </style>
</head><body>
  <div class="wrap">
    <h1>取得エラー（{$c}）</h1>
    <pre>{$e}</pre>
    <p><a href="{$self}" target="_top">← 戻る</a></p>
  </div>
</body></html>
HTML;
}

// =============================
// ユーティリティ
// =============================
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeCode4($x): string {
  $s = trim((string)($x ?? ''));
  $d = preg_replace('/[^0-9]/', '', $s);
  if ($d === null) $d = '';
  if ($d === '') return '';
  $d = str_pad($d, 4, '0', STR_PAD_LEFT);
  return substr($d, -4);
}

/**
 * red / blue パラメータをカンマ区切りで解析する。
 *
 * 例:
 *   PER,46,134
 *   PBR,yield,116
 *
 * @return array<string,bool>
 */
function parseHighlightTargets(string $raw): array {
  $targets = [];

  foreach (explode(',', $raw) as $item) {
    $item = trim($item);
    if ($item === '') continue;

    /*
     * 基本情報マスタ用の名前は大文字・小文字を区別しない。
     * yield は YIELD、Yield などでも同じ指定として扱う。
     */
    if (preg_match('/^[A-Za-z]+$/', $item)) {
      $key = strtoupper($item);

      if (in_array($key, ['PER', 'PBR', 'YIELD', 'ROC', 'LSR'], true)) {
        $targets[$key] = true;
      }

      continue;
    }

    /*
     * 日足分析マスタ用の番号。
     * 先頭ゼロは除去し、46 と 046 を同じ指定として扱う。
     */
    if (preg_match('/^\d+$/', $item)) {
      $targets[(string)((int)$item)] = true;
    }
  }

  return $targets;
}
/**
 * 指定された見出しの値セルに適用する背景色を返す。
 *
 * red と blue の両方に同じ項目が指定された場合は red を優先する。
 */
function resolveHighlightColor(
  string $kind,
  string $headerName,
  array $redTargets,
  array $blueTargets
): string {
  $targetKey = resolveHighlightTargetKey($kind, $headerName);

  if ($targetKey === '') {
    return '';
  }

  // 同じ項目が両方に指定された場合は red を優先
  if (isset($redTargets[$targetKey])) {
    return '#FFBBC2';
  }

  if (isset($blueTargets[$targetKey])) {
    return '#C1C9FF';
  }

  return '';
}
function buildVerticalRowsHtml(
  array $headers,
  array $row,
  string $kind = '',
  array $redTargets = [],
  array $blueTargets = []
): string {
  $out = [];
  $n = count($headers);

  for ($c = 0; $c < $n; $c++) {
    $hname = trim((string)($headers[$c] ?? ''));
    if ($hname === '') continue;

    $val = $row[$c] ?? '';

    $backgroundColor = resolveHighlightColor(
      $kind,
      $hname,
      $redTargets,
      $blueTargets
    );

    $cellStyle = '';
    if ($backgroundColor !== '') {
      $cellStyle = ' style="background-color:' . h($backgroundColor) . ';"';
    }

    $out[] =
      '<tr>' .
      '<th' . $cellStyle . '>' . h($hname) . '</th>' .
      '<td' . $cellStyle . '>' .
      h(formatCellForHtml($val, $hname)) .
      '</td>' .
      '</tr>';
  }

  return $out
    ? implode("\n", $out)
    : '<tr><td>（表示項目なし）</td></tr>';
}
/**
 * マスタの見出しから、red / blue パラメータと照合するキーを取得する。
 *
 * basic:
 *   PER      → PER
 *   PBR      → PBR
 *   利回り   → YIELD
 *   騰落率   → ROC
 *   信用倍率 → LSR
 *
 * daily:
 *   (46)～   → 46
 *   (116)～  → 116
 */
function resolveHighlightTargetKey(
  string $kind,
  string $headerName
): string {
  $kind = strtolower(trim($kind));
  $headerName = trim($headerName);

  if ($kind === 'basic') {
    $basicMap = [
      'PER'      => 'PER',
      'PBR'      => 'PBR',
      '利回り'   => 'YIELD',
      '騰落率'   => 'ROC',
      '信用倍率' => 'LSR',
    ];

    return $basicMap[$headerName] ?? '';
  }

  if ($kind === 'daily') {
    if (preg_match('/^\((\d+)\)/', $headerName, $matches)) {
      return (string)((int)$matches[1]);
    }
  }

  return '';
}
function formatCellForHtml($v, string $headerName = ''): string {
  if ($v === null) return '';
  if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';

  $h = trim($headerName);

  // 数値化（必要なときだけ）
  $toNumberOrNull = function($x): ?float {
    if (is_int($x) || is_float($x)) {
      $n = (float)$x;
      return is_finite($n) ? $n : null;
    }
    $s = trim((string)$x);
    if ($s === '') return null;
    $clean = str_replace(',', '', $s);
    if (!is_numeric($clean)) return null;
    $n = (float)$clean;
    return is_finite($n) ? $n : null;
  };

  // ---- % 表示（優先）----
  if ($h !== '' && (in_array($h, PCT_SUFFIX_HEADERS, true) || in_array($h, PCT_X100_SUFFIX_HEADERS, true))) {
    $num = $toNumberOrNull($v);
    if ($num === null) return (string)$v;

    $val = in_array($h, PCT_X100_SUFFIX_HEADERS, true) ? ($num * 100.0) : $num;
    return number_format($val, 2, '.', '') . '%';
  }

  // ---- 小数点2桁 ----
  if ($h !== '' && in_array($h, DEC2_HEADERS, true)) {
    $num = $toNumberOrNull($v);
    if ($num !== null) return number_format($num, 2, '.', '');
  }

  return (string)$v;
}

