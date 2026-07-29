<?php
 /**
  * 全銘柄四季報情報取得 / 四季報情報更新監視（PHP）
  *
  * 仕様:
  * - 対象日のHTML保存フォルダから、保存済みの四季報HTMLを読み込んでパースする
  *
  * - 実行モード
  *     ・--mode=get
  *         全銘柄の四季報情報を取得する
  *         通常実行時はCSV/TXTをDriveへアップロードする
  *
  *     ・--mode=check
  *         四季報情報の更新有無を監視する
  *         前回取得したCSVと今回取得したCSVを比較する
  *         --noupload指定がない場合はCSV/TXTをDriveへアップロードする
  *         比較処理が正常終了した場合のみ、今回のCSVを前回取得内容として保存する
  *
  * - HTML保存先
  *     /opt/invest/scraping/data/YYYYMMDD
  *
  * - HTMLファイル名
  *     08_shikiho_XXXX_market_price.html
  *     ※ XXXXは証券コード4桁
  *
  * - HTMLファイルの扱い
  *     ・処理済みHTMLは削除しない
  *     ・同一実行中に処理済みとなったHTMLは再処理しない
  *     ・未処理の対象HTMLがなくなった時点で処理を終了する
  *
  * - 出力
  *     ・CSV/TXTを /opt/invest/scraping/tmp に出力
  *     ・CSVはUTF-8 BOM付き
  *     ・CSVには「四季報更新日」「実行結果」「データ更新時点」を出力する
  *     ・--mode=checkの場合、出力ファイル名のプレフィックスを
  *       「四季報情報更新監視」とする
  *
  * - 更新監視用CSV
  *     /opt/invest/scraping/state/shikiho_check_last.csv
  *
  * - アップロード
  *     ・--noupload指定がない場合は、modeに関係なくDriveへアップロード後にCSV/TXTを削除する
  *     ・--noupload指定時は、modeに関係なくDriveへアップロードせずローカルに残す
  *
  * - 異常終了
  *     ・modeが指定されていない場合は異常終了する
  *     ・処理中断時はログへFATALを出力して異常終了する
  *
  * 起動方法:
  *
  *   php zenmeigara_shikiho.php --mode=get
  *     → 当日を対象に全銘柄の四季報情報を取得
  *
  *   php zenmeigara_shikiho.php 2026-06-14 --mode=get
  *     → 指定日を対象に全銘柄の四季報情報を取得
  *
  *   php zenmeigara_shikiho.php --mode=get --noupload
  *     → 当日を対象に実行し、CSV/TXTをアップロードしない
  *
  *   php zenmeigara_shikiho.php 2026-06-14 --mode=check
  *     → 指定日を対象に四季報情報の更新有無を監視
  *
  *   php zenmeigara_shikiho.php 2026-06-14 --mode=check --noupload
  *     → 指定日を対象に更新監視を行い、CSV/TXTをアップロードしない  
  *
  *   ※ --mode=get または --mode=check の指定は必須
  *   ※ --force はありません。指定した日付のHTMLフォルダを読み込んで取り込みます
  */

// ===== 依存（あなたの環境の共通ライブラリ）=====
require __DIR__ . '/lib/scraping_common.php';

// ===== 設定 =====
date_default_timezone_set('Asia/Tokyo');

$DATA_BASE_DIR = '/opt/invest/scraping/data';
$TMP_DIR = '/opt/invest/scraping/tmp';
$STATE_DIR = '/opt/invest/scraping/state';

$SHIKIHO_CHECK_STATE_PATH = rtrim($STATE_DIR, '/') . '/shikiho_check_last.csv';

// ===== 実行引数 =====
$args = array_slice($argv, 1);

$targetYmd = date('Y-m-d');
$noUpload = false;
$dateSpecified = false;
$mode = null;

$usage =
  "使用方法: php zenmeigara_shikiho.php "
  . "[YYYY-MM-DD] --mode=get|check [--noupload]\n";

