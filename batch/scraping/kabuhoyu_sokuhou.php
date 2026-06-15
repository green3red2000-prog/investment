<?php
declare(strict_types=1);

/**
 * 大量保有速報（maonline.jp/kabuhoyu） 当日分スクレイピング PHP版
 *
 * - Webshare Proxy（scraping_common.php 経由）
 * - 出力: /opt/invest/scraping/tmp
 *   - 大量保有速報_yyyy-MM-dd.csv
 *   - 大量保有速報_メッセージ_yyyy-MM-dd.txt
 * - Driveへアップロード（CSVはGoogleスプレッドシート化）→ 成功後ローカル削除
 * - メール送信なし
 *
 * 仕様（GASから踏襲）:
 * - todayStr は実日付（Asia/Tokyo）
 * - 「内容」に（買い増し）/（保有減少）が含まれる場合は「区分」を上書きし、当該文字列を「内容」から削除
 * - 対象者/証券コードの厳密分離（例: 株式会社Birdman＜7063＞…）
 */

require __DIR__ . '/lib/scraping_common.php';

// 実行単位でプロキシ固定（403/429等の時だけローテーション）
http_session_begin(true);

date_default_timezone_set('Asia/Tokyo');

// ===== 設定 =====
$JOB_NAME     = '大量保有速報';
$BASE_URL     = 'https://maonline.jp';
$MAX_PAGES    = 50;
$INTERVAL_US  = 2000000; // 1.2秒（必要なら調整）

// 任意: キーワード（メッセージTXTに「ヒット一覧」を書くだけ。メールは送らない）
$KEYWORDS = [
  '6492','290A','262A','2502','4011','3773','3655','5259','5247',
  '井村俊哉','清原 達郎','fundnote株式会社','片山 晃','五味 大輔'
];

// ===== 対象日 =====
// 1) 引数があればそれを使う（YYYY-MM-DD）
// 2) なければ当日
$todayStr = $argv[1] ?? '';
$todayStr = trim((string)$todayStr);
if ($todayStr === '') $todayStr = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $todayStr)) {
  fwrite(STDERR, "[ERROR] date must be YYYY-MM-DD. got: {$todayStr}\n");
  exit(1);
}

$paths   = build_output_paths($JOB_NAME, $todayStr);
$csvPath = $paths['csv'];
$txtPath = $paths['txt'];

echo "処理開始: 対象日={$todayStr}\n";

$records = [];
$hitPast = false;

for ($page = 1; $page <= $MAX_PAGES; $page++) {
  $url = "{$BASE_URL}/kabuhoyu?page={$page}";
  echo "ページ取得: page={$page} url={$url}\n";

  try {
    $html = http_get_text($url);
  } catch (Throwable $e) {
    fwrite(STDERR, "[WARN] fetch failed page={$page}: {$e->getMessage()}\n");
    usleep($INTERVAL_US);
    continue;
  }

  $newsList = parseNewsBlocks($html);
  echo "解析結果: page={$page} 件数=" . count($newsList) . "\n";

  if (count($newsList) === 0) {
    echo "情報なし: 次ページへ\n";
    usleep($INTERVAL_US);
    continue;
  }

  $pageHasToday = false;

  foreach ($newsList as $item) {
    $recDate = trim((string)($item['date'] ?? ''));
    $cmp = compareYmd($recDate, $todayStr);

    if ($cmp > 0) {
      // 未来日付はスキップ
      continue;

    } elseif ($cmp === 0) {
      $pageHasToday = true;

      $rec = buildRecordFromTitle(
        $todayStr,
        (string)($item['h4Html'] ?? ''),
        (string)($item['href'] ?? ''),
        $BASE_URL
      );
      $records[] = $rec;

    } else {
      // 過去日付に到達したら巡回終了
      $hitPast = true;
      break;
    }
  }

  if ($hitPast) {
    echo "過去日付を検出 → 巡回終了 (page={$page})\n";
    break;
  }

  if ($pageHasToday) {
    usleep($INTERVAL_US);
    continue;
  }

  echo "当日データが見つからないため終了: page={$page}\n";
  break;
}

echo "当日抽出件数: " . count($records) . " 件\n";

// ===== CSV出力 =====
writeCsv($csvPath, $records);

// ===== メッセージTXT出力 =====
$subjectLine = "{$JOB_NAME}：{$todayStr}";
$bodyLines = [];
$bodyLines[] = "報告件数は、" . count($records) . "件でした。";
$bodyLines[] = "";

