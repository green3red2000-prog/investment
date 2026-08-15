<?php
/**
 * 市況関連データ抽出：信用残・評価損益
 *
 * market_data_extract.php から読み込まれ、
 * 信用残・評価損益のHTML解析およびメッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 */

// =======================================================
// 信用残・評価損益
// =======================================================

/**
 * 信用残・評価損益HTMLから、
 * 「評価損益率 信用残」の最新5行を取得する。
 *
 * @param string $html
 * @return array
 */
function parse_margin_balance_profit_loss_html_($html) {
  $xpath = load_xpath_($html);

  /*
   * 対象テーブルのtbodyを取得する。
   *
   * HTML上では以下。
   *
   * <tbody id="datatbl">
   *   <tr>見出し...</tr>
   *   <tr>2026-08-07...</tr>
   *   ...
   * </tbody>
   */
  $tableNodes = $xpath->query(
    "//*[@id='datatbl']"
  );

  if (!$tableNodes || $tableNodes->length !== 1) {
    throw new RuntimeException(
      "評価損益率・信用残テーブルが1件取得できません: " .
      ($tableNodes ? $tableNodes->length : 0)
    );
  }

  $tableNode = $tableNodes->item(0);

  /*
   * td要素を持つtrだけをデータ行として取得する。
   * 見出し行はthのみなので除外される。
   */
  $rowNodes = $xpath->query(
    "./tr[td]",
    $tableNode
  );

  if (!$rowNodes || $rowNodes->length < 5) {
    throw new RuntimeException(
      "評価損益率・信用残のデータ行が5件以上取得できません: " .
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
        "評価損益率・信用残の列数が9列ではありません: " .
        "row=" . ($i + 1) .
        " columns=" . ($cells ? $cells->length : 0)
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
        "日付が1件取得できません: row=" . ($i + 1)
      );
    }

    $date =
      normalize_text_(
        $dateNodes->item(0)->textContent
      );

    validate_margin_balance_date_(
      $date,
      $i + 1
    );

    /*
     * 2～9列目。
     */
    $sellShares =
      normalize_text_(
        $cells->item(1)->textContent
      );

    $sellAmount =
      normalize_text_(
        $cells->item(2)->textContent
      );

    $sellChange =
      normalize_text_(
        $cells->item(3)->textContent
      );

    $buyShares =
      normalize_text_(
        $cells->item(4)->textContent
      );

    $buyAmount =
      normalize_text_(
        $cells->item(5)->textContent
      );

    $buyChange =
      normalize_text_(
        $cells->item(6)->textContent
      );

    $marginRatio =
      normalize_text_(
        $cells->item(7)->textContent
      );

    $profitLossRatio =
      normalize_text_(
        $cells->item(8)->textContent
      );

    /*
     * 数値検証および正規化。
     */
    $sellSharesFormatted =
      format_margin_balance_integer_(
        $sellShares,
        '売り残枚数(千株)',
        $i + 1
      );

    $sellAmountFormatted =
      format_margin_balance_integer_(
        $sellAmount,
        '売り残金額(百万円)',
        $i + 1
      );

    $sellChangeFormatted =
      format_margin_balance_change_percent_(
        $sellChange,
        '売り残前回比(％)',
        $i + 1
      );

    $buySharesFormatted =
      format_margin_balance_integer_(
        $buyShares,
        '買い残枚数(千株)',
        $i + 1
      );

    $buyAmountFormatted =
      format_margin_balance_integer_(
        $buyAmount,
        '買い残金額(百万円)',
        $i + 1
      );

    $buyChangeFormatted =
      format_margin_balance_change_percent_(
        $buyChange,
        '買い残前回比(％)',
        $i + 1
      );

    $marginRatioFormatted =
      format_margin_balance_ratio_(
        $marginRatio,
        '信用倍率',
        $i + 1,
        true
      );

    /*
     * 信用倍率・信用評価率は、
     * HTML上で未公表の場合に
     * 空欄、「-」「－」「ー」となる場合があるため、
     * 欠損値を許容する。
     */
    $profitLossRatioFormatted =
      format_margin_balance_ratio_(
        $profitLossRatio,
        '信用評価率',
        $i + 1,
        true
      );

    $rows[] = array(
      'date' => $date,
      'sell_shares' => $sellSharesFormatted,
      'sell_amount' => $sellAmountFormatted,
      'sell_change' => $sellChangeFormatted,
      'buy_shares' => $buySharesFormatted,
      'buy_amount' => $buyAmountFormatted,
      'buy_change' => $buyChangeFormatted,
      'margin_ratio' => $marginRatioFormatted,
      'profit_loss_ratio' => $profitLossRatioFormatted,
    );
  }

  /*
   * 日付が降順になっていることを確認する。
   */
  for ($i = 1; $i < count($rows); $i++) {
    if (
      strcmp(
        $rows[$i - 1]['date'],
        $rows[$i]['date']
      ) <= 0
    ) {
      throw new RuntimeException(
        "評価損益率・信用残の日付順が最新順ではありません: " .
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
function validate_margin_balance_date_(
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
      "評価損益率・信用残の日付形式が不正です: " .
      "row={$rowNo} value={$value}"
    );
  }

  $year = (int)$matches[1];
  $month = (int)$matches[2];
  $day = (int)$matches[3];

  if (!checkdate($month, $day, $year)) {
    throw new RuntimeException(
      "評価損益率・信用残の日付が不正です: " .
      "row={$rowNo} value={$value}"
    );
  }
}

/**
 * 枚数・金額の整数値を検証し、
 * 3桁ごとのカンマ区切りへ正規化する。
 *
 * @param string $value
 * @param string $fieldName
 * @param int $rowNo
 * @return string
 */
function format_margin_balance_integer_(
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
      "信用残・評価損益の数値形式が不正です: " .
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
 * 売り残・買い残の前回比を検証して正規化する。
 *
 * 正数には+を付ける。
 * 負数には-を付ける。
 * 0には符号を付けない。
 *
 * @param string $value
 * @param string $fieldName
 * @param int $rowNo
 * @return string
 */
function format_margin_balance_change_percent_(
  $value,
  $fieldName,
  $rowNo
) {
  $original =
    trim((string)$value);

  $raw =
    str_replace(
      array(',', '%', '＋', '－'),
      array('', '', '+', '-'),
      $original
    );

  if (
    $raw === '' ||
    !preg_match(
      '/^[+-]?\d+(?:\.\d+)?$/',
      $raw
    )
  ) {
    throw new RuntimeException(
      "信用残・評価損益の前回比形式が不正です: " .
      "row={$rowNo}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  $number = (float)$raw;

  if ($number > 0) {
    return
      '+' .
      number_format(
        $number,
        2,
        '.',
        ''
      ) .
      '%';
  }

  if ($number < 0) {
    return
      '-' .
      number_format(
        abs($number),
        2,
        '.',
        ''
      ) .
      '%';
  }

  return '0.00%';
}

/**
 * 信用倍率・信用評価率を検証して正規化する。
 *
 * HTML上で未公表の場合の
 * 空欄、「-」「－」「ー」を許容する。
 *
 * @param string $value
 * @param string $fieldName
 * @param int $rowNo
 * @param bool $allowMissing
 * @return string
 */
function format_margin_balance_ratio_(
  $value,
  $fieldName,
  $rowNo,
  $allowMissing
) {
  $original =
    trim((string)$value);

  if (
    $allowMissing &&
    (
      $original === '' ||
      $original === '-' ||
      $original === '－' ||
      $original === 'ー'
    )
  ) {
    return '-';
  }

  $raw =
    str_replace(
      array(',', '%', '＋', '－'),
      array('', '', '+', '-'),
      $original
    );

  if (
    $raw === '' ||
    !preg_match(
      '/^[+-]?\d+(?:\.\d+)?$/',
      $raw
    )
  ) {
    throw new RuntimeException(
      "信用残・評価損益の比率形式が不正です: " .
      "row={$rowNo}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  $number = (float)$raw;

  /*
   * 信用倍率・信用評価率は、
   * HTML上では%記号なしの数値であるため、
   * 出力時に%記号を付加する。
   *
   * 元値の100倍等は行わない。
   */
  return
    number_format(
      $number,
      2,
      '.',
      ''
    ) .
    '%';
}

/**
 * 信用残・評価損益の抽出結果から
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_margin_balance_profit_loss_message_(
  $parsed
) {
  if (
    !isset($parsed['rows']) ||
    !is_array($parsed['rows']) ||
    count($parsed['rows']) !== 5
  ) {
    throw new RuntimeException(
      "信用残・評価損益のレポート対象が5件ではありません。"
    );
  }

  $lines = array();

  $lines[] = "■信用残・評価損益";
  $lines[] = '';
  $lines[] = "【評価損益率 信用残】";

  /*
   * 見出し。
   */
  $lines[] =
    "日付" . "\t" .
    "売り残枚数(千株)" . "\t" .
    "売り残金額(百万円)" . "\t" .
    "売り残前回比(％)" . "\t" .
    "買い残枚数(千株)" . "\t" .
    "買い残金額(百万円)" . "\t" .
    "買い残前回比(％)" . "\t" .
    "信用倍率" . "\t" .
    "信用評価率";

  foreach ($parsed['rows'] as $row) {
    /*
     * 日付
     * YYYY-MM-DD
     */
    $date =
      (string)$row['date'];

    /*
     * 整数系:
     * 999,999,999
     * 最大11文字幅で右寄せ。
     */
    $sellShares =
      str_pad(
        (string)$row['sell_shares'],
        11,
        ' ',
        STR_PAD_LEFT
      );

    $sellAmount =
      str_pad(
        (string)$row['sell_amount'],
        11,
        ' ',
        STR_PAD_LEFT
      );

    $buyShares =
      str_pad(
        (string)$row['buy_shares'],
        11,
        ' ',
        STR_PAD_LEFT
      );

    $buyAmount =
      str_pad(
        (string)$row['buy_amount'],
        11,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * 前回比:
     * 正負の符号を含め、
     * -999.00%まで考慮して8文字幅で右寄せ。
     */
    $sellChange =
      str_pad(
        (string)$row['sell_change'],
        8,
        ' ',
        STR_PAD_LEFT
      );

    $buyChange =
      str_pad(
        (string)$row['buy_change'],
        8,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * 信用倍率・信用評価率:
     * 999.00%を基本形式とし、
     * 負数も考慮して8文字幅で右寄せ。
     *
     * 信用倍率または信用評価率が未公表の場合の "-"
     * も同じ幅で右寄せする。
     */
    $marginRatio =
      str_pad(
        (string)$row['margin_ratio'],
        8,
        ' ',
        STR_PAD_LEFT
      );

    $profitLossRatio =
      str_pad(
        (string)$row['profit_loss_ratio'],
        8,
        ' ',
        STR_PAD_LEFT
      );

    $lines[] =
      $date . "\t" .
      $sellShares . "\t" .
      $sellAmount . "\t" .
      $sellChange . "\t" .
      $buyShares . "\t" .
      $buyAmount . "\t" .
      $buyChange . "\t" .
      $marginRatio . "\t" .
      $profitLossRatio;
  }

  return implode("\n", $lines);
}