foreach ($args as $arg) {
  $arg = trim((string)$arg);

  // mode
  if (preg_match('/^--mode=(get|check)$/', $arg, $matches)) {
    if ($mode !== null) {
      fwrite(STDERR, "FATAL: --modeが重複して指定されています。\n");
      fwrite(STDERR, $usage);
      exit(1);
    }

    $mode = $matches[1];
    continue;
  }

  // --mode=で始まるが、get/check以外
  if (strpos($arg, '--mode=') === 0) {
    fwrite(STDERR, "FATAL: modeの指定が不正です: {$arg}\n");
    fwrite(STDERR, "modeはgetまたはcheckを指定してください。\n");
    fwrite(STDERR, $usage);
    exit(1);
  }

  // noupload
  if ($arg === '--noupload') {
    if ($noUpload) {
      fwrite(STDERR, "FATAL: --nouploadが重複して指定されています。\n");
      fwrite(STDERR, $usage);
      exit(1);
    }

    $noUpload = true;
    continue;
  }

  // 上記以外のオプションは許可しない
  if (strpos($arg, '--') === 0) {
    fwrite(STDERR, "FATAL: 不明な引数です: {$arg}\n");
    fwrite(STDERR, $usage);
    exit(1);
  }

  // 日付は1つだけ指定可能
  if ($dateSpecified) {
    fwrite(STDERR, "FATAL: 日付が複数指定されています: {$arg}\n");
    fwrite(STDERR, $usage);
    exit(1);
  }

  $dt = DateTime::createFromFormat('!Y-m-d', $arg);
  $errors = DateTime::getLastErrors();

  $invalidDate =
    $dt === false ||
    (
      $errors !== false &&
      (
        $errors['warning_count'] > 0 ||
        $errors['error_count'] > 0
      )
    ) ||
    $dt->format('Y-m-d') !== $arg;

  if ($invalidDate) {
    fwrite(STDERR, "FATAL: 日付の形式が不正です: {$arg}\n");
    fwrite(STDERR, "日付はYYYY-MM-DD形式で指定してください。\n");
    fwrite(STDERR, $usage);
    exit(1);
  }

  $targetYmd = $dt->format('Y-m-d');
  $dateSpecified = true;
}

// modeは必須
if ($mode === null) {
  fwrite(STDERR, "FATAL: modeが指定されていません。\n");
  fwrite(STDERR, $usage);
  exit(1);
}

// modeに応じてジョブ名を切り替える
if ($mode === 'get') {
  $JOB_NAME = '全銘柄四季報情報取得';
} else {
  $JOB_NAME = '四季報情報更新監視';
}

$targetYmd8 = str_replace('-', '', $targetYmd);
$targetYmdSlash = str_replace('-', '/', $targetYmd);

$htmlDir = rtrim($DATA_BASE_DIR, '/') . '/' . $targetYmd8;

// ===== 出力ファイル名 =====
$csvPath =
  rtrim($TMP_DIR, '/') . "/{$JOB_NAME}_{$targetYmd}.csv";

$txtPath =
  rtrim($TMP_DIR, '/') . "/{$JOB_NAME}_メッセージ_{$targetYmd}.txt";

// ===== ユーティリティ =====
function norm_ws($s) {
  $s = (string)$s;
  // ★ HTMLエンティティをUTF-8として復元
  $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
  $s = str_replace(["\xC2\xA0", '&nbsp;'], ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  $s = trim($s);
  return ($s === null) ? '' : $s;
}


function dom_load_xpath($html) {
  libxml_use_internal_errors(true);

  $html = (string)$html;

  // ★ DOMDocument が文字コードを誤認しやすいので、UTF-8 と明示してから読み込む
  // 1) もしUTF-8でないならUTF-8へ変換（mbstringがあれば）
  if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
    $enc = mb_detect_encoding($html, array('UTF-8','SJIS','SJIS-win','EUC-JP','ISO-8859-1','ASCII'), true);
    if ($enc && $enc !== 'UTF-8') {
      $html = mb_convert_encoding($html, 'UTF-8', $enc);
    }
  }

  // 2) DOMにUTF-8と認識させる定番テク（XML宣言＋meta charset）
  //    ※これを入れないと ISO-8859-1 扱いになりやすい
  if (stripos($html, 'charset=') === false) {
    $html = '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . $html;
  }
  $html = '<?xml encoding="UTF-8">' . $html;

  $dom = new DOMDocument();
  $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
  libxml_clear_errors();

  return new DOMXPath($dom);
}


