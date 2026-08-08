<?php
/**
 * 市況関連データ抽出（PHP）
 *
 * 保存済みHTML、またはWebから取得したHTMLを読み込み、
 * 処理対象ページごとの専用処理で市況関連データを抽出してTXTへ出力する。
 *
 * 現在の実装対象:
 *   tosho_sector_index：東証業種別指数
 *   nikkei225_valuation：日経225バリュエーション
 *
 * 実行例:
 *   php market_data_extract.php
 *   php market_data_extract.php --date=2026-07-31
 *   php market_data_extract.php --target=tosho_sector_index
 *   php market_data_extract.php --source=file --target=tosho_sector_index --noupload
 *   php market_data_extract.php --source=web --target=tosho_sector_index --noupload
 *   php market_data_extract.php --source=file --target=nikkei225_valuation --noupload
 *   php market_data_extract.php --source=web --target=nikkei225_valuation --noupload
 *   php market_data_extract.php --target=ALL --source=file
 *   php market_data_extract.php --target=GROUP1 --source=web
 */

// ===== 依存（共通ライブラリ）=====
require __DIR__ . '/lib/scraping_common.php';
require __DIR__ . '/mde_tosho_sector_index.php';
require __DIR__ . '/mde_nikkei225_valuation.php';

// ===== 設定 =====
date_default_timezone_set('Asia/Tokyo');

$JOB_NAME = '市況関連データ抽出';
$DATA_BASE_DIR = '/opt/invest/scraping/data';
$TMP_DIR = '/opt/invest/scraping/tmp';

/**
 * 処理対象定義
 *
 * implemented=false の対象は、今後専用処理を追加した時点でtrueへ変更する。
 */
$TARGET_DEFINITIONS = array(
  'tosho_sector_index' => array(
    'name' => '東証業種別指数',
    'url' => 'https://nikkei225jp.com/chart/gyoushu.php',
    'file' => '09_extract_01_tosho_sector_index.html',
    'groups' => array(),
    'implemented' => true,
  ),
  'nikkei225_valuation' => array(
    'name' => '日経225バリュエーション',
    'url' => 'https://nikkei225jp.com/data/per.php',
    'file' => '09_extract_02_nikkei225_valuation.html',
    'groups' => array(),
    'implemented' => true,
  ),
  'advance_decline_ratio' => array(
    'name' => '騰落レシオ',
    'url' => 'https://nikkei225jp.com/data/touraku.php',
    'file' => '09_extract_03_advance_decline_ratio.html',
    'groups' => array(),
    'implemented' => false,
  ),
  'short_selling_ratio' => array(
    'name' => '空売り比率',
    'url' => 'https://nikkei225jp.com/data/karauri.php',
    'file' => '09_extract_04_short_selling_ratio.html',
    'groups' => array(),
    'implemented' => false,
  ),
  'nikkei225_contribution' => array(
    'name' => '日経225寄与度',
    'url' => 'https://nikkei225jp.com/chart/nikkei.php',
    'file' => '09_extract_05_nikkei225_contribution.html',
    'groups' => array(),
    'implemented' => false,
  ),
  'volatility_index' => array(
    'name' => '恐怖指数',
    'url' => 'https://nikkei225jp.com/data/vix.php',
    'file' => '09_extract_06_volatility_index.html',
    'groups' => array('GROUP1'),
    'implemented' => false,
  ),
  'government_bond_yield' => array(
    'name' => '国債利回り',
    'url' => 'https://nikkei225jp.com/bond/',
    'file' => '09_extract_07_government_bond_yield.html',
    'groups' => array('GROUP1'),
    'implemented' => false,
  ),
  'us_market_valuation' => array(
    'name' => '米国株バリュエーション',
    'url' => 'https://nikkei225jp.com/data/us_per.php',
    'file' => '09_extract_08_us_market_valuation.html',
    'groups' => array('GROUP1'),
    'implemented' => false,
  ),
  'economic_schedule' => array(
    'name' => '経済スケジュール',
    'url' => 'https://nikkei225jp.com/schedule/',
    'file' => '09_extract_09_economic_schedule.html',
    'groups' => array('GROUP1'),
    'implemented' => false,
  ),
);

