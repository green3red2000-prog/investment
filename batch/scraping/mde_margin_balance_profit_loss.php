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
      format_margin_balance_shares_(
        $sellShares,
        '売り残枚数',
        $i + 1
      );

    $sellAmountFormatted =
      format_margin_balance_amount_(
        $sellAmount,
        '売り残金額',
        $i + 1
      );

    $sellChangeFormatted =
      format_margin_balance_change_percent_(
        $sellChange,
        '売り残前回比',
        $i + 1
      );

    $buySharesFormatted =
      format_margin_balance_shares_(
        $buyShares,
        '買い残枚数',
        $i + 1
      );

    $buyAmountFormatted =
      format_margin_balance_amount_(
        $buyAmount,
        '買い残金額',
        $i + 1
      );

    $buyChangeFormatted =
      format_margin_balance_change_percent_(
        $buyChange,
        '買い残前回比',
        $i + 1
      );

    $marginRatioFormatted =
      format_margin_balance_ratio_(
        $marginRatio,
        '信用倍率',
        $i + 1,
        true,
        false
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
        true,
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
 * 売り残・買い残の枚数を検証し、
 * 千株単位から万株・億株形式へ変換する。
 *
 * 千株から万株への変換時は、
 * 10で除算して端数を切り捨てる。
 *
 * 例:
 *   483,812千株
 *     → 48,381万株
 *     → 4億8381万株
 *
 *   3,519,645千株
 *     → 351,964万株
 *     → 35億1964万株
 */
function format_margin_balance_shares_(
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
      "信用残・評価損益の枚数形式が不正です: " .
      "row={$rowNo}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  /*
   * 元データは千株単位。
   *
   * 10千株 = 1万株なので、
   * 10で除算して端数切り捨て。
   */
  $manShares =
    intdiv(
      (int)$raw,
      10
    );

  /*
   * 1億株 = 10,000万株。
   */
  if ($manShares >= 10000) {
    $okuShares =
      intdiv(
        $manShares,
        10000
      );

    $remainManShares =
      $manShares % 10000;

    if ($remainManShares === 0) {
      return
        $okuShares .
        '億株';
    }

    return
      $okuShares .
      '億' .
      $remainManShares .
      '万株';
  }

  return
    $manShares .
    '万株';
}

/**
 * 売り残・買い残の金額を検証し、
 * 億円単位から億円・兆円形式へ変換する。
 *
 * HTML上の金額は億円単位・小数点以下2桁で
 * 表示されるため、小数点以下を切り捨てる。
 *
 * 例:
 *   8,930.83億円
 *     → 8930億円
 *
 *   62,006.65億円
 *     → 6兆2006億円
 */
function format_margin_balance_amount_(
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
    !preg_match('/^\d+(?:\.\d+)?$/', $raw)
  ) {
    throw new RuntimeException(
      "信用残・評価損益の金額形式が不正です: " .
      "row={$rowNo}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  /*
   * 元データは億円単位・小数点以下2桁。
   *
   * レポートでは億円単位の整数で表示するため、
   * 小数点以下を切り捨てる。
   */
  $okuYen =
    (int)floor(
      (float)$raw
    );

  /*
   * 1兆円 = 10,000億円。
   */
  if ($okuYen >= 10000) {
    $choYen =
      intdiv(
        $okuYen,
        10000
      );

    $remainOkuYen =
      $okuYen % 10000;

    if ($remainOkuYen === 0) {
      return
        $choYen .
        '兆円';
    }

    return
      $choYen .
      '兆' .
      $remainOkuYen .
      '億円';
  }

  return
    $okuYen .
    '億円';
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
  $allowMissing,
  $appendPercent
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
  $formatted =
    number_format(
      $number,
      2,
      '.',
      ''
    );

  if ($appendPercent) {
    $formatted .= '%';
  }

  return $formatted;
}
/**
 * 全角・半角を考慮して右寄せする。
 */
function pad_margin_balance_left_(
  $value,
  $width
) {
  if (!function_exists('mb_strwidth')) {
    throw new RuntimeException(
      "mb_strwidth()が使用できません。"
    );
  }

  $text =
    (string)$value;

  $currentWidth =
    mb_strwidth(
      $text,
      'UTF-8'
    );

  if ($currentWidth >= $width) {
    return $text;
  }

  return
    str_repeat(
      ' ',
      $width - $currentWidth
    ) .
    $text;
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
    "信用倍率" . "\t" .
    "信用評価率" . "\t" .
    "売り残枚数" . "\t" .
    "売り残金額" . "\t" .
    "売り残前回比" . "\t" .
    "買い残枚数" . "\t" .
    "買い残金額" . "\t" .
    "買い残前回比";

  foreach ($parsed['rows'] as $row) {
    /*
     * 日付
     * YYYY-MM-DD
     */
    $date =
      (string)$row['date'];

    /*
     * 枚数・金額:
     * 万株・億株、億円・兆円形式。
     * 全角文字を考慮して14文字幅で右寄せ。
     */
    $sellShares =
      pad_margin_balance_left_(
        (string)$row['sell_shares'],
        14
      );

    $sellAmount =
      pad_margin_balance_left_(
        (string)$row['sell_amount'],
        14
      );

    $buyShares =
      pad_margin_balance_left_(
        (string)$row['buy_shares'],
        14
      );

    $buyAmount =
      pad_margin_balance_left_(
        (string)$row['buy_amount'],
        14
      );

    /*
     * 前回比:
     * 正負の符号を含め、
     * -999.00%まで考慮して8文字幅で右寄せ。
     */
    $sellChange =
      pad_margin_balance_left_(
        (string)$row['sell_change'],
        8
      );

    $buyChange =
      pad_margin_balance_left_(
        (string)$row['buy_change'],
        8
      );

    /*
     * 信用倍率・信用評価率:
     * 信用倍率は999.00、
     * 信用評価率は999.00%を基本形式とし、
     * 8文字幅で右寄せ。
     *
     * 未公表の場合の "-"
     * も同じ幅で右寄せする。
     */
    $marginRatio =
      pad_margin_balance_left_(
        (string)$row['margin_ratio'],
        8
      );

    $profitLossRatio =
      pad_margin_balance_left_(
        (string)$row['profit_loss_ratio'],
        8
      );

    $lines[] =
      $date . "\t" .
      $marginRatio . "\t" .
      $profitLossRatio . "\t" .
      $sellShares . "\t" .
      $sellAmount . "\t" .
      $sellChange . "\t" .
      $buyShares . "\t" .
      $buyAmount . "\t" .
      $buyChange;
  }

  return implode("\n", $lines);
}