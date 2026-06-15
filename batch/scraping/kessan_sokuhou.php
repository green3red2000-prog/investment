<?php
declare(strict_types=1);

/**
 * 決算速報（株探ニューススクレイピング PHP版）
 * - Webshare Proxy（lib側で複数持ち、毎回ランダム）
 * - 出力: /opt/invest/scraping/tmp
 *   - 決算速報_yyyy-MM-dd.csv
 *   - 決算速報_メッセージ_yyyy-MM-dd.txt
 * - Driveへアップロード（CSVはGoogleスプレッドシート化）→ 成功後ローカル削除
 */

require __DIR__ . '/lib/scraping_common.php';

http_session_begin(true);

// ===== 設定項目 =====
date_default_timezone_set('Asia/Tokyo');

$JOB_NAME    = '決算速報';
$TARGET_MMDD = '';                 // 空なら前日 (例: "05/24")
$INTERVAL_US = 2500000;            // 2.5秒 (マイクロ秒)
$MAX_PAGES   = 50;

$KEYWORDS = [
  "最高益","上方修正","下方修正","一転黒字","一転赤字",
  "黒字浮上","赤字転落","赤字縮小","赤字拡大","増益",
  "減益","増額修正","減額修正"
];

// ===== 日付決定 =====
$effectiveTarget = getEffectiveTargetMMDD($TARGET_MMDD);
$targetISODate   = getEffectiveTargetISODate($TARGET_MMDD);

// 出力パス（共通関数）
$paths   = build_output_paths($JOB_NAME, $targetISODate);
$csvPath = $paths['csv'];
$txtPath = $paths['txt'];

// ===== メイン処理 =====
$baseUrl = 'https://kabutan.jp/news/?page=';

$counts = array_fill_keys(array_merge($KEYWORDS, ["ー"]), 0);
$rows   = [];
$stop   = false;

echo "対象日: {$effectiveTarget} の調査を開始します...\n";

for ($page = 1; $page <= $MAX_PAGES && !$stop; $page++) {
  echo "Page {$page} 取得中...\n";
  $url = $baseUrl . $page;

  try {
    // Webshare proxy経由（ランダム選択 + 圧縮）
    $html = http_get_text($url);
  } catch (Throwable $e) {
    fwrite(STDERR, "[WARN] page={$page} fetch failed: {$e->getMessage()}\n");
    usleep($INTERVAL_US);
    continue;
  }

  // テーブル行らしき<tr>を拾う（start/end tagに依存しない）
  if (!preg_match_all('/<tr[\s\S]*?<\/tr>/i', $html, $trMatches)) {
    usleep($INTERVAL_US);
    continue;
  }

  foreach ($trMatches[0] as $tr) {
    // datetime (ISO) を拾う
    if (!preg_match('/<time[^>]*datetime="([^"]+)"/i', $tr, $dt)) {
      continue;
    }

    $iso  = $dt[1];
    $mmdd = toMMDD($iso);
    $hhmm = toHHMM($iso);

    $cmp = compareMMDD($mmdd, $effectiveTarget);

    if ($cmp > 0) {
      // 対象日より新しい → まだ続く
      continue;

    } elseif ($cmp === 0) {
      // 対象日 → 取得対象
      // 証券コード
      preg_match('/<td[^>]*\bdata-code="([A-Za-z0-9]+)"/i', $tr, $codeMatch);
      $code = $codeMatch[1] ?? '';
      
      // ★追加：コードが無い行（指数/見出し行など）は除外
      if ($code === '') {
        continue;
      }

      // 見出し（リンクテキスト）
      // ※複数リンクがある場合があるので、最初に「それっぽい」aタグを拾う
      if (!preg_match('/<a[^>]*>([\s\S]*?)<\/a>/i', $tr, $aMatch)) {
        continue;
      }

      $title = cleanHtmlText($aMatch[1]);
      if ($title === '') continue;

      $split = splitTitleCompanyBody($title);
      $klass = classify($split['body'], $KEYWORDS);

      $counts[$klass]++;

      $rows[] = [
        'date'    => $mmdd,
        'time'    => $hhmm,
        'code'    => $code,
        'company' => $split['company'],
        'body'    => $split['body'],
        'class'   => $klass,
      ];

    } else {
      // 対象日より古い日付に到達 → 以降は打ち切り
      $stop = true;
      break;
    }
  }

  if (!$stop) usleep($INTERVAL_US);
}