// ===== 起動引数 =====
$args = array_slice($argv, 1);

$targetYmd = date('Y-m-d');
$target = 'ALL';
$source = 'file';
$noUpload = false;

$dateSpecified = false;
$targetSpecified = false;
$sourceSpecified = false;

$usage =
  "使用方法: php market_data_extract.php " .
  "[--date=YYYY-MM-DD] " .
  "[--target=ALL|GROUP1|識別名] " .
  "[--source=web|file] " .
  "[--noupload]\n";

foreach ($args as $arg) {
  $arg = trim((string)$arg);

  if (strpos($arg, '--date=') === 0) {
    if ($dateSpecified) {
      fail_usage_("--dateが重複して指定されています。", $usage);
    }

    $dateRaw = substr($arg, strlen('--date='));
    $dt = DateTime::createFromFormat('!Y-m-d', $dateRaw);
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
      $dt->format('Y-m-d') !== $dateRaw;

    if ($invalidDate) {
      fail_usage_("日付の形式が不正です: {$dateRaw}", $usage);
    }

    $targetYmd = $dt->format('Y-m-d');
    $dateSpecified = true;
    continue;
  }

  if (strpos($arg, '--target=') === 0) {
    if ($targetSpecified) {
      fail_usage_("--targetが重複して指定されています。", $usage);
    }

    $targetRaw = trim(substr($arg, strlen('--target=')));

    $isSpecialTarget =
      in_array(
        $targetRaw,
        array('ALL', 'GROUP1'),
        true
      );

    $isRegisteredTarget =
      isset($TARGET_DEFINITIONS[$targetRaw]);

    if (
      $targetRaw === '' ||
      (!$isSpecialTarget && !$isRegisteredTarget)
    ) {
      fail_usage_("targetの指定が不正です: {$targetRaw}", $usage);
    }

    $target = $targetRaw;
    $targetSpecified = true;
    continue;
  }

  if (strpos($arg, '--source=') === 0) {
    if ($sourceSpecified) {
      fail_usage_("--sourceが重複して指定されています。", $usage);
    }

    $sourceRaw = trim(substr($arg, strlen('--source=')));

    if (!in_array($sourceRaw, array('web', 'file'), true)) {
      fail_usage_("sourceの指定が不正です: {$sourceRaw}", $usage);
    }

    $source = $sourceRaw;
    $sourceSpecified = true;
    continue;
  }

  if ($arg === '--noupload') {
    if ($noUpload) {
      fail_usage_("--nouploadが重複して指定されています。", $usage);
    }

    $noUpload = true;
    continue;
  }

  fail_usage_("不明な引数です: {$arg}", $usage);
}

$targetYmd8 = str_replace('-', '', $targetYmd);
$targetYmdSlash = str_replace('-', '/', $targetYmd);
$htmlDir = rtrim($DATA_BASE_DIR, '/') . '/' . $targetYmd8;