// キーワードヒット（任意）
$hits = buildKeywordMatches($records, $KEYWORDS);
$bodyLines[] = "事前登録キーワード一致（タイトル判定）:";
if (count($hits) === 0) {
  $bodyLines[] = "・該当なし";
} else {
  foreach ($hits as $h) {
    $bodyLines[] = "・{$h['title']} ({$h['url']})";
  }
}
$bodyLines[] = "";

write_message_txt($txtPath, $subjectLine, implode("\n", $bodyLines));

echo "ローカル出力完了:\n- {$csvPath}\n- {$txtPath}\n";

// ===== Driveへアップロード → ローカル削除 =====
upload_outputs_and_cleanup($JOB_NAME, $todayStr, $csvPath, $txtPath);

echo "処理完了\n";

// =====================
// 関数群
// =====================

/**
 * <div class="news_box"> ... <div class="news"> ... を拾う（GASのregex踏襲）
 * return: array<int,array{date:string,h4Html:string,href:string,titleTextRaw:string}>
 */
function parseNewsBlocks(string $html): array {
  $out = [];

  // news_box を優先的にスコープ（無ければ全文）
  $scoped = '';
  if (preg_match_all('/<div\s+class=["\']news_box["\'][^>]*>([\s\S]*?)<\/div>\s*<\/div>/i', $html, $boxMatches)) {
    foreach ($boxMatches[1] as $b) $scoped .= $b . "\n";
  }
  if ($scoped === '') $scoped = $html;

  $re = '/<div\s+class=["\']news["\'][^>]*>\s*<div\s+class=["\']date["\']>(.*?)<\/div>\s*<h4\s+class=["\']title["\']>([\s\S]*?)<\/h4>\s*<\/div>/i';
  if (!preg_match_all($re, $scoped, $m, PREG_SET_ORDER)) return $out;

  foreach ($m as $row) {
    $date  = trim(stripTags((string)($row[1] ?? '')));
    $h4Html = (string)($row[2] ?? '');

    $href = '';
    $aText = '';
    if (preg_match('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i', $h4Html, $aMatch)) {
      $href = (string)($aMatch[1] ?? '');
      $aText = trim(stripTags((string)($aMatch[2] ?? '')));
    }

    $out[] = [
      'date' => $date,
      'h4Html' => $h4Html,
      'href' => $href,
      'titleTextRaw' => $aText,
    ];
  }

  return $out;
}

/**
 * タイトル解析（対象者・証券コードの厳密分離 + 区分上書きロジック）
 * return: array{date:string,category:string,reporter:string,target:string,code:string,url:string,contentDisplay:string,titleText:string}
 */
function buildRecordFromTitle(string $dateStr, string $h4Html, string $href, string $baseUrl): array {
  $url = '';
  $titleText = '';

  if (preg_match('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i', $h4Html, $aMatch)) {
    $url = $baseUrl . (string)($aMatch[1] ?? '');
    $titleText = trim(stripTags((string)($aMatch[2] ?? '')));
  } else {
    $titleText = trim(stripTags($h4Html));
  }

  // 初期カテゴリー（<span class="diffflag">）
  $category = '';
  if (preg_match('/<span\s+class=["\']diffflag[^"\']*["\'][^>]*>(.*?)<\/span>/i', $h4Html, $diffMatch)) {
    $category = trim(stripTags((string)($diffMatch[1] ?? '')));
  }

  // 報告者/対象者/証券コード
  $splitGa = explode('が', $titleText);
  $reporter = trim((string)($splitGa[0] ?? ''));
  $afterGa = trim(implode('が', array_slice($splitGa, 1)));

  // 例: 株式会社Birdman＜7063＞株式の変更報告書を提出（保有減少）
  $target = '';
  $code = '';
  $tailAfterCode = '';

  if (preg_match('/(.+?)[＜<]\s*([A-Za-z0-9]{4})\s*[＞>]/u', $afterGa, $m, PREG_OFFSET_CAPTURE)) {
    $target = trim((string)($m[1][0] ?? ''));
    $code   = trim((string)($m[2][0] ?? ''));
    $startTail = (int)$m[0][1] + strlen((string)$m[0][0]);
    $tailAfterCode = trim(substr($afterGa, $startTail));
  } else {
    // フォールバック：4桁英数を拾う
    if (preg_match('/([A-Za-z0-9]{4})/u', $afterGa, $cf, PREG_OFFSET_CAPTURE)) {
      $code = (string)($cf[1][0] ?? '');
      $idx  = (int)($cf[1][1] ?? 0);
      $target = trim(preg_replace('/[＜<]\s*$/u', '', substr($afterGa, 0, $idx)) ?? '');
      $tailAfterCode = trim(substr($afterGa, $idx + strlen($code)));
    } else {
      $target = trim($afterGa);
      $tailAfterCode = '';
    }
  }

  // 内容: コード以降を「の」で分割し後半
  $contentText = '';
  if ($tailAfterCode !== '') {
    $splitNo = explode('の', $tailAfterCode);
    $contentText = trim(count($splitNo) >= 2 ? implode('の', array_slice($splitNo, 1)) : $tailAfterCode);
  }

  // ▼ 区分の上書き＆内容からラベル削除
  if (mb_strpos($contentText, '（買い増し）') !== false) {
    $category = '買い増し';
    $contentText = trim(str_replace('（買い増し）', '', $contentText));
  } elseif (mb_strpos($contentText, '（保有減少）') !== false) {
    $category = '保有減少';
    $contentText = trim(str_replace('（保有減少）', '', $contentText));
  }

  return [
    'date' => $dateStr,
    'category' => $category,
    'reporter' => $reporter,
    'target' => $target,
    'code' => $code,
    'url' => $url,
    'contentDisplay' => $contentText,
    'titleText' => $titleText,
  ];
}