// ===== CSV出力（tmp配下）=====
writeCsv($csvPath, $rows);

// ===== メッセージTXT出力（tmp配下）=====
$summaryLines = buildSummaryLines($counts); // ここで「ー」→「その他」
$subjectLine  = "{$JOB_NAME}：{$effectiveTarget}";
$body =
  "決算サマリ：\n" .
  (count($summaryLines) ? implode("\n", $summaryLines) : "該当なし") .
  "\n";

write_message_txt($txtPath, $subjectLine, $body);

echo "ローカル出力完了:\n";
echo "- {$csvPath}\n";
echo "- {$txtPath}\n";

// ===== Driveへアップロード → ローカル削除 =====
upload_outputs_and_cleanup($JOB_NAME, $targetISODate, $csvPath, $txtPath);

// =====================
// 関数群
// =====================

function getEffectiveTargetMMDD(string $target): string {
  $t = trim($target);
  if ($t !== '') return $t;
  return date('m/d', strtotime('-1 day'));
}

function getEffectiveTargetISODate(string $targetMMDD): string {
  $t = trim($targetMMDD);
  if ($t !== '') {
    [$m, $d] = explode('/', $t);
    return date('Y') . '-' . sprintf('%02d-%02d', (int)$m, (int)$d);
  }
  return date('Y-m-d', strtotime('-1 day'));
}

function toMMDD(string $iso): string {
  return date('m/d', strtotime($iso));
}

function toHHMM(string $iso): string {
  return date('H:i', strtotime($iso));
}

function compareMMDD(string $a, string $b): int {
  if ($a === $b) return 0;
  return (strtotime("2000/$a") > strtotime("2000/$b")) ? 1 : -1;
}

function cleanHtmlText(string $s): string {
  $s = preg_replace('/<[^>]+>/', '', $s);
  $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
  return trim(preg_replace('/\s+/', ' ', $s));
}

function splitTitleCompanyBody(string $title): array {
  $seps = ['、', '：', ':', '／', '/', ' - ', '-'];
  foreach ($seps as $sep) {
    $idx = mb_strpos($title, $sep);
    if ($idx !== false) {
      return [
        'company' => trim(mb_substr($title, 0, $idx)),
        'body'    => trim(mb_substr($title, $idx + mb_strlen($sep))),
      ];
    }
  }
  return ['company' => '', 'body' => $title];
}

function classify(string $text, array $keywords): string {
  foreach ($keywords as $k) {
    if (mb_strpos($text, $k) !== false) return $k;
  }
  return 'ー';
}

function buildSummaryLines(array $counts): array {
  $lines = [];
  foreach ($counts as $k => $c) {
    if ((int)$c <= 0) continue;
    $label = ($k === 'ー') ? 'その他' : (string)$k;
    $lines[] = "{$label}{$c}件";
  }
  return $lines;
}

function writeCsv(string $csvPath, array $rows): void {
  $fp = fopen($csvPath, 'wb');
  if (!$fp) {
    throw new RuntimeException("CSV作成に失敗: {$csvPath}");
  }

  // Excel向けにBOM
  fwrite($fp, "\xEF\xBB\xBF");

  // 見出し（固定）
  fputcsv($fp, ['日付', '時刻', '証券コード', '会社名', '速報内容', '分類']);

  foreach ($rows as $row) {
    fputcsv($fp, [
      $row['date'] ?? '',
      $row['time'] ?? '',
      $row['code'] ?? '',
      $row['company'] ?? '',
      $row['body'] ?? '',
      $row['class'] ?? '',
    ]);
  }

  fclose($fp);
}