// ===== メイン処理 =====
try {
  ensure_dir($TMP_DIR);

  if ($source === 'file' && !is_dir($htmlDir)) {
    throw new RuntimeException(
      "対象HTMLフォルダが存在しません: {$htmlDir}"
    );
  }

  $selectedTargets = select_targets_(
    $TARGET_DEFINITIONS,
    $target
  );

  if (count($selectedTargets) === 0) {
    throw new RuntimeException(
      "実装済みの処理対象がありません。" .
      " target={$target}"
    );
  }

  $isIndividual =
    !in_array(
      $target,
      array('ALL', 'GROUP1'),
      true
    );

  $individualName = $isIndividual
    ? $TARGET_DEFINITIONS[$target]['name']
    : '';

  /*
   * メッセージ本文に記載するジョブ名。
   *
   * 個別実行時は対象日本語名を付加する。
   * ALLおよびGROUP1では従来どおりジョブ名だけとする。
   */
  $outputJobName = $isIndividual
    ? $JOB_NAME . '＃' . $individualName
    : $JOB_NAME;

  /*
   * 出力ファイル名の基礎部分。
   *
   * ALLおよびGROUP1では、実行対象を識別できるように
   * targetの値をファイル名へ付加する。
   *
   * 個別実行時は、従来どおり対象日本語名を使用する。
   */
  $outputFileNameBase = $isIndividual
    ? $JOB_NAME . '＃' . $individualName
    : $JOB_NAME . '_' . $target;

  $messageTxtPath =
    rtrim($TMP_DIR, '/') .
    "/{$outputFileNameBase}_メッセージ_{$targetYmd}.txt";

  $reportTxtPath =
    rtrim($TMP_DIR, '/') .
    "/{$outputFileNameBase}_レポート_{$targetYmd}.txt";

  $reportSections = array();
  $successCount = 0;

  foreach ($selectedTargets as $targetId => $definition) {
    echo
      "[START] target={$targetId}" .
      " source={$source}" .
      "\n";

    $loaded = load_target_html_(
      $targetId,
      $definition,
      $source,
      $htmlDir
    );

    $html = (string)$loaded['html'];
    $proxy = (string)$loaded['proxy'];

    try {
      switch ($targetId) {
        case 'tosho_sector_index':
          $parsed = parse_tosho_sector_index_html_($html);
          $reportSection =
            build_tosho_sector_index_message_($parsed);
          break;

        case 'nikkei225_valuation':
          $parsed = parse_nikkei225_valuation_html_($html);
          $reportSection =
            build_nikkei225_valuation_message_($parsed);
          break;

        default:
          throw new RuntimeException(
            "専用抽出処理が実装されていません: {$targetId}"
          );
      }

    } catch (Throwable $e) {
      if (
        $source === 'web' &&
        $proxy !== ''
      ) {
        remember_url_proxy_failure_(
          (string)$definition['url'],
          $proxy
        );
      }

      throw $e;
    }

    if (
      $source === 'web' &&
      $proxy !== ''
    ) {
      remember_url_proxy_success_(
        (string)$definition['url'],
        $proxy
      );
    }

    $reportSections[] = $reportSection;
    $successCount++;

    echo
      "[OK] target={$targetId}" .
      " html_bytes=" . strlen($html) .
      "\n";
  }

  /*
   * メッセージ本文
   *
   * 実行結果や処理条件などの概要だけを記載する。
   */
  $messageLines = array();
  $messageLines[] = "{$outputJobName}：{$targetYmdSlash}";
  $messageLines[] = '';
  $messageLines[] = "処理を終了しました。";
  $messageLines[] = '';
  $messageLines[] = "対象日: {$targetYmd}";
  $messageLines[] = "ソース: {$source}";

  if ($source === 'file') {
    $messageLines[] = "読込フォルダ: {$htmlDir}";
  }

  $messageLines[] =
    "アップロード: " . ($noUpload ? 'なし' : 'あり');

  $messageLines[] =
    "処理対象数: " . count($selectedTargets) . " 件";

  $messageLines[] =
    "処理件数: {$successCount} 件";

  $messageText =
    implode("\n", $messageLines) . "\n";

  /*
   * レポート本文
   *
   * 各処理対象の専用処理から返された結果を、
   * 空行で区切って連結する。
   */
  $reportText =
    implode("\n\n", $reportSections) . "\n";

  if (
    file_put_contents(
      $messageTxtPath,
      $messageText
    ) === false
  ) {
    throw new RuntimeException(
      "メッセージTXTファイルの出力に失敗しました: " .
      $messageTxtPath
    );
  }

  if (
    file_put_contents(
      $reportTxtPath,
      $reportText
    ) === false
  ) {
    throw new RuntimeException(
      "レポートTXTファイルの出力に失敗しました: " .
      $reportTxtPath
    );
  }

  echo "ローカル出力完了:\n";
  echo "- {$messageTxtPath}\n";
  echo "- {$reportTxtPath}\n";

  if ($noUpload) {
    echo "\n";
    echo "===== メッセージ =====\n";
    echo $messageText;

    echo "\n";
    echo "===== レポート =====\n";
    echo $reportText;

    echo
      "[NOUPLOAD] Driveアップロードをスキップしました。\n";

    echo
      "[NOUPLOAD] ローカルファイルを保持します:\n";

    echo "- {$messageTxtPath}\n";
    echo "- {$reportTxtPath}\n";

  } else {
    upload_txt_files_and_cleanup_(
      array(
        array(
          'path' => $messageTxtPath,
          'drive_name' => basename($messageTxtPath),
        ),
        array(
          'path' => $reportTxtPath,
          'drive_name' => basename($reportTxtPath),
        ),
      )
    );
  }

  echo
    "[OK] {$JOB_NAME} finished." .
    " target_date={$targetYmd}" .
    " source={$source}" .
    " target={$target}" .
    " noupload=" . ($noUpload ? 'yes' : 'no') .
    " count={$successCount}" .
    "\n";

  exit(0);

} catch (Throwable $e) {
  fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
  exit(1);
}