function stripTags(string $html): string {
  $noTags = preg_replace('/<[^>]*>/', '', $html);
  $noTags = html_entity_decode((string)$noTags, ENT_QUOTES, 'UTF-8');
  $noTags = preg_replace('/\s+/', ' ', (string)$noTags);
  return trim((string)$noTags);
}

function ymdToTs(string $ymd): int {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return 0;
  return (int)strtotime($ymd . ' 00:00:00');
}

function compareYmd(string $a, string $b): int {
  $ta = ymdToTs($a);
  $tb = ymdToTs($b);
  if ($ta === 0 || $tb === 0) return 0;
  if ($ta === $tb) return 0;
  return ($ta > $tb) ? 1 : -1;
}

/**
 * CSV: [日付, 区分, 報告者, 対象者, 証券コード, 内容]
 * 内容はURLがあれば HYPERLINK 形式（Google Sheets化後にリンク化）
 */
function writeCsv(string $csvPath, array $records): void {
  $fp = fopen($csvPath, 'wb');
  if (!$fp) throw new RuntimeException("CSV作成に失敗: {$csvPath}");

  // Excel向けにBOM
  fwrite($fp, "\xEF\xBB\xBF");

  fputcsv($fp, ['日付','区分','報告者','対象者','証券コード','内容']);

  foreach ($records as $r) {
    $url = (string)($r['url'] ?? '');
    $disp = (string)($r['contentDisplay'] ?? '');
    $title = (string)($r['titleText'] ?? '');

    $textForLink = $disp !== '' ? $disp : $title;
    $cell = $textForLink;

    if ($url !== '') {
      // CSV内で " を "" に
      $u = str_replace('"', '""', $url);
      $t = str_replace('"', '""', $textForLink);
      $cell = '=HYPERLINK("' . $u . '","' . $t . '")';
    }

    fputcsv($fp, [
      (string)($r['date'] ?? ''),
      (string)($r['category'] ?? ''),
      (string)($r['reporter'] ?? ''),
      (string)($r['target'] ?? ''),
      (string)($r['code'] ?? ''),
      $cell,
    ]);
  }

  fclose($fp);
}

/** キーワードヒット（URLでユニーク化） */
function buildKeywordMatches(array $records, array $keywords): array {
  $hits = [];
  foreach ($records as $r) {
    $titleRaw = (string)($r['titleText'] ?? '');
    $title = normalizeForKeyword($titleRaw);

    foreach ($keywords as $kwRaw) {
      $kwRaw = (string)$kwRaw;
      if ($kwRaw === '') continue;

      $kw = normalizeForKeyword($kwRaw);
      if ($kw === '') continue;

      if (mb_strpos($title, $kw) !== false) {
        $hits[] = ['url' => (string)($r['url'] ?? ''), 'title' => $titleRaw];
        break;
      }
    }
  }

  // URLでユニーク化
  $seen = [];
  $uniq = [];
  foreach ($hits as $h) {
    $u = (string)($h['url'] ?? '');
    if ($u === '') continue;
    if (isset($seen[$u])) continue;
    $seen[$u] = true;
    $uniq[] = $h;
  }
  return $uniq;
}


function normalizeForKeyword(string $s): string {
  $s = trim($s);
  // あらゆる空白（半角/全角スペース、タブ、改行）を除去
  $s = preg_replace('/[\s　]+/u', '', $s);
  return $s ?? '';
}

