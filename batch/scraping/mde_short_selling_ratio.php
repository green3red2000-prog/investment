<?php
/**
 * 市況関連データ抽出：空売り比率
 *
 * market_data_extract.php から読み込まれ、
 * 空売り比率のHTML解析およびレポート生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 */

// =======================================================
// 空売り比率
// =======================================================

/**
 * 空売り比率HTMLを解析する。
 *
 * 「空売り比率 90営業日」の表から、
 * 最新5営業日分について以下を取得する。
 *
 *   日付
 *   空売り比率合計
 *   空売り比率(価格規制あり)
 *   空売り比率(価格規制なし)
 */
function parse_short_selling_ratio_html_($html) {
  $xpath = load_xpath_($html);

  /*
   * 「空売り比率 90営業日」の表。
   */
  $tableNodes = $xpath->query(
    "//table[@id='datatbl']"
  );

  if (!$tableNodes || $tableNodes->length === 0) {
    throw new RuntimeException(
      "空売り比率90営業日のテーブルが見つかりません。"
    );
  }

  $table = $tableNodes->item(0);

  /*
   * データ行だけを取得する。
   *
   * 1列目にtime要素を持つtrだけを対象とし、
   * 見出し行を除外する。
   */
  $rowNodes = $xpath->query(
    ".//tr[td[1]//time]",
    $table
  );

  if (!$rowNodes || $rowNodes->length === 0) {
    throw new RuntimeException(
      "空売り比率90営業日のデータ行が見つかりません。"
    );
  }

  if ($rowNodes->length < 5) {
    throw new RuntimeException(
      "空売り比率90営業日の取得件数が5件未満です: " .
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

    if (!$cells || $cells->length !== 7) {
      throw new RuntimeException(
        "空売り比率90営業日の列数が7列ではありません: " .
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
     *  5 空売り比率合計
     *  6 空売り比率(価格規制あり)
     *  7 空売り比率(価格規制なし)
     */

    $date = normalize_text_(
      $cells->item(0)->textContent
    );

    $totalRatio =
      normalize_short_selling_ratio_number_(
        $cells->item(4)->textContent,
        $i + 1,
        '空売り比率合計'
      );

    $regulatedRatio =
      normalize_short_selling_ratio_number_(
        $cells->item(5)->textContent,
        $i + 1,
        '空売り比率(価格規制あり)'
      );

    $unregulatedRatio =
      normalize_short_selling_ratio_number_(
        $cells->item(6)->textContent,
        $i + 1,
        '空売り比率(価格規制なし)'
      );

    if (
      !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $date
      )
    ) {
      throw new RuntimeException(
        "空売り比率の日付形式が不正です: " .
        "row=" . ($i + 1) .
        " date={$date}"
      );
    }

    $rows[] = array(
      'date' => $date,
      'total_ratio' => $totalRatio,
      'regulated_ratio' => $regulatedRatio,
      'unregulated_ratio' => $unregulatedRatio,
    );
  }

  /*
   * 最新→過去の降順になっていることを確認する。
   */
  validate_short_selling_ratio_dates_($rows);

  return array(
    'rows' => $rows,
  );
}

/**
 * 空売り比率を検証し、
 * 小数点以下1桁へ正規化する。
 */
function normalize_short_selling_ratio_number_(
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
      "空売り比率の数値形式が不正です: " .
      "row={$rowNumber}" .
      " field={$fieldName}" .
      " value={$text}"
    );
  }

  $number = (float)$numericText;

  return number_format(
    $number,
    1,
    '.',
    ''
  );
}

/**
 * 取得した5営業日が、
 * 最新日から過去へ向かう降順になっていることを確認する。
 */
function validate_short_selling_ratio_dates_($rows) {
  if (count($rows) !== 5) {
    throw new RuntimeException(
      "空売り比率の使用件数が5件ではありません: " .
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
        "空売り比率の日付順が最新順ではありません: " .
        "row=" . ($i + 1) .
        " previous={$previousDate}" .
        " current={$currentDate}"
      );
    }
  }
}

/**
 * 空売り比率のレポート内容を作成する。
 */
function build_short_selling_ratio_message_($parsed) {
  if (
    !is_array($parsed) ||
    !isset($parsed['rows']) ||
    !is_array($parsed['rows'])
  ) {
    throw new RuntimeException(
      "空売り比率の解析結果が不正です。"
    );
  }

  if (count($parsed['rows']) !== 5) {
    throw new RuntimeException(
      "空売り比率のレポート出力件数が5件ではありません: " .
      count($parsed['rows'])
    );
  }

  $lines = array();

  $lines[] = '【空売り比率】';
  $lines[] = '';
  $lines[] = '■空売り比率 90営業日';

  $lines[] = implode(
    "\t",
    array(
      '日付',
      '空売り比率合計',
      '空売り比率(価格規制あり)',
      '空売り比率(価格規制なし)',
    )
  );

  foreach ($parsed['rows'] as $row) {
    $lines[] = implode(
      "\t",
      array(
        $row['date'],
        $row['total_ratio'],
        $row['regulated_ratio'],
        $row['unregulated_ratio'],
      )
    );
  }

  return implode("\n", $lines);
}