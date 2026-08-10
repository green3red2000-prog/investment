
<?php
/**
 * 市況関連データ抽出：日経225寄与度
 *
 * market_data_extract.php から読み込まれ、
 * 日経225寄与度のHTML解析およびレポート生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 *   class_xpath_()
 */

// =======================================================
// 日経225寄与度
// =======================================================

/**
 * 日経225寄与度HTMLを解析する。
 *
 * 「日本225 寄与度ランキング」の表から、
 * 寄与度上位および寄与度下位について、
 * それぞれ先頭5銘柄を取得する。
 *
 * 各銘柄から以下を取得する。
 *
 *   銘柄名
 *   寄与度
 *   現在値
 *   前日比
 */
function parse_nikkei225_contribution_html_($html) {
  $xpath = load_xpath_($html);
   
  /*
   * 更新日を取得する。
   *
   * 日本225の表示日付は、
   * id="T111" 内の class="scol" から MM/DD 形式で取得する。
   *
   * 年はHTML上に存在しないため、現在日時の年を使用する。
   */
  $dateNodes = $xpath->query(
    "//*[@id='T111']//*[" . class_xpath_('scol') . "]"
  );

  if (!$dateNodes || $dateNodes->length !== 1) {
    throw new RuntimeException(
      "日本225の日付が取得できません。"
    );
  }

  $dateText = normalize_text_(
    $dateNodes->item(0)->textContent
  );

  if (
    !preg_match(
      '/^(\d{2})\/(\d{2})$/',
      $dateText,
      $dateMatches
    )
  ) {
    throw new RuntimeException(
      "日本225の日付形式が不正です: " .
      $dateText
    );
  }

  $year = date('Y');

  $month = $dateMatches[1];
  $day = $dateMatches[2];

  if (
    !checkdate(
      (int)$month,
      (int)$day,
      (int)$year
    )
  ) {
    throw new RuntimeException(
      "日本225の日付が不正です: " .
      "{$year}/{$month}/{$day}"
    );
  }

  /*
   * 寄与度ランキングの更新時刻を取得する。
   */
  $timeNodes = $xpath->query(
    "//*[@id='LastTimeT']/time"
  );

  if (!$timeNodes || $timeNodes->length !== 1) {
    throw new RuntimeException(
      "日経225寄与度ランキングの更新時刻が取得できません。"
    );
  }

  $timeText = normalize_text_(
    $timeNodes->item(0)->textContent
  );

  if (
    !preg_match(
      '/^\d{2}:\d{2}$/',
      $timeText
    )
  ) {
    throw new RuntimeException(
      "日経225寄与度ランキングの更新時刻形式が不正です: " .
      $timeText
    );
  }

  /*
   * レポート用更新日時。
   */
  $updatedAt =
    $year .
    '/' .
    $month .
    '/' .
    $day .
    ' ' .
    $timeText;   
   

  /*
   * 「日本225 寄与度ランキング」の表。
   */
  $tableNodes = $xpath->query(
    "//table[@id='nkrnk']"
  );

  if (!$tableNodes || $tableNodes->length === 0) {
    throw new RuntimeException(
      "日本225寄与度ランキングのテーブルが見つかりません。"
    );
  }

  if ($tableNodes->length !== 1) {
    throw new RuntimeException(
      "日本225寄与度ランキングのテーブルが複数存在します: " .
      $tableNodes->length
    );
  }

  $table = $tableNodes->item(0);

  /*
   * ランキングのデータ行を取得する。
   *
   * 1行につき、
   *   左側：寄与度上位
   *   右側：寄与度下位
   * の2セルで構成される。
   */
  $rowNodes = $xpath->query(
    ".//tbody/tr[" . class_xpath_('kiyoBdTR') . "]",
    $table
  );

  if (!$rowNodes || $rowNodes->length === 0) {
    throw new RuntimeException(
      "日本225寄与度ランキングのデータ行が見つかりません。"
    );
  }

  if ($rowNodes->length < 5) {
    throw new RuntimeException(
      "日本225寄与度ランキングの取得件数が5件未満です: " .
      $rowNodes->length
    );
  }

  $upper = array();
  $lower = array();

  /*
   * ランキングは上位順・下位順に並んでいるため、
   * 先頭5行のみ使用する。
   */
  for ($i = 0; $i < 5; $i++) {
    $rowNode = $rowNodes->item($i);

    $cells = $xpath->query(
      "./td[" . class_xpath_('kiyoBdTD') . "]",
      $rowNode
    );

    if (!$cells || $cells->length !== 2) {
      throw new RuntimeException(
        "日本225寄与度ランキングの列数が2列ではありません: " .
        "row=" . ($i + 1) .
        " columns=" . ($cells ? $cells->length : 0)
      );
    }

    /*
     * 左セル：寄与度上位
     */
    $upper[] = parse_nikkei225_contribution_cell_(
      $xpath,
      $cells->item(0),
      $i + 1,
      '寄与度上位'
    );

    /*
     * 右セル：寄与度下位
     */
    $lower[] = parse_nikkei225_contribution_cell_(
      $xpath,
      $cells->item(1),
      $i + 1,
      '寄与度下位'
    );
  }

  if (count($upper) !== 5) {
    throw new RuntimeException(
      "寄与度上位の取得件数が5件ではありません: " .
      count($upper)
    );
  }

  if (count($lower) !== 5) {
    throw new RuntimeException(
      "寄与度下位の取得件数が5件ではありません: " .
      count($lower)
    );
  }

  return array(
    'updated_at' => $updatedAt,
    'upper' => $upper,
    'lower' => $lower,
  );
}

