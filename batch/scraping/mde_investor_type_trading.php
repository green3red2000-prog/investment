<?php
/**
 * 市況関連データ抽出：投資部門別売買状況
 *
 * market_data_extract.php から読み込まれ、
 * 投資主体別売買状況のHTML解析およびメッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 */

// =======================================================
// 投資部門別売買状況
// =======================================================

/**
 * 投資主体別売買状況HTMLから、
 * 「投資主体別 売買状況」の最新5行を取得する。
 *
 * @param string $html
 * @return array
 */
function parse_investor_type_trading_html_($html) {
  $xpath = load_xpath_($html);

  /*
   * 対象テーブルを取得する。
   */
  $tableNodes = $xpath->query(
    "//table[@id='datatbl']"
  );

  if (!$tableNodes || $tableNodes->length !== 1) {
    throw new RuntimeException(
      "投資主体別売買状況テーブルが1件取得できません: " .
      ($tableNodes ? $tableNodes->length : 0)
    );
  }

  $tableNode = $tableNodes->item(0);

  /*
   * captionが
   * 「投資主体別 売買状況」
   * であることを確認する。
   */
  $captionNodes = $xpath->query(
    "./caption",
    $tableNode
  );

  if (!$captionNodes || $captionNodes->length !== 1) {
    throw new RuntimeException(
      "投資主体別売買状況テーブルの見出しが1件取得できません: " .
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
      '投資主体別 売買状況'
    ) === false
  ) {
    throw new RuntimeException(
      "投資主体別売買状況テーブルの見出しが想定と一致しません: " .
      $caption
    );
  }

  /*
   * 週次データ行だけを取得する。
   *
   * 月計・年計の行にはtime要素が存在しないため、
   * 1列目にtime要素を持つtrだけを対象とする。
   */
  $rowNodes = $xpath->query(
    "./tr[td[1]//time]",
    $tableNode
  );

  if (!$rowNodes || $rowNodes->length < 5) {
    throw new RuntimeException(
      "投資主体別売買状況の週次データ行が5件以上取得できません: " .
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

    if (!$cells || $cells->length !== 14) {
      throw new RuntimeException(
        "投資主体別売買状況の列数が14列ではありません: " .
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
        "投資主体別売買状況の日付が1件取得できません: " .
        "row=" . ($i + 1)
      );
    }

    $dateRaw =
      normalize_text_(
        $dateNodes->item(0)->textContent
      );

    $date =
      format_investor_type_trading_date_(
        $dateRaw,
        $i + 1
      );

    /*
     * 2列目：日本225
     */
    $nikkei225Raw =
      normalize_text_(
        $cells->item(1)->textContent
      );

    $nikkei225 =
      format_investor_type_trading_price_(
        $nikkei225Raw,
        '日経平均',
        $i + 1
      );

    /*
     * 3列目：日本225変化(週)
     *
     * HTMLでは、
     * ▲0.73%
     * ▼0.39%
     * 0.00%
     *
     * のように方向記号が付与される。
     */
    $weeklyChangeRaw =
      normalize_text_(
        $cells->item(2)->textContent
      );

    $weeklyChange =
      format_investor_type_trading_percent_(
        $weeklyChangeRaw,
        '週日経平均騰落率',
        $i + 1
      );

    /*
     * 4～14列目：
     * 投資主体別売買額。
     */
    $overseas =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(3)->textContent
        ),
        '海外',
        $i + 1
      );

    $securities =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(4)->textContent
        ),
        '証券自己',
        $i + 1
      );

    $individualTotal =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(5)->textContent
        ),
        '個人計',
        $i + 1
      );

    $individualCash =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(6)->textContent
        ),
        '個人(現金)',
        $i + 1
      );

    $individualMargin =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(7)->textContent
        ),
        '個人(信用)',
        $i + 1
      );

    $investmentTrust =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(8)->textContent
        ),
        '投資信託',
        $i + 1
      );

    $businessCorporation =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(9)->textContent
        ),
        '事業法人',
        $i + 1
      );

    $otherCorporation =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(10)->textContent
        ),
        'その他法人',
        $i + 1
      );

    $trustBank =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(11)->textContent
        ),
        '信託銀行',
        $i + 1
      );

    $lifeNonlife =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(12)->textContent
        ),
        '生保損保',
        $i + 1
      );

    $cityRegionalBank =
      format_investor_type_trading_amount_(
        normalize_text_(
          $cells->item(13)->textContent
        ),
        '都銀地銀',
        $i + 1
      );

    $rows[] = array(
      'date' => $date,
      'nikkei225' => $nikkei225,
      'weekly_change' => $weeklyChange,
      'overseas' => $overseas,
      'securities' => $securities,
      'individual_total' => $individualTotal,
      'individual_cash' => $individualCash,
      'individual_margin' => $individualMargin,
      'investment_trust' => $investmentTrust,
      'business_corporation' => $businessCorporation,
      'other_corporation' => $otherCorporation,
      'trust_bank' => $trustBank,
      'life_nonlife' => $lifeNonlife,
      'city_regional_bank' => $cityRegionalBank,
    );
  }

  /*
   * 日付が最新順になっていることを確認する。
   */
  for ($i = 1; $i < count($rows); $i++) {
    if (
      strcmp(
        $rows[$i - 1]['date'],
        $rows[$i]['date']
      ) <= 0
    ) {
      throw new RuntimeException(
        "投資主体別売買状況の日付順が最新順ではありません: " .
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
 * 日付を検証し、
 * yyyy-MM-dd形式へ正規化する。
 *
 * HTMLはyyyy/MM/dd形式。
 *
 * @param string $value
 * @param int $rowNo
 * @return string
 */
function format_investor_type_trading_date_(
  $value,
  $rowNo
) {
  if (
    !preg_match(
      '/^(\d{4})\/(\d{2})\/(\d{2})$/',
      trim((string)$value),
      $matches
    )
  ) {
    throw new RuntimeException(
      "投資主体別売買状況の日付形式が不正です: " .
      "row={$rowNo} value={$value}"
    );
  }

  $year = (int)$matches[1];
  $month = (int)$matches[2];
  $day = (int)$matches[3];

  if (!checkdate($month, $day, $year)) {
    throw new RuntimeException(
      "投資主体別売買状況の日付が不正です: " .
      "row={$rowNo} value={$value}"
    );
  }

  return sprintf(
    '%04d-%02d-%02d',
    $year,
    $month,
    $day
  );
}

/**
 * 日経平均を検証し、
 * 小数点以下2桁、3桁ごとのカンマ区切りへ
 * 正規化する。
 *
 * 欠損値は"-"として扱う。
 *
 * @param string $value
 * @param string $fieldName
 * @param int $rowNo
 * @return string
 */
function format_investor_type_trading_price_(
  $value,
  $fieldName,
  $rowNo
) {
  $original =
    trim((string)$value);

  if (
    $original === '' ||
    $original === '-' ||
    $original === '－' ||
    $original === 'ー'
  ) {
    return '-';
  }

  $raw =
    str_replace(
      ',',
      '',
      $original
    );

  if (
    !preg_match(
      '/^\d+(?:\.\d+)?$/',
      $raw
    )
  ) {
    throw new RuntimeException(
      "投資主体別売買状況の株価形式が不正です: " .
      "row={$rowNo}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  return number_format(
    (float)$raw,
    2,
    '.',
    ','
  );
}

/**
 * 週日経平均騰落率を検証し、
 * 小数点以下2桁＋%へ正規化する。
 *
 * ▲または+：正数
 * ▼または-：負数
 * 0：符号なし
 *
 * 欠損値は"-"として扱う。
 *
 * @param string $value
 * @param string $fieldName
 * @param int $rowNo
 * @return string
 */
function format_investor_type_trading_percent_(
  $value,
  $fieldName,
  $rowNo
) {
  $original =
    trim((string)$value);

  if (
    $original === '' ||
    $original === '-' ||
    $original === '－' ||
    $original === 'ー'
  ) {
    return '-';
  }

  $isPositive =
    strpos($original, '▲') !== false ||
    strpos($original, '+') !== false ||
    strpos($original, '＋') !== false;

  $isNegative =
    strpos($original, '▼') !== false ||
    strpos($original, '-') !== false ||
    strpos($original, '－') !== false;

  if ($isPositive && $isNegative) {
    throw new RuntimeException(
      "投資主体別売買状況の騰落率方向が不正です: " .
      "row={$rowNo}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  $raw =
    str_replace(
      array(
        ',',
        '%',
        '▲',
        '▼',
        '+',
        '-',
        '＋',
        '－'
      ),
      '',
      $original
    );

  $raw = trim($raw);

  if (
    $raw === '' ||
    !preg_match(
      '/^\d+(?:\.\d+)?$/',
      $raw
    )
  ) {
    throw new RuntimeException(
      "投資主体別売買状況の騰落率形式が不正です: " .
      "row={$rowNo}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  $number = (float)$raw;

  if ($number == 0.0) {
    return '0.00%';
  }

  if ($isPositive) {
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

  if ($isNegative) {
    return
      '-' .
      number_format(
        $number,
        2,
        '.',
        ''
      ) .
      '%';
  }

  /*
   * 非0にもかかわらず方向が取得できない場合は
   * 異常終了とする。
   */
  throw new RuntimeException(
    "投資主体別売買状況の騰落率方向を判定できません: " .
    "row={$rowNo}" .
    " field={$fieldName}" .
    " value={$value}"
  );
}

/**
 * 投資主体別売買額を検証し、
 * 符号付き整数・3桁ごとのカンマ区切りへ
 * 正規化する。
 *
 * 正数には+を付加する。
 * 負数には-を付加する。
 * 0には符号を付加しない。
 *
 * 欠損値は"-"として扱う。
 *
 * @param string $value
 * @param string $fieldName
 * @param int $rowNo
 * @return string
 */
function format_investor_type_trading_amount_(
  $value,
  $fieldName,
  $rowNo
) {
  $original =
    trim((string)$value);

  if (
    $original === '' ||
    $original === '-' ||
    $original === '－' ||
    $original === 'ー'
  ) {
    return '-';
  }

  $raw =
    str_replace(
      array(',', '＋', '－'),
      array('', '+', '-'),
      $original
    );

  if (
    !preg_match(
      '/^[+-]?\d+$/',
      $raw
    )
  ) {
    throw new RuntimeException(
      "投資主体別売買状況の数値形式が不正です: " .
      "row={$rowNo}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  $number = (int)$raw;

  if ($number > 0) {
    return
      '+' .
      number_format(
        $number,
        0,
        '.',
        ','
      );
  }

  if ($number < 0) {
    return
      '-' .
      number_format(
        abs($number),
        0,
        '.',
        ','
      );
  }

  return '0';
}

/**
 * 投資部門別売買状況の抽出結果から
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_investor_type_trading_message_(
  $parsed
) {
  if (
    !isset($parsed['rows']) ||
    !is_array($parsed['rows']) ||
    count($parsed['rows']) !== 5
  ) {
    throw new RuntimeException(
      "投資部門別売買状況のレポート対象が5件ではありません。"
    );
  }

  $lines = array();

  $lines[] = "■投資部門別売買状況";
  $lines[] = '';

  /*
   * 指定されたレポート見出しを使用する。
   */
  $lines[] = "【東証プライム市場　新高値/新安値】";

  /*
   * 列見出し。
   */
  $lines[] =
    "日付" . "\t" .
    "日経平均" . "\t" .
    "週日経平均騰落率" . "\t" .
    "海外" . "\t" .
    "証券自己" . "\t" .
    "個人計" . "\t" .
    "個人(現金)" . "\t" .
    "個人(信用)" . "\t" .
    "投資信託" . "\t" .
    "事業法人" . "\t" .
    "その他法人" . "\t" .
    "信託銀行" . "\t" .
    "生保損保" . "\t" .
    "都銀地銀";

  foreach ($parsed['rows'] as $row) {
    /*
     * 日経平均
     * 999,999.00
     * 最大10文字幅で右寄せ。
     */
    $nikkei225 =
      str_pad(
        (string)$row['nikkei225'],
        10,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * 週日経平均騰落率
     * ±999.00%まで考慮して8文字幅で右寄せ。
     */
    $weeklyChange =
      str_pad(
        (string)$row['weekly_change'],
        8,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * 売買額
     *
     * 99,999,999に符号が付く可能性があるため、
     * 最大11文字幅で右寄せする。
     */
    $overseas =
      pad_investor_type_trading_amount_(
        $row['overseas']
      );

    $securities =
      pad_investor_type_trading_amount_(
        $row['securities']
      );

    $individualTotal =
      pad_investor_type_trading_amount_(
        $row['individual_total']
      );

    $individualCash =
      pad_investor_type_trading_amount_(
        $row['individual_cash']
      );

    $individualMargin =
      pad_investor_type_trading_amount_(
        $row['individual_margin']
      );

    $investmentTrust =
      pad_investor_type_trading_amount_(
        $row['investment_trust']
      );

    $businessCorporation =
      pad_investor_type_trading_amount_(
        $row['business_corporation']
      );

    $otherCorporation =
      pad_investor_type_trading_amount_(
        $row['other_corporation']
      );

    $trustBank =
      pad_investor_type_trading_amount_(
        $row['trust_bank']
      );

    $lifeNonlife =
      pad_investor_type_trading_amount_(
        $row['life_nonlife']
      );

    $cityRegionalBank =
      pad_investor_type_trading_amount_(
        $row['city_regional_bank']
      );

    $lines[] =
      $row['date'] . "\t" .
      $nikkei225 . "\t" .
      $weeklyChange . "\t" .
      $overseas . "\t" .
      $securities . "\t" .
      $individualTotal . "\t" .
      $individualCash . "\t" .
      $individualMargin . "\t" .
      $investmentTrust . "\t" .
      $businessCorporation . "\t" .
      $otherCorporation . "\t" .
      $trustBank . "\t" .
      $lifeNonlife . "\t" .
      $cityRegionalBank;
  }

  return implode("\n", $lines);
}

/**
 * 投資主体別売買額を11文字幅で右寄せする。
 *
 * 欠損値"-"についても同じ幅で右寄せする。
 *
 * @param string $value
 * @return string
 */
function pad_investor_type_trading_amount_(
  $value
) {
  return str_pad(
    (string)$value,
    11,
    ' ',
    STR_PAD_LEFT
  );
}