<?php
/**
 * 市況関連データ抽出：新高値・新安値
 *
 * market_data_extract.php から読み込まれ、
 * 新高値・新安値のHTML解析およびメッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 */

// =======================================================
// 新高値・新安値
// =======================================================

/**
 * 新高値・新安値HTMLから、
 * 「新高値 新安値 30営業日」の最新5行を取得する。
 *
 * @param string $html
 * @return array
 */
function parse_new_high_low_html_($html) {
  $xpath = load_xpath_($html);

  /*
   * 対象テーブルを取得する。
   */
  $tableNodes = $xpath->query(
    "//table[@id='datatbl']"
  );

  if (!$tableNodes || $tableNodes->length !== 1) {
    throw new RuntimeException(
      "新高値・新安値テーブルが1件取得できません: " .
      ($tableNodes ? $tableNodes->length : 0)
    );
  }

  $tableNode = $tableNodes->item(0);

  /*
   * captionが
   * 「新高値 新安値 30営業日」
   * であることを確認する。
   */
  $captionNodes = $xpath->query(
    "./caption",
    $tableNode
  );

  if (!$captionNodes || $captionNodes->length !== 1) {
    throw new RuntimeException(
      "新高値・新安値テーブルの見出しが1件取得できません: " .
      ($captionNodes ? $captionNodes->length : 0)
    );
  }

  $caption =
    normalize_text_(
      $captionNodes->item(0)->textContent
    );

  if (
    mb_strpos(
      $caption,
      '新高値 新安値 30営業日'
    ) === false
  ) {
    throw new RuntimeException(
      "新高値・新安値テーブルの見出しが想定と一致しません: " .
      $caption
    );
  }

  /*
   * tdを持つtrだけをデータ行として取得する。
   * 見出し行はthのみなので除外される。
   */
  $rowNodes = $xpath->query(
    "./tr[td]",
    $tableNode
  );

  if (!$rowNodes || $rowNodes->length < 5) {
    throw new RuntimeException(
      "新高値・新安値のデータ行が5件以上取得できません: " .
      ($rowNodes ? $rowNodes->length : 0)
    );
  }

  $rows = array();

  /*
   * HTMLは最新日付から降順に並んでいるため、
   * 先頭5行を使用する。
   */
  for ($i = 0; $i < 5; $i++) {
    $rowNode = $rowNodes->item($i);

    $cells = $xpath->query(
      "./td",
      $rowNode
    );

    if (!$cells || $cells->length !== 9) {
      throw new RuntimeException(
        "新高値・新安値の列数が9列ではありません: " .
        "row=" . ($i + 1) .
        " columns=" .
        ($cells ? $cells->length : 0)
      );
    }

    /*
     * 1列目：日付
     */
    $dateNodes = $xpath->query(
      ".//time",
      $cells->item(0)
    );

    if (!$dateNodes || $dateNodes->length !== 1) {
      throw new RuntimeException(
        "新高値・新安値の日付が1件取得できません: " .
        "row=" . ($i + 1)
      );
    }

    $date =
      normalize_text_(
        $dateNodes->item(0)->textContent
      );

    validate_new_high_low_date_(
      $date,
      $i + 1
    );

    /*
     * 5列目：新高値銘柄数
     * 6列目：新安値銘柄数
     * 7列目：値上がり銘柄数
     * 8列目：値下がり銘柄数
     */
    $newHigh =
      normalize_text_(
        $cells->item(4)->textContent
      );

    $newLow =
      normalize_text_(
        $cells->item(5)->textContent
      );

    $advance =
      normalize_text_(
        $cells->item(6)->textContent
      );

    $decline =
      normalize_text_(
        $cells->item(7)->textContent
      );

    $newHighFormatted =
      format_new_high_low_count_(
        $newHigh,
        '新高値銘柄数',
        $i + 1
      );

    $newLowFormatted =
      format_new_high_low_count_(
        $newLow,
        '新安値銘柄数',
        $i + 1
      );

    $advanceFormatted =
      format_new_high_low_count_(
        $advance,
        '値上がり銘柄数',
        $i + 1
      );

    $declineFormatted =
      format_new_high_low_count_(
        $decline,
        '値下がり銘柄数',
        $i + 1
      );

    $rows[] = array(
      'date' => $date,
      'new_high' => $newHighFormatted,
      'new_low' => $newLowFormatted,
      'advance' => $advanceFormatted,
      'decline' => $declineFormatted,
    );
  }

  /*
   * 最新日付から降順であることを確認する。
   */
  for ($i = 1; $i < count($rows); $i++) {
    if (
      strcmp(
        $rows[$i - 1]['date'],
        $rows[$i]['date']
      ) <= 0
    ) {
      throw new RuntimeException(
        "新高値・新安値の日付順が最新順ではありません: " .
        "row=" . ($i + 1) .
        " previous=" . $rows[$i - 1]['date'] .
        " current=" . $rows[$i]['date']
      );
    }
  }

  return array(
    'rows' => $rows,
  );
}