/**
 * 寄与度ランキングの1セルを解析する。
 */
function parse_nikkei225_contribution_cell_(
  $xpath,
  $cell,
  $rowNumber,
  $rankingName
) {
  /*
   * 銘柄名
   */
  $nameNodes = $xpath->query(
    ".//*[" . class_xpath_('kiyoTDsp0') . "]",
    $cell
  );

  if (!$nameNodes || $nameNodes->length !== 1) {
    throw new RuntimeException(
      "{$rankingName}の銘柄名が取得できません: " .
      "row={$rowNumber}"
    );
  }

  $name = normalize_text_(
    $nameNodes->item(0)->textContent
  );

  if ($name === '') {
    throw new RuntimeException(
      "{$rankingName}の銘柄名が空です: " .
      "row={$rowNumber}"
    );
  }

  /*
   * 寄与度
   */
  $contributionNodes = $xpath->query(
    ".//*[" . class_xpath_('kiyoTDsp1') . "]",
    $cell
  );

  if (
    !$contributionNodes ||
    $contributionNodes->length !== 1
  ) {
    throw new RuntimeException(
      "{$rankingName}の寄与度が取得できません: " .
      "row={$rowNumber}"
    );
  }

  $contribution =
    normalize_nikkei225_contribution_value_(
      $contributionNodes->item(0)->textContent,
      $rowNumber,
      $rankingName
    );

  /*
   * 現在値
   */
  $currentPriceNodes = $xpath->query(
    ".//*[" . class_xpath_('kiyoTDsp2') . "]",
    $cell
  );

  if (
    !$currentPriceNodes ||
    $currentPriceNodes->length !== 1
  ) {
    throw new RuntimeException(
      "{$rankingName}の現在値が取得できません: " .
      "row={$rowNumber}"
    );
  }

  $currentPrice =
    normalize_nikkei225_contribution_current_price_(
      $currentPriceNodes->item(0)->textContent,
      $rowNumber,
      $rankingName
    );

  /*
   * 前日比
   */
  $changeNodes = $xpath->query(
    ".//*[" . class_xpath_('kiyoTDsp3') . "]",
    $cell
  );

  if (
    !$changeNodes ||
    $changeNodes->length !== 1
  ) {
    throw new RuntimeException(
      "{$rankingName}の前日比が取得できません: " .
      "row={$rowNumber}"
    );
  }

  $change =
    normalize_nikkei225_contribution_change_(
      $changeNodes->item(0)->textContent,
      $rowNumber,
      $rankingName
    );

  return array(
    'name' => $name,
    'contribution' => $contribution,
    'current_price' => $currentPrice,
    'change' => $change,
  );
}

/**
 * 寄与度を検証し、
 * 符号付き・小数点以下2桁・3桁ごとのカンマ区切りへ
 * 正規化する。
 */
function normalize_nikkei225_contribution_value_(
  $value,
  $rowNumber,
  $rankingName
) {
  $text = normalize_text_((string)$value);
  $numericText = str_replace(',', '', $text);

  if (
    !preg_match(
      '/^[+-]?\d+(?:\.\d+)?$/',
      $numericText
    )
  ) {
    throw new RuntimeException(
      "{$rankingName}の寄与度形式が不正です: " .
      "row={$rowNumber}" .
      " value={$text}"
    );
  }

  $number = (float)$numericText;

  if ($number > 0) {
    return '+' . number_format(
      $number,
      2,
      '.',
      ','
    );
  }

  if ($number < 0) {
    return '-' . number_format(
      abs($number),
      2,
      '.',
      ','
    );
  }

  return number_format(
    0,
    2,
    '.',
    ','
  );
}

/**
 * 現在値を検証し、
 * 3桁ごとのカンマ区切りへ正規化する。
 *
 * HTML上には2,567.5のように小数を含む現在値も存在するため、
 * 元データに小数部がある場合はその桁数を保持する。
 */
function normalize_nikkei225_contribution_current_price_(
  $value,
  $rowNumber,
  $rankingName
) {
  $text = normalize_text_((string)$value);
  $numericText = str_replace(',', '', $text);

  if (
    !preg_match(
      '/^\d+(?:\.\d+)?$/',
      $numericText
    )
  ) {
    throw new RuntimeException(
      "{$rankingName}の現在値形式が不正です: " .
      "row={$rowNumber}" .
      " value={$text}"
    );
  }

  $decimalPlaces = 0;

  if (strpos($numericText, '.') !== false) {
    $parts = explode('.', $numericText, 2);
    $decimalPlaces = strlen($parts[1]);
  }

  return number_format(
    (float)$numericText,
    $decimalPlaces,
    '.',
    ','
  );
}

