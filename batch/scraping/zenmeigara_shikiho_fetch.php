<?php
/**
 * 全銘柄四季報情報取得（PHP）
 *
 * 仕様:
 * - 証券コードマスタ（Drive）を読み、1行ずつ処理（参照のみ：書き込みはしない）
 * - 市場・商品区分が「プライム（内国株式）」「スタンダード（内国株式）」「グロース（内国株式）」のみ対象
 * - https://shikiho.toyokeizai.net/stocks/{code} を http_get_text_browser() で取得してパース
 * - (5)のCSV出力に「更新日」「実行結果」を書き込む（正常/エラー/中断）
 * - CSV/TXT を /opt/invest/scraping/tmp に出力（CSVはUTF-8 BOM付き）
 * - Driveへアップロード後、ローカル削除（upload_outputs_and_cleanup を使用）
 *
 * cron想定: 毎週金曜 18:00
 */

// ===== 依存（あなたの環境の共通ライブラリ）=====
require __DIR__ . '/lib/scraping_common.php';

// ===== 設定 =====
date_default_timezone_set('Asia/Tokyo');

$JOB_NAME = '全銘柄四季報情報取得';

$FOLDER_MASTER = array('投資','プログラミング','GAS','マスタ');   // 読み込み元（参照のみ）
$MASTER_FILE_NAME = '証券コードマスタ';                            // スプレッドシート名

// シート名は固定でも良いが、環境差があるので「1枚目のシート名を動的取得」する実装
$MASTER_SHEET_NAME_FIXED = ''; // 例: '証券コードマスタ'。空なら1枚目を使う。

$TMP_DIR = '/opt/invest/scraping/tmp';

// 対象市場
$TARGET_MARKETS = array(
  'プライム（内国株式）',
  'スタンダード（内国株式）',
  'グロース（内国株式）',
);

// 次の銘柄までの待機（秒）※揺らぎ
$SLEEP_SEC_MIN = 15;
$SLEEP_SEC_MAX = 20;

// ===== 出力ファイル名 =====
$todayYmd = date('Y-m-d');
$todayYmdSlash = date('Y/m/d');

$csvPath = rtrim($TMP_DIR, '/')."/{$JOB_NAME}_{$todayYmd}.csv";
$txtPath = rtrim($TMP_DIR, '/')."/{$JOB_NAME}_メッセージ_{$todayYmd}.txt";

// ===== テスト用 =====
define('TEST_MODE', false);   // true: テスト / false: 本番
define('TEST_LIMIT', 3);     // 実処理する銘柄数（スキップ除外）

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
 * 四季報ページHTMLから必要項目を抽出
 */
