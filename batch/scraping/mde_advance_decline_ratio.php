<?php
/**
 * 騰落レシオHTMLを解析する。
 *
 * 騰落レシオの表から、
 * 最新5営業日分について以下を取得する。
 *
 *   日付
 *   騰落レシオ(25日)
 *   騰落レシオ(15日)
 *   騰落レシオ(10日)
 *   騰落レシオ(6日)
 */
function parse_advance_decline_ratio_html_($html) {
  $xpath = load_xpath_($html);

  /*
   * 騰落レシオの表。
   */
  $tableNodes = $xpath->query(
    "//table[@id='datatbl']"
  );

  if (!$tableNodes || $tableNodes->length === 0) {
    throw new RuntimeException(
      "騰落レシオのテーブルが見つかりません。"
    );
  }

  $table = $tableNodes->item(0);

  /*
   * データ行だけを取得する。
   *
   * 見出し行や表末尾の説明行を除外するため、
   * 1列目にtime要素を持つtrだけを対象とする。
   */
  $rowNodes = $xpath->query(
    ".//tr[td[1]//time]",
    $table
  );

  if (!$rowNodes || $rowNodes->length === 0) {
    throw new RuntimeException(
      "騰落レシオのデータ行が見つかりません。"
    );
  }

  if ($rowNodes->length < 5) {
    throw new RuntimeException(
      "騰落レシオの取得件数が5件未満です: " .
      $rowNodes->length
    );
  }

  $rows = array();

  /*
   * HTMLは最新営業日から降順で並んでいるため、
   * 先頭から5件だけを使用する。
   */
  for ($i = 0; $i < 5; $i++) {
    $rowNode = $rowNodes->item($i);

    $cells = $xpath->query(
      "./td",
      $rowNode
    );

    if (!$cells || $cells->length !== 10) {
      throw new RuntimeException(
        "騰落レシオの列数が10列ではありません: " .
        "row=" . ($i + 1) .
        " columns=" . ($cells ? $cells->length : 0)
      );
    }

    /*
     * 元HTML上の列番号
     *
     *  1 日付
     *  2 日本225
     *  3 日本225(変化)
     *  4 プライム出来高(百万株)
     *  5 値上がり銘柄数
     *  6 値下がり銘柄数
     *  7 騰落レシオ(25日)
     *  8 騰落レシオ(15日)
     *  9 騰落レシオ(10日)
     * 10 騰落レシオ(6日)
     */

    $date = normalize_text_(
      $cells->item(0)->textContent
    );

    $ratio25 =
      normalize_advance_decline_ratio_number_(
        $cells->item(6)->textContent,
        $i + 1,
        '騰落レシオ(25日)'
      );

    $ratio15 =
      normalize_advance_decline_ratio_number_(
        $cells->item(7)->textContent,
        $i + 1,
        '騰落レシオ(15日)'
      );

    $ratio10 =
      normalize_advance_decline_ratio_number_(
        $cells->item(8)->textContent,
        $i + 1,
        '騰落レシオ(10日)'
      );

    $ratio6 =
      normalize_advance_decline_ratio_number_(
        $cells->item(9)->textContent,
        $i + 1,
        '騰落レシオ(6日)'
      );

    if (
      !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $date
      )
    ) {
      throw new RuntimeException(
        "騰落レシオの日付形式が不正です: " .
        "row=" . ($i + 1) .
        " date={$date}"
      );
    }

    $rows[] = array(
      'date' => $date,
      'ratio_25' => $ratio25,
      'ratio_15' => $ratio15,
      'ratio_10' => $ratio10,
      'ratio_6' => $ratio6,
    );
  }

  /*
   * 最新→過去の降順になっていることを確認する。
   */
  validate_advance_decline_ratio_dates_($rows);

  return array(
    'rows' => $rows,
  );
}

/**
 * 騰落レシオを検証し、
 * 小数点以下2桁へ正規化する。
 */
function normalize_advance_decline_ratio_number_(
  $value,
  $rowNumber,
  $fieldName
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
      "騰落レシオの数値形式が不正です: " .
      "row={$rowNumber}" .
      " field={$fieldName}" .
      " value={$text}"
    );
  }

  $number = (float)$numericText;

  return number_format(
    $number,
    2,
    '.',
    ''
  );
}

/**
 * 取得した5営業日が、
 * 最新日から過去へ向かう降順になっていることを確認する。
 */
function validate_advance_decline_ratio_dates_($rows) {
  if (count($rows) !== 5) {
    throw new RuntimeException(
      "騰落レシオの使用件数が5件ではありません: " .
      count($rows)
    );
  }

  for ($i = 1; $i < count($rows); $i++) {
    $previousDate = isset($rows[$i - 1]['date'])
      ? (string)$rows[$i - 1]['date']
      : '';

    $currentDate = isset($rows[$i]['date'])
      ? (string)$rows[$i]['date']
      : '';

    if ($previousDate <= $currentDate) {
      throw new RuntimeException(
        "騰落レシオの日付順が最新順ではありません: " .
        "row=" . ($i + 1) .
        " previous={$previousDate}" .
        " current={$currentDate}"
      );
    }
  }
}

/**
 * 騰落レシオのレポート内容を作成する。
 */
function build_advance_decline_ratio_message_($parsed) {
  if (
    !is_array($parsed) ||
    !isset($parsed['rows']) ||
    !is_array($parsed['rows'])
  ) {
    throw new RuntimeException(
      "騰落レシオの解析結果が不正です。"
    );
  }

  if (count($parsed['rows']) !== 5) {
    throw new RuntimeException(
      "騰落レシオのレポート出力件数が5件ではありません: " .
      count($parsed['rows'])
    );
  }

  $lines = array();

  $lines[] = '■騰落レシオ';
  $lines[] = '';
  $lines[] = '【騰落レシオ】';

  $lines[] = implode(
    "\t",
    array(
      '日付',
      '騰落レシオ(25日)',
      '騰落レシオ(15日)',
      '騰落レシオ(10日)',
      '騰落レシオ(6日)',
    )
  );

  foreach ($parsed['rows'] as $row) {
    $lines[] = implode(
      "\t",
      array(
        $row['date'],
        $row['ratio_25'],
        $row['ratio_15'],
        $row['ratio_10'],
        $row['ratio_6'],
      )
    );
  }

  return implode("\n", $lines);
}