/**
 * <dl> の dt→dd を「見出し文字列（空白除去）」でマップ化
 */
function extract_dl_map($xp, $dlXpath) {
  $map = array();

  $dlNodes = $xp->query($dlXpath);
  if (!$dlNodes || $dlNodes->length === 0) return $map;

  for ($i=0; $i<$dlNodes->length; $i++) {
    $dl = $dlNodes->item($i);
    if (!$dl) continue;

    $dtNodes = $xp->query(".//dt", $dl);
    if (!$dtNodes) continue;

    for ($j=0; $j<$dtNodes->length; $j++) {
      $dt = $dtNodes->item($j);
      if (!$dt) continue;

      $label = $dt->textContent;
      $label = preg_replace('/\s+/u', '', (string)$label);
      $label = trim($label);

      $sib = $dt->nextSibling;
      while ($sib && !($sib instanceof DOMElement)) {
        $sib = $sib->nextSibling;
      }
      if ($sib && $sib->nodeName === 'dd') {
        $val = norm_ws($sib->textContent);
        if ($label !== '') $map[$label] = $val;
      }
    }
  }
  return $map;
}
/**
 * dd要素から「直下のテキストノード」だけを連結して返す
 * （リンク等の子要素テキストを混ぜないため）
 */
function dd_direct_text_($dd) {
  if (!$dd) return '';
  $buf = '';
  if ($dd->hasChildNodes()) {
    foreach ($dd->childNodes as $ch) {
      if ($ch && $ch->nodeType === XML_TEXT_NODE) {
        $buf .= $ch->nodeValue;
      }
    }
  }
  $buf = norm_ws($buf);
  // 直下テキストが空なら textContent にフォールバック
  if ($buf === '') $buf = norm_ws($dd->textContent);
  return $buf;
}

/**
 * CSVファイルを二次元配列として読み込む
 */
function read_csv_file_($path) {
  $fp = fopen($path, 'rb');

  if ($fp === false) {
    throw new RuntimeException(
      "CSVファイルを開けませんでした: {$path}"
    );
  }

  $rows = array();

  try {
    while (($row = fgetcsv($fp)) !== false) {
      // 先頭セルのUTF-8 BOMを除去
      if (count($rows) === 0 && isset($row[0])) {
        $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
      }

      $rows[] = $row;
    }
  } finally {
    fclose($fp);
  }

  if (count($rows) === 0) {
    throw new RuntimeException(
      "CSVファイルが空です: {$path}"
    );
  }

  return $rows;
}


/**
 * CSVの二次元配列を証券コード別の連想配列へ変換する
 */
function csv_rows_to_code_map_($rows) {
  if (!is_array($rows) || count($rows) === 0) {
    throw new RuntimeException(
      "CSVデータがありません。"
    );
  }

  $headers = $rows[0];

  if (!isset($headers[0]) || $headers[0] !== '証券コード') {
    throw new RuntimeException(
      "CSVの先頭列が「証券コード」ではありません。"
    );
  }

  $map = array();
  $headerCount = count($headers);

  for ($i = 1; $i < count($rows); $i++) {
    $row = $rows[$i];

    // 列数が不足している場合は空文字で補完
    if (count($row) < $headerCount) {
      $row = array_pad($row, $headerCount, '');
    }

    // 余分な列がある場合はヘッダー列数までに制限
    if (count($row) > $headerCount) {
      $row = array_slice($row, 0, $headerCount);
    }

    $code = isset($row[0]) ? trim((string)$row[0]) : '';

    if ($code === '') {
      throw new RuntimeException(
        "証券コードが空のCSV行があります。行番号=" . ($i + 1)
      );
    }

    if (isset($map[$code])) {
      throw new RuntimeException(
        "証券コードがCSV内で重複しています: {$code}"
      );
    }

    $values = array();

    for ($j = 0; $j < $headerCount; $j++) {
      $header = (string)$headers[$j];
      $values[$header] = isset($row[$j]) ? (string)$row[$j] : '';
    }

    $map[$code] = $values;
  }

  return array(
    'headers' => $headers,
    'rows' => $map,
  );
}


/**
 * CSVの更新内容をテキスト出力用の配列として作成する
 *
 * 「四季報更新日」は実行ごとに変わるため比較対象外とする。
 */