// =======================================================
// 起動引数・対象選択
// =======================================================

function fail_usage_($message, $usage) {
  fwrite(STDERR, "FATAL: {$message}\n");
  fwrite(STDERR, $usage);
  exit(1);
}

function select_targets_($definitions, $target) {
  $selected = array();

  /*
   * ALL：
   * すべての実装済みページを処理対象とする。
   */
  if ($target === 'ALL') {
    foreach ($definitions as $targetId => $definition) {
      if (empty($definition['implemented'])) {
        continue;
      }

      $selected[$targetId] = $definition;
    }

    return $selected;
  }

  /*
   * GROUP1：
   * GROUP1に属するページのうち、
   * 専用抽出処理が実装済みのページを処理対象とする。
   */
  if ($target === 'GROUP1') {
    foreach ($definitions as $targetId => $definition) {
      if (empty($definition['implemented'])) {
        continue;
      }

      $groups =
        isset($definition['groups']) &&
        is_array($definition['groups'])
          ? $definition['groups']
          : array();

      if (in_array('GROUP1', $groups, true)) {
        $selected[$targetId] = $definition;
      }
    }

    return $selected;
  }

  /*
   * 識別名：
   * 指定されたページだけを処理対象とする。
   */
  if (!isset($definitions[$target])) {
    throw new RuntimeException(
      "指定された処理対象が登録されていません: {$target}"
    );
  }

  $definition = $definitions[$target];

  if (empty($definition['implemented'])) {
    throw new RuntimeException(
      "指定された処理対象は未実装です: {$target}"
    );
  }

  $selected[$target] = $definition;

  return $selected;
}

// =======================================================
// HTML取得
// =======================================================

function load_target_html_($targetId, $definition, $source, $htmlDir) {
  if ($source === 'file') {
    $htmlPath =
      rtrim($htmlDir, '/') . '/' . $definition['file'];

    if (!is_file($htmlPath)) {
      throw new RuntimeException(
        "対象HTMLファイルが存在しません: {$htmlPath}"
      );
    }

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

    echo "[FILE] {$targetId} {$htmlPath}\n";

    return [
      'html' => $html,
      'proxy' => '',
    ];
  }

  if (!function_exists('http_get_text_browser_with_meta')) {
    throw new RuntimeException(
      "http_get_text_browser_with_meta()が見つかりません。"
    );
  }

  http_session_begin(true);

  $response = http_get_text_browser_with_meta(
    $definition['url'],
    array(
      'timeout' => 120,
      'retry_max' => 3,
      'retry_sleep_ms' => 3000,
      'allow_http_error' => true,
      'defer_proxy_success' => true,
    )
  );
  
  $proxy = isset($response['proxy'])
    ? (string)$response['proxy']
    : '';

  $html = isset($response['html'])
    ? (string)$response['html']
    : '';

  $httpCode = isset($response['http_code'])
    ? (int)$response['http_code']
    : 0;

  $title = isset($response['title'])
    ? (string)$response['title']
    : '';

  echo
    "[WEB] {$targetId}" .
    " http={$httpCode}" .
    " title={$title}" .
    " url={$definition['url']}" .
    "\n";

  if ($httpCode !== 200) {
  	  
  	if ($proxy !== '') {
      remember_url_proxy_failure_(
        (string)$definition['url'],
        $proxy
      );
    }
  	  
    throw new RuntimeException(
      "HTTPレスポンスコードが200ではありません: {$httpCode}"
    );
  }

  if ($html === '') {
    throw new RuntimeException(
      "Webから取得したHTMLが空です: {$definition['url']}"
    );
  }

  return [
    'html' => $html,
    'proxy' => $proxy,
  ];
}