/**
 * 日付を検証する。
 *
 * @param string $value
 * @param int $rowNo
 * @return void
 */
function validate_new_high_low_date_(
  $value,
  $rowNo
) {
  if (
    !preg_match(
      '/^(\d{4})-(\d{2})-(\d{2})$/',
      $value,
      $matches
    )
  ) {
    throw new RuntimeException(
      "新高値・新安値の日付形式が不正です: " .
      "row={$rowNo} value={$value}"
    );
  }

  $year = (int)$matches[1];
  $month = (int)$matches[2];
  $day = (int)$matches[3];

  if (!checkdate($month, $day, $year)) {
    throw new RuntimeException(
      "新高値・新安値の日付が不正です: " .
      "row={$rowNo} value={$value}"
    );
  }
}

/**
 * 銘柄数を検証し、
 * 3桁ごとのカンマ区切りへ正規化する。
 *
 * @param string $value
 * @param string $fieldName
 * @param int $rowNo
 * @return string
 */
function format_new_high_low_count_(
  $value,
  $fieldName,
  $rowNo
) {
  $raw =
    str_replace(
      ',',
      '',
      trim((string)$value)
    );

  if (
    $raw === '' ||
    !preg_match('/^\d+$/', $raw)
  ) {
    throw new RuntimeException(
      "新高値・新安値の数値形式が不正です: " .
      "row={$rowNo}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  return number_format(
    (float)$raw,
    0,
    '.',
    ','
  );
}

/**
 * 新高値・新安値の抽出結果から
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_new_high_low_message_(
  $parsed
) {
  if (
    !isset($parsed['rows']) ||
    !is_array($parsed['rows']) ||
    count($parsed['rows']) !== 5
  ) {
    throw new RuntimeException(
      "新高値・新安値のレポート対象が5件ではありません。"
    );
  }

  $lines = array();

  $lines[] = "■新高値・新安値";
  $lines[] = '';
  $lines[] = "【東証プライム市場　新高値/新安値】";

  /*
   * 列見出し。
   */
  $lines[] =
    "日付" . "\t" .
    "新高値銘柄数" . "\t" .
    "新安値銘柄数" . "\t" .
    "値上がり銘柄数" . "\t" .
    "値下がり銘柄数";

  foreach ($parsed['rows'] as $row) {
    /*
     * 99,999
     * 最大6文字幅で右寄せ。
     */
    $newHigh =
      str_pad(
        (string)$row['new_high'],
        6,
        ' ',
        STR_PAD_LEFT
      );

    $newLow =
      str_pad(
        (string)$row['new_low'],
        6,
        ' ',
        STR_PAD_LEFT
      );

    $advance =
      str_pad(
        (string)$row['advance'],
        6,
        ' ',
        STR_PAD_LEFT
      );

    $decline =
      str_pad(
        (string)$row['decline'],
        6,
        ' ',
        STR_PAD_LEFT
      );

    $lines[] =
      $row['date'] . "\t" .
      $newHigh . "\t" .
      $newLow . "\t" .
      $advance . "\t" .
      $decline;
  }

  /*
   * 固定コメント。
   */
  $lines[] = '';
  $lines[] =
    "※新高値/新安値銘柄の計算方法は、" .
    "1～3月の期間は昨年1月の大発会からの比較、" .
    "4～12月の期間はその年の1月からの比較";

  return implode("\n", $lines);
}