function build_csv_update_lines_($currentRows, $previousRows) {
  $current = csv_rows_to_code_map_($currentRows);

  if ($previousRows === null) {
    $previous = array(
      'headers' => $current['headers'],
      'rows' => array(),
    );
  } else {
    $previous = csv_rows_to_code_map_($previousRows);
  }

  $ignoreHeaders = array(
    '証券コード',
    '四季報更新日',
  );

  $allCodes = array_unique(
    array_merge(
      array_keys($current['rows']),
      array_keys($previous['rows'])
    )
  );

  sort($allCodes, SORT_STRING);

  $changeLines = array();

  foreach ($allCodes as $code) {
    $hasCurrent = isset($current['rows'][$code]);
    $hasPrevious = isset($previous['rows'][$code]);

    // 今回新たに追加された銘柄
    if ($hasCurrent && !$hasPrevious) {
      $changeLines[] = $code;
      $changeLines[] = "　新規追加";
      $changeLines[] = "";
      continue;
    }

    // 今回のCSVから消えた銘柄
    if (!$hasCurrent && $hasPrevious) {
      $changeLines[] = $code;
      $changeLines[] = "　削除";
      $changeLines[] = "";
      continue;
    }

    $codeLines = array();

    foreach ($current['headers'] as $header) {
      $header = (string)$header;

      if (in_array($header, $ignoreHeaders, true)) {
        continue;
      }

      $oldValue = isset($previous['rows'][$code][$header])
        ? (string)$previous['rows'][$code][$header]
        : '';

      $newValue = isset($current['rows'][$code][$header])
        ? (string)$current['rows'][$code][$header]
        : '';

      if ($oldValue === $newValue) {
        continue;
      }

      $oldDisplay = ($oldValue === '') ? '（空欄）' : $oldValue;
      $newDisplay = ($newValue === '') ? '（空欄）' : $newValue;

      $codeLines[] = "　{$header}";
      $codeLines[] = "　　{$oldDisplay}";
      $codeLines[] = "　　　↓";
      $codeLines[] = "　　{$newDisplay}";
      $codeLines[] = "";
    }

    if (count($codeLines) > 0) {
      $changeLines[] = $code;

      foreach ($codeLines as $line) {
        $changeLines[] = $line;
      }
    }
  }

  if (count($changeLines) === 0) {
    return array(
      'ＣＳＶ更新状況：更新なし',
    );
  }

  // 最後の余分な空行を削除
  while (
    count($changeLines) > 0 &&
    $changeLines[count($changeLines) - 1] === ''
  ) {
    array_pop($changeLines);
  }

  return array_merge(
    array(
      'ＣＳＶ更新状況：',
    ),
    $changeLines
  );
}


/**
 * 更新監視用CSVを一時ファイル経由で安全に保存する
 */
function save_check_state_csv_($sourcePath, $statePath) {
  ensure_dir(dirname($statePath));

  $tmpPath =
    $statePath . '.tmp.' . getmypid();

  if (!copy($sourcePath, $tmpPath)) {
    throw new RuntimeException(
      "更新監視用CSVの一時保存に失敗しました: {$tmpPath}"
    );
  }

  if (!rename($tmpPath, $statePath)) {
    @unlink($tmpPath);

    throw new RuntimeException(
      "更新監視用CSVの更新に失敗しました: {$statePath}"
    );
  }
}
/**
 * 四季報ページHTMLから必要項目を抽出
 */