// =======================================================
// DOM共通処理
// =======================================================

function normalize_text_($value) {
  $value = html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
  $value = str_replace(array("\xC2\xA0", '&nbsp;'), ' ', $value);
  $value = preg_replace('/\s+/u', ' ', $value);
  return trim((string)$value);
}

function load_xpath_($html) {
  libxml_use_internal_errors(true);

  $html = (string)$html;

  if (
    function_exists('mb_detect_encoding') &&
    function_exists('mb_convert_encoding')
  ) {
    $encoding = mb_detect_encoding(
      $html,
      array(
        'UTF-8',
        'SJIS',
        'SJIS-win',
        'EUC-JP',
        'ISO-8859-1',
        'ASCII',
      ),
      true
    );

    if ($encoding && $encoding !== 'UTF-8') {
      $html = mb_convert_encoding($html, 'UTF-8', $encoding);
    }
  }

  if (stripos($html, 'charset=') === false) {
    $html =
      '<meta http-equiv="Content-Type" ' .
      'content="text/html; charset=UTF-8">' .
      $html;
  }

  $html = '<?xml encoding="UTF-8">' . $html;

  $dom = new DOMDocument();
  $loaded = $dom->loadHTML(
    $html,
    LIBXML_NOERROR | LIBXML_NOWARNING
  );

  libxml_clear_errors();

  if (!$loaded) {
    throw new RuntimeException('HTMLのDOM変換に失敗しました。');
  }

  return new DOMXPath($dom);
}

function class_xpath_($className) {
  return
    "contains(concat(' ', normalize-space(@class), ' '), " .
    "' {$className} ')";
}

// =======================================================
// TXTアップロード
// =======================================================

/**
 * 複数のTXTファイルをGoogle Driveへアップロードし、
 * すべてのアップロード成功後にローカルファイルを削除する。
 */
function upload_txt_files_and_cleanup_($files) {
  if (!is_array($files) || count($files) === 0) {
    throw new RuntimeException(
      "アップロード対象TXTが指定されていません。"
    );
  }

  foreach ($files as $file) {
    $txtPath = isset($file['path'])
      ? (string)$file['path']
      : '';

    if ($txtPath === '' || !is_file($txtPath)) {
      throw new RuntimeException(
        "TXTがありません: {$txtPath}"
      );
    }
  }

  if (!function_exists('build_oauth_client_')) {
    throw new RuntimeException(
      "build_oauth_client_()が見つかりません。"
    );
  }

  if (!function_exists('upload_file_with_retry_')) {
    throw new RuntimeException(
      "upload_file_with_retry_()が見つかりません。"
    );
  }

  require_once __DIR__ . '/vendor/autoload.php';

  $client = build_oauth_client_();
  $drive = new Google\Service\Drive($client);

  $folderId = DRIVE_UPLOAD_FOLDER_ID;

  if (
    $folderId === '' ||
    !preg_match('/^[A-Za-z0-9_-]{10,}$/', $folderId)
  ) {
    throw new RuntimeException(
      "Invalid DRIVE_UPLOAD_FOLDER_ID: {$folderId}"
    );
  }

  /*
   * 先にすべてアップロードする。
   * この段階ではローカルファイルを削除しない。
   */
  foreach ($files as $file) {
    $txtPath = (string)$file['path'];

    $driveName =
      isset($file['drive_name']) &&
      (string)$file['drive_name'] !== ''
        ? (string)$file['drive_name']
        : basename($txtPath);

    $created = upload_file_with_retry_(
      $drive,
      $txtPath,
      $driveName,
      'text/plain',
      $folderId
    );

    echo
      "Uploaded TXT: " .
      $created->getName() .
      " (" . $created->getId() . ")\n";
  }

  /*
   * すべてのアップロード成功後に、
   * ローカルファイルを削除する。
   */
  foreach ($files as $file) {
    $txtPath = (string)$file['path'];

    if (!@unlink($txtPath)) {
      throw new RuntimeException(
        "アップロード後のTXT削除に失敗しました: " .
        $txtPath
      );
    }

    echo "Removed local TXT: {$txtPath}\n";
  }

  echo
    "DONE: all TXT files uploaded & local files removed.\n";
}