function parse_shikiho_html($html) {
  $xp = dom_load_xpath($html);

  // 決算発表予定日
  $planned = '';
  $n = $xp->query("//div[contains(@class,'planned-disclosure-date')]//span[contains(@class,'date')]");
  if ($n && $n->length > 0) $planned = norm_ws($n->item(0)->textContent);

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

/**
 * Sheets値取得（A1レンジ）
 */
function sheets_get_values_($sheets, $spreadsheetId, $rangeA1) {
  $resp = $sheets->spreadsheets_values->get($spreadsheetId, $rangeA1);
  $values = $resp->getValues();
  return is_array($values) ? $values : array();
}

/**
 * 指定シートの1枚目タイトル取得
 */
function get_first_sheet_title_($sheets, $spreadsheetId) {
  $ss = $sheets->spreadsheets->get($spreadsheetId);
  $sheetsArr = $ss->getSheets();
  if (!is_array($sheetsArr) || count($sheetsArr) === 0) return '';
  $sheet0 = $sheetsArr[0];
  if (!$sheet0) return '';
  $p = $sheet0->getProperties();
  return $p ? (string)$p->getTitle() : '';
}

function find_spreadsheet_file_id_by_name_($drive, $folderId, $fileName) {
  $q = sprintf(
    "name = '%s' and '%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.spreadsheet'",
    str_replace("'", "\\'", $fileName),
    $folderId
  );
  $res = $drive->files->listFiles(array(
    'q' => $q,
    'fields' => 'files(id,name)',
    'pageSize' => 10,
  ));
  $files = $res->getFiles();
  if (!$files || count($files) === 0) return null;
  return $files[0]->getId();
}

// ===== メイン処理 =====
try {
  // tmp dir
  ensure_dir($TMP_DIR);

  // proxy session
  http_session_begin(false);

  // Google API
  require_once __DIR__ . '/vendor/autoload.php';

  $client = build_oauth_client_(); // scraping_common.php
  $drive  = new Google\Service\Drive($client);
  $sheets = new Google\Service\Sheets($client);

  // Drive: folder -> fileId
  $folderId = resolve_folder_id_by_path_($drive, $FOLDER_MASTER);
  $fileId = find_spreadsheet_file_id_by_name_($drive, $folderId, $MASTER_FILE_NAME);
  if ($fileId === null) throw new RuntimeException("マスタスプレッドシートが見つかりません: {$MASTER_FILE_NAME}");

  // Sheet title
  $sheetTitle = $MASTER_SHEET_NAME_FIXED;
  if ($sheetTitle === '' || $sheetTitle === null) {
    $sheetTitle = get_first_sheet_title_($sheets, $fileId);
  }
  if ($sheetTitle === '') throw new RuntimeException("マスタのシート名取得に失敗");

  // 1行目（ヘッダ）
  $headerRange = $sheetTitle . "!A1:ZZ1";
  $headerRows = sheets_get_values_($sheets, $fileId, $headerRange);
  $headers = array();
  if (isset($headerRows[0]) && is_array($headerRows[0])) $headers = $headerRows[0];
  if (count($headers) === 0) throw new RuntimeException("ヘッダ行が取得できませんでした: {$headerRange}");

  // 列名→index（参照専用）
  $col = array();
  for ($i=0; $i<count($headers); $i++) {
    $name = trim((string)$headers[$i]);
    if ($name !== '') $col[$name] = $i;
  }

  // 必須列（入力）
  $needCols = array('証券コード', '市場・商品区分');
  foreach ($needCols as $nc) {
    if (!isset($col[$nc])) throw new RuntimeException("必須列が見つかりません: {$nc}");
  }

  // データ範囲（2行目以降）
  $dataRange = $sheetTitle . "!A2:ZZ";
  $rows = sheets_get_values_($sheets, $fileId, $dataRange);

  // counters
  $testProcessed = 0;   // 実処理数（スキップ除外）
  $totalTargets = 0;    // 対象市場で実処理した銘柄数（テストなら最大TEST_LIMIT）
  $okCount = 0;         // 正常
  $skipCount = 0;       // 市場対象外スキップ
  $errCount = 0;        // 正常以外（四季報読み込み失敗 / 処理中断）

  // CSV出力用
  $csvOut = array();
  $csvOut[] = array(
    '証券コード',
    '更新日',
    '実行結果',
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
  // (3)(4) メインループ
  // -------------------------------
  for ($r=0; $r<count($rows); $r++) {
    $row = $rows[$r];
    if (!is_array($row)) $row = array();

    $code = isset($row[$col['証券コード']]) ? trim((string)$row[$col['証券コード']]) : '';
    if ($code === '') break; // 空行で終了
    
    // ---- テストモード：実処理数制限（スキップ除外） ----
    if (TEST_MODE && $testProcessed >= TEST_LIMIT) {
      echo "[TEST] limit reached ({$testProcessed}). stop loop.\n";
      break;
    }
    
    $totalTargets++;

    $market = isset($row[$col['市場・商品区分']]) ? trim((string)$row[$col['市場・商品区分']]) : '';

    $updateDate = $todayYmd; // yyyy-MM-dd
    $result = '';
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

    // 対象市場チェック（スキップ）
    if (!in_array($market, $TARGET_MARKETS, true)) {
      $skipCount++;
      $result = 'スキップ（市場対象外）';

      // CSV追記（スキップも残す）
      $csvOut[] = array($code, $updateDate, $result, '', '', '', '', '', '', '', '', '', '');

      continue;
    }

    // 四季報アクセス
    try {
      if (!function_exists('http_get_text_browser')) {
        throw new RuntimeException("http_get_text_browser() が見つかりません（scraping_common.php を確認）");
      }

      $url = 'https://shikiho.toyokeizai.net/stocks/' . rawurlencode($code);
      echo "[HTTP] {$code} {$url}\n";

      $html = http_get_text_browser($url, [
         'timeout' => 90,
       ]);
      $parsed = parse_shikiho_html($html);
      
      
      // 取得HTMLが“それっぽい”のに、主要項目が全部空ならブロック/未生成DOMの可能性が高いので失敗扱い
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
        // デバッグ保存は任意（デフォルトOFF）
        $dbg = '';
        // $dbg = $TMP_DIR . '/shikiho_empty_' . $code . '_' . date('Ymd_His') . '.html';
        // @file_put_contents($dbg, $html);
        $suffix = ($dbg !== '') ? " (saved={$dbg})" : "";
        throw new RuntimeException("parsed empty or blocked html{$suffix}");
      }

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
      fwrite(STDERR, "[ERR] code={$code} " . $e->getMessage() . "\n");
    }

    $testProcessed++;

    // CSV追記（★更新日/実行結果はCSVにのみ書く）
    $csvOut[] = array(
      $code,
      $updateDate,
      $result,
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

    $sec = random_int($SLEEP_SEC_MIN, $SLEEP_SEC_MAX) + (random_int(0, 1000) / 1000);
    usleep((int)($sec * 1_000_000));
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
  // (6) TXT出力
  // -------------------------------
  $lines = array();
  $lines[] = "{$JOB_NAME}：{$todayYmdSlash}";
  $lines[] = "";
  $lines[] = "処理を終了しました。";
  $lines[] = "";
  $lines[] = "銘柄数: {$totalTargets} 件";
  $lines[] = "処理件数: {$okCount} 件";
  $lines[] = "スキップ件数: {$skipCount} 件";
  $lines[] = "エラー : {$errCount} 件";

  // 407等でエラーになったプロキシ一覧（scraping_common.php が収集）
  if (function_exists('get_bad_proxies')) {
    $bad = get_bad_proxies();
    if (is_array($bad) && count($bad) > 0) {
      $lines[] = "";
      $lines[] = "エラーになったプロキシ一覧（host:port）:";
      foreach ($bad as $hp) $lines[] = $hp;
    }
  }

  file_put_contents($txtPath, implode("\n", $lines) . "\n");

  echo "ローカル出力完了:\n- {$csvPath}\n- {$txtPath}\n";

  // -------------------------------
  // (7) Driveアップロード → ローカル削除
  // -------------------------------
  if (!function_exists('upload_outputs_and_cleanup')) {
    throw new RuntimeException("upload_outputs_and_cleanup() が見つかりません（scraping_common.php を確認）");
  }
  upload_outputs_and_cleanup($JOB_NAME, $todayYmd, $csvPath, $txtPath);

  echo "[OK] {$JOB_NAME} finished. targets={$totalTargets} ok={$okCount} skip={$skipCount} err={$errCount}\n";
  exit(0);

} catch (Exception $e) {
  fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
  exit(1);
}