function parse_shikiho_html($html) {
  $xp = dom_load_xpath($html);

  // データ更新時点
  // 例：2026年3集夏号 時点
  $dataAsOf = '';
  $n = $xp->query(
    "//div[contains(@class,'performance-table__head')]" .
    "//span[contains(@class,'date')]"
  );
  if ($n && $n->length > 0) {
    $dataAsOf = norm_ws($n->item(0)->textContent);
  }

  // 決算発表予定日
  $planned = '';
  $n = $xp->query("//div[contains(@class,'planned-disclosure-date')]//span[contains(@class,'date')]");
  if ($n && $n->length > 0) {
    $planned = norm_ws($n->item(0)->textContent);

    // yyyy/MM/dd → yyyy-MM-dd
    if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $planned)) {
      $dt = DateTime::createFromFormat('!Y/n/j', $planned);
      if ($dt !== false) {
        $planned = $dt->format('Y-m-d');
      }
    }
  }

  // 特色 / 連結事業（ラベル一致ではなく dd の並び順で取得する）
  $feature = '';
  $consol  = '';
  $dds = $xp->query("//dl[contains(@class,'information__list')]/dd");
  if ($dds && $dds->length >= 2) {
    $feature = dd_direct_text_($dds->item(0));
    $consol  = dd_direct_text_($dds->item(1));
  } else {
    // フォールバック（旧方式）
    $infoMap = extract_dl_map($xp, "//dl[contains(@class,'information__list')]");
    $feature = isset($infoMap['特色']) ? $infoMap['特色'] : '';
    $consol  = isset($infoMap['連結事業']) ? $infoMap['連結事業'] : '';
  }
  // 四季報スコア
  $score = '';
  $n = $xp->query("//div[contains(@class,'score__head')]//span[contains(@class,'num')]");
  if ($n && $n->length > 0) $score = norm_ws($n->item(0)->textContent);

  // スコア内訳（ddの出現順で 6個取る：成長性,収益性,安全性,規模,割安度,値上がり）
  $growth = $profit = $safety = $scale = $cheapness = $upside = '';
  $dds2 = $xp->query("//div[contains(@class,'score__chart-wrapper__main')]//dd");
  if ($dds2 && $dds2->length >= 6) {
    $growth    = norm_ws($dds2->item(0)->textContent);
    $profit    = norm_ws($dds2->item(1)->textContent);
    $safety    = norm_ws($dds2->item(2)->textContent);
    $scale     = norm_ws($dds2->item(3)->textContent);
    $cheapness = norm_ws($dds2->item(4)->textContent);
    $upside    = norm_ws($dds2->item(5)->textContent);
  } else {
    // フォールバック（旧方式）
    $scoreMap = extract_dl_map($xp, "//div[contains(@class,'score__chart-wrapper__main')]//dl");
    $growth     = isset($scoreMap['成長性']) ? norm_ws($scoreMap['成長性']) : '';
    $profit     = isset($scoreMap['収益性']) ? norm_ws($scoreMap['収益性']) : '';
    $safety     = isset($scoreMap['安全性']) ? norm_ws($scoreMap['安全性']) : '';
    $scale      = isset($scoreMap['規模'])   ? norm_ws($scoreMap['規模'])   : '';
    $cheapness  = isset($scoreMap['割安度']) ? norm_ws($scoreMap['割安度']) : '';
    $upside     = isset($scoreMap['値上がり']) ? norm_ws($scoreMap['値上がり']) : '';
  }

  return array(
    'data_as_of' => $dataAsOf,
    'planned_disclosure_date' => $planned,
    'feature' => $feature,
    'consolidated_business' => $consol,
    'shikiho_score' => $score,
    'growth' => $growth,
    'profitability' => $profit,
    'safety' => $safety,
    'scale' => $scale,
    'cheapness' => $cheapness,
    'upside' => $upside,
  );
}