/**
 * 前日比を検証し、
 * 小数点以下2桁＋パーセント記号へ正規化する。
 *
 * HTMLでは下落時に「▼4.26%」のように表示されるため、
 * ▼をマイナスとして扱う。
 *
 * 上昇時はプラス記号を付加せず、
 * 16.34%の形式とする。
 */
function normalize_nikkei225_contribution_change_(
  $value,
  $rowNumber,
  $rankingName
) {
  $text = normalize_text_((string)$value);

  $isNegative =
    strpos($text, '▼') !== false ||
    strpos($text, '-') !== false;

  $numericText = str_replace(
    array(
      ',',
      '%',
      '▲',
      '▼',
      '+',
      '-',
    ),
    '',
    $text
  );

  if (
    !preg_match(
      '/^\d+(?:\.\d+)?$/',
      $numericText
    )
  ) {
    throw new RuntimeException(
      "{$rankingName}の前日比形式が不正です: " .
      "row={$rowNumber}" .
      " value={$text}"
    );
  }

  $number = (float)$numericText;

  if ($isNegative && $number != 0) {
    $number *= -1;
  }

  return number_format(
    $number,
    2,
    '.',
    ''
  ) . '%';
}

/**
 * 銘柄名を「全角15文字分」の表示幅へ整形する。
 *
 * 全角1文字を表示幅2として扱うため、
 * 表示幅30になるまで右側を半角スペースで埋める。
 *
 * 15文字分を超える銘柄名については、
 * 情報を欠落させないため切り捨てずそのまま出力する。
 */
function format_nikkei225_contribution_name_($name) {
  $name = (string)$name;

  if (!function_exists('mb_strwidth')) {
    throw new RuntimeException(
      "mb_strwidth()が使用できません。mbstring拡張を確認してください。"
    );
  }

  $targetWidth = 30;
  $width = mb_strwidth($name, 'UTF-8');

  if ($width >= $targetWidth) {
    return $name;
  }

  return $name . str_repeat(
    ' ',
    $targetWidth - $width
  );
}

/**
 * 日経225寄与度のレポート内容を作成する。
 */
function build_nikkei225_contribution_message_($parsed) {
  if (
    !is_array($parsed) ||
    !isset($parsed['updated_at']) ||
    !is_string($parsed['updated_at']) ||
    $parsed['updated_at'] === '' ||
    !isset($parsed['upper']) ||
    !is_array($parsed['upper']) ||
    !isset($parsed['lower']) ||
    !is_array($parsed['lower'])
  ) {
    throw new RuntimeException(
      "日経225寄与度の解析結果が不正です。"
    );
  }

  if (count($parsed['upper']) !== 5) {
    throw new RuntimeException(
      "寄与度上位のレポート出力件数が5件ではありません: " .
      count($parsed['upper'])
    );
  }

  if (count($parsed['lower']) !== 5) {
    throw new RuntimeException(
      "寄与度下位のレポート出力件数が5件ではありません: " .
      count($parsed['lower'])
    );
  }

  $lines = array();

  $lines[] = '■日経225寄与度';
  $lines[] = '更新日時: ' . $parsed['updated_at'];
  $lines[] = '';

  append_nikkei225_contribution_section_(
    $lines,
    '寄与度上位',
    $parsed['upper']
  );

  $lines[] = '';

  append_nikkei225_contribution_section_(
    $lines,
    '寄与度下位',
    $parsed['lower']
  );

  return implode("\n", $lines);
}

/**
 * 寄与度上位または寄与度下位の表を
 * レポートへ追加する。
 */
function append_nikkei225_contribution_section_(
  &$lines,
  $sectionName,
  $rows
) {
  $lines[] = "【{$sectionName}】";

  /*
   * 銘柄名は全角15文字分の幅を確保する。
   *
   * 寄与度：
   *   符号を含め最大「+99,999.00」を想定して10文字幅。
   *
   * 現在値：
   *   「9,999,999」を基準として9文字幅。
   *
   * 前日比：
   *   マイナスを含め最大「-99.99%」を想定して7文字幅。
   */
  $lines[] =
    format_nikkei225_contribution_name_('銘柄名') .
    "\t" .
    sprintf('%10s', '寄与度') .
    "\t" .
    sprintf('%9s', '現在値') .
    "\t" .
    sprintf('%7s', '前日比');

  foreach ($rows as $row) {
    $lines[] =
      format_nikkei225_contribution_name_(
        $row['name']
      ) .
      "\t" .
      sprintf(
        '%10s',
        $row['contribution']
      ) .
      "\t" .
      sprintf(
        '%9s',
        $row['current_price']
      ) .
      "\t" .
      sprintf(
        '%7s',
        $row['change']
      );
  }
}