// ===== メイン処理 =====
try {
  // tmp dir
  ensure_dir($TMP_DIR);
  
  require_once __DIR__ . '/vendor/autoload.php';

  // 対象HTMLフォルダ確認
  if (!is_dir($htmlDir)) {
    throw new RuntimeException(
      "対象HTMLフォルダが見つかりません: {$htmlDir}"
    );
  }

  echo "[INFO] mode={$mode}\n";
  echo "[INFO] target_date={$targetYmd}\n";
  echo "[INFO] html_dir={$htmlDir}\n";
  echo "[INFO] upload=" . ($noUpload ? 'disabled' : 'enabled') . "\n";

  // counters
  $totalTargets = 0;
  $okCount = 0;
  $errCount = 0;
  
  // 同一実行中に処理済みとなったHTMLファイル
  $processedFiles = array();

  // CSV出力用
  $csvOut = array();
  $csvOut[] = array(
    '証券コード',
    '四季報更新日',
    '実行結果',
    'データ更新時点',
    '決算発表予定日',
    '特色',
    '連結事業',
    '四季報スコア',
    '成長性',
    '収益性',
    '安全性',
    '規模',
    '割安度',
    '値上がり',
  );

  // -------------------------------
  // (3)(4) 保存済みHTML取込
  // -------------------------------
  while (true) {
    $htmlFiles = glob(
      rtrim($htmlDir, '/') . '/08_shikiho_*_market_price.html'
    );
  
    if ($htmlFiles === false) {
      throw new RuntimeException(
        "HTMLファイル一覧の取得に失敗しました: {$htmlDir}"
      );
    }

    // 命名規則に完全一致するファイルだけを処理対象にする
    $targetFiles = array();

    foreach ($htmlFiles as $htmlPath) {
      $fileName = basename($htmlPath);

      if (!preg_match(
        '/^08_shikiho_([0-9]{4})_market_price\.html$/',
        $fileName
      )) {
        continue;
      }

      // 同一実行中に処理済みのHTMLは対象外
      if (isset($processedFiles[$htmlPath])) {
        continue;
      }

      $targetFiles[] = $htmlPath;
    }

    // 未処理の対象HTMLが無くなったら終了
    if (count($targetFiles) === 0) {
      echo "[INFO] 未処理の取り込み対象HTMLが無くなったため終了します。\n";
      break;
    }

    sort($targetFiles, SORT_STRING);

    // 1件ずつ処理する
    $htmlPath = $targetFiles[0];
    $fileName = basename($htmlPath);

    if (!preg_match(
      '/^08_shikiho_([0-9]{4})_market_price\.html$/',
      $fileName,
      $matches
    )) {
      throw new RuntimeException(
        "HTMLファイル名が命名規則に一致しません: {$fileName}"
      );
    }

    $code = $matches[1];

    $totalTargets++;

    $updateDate = $targetYmd;
    $result = '';
    $dataAsOf = '';
    $planned = '';
    $feature = '';
    $consol = '';
    $score = '';
    $growth = '';
    $profit = '';
    $safety = '';
    $scale = '';
    $cheap = '';
    $up = '';

    echo "[HTML] {$code} {$htmlPath}\n";

    try {
      $html = file_get_contents($htmlPath);

      if ($html === false) {
        throw new RuntimeException(
          "HTMLファイルの読み込みに失敗しました: {$htmlPath}"
        );
      }

      if ($html === '') {
        throw new RuntimeException(
          "HTMLファイルが空です: {$htmlPath}"
        );
      }

      $parsed = parse_shikiho_html($html);

      // 主要項目がすべて空なら、取得失敗HTMLとして扱う
      $allEmpty =
        ($parsed['planned_disclosure_date'] === '') &&
        ($parsed['shikiho_score'] === '') &&
        ($parsed['feature'] === '') &&
        ($parsed['consolidated_business'] === '') &&
        ($parsed['growth'] === '') &&
        ($parsed['profitability'] === '') &&
        ($parsed['safety'] === '') &&
        ($parsed['scale'] === '') &&
        ($parsed['cheapness'] === '') &&
        ($parsed['upside'] === '');

      if ($allEmpty) {
        throw new RuntimeException(
          "parsed empty or invalid html"
        );
      }

      $dataAsOf = $parsed['data_as_of'];
      $planned = $parsed['planned_disclosure_date'];
      $feature = $parsed['feature'];
      $consol  = $parsed['consolidated_business'];
      $score   = $parsed['shikiho_score'];
      $growth  = $parsed['growth'];
      $profit  = $parsed['profitability'];
      $safety  = $parsed['safety'];
      $scale   = $parsed['scale'];
      $cheap   = $parsed['cheapness'];
      $up      = $parsed['upside'];

      $result = '正常';
      $okCount++;

    } catch (Exception $e) {
      $result = 'エラー：四季報読み込みに失敗';
      $errCount++;

      fwrite(
        STDERR,
        "[ERR] code={$code} file={$htmlPath} " .
        $e->getMessage() .
        "\n"
      );
    }

    // CSV追記
    $csvOut[] = array(
      $code,
      $updateDate,
      $result,
      $dataAsOf,
      $planned,
      $feature,
      $consol,
      $score,
      $growth,
      $profit,
      $safety,
      $scale,
      $cheap,
      $up,
    );
    
    // 正常・エラーを問わず、同一実行中は処理済みとして再処理しない
    $processedFiles[$htmlPath] = true;
    echo "[PROCESSED] {$htmlPath}\n";

  }

  // -------------------------------
  // (5) CSV出力（UTF-8 BOM）
  // -------------------------------
  $fp = fopen($csvPath, 'wb');
  if (!$fp) throw new RuntimeException("CSV作成に失敗: $csvPath");

  fwrite($fp, "\xEF\xBB\xBF");
  foreach ($csvOut as $line) {
    fputcsv($fp, $line);
  }
  fclose($fp);
  
  // -------------------------------
  // (6) CSV更新内容比較
  // -------------------------------
  $csvUpdateLines = array();

  if ($mode === 'check') {
    // 今回作成したCSVを読み込む
    $currentCsvRows = read_csv_file_($csvPath);

    // 前回CSVが存在する場合のみ読み込む
    $previousCsvRows = null;

    if (is_file($SHIKIHO_CHECK_STATE_PATH)) {
      $previousCsvRows =
        read_csv_file_($SHIKIHO_CHECK_STATE_PATH);
    }

    // 比較結果を作成
    $csvUpdateLines =
      build_csv_update_lines_(
        $currentCsvRows,
        $previousCsvRows
      );
  }

  // -------------------------------
  // (7) TXT出力
  // -------------------------------
  $lines = array();
  $lines[] = "{$JOB_NAME}：{$targetYmdSlash}";
  $lines[] = "";
  $lines[] = "処理を終了しました。";
  $lines[] = "";
  $lines[] = "対象日: {$targetYmd}";
  $lines[] = "読込フォルダ: {$htmlDir}";
  $lines[] = "アップロード: " . ($noUpload ? 'なし' : 'あり');
  $lines[] = "";
  $lines[] = "銘柄数: {$totalTargets} 件";
  $lines[] = "処理件数: {$okCount} 件";
  $lines[] = "エラー : {$errCount} 件";

  if ($mode === 'check') {
    $lines[] = "";

    foreach ($csvUpdateLines as $line) {
      $lines[] = $line;
    }
  }

  $txtResult =
    file_put_contents(
      $txtPath,
      implode("\n", $lines) . "\n"
    );

  if ($txtResult === false) {
    throw new RuntimeException(
      "TXTファイルの出力に失敗しました: {$txtPath}"
    );
  }
  
  // -------------------------------
  // (8) 更新監視用CSV保存
  // -------------------------------
  if ($mode === 'check') {
    if ($errCount === 0) {
      // CSV比較およびTXT出力が正常終了した場合のみ更新する
      save_check_state_csv_(
        $csvPath,
        $SHIKIHO_CHECK_STATE_PATH
      );

      echo "[STATE] 更新監視用CSVを保存しました: "
        . "{$SHIKIHO_CHECK_STATE_PATH}\n";

    } else {
      echo "[STATE] 読み込みエラーがあるため、"
        . "更新監視用CSVの保存をスキップしました。"
        . " err={$errCount}\n";
    }
  }

  echo "ローカル出力完了:\n- {$csvPath}\n- {$txtPath}\n";

  // -------------------------------
  // (9) Driveアップロード → ローカル削除
  // -------------------------------
  if ($noUpload) {
    echo "[NOUPLOAD] Driveアップロードをスキップしました。\n";
    echo "[NOUPLOAD] ローカルファイルを保持します:\n";
    echo "- {$csvPath}\n";
    echo "- {$txtPath}\n";

  } else {
    if (!function_exists('upload_outputs_and_cleanup')) {
      throw new RuntimeException(
        "upload_outputs_and_cleanup() が見つかりません（scraping_common.php を確認）"
      );
    }

    upload_outputs_and_cleanup(
      $JOB_NAME,
      $targetYmd,
      $csvPath,
      $txtPath
    );
  }
  echo "[OK] {$JOB_NAME} finished."
  	. " mode={$mode}"
    . " target_date={$targetYmd}"
    . " noupload=" . ($noUpload ? 'yes' : 'no')
    . " targets={$totalTargets}"
    . " ok={$okCount}"
    . " err={$errCount}"
    . "\n";
  exit(0);

} catch (Exception $e) {
  fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
  exit(1);
}
