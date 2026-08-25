<?php
/**
 * 市況関連データ抽出：世界バフェット指数
 *
 * market_data_extract.php から読み込まれ、
 * 世界バフェット指数HTMLの解析および
 * メッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 *   class_xpath_()
 */

// =======================================================
// 世界バフェット指数
// =======================================================

/**
 * 世界バフェット指数HTMLから、
 * 主要国合計のバフェット指数概況および
 * 国別バフェット指数を取得する。
 *
 * @param string $html
 * @return array
 */
function parse_global_buffett_indicator_html_($html) {
  $xpath = load_xpath_($html);

  // -------------------------------------------------------
  // 主要国合計のバフェット指数概況
  // -------------------------------------------------------

  $summaryTableNodes = $xpath->query(
    "//table[@id='datatblSummary']"
  );

  if (
    !$summaryTableNodes ||
    $summaryTableNodes->length !== 1
  ) {
    throw new RuntimeException(
      "主要国合計のバフェット指数概況テーブルが1件取得できません: " .
      ($summaryTableNodes
        ? $summaryTableNodes->length
        : 0)
    );
  }

  $summaryTable =
    $summaryTableNodes->item(0);

  /*
   * caption確認。
   */
  $summaryCaptionNodes = $xpath->query(
    "./caption//*[contains(" .
      "normalize-space(.)," .
      "'主要国合計のバフェット指数概況'" .
    ")]",
    $summaryTable
  );

  if (
    !$summaryCaptionNodes ||
    $summaryCaptionNodes->length === 0
  ) {
    throw new RuntimeException(
      "主要国合計のバフェット指数概況の見出しが取得できません。"
    );
  }

  /*
   * 世界時価総額 合計
   */
  $worldMarketCap =
    get_global_buffett_text_by_id_(
      $xpath,
      $summaryTable,
      'bfSumMc',
      '世界時価総額合計'
    );

  $worldMarketCapCaption =
    get_global_buffett_text_by_id_(
      $xpath,
      $summaryTable,
      'bfSumMcYen',
      '世界時価総額合計キャプション'
    );

  /*
   * 世界予想名目GDP 合計
   */
  $worldGdp =
    get_global_buffett_text_by_id_(
      $xpath,
      $summaryTable,
      'bfSumGdp',
      '世界予想名目GDP合計'
    );

  $worldGdpCaption =
    get_global_buffett_text_by_id_(
      $xpath,
      $summaryTable,
      'bfSumGdpSub',
      '世界予想名目GDP合計キャプション'
    );

  /*
   * 世界バフェット指数
   */
  $worldBuffett =
    get_global_buffett_text_by_id_(
      $xpath,
      $summaryTable,
      'bfSumBf',
      '世界バフェット指数'
    );

  $worldBuffettCaption =
    get_global_buffett_text_by_id_(
      $xpath,
      $summaryTable,
      'bfSumBand',
      '世界バフェット指数評価'
    );

  /*
   * 各値が想定する形式であることを確認する。
   *
   * 世界時価総額・GDP:
   *   $148.8兆
   *
   * 世界バフェット指数:
   *   149%
   */
  if (
    !preg_match(
      '/^\$[\d,]+(?:\.\d+)?兆$/u',
      $worldMarketCap
    )
  ) {
    throw new RuntimeException(
      "世界時価総額合計の形式が不正です: " .
      $worldMarketCap
    );
  }

  if (
    !preg_match(
      '/^\$[\d,]+(?:\.\d+)?兆$/u',
      $worldGdp
    )
  ) {
    throw new RuntimeException(
      "世界予想名目GDP合計の形式が不正です: " .
      $worldGdp
    );
  }

  if (
    !preg_match(
      '/^\d+(?:\.\d+)?%$/',
      $worldBuffett
    )
  ) {
    throw new RuntimeException(
      "世界バフェット指数の形式が不正です: " .
      $worldBuffett
    );
  }

  // -------------------------------------------------------
  // 国別バフェット指数
  // -------------------------------------------------------

  $countryTableNodes = $xpath->query(
    "//table[@id='datatbl']"
  );

  if (
    !$countryTableNodes ||
    $countryTableNodes->length !== 1
  ) {
    throw new RuntimeException(
      "国別バフェット指数テーブルが1件取得できません: " .
      ($countryTableNodes
        ? $countryTableNodes->length
        : 0)
    );
  }

  $countryTable =
    $countryTableNodes->item(0);

  /*
   * caption確認。
   */
  $countryCaptionNodes = $xpath->query(
    "./caption//*[contains(" .
      "normalize-space(.)," .
      "'国別バフェット指数'" .
    ")]",
    $countryTable
  );

  if (
    !$countryCaptionNodes ||
    $countryCaptionNodes->length === 0
  ) {
    throw new RuntimeException(
      "国別バフェット指数の見出しが取得できません。"
    );
  }

  /*
   * tbody直下のtrを取得する。
   */
  $rowNodes = $xpath->query(
    "./tbody/tr",
    $countryTable
  );

  if (
    !$rowNodes ||
    $rowNodes->length !== 24
  ) {
    throw new RuntimeException(
      "国別バフェット指数の取得件数が24件ではありません: " .
      ($rowNodes
        ? $rowNodes->length
        : 0)
    );
  }

  $rows = array();

  for (
    $i = 0;
    $i < $rowNodes->length;
    $i++
  ) {
    $rowNo = $i + 1;
    $rowNode = $rowNodes->item($i);

    $cells = $xpath->query(
      "./td",
      $rowNode
    );

    if (
      !$cells ||
      $cells->length !== 6
    ) {
      throw new RuntimeException(
        "国別バフェット指数の列数が6列ではありません: " .
        "row={$rowNo}" .
        " columns=" .
        ($cells ? $cells->length : 0)
      );
    }

    // -----------------------------------------------------
    // 国・地域
    // -----------------------------------------------------

    $countryNodes = $xpath->query(
      ".//*[" . class_xpath_('jp') . "]",
      $cells->item(0)
    );

    if (
      !$countryNodes ||
      $countryNodes->length !== 1
    ) {
      throw new RuntimeException(
        "国・地域が1件取得できません: " .
        "row={$rowNo}"
      );
    }

    $country =
      normalize_text_(
        $countryNodes->item(0)->textContent
      );

    if ($country === '') {
      throw new RuntimeException(
        "国・地域が空です: row={$rowNo}"
      );
    }

    // -----------------------------------------------------
    // 時価総額
    // -----------------------------------------------------

    $marketCapCell = $cells->item(1);

    /*
     * td直下のテキストノードが時価総額。
     * span.bfYrが日付。
     */
    $marketCapRaw =
      get_global_buffett_direct_text_(
        $xpath,
        $marketCapCell
      );

    $marketCap =
      format_global_buffett_decimal_(
        $marketCapRaw,
        '時価総額（兆ドル）',
        $country,
        $rowNo,
        false
      );

    $dateNodes = $xpath->query(
      ".//*[" . class_xpath_('bfYr') . "]",
      $marketCapCell
    );

    if (
      !$dateNodes ||
      $dateNodes->length !== 1
    ) {
      throw new RuntimeException(
        "時価総額の日付が1件取得できません: " .
        "row={$rowNo}" .
        " country={$country}"
      );
    }

    $dateRaw =
      normalize_text_(
        $dateNodes->item(0)->textContent
      );

    $date =
      format_global_buffett_date_(
        $dateRaw,
        $country,
        $rowNo
      );

    // -----------------------------------------------------
    // 予想GDP
    // -----------------------------------------------------

    $gdpCell = $cells->item(2);

    $gdpRaw =
      get_global_buffett_direct_text_(
        $xpath,
        $gdpCell
      );

    $gdp =
      format_global_buffett_decimal_(
        $gdpRaw,
        '予想GDP（兆ドル）',
        $country,
        $rowNo,
        false
      );

    $referenceNodes = $xpath->query(
      ".//*[" . class_xpath_('bfYr') . "]",
      $gdpCell
    );

    if (
      !$referenceNodes ||
      $referenceNodes->length !== 1
    ) {
      throw new RuntimeException(
        "予想GDPの参考値が1件取得できません: " .
        "row={$rowNo}" .
        " country={$country}"
      );
    }

    $reference =
      normalize_text_(
        $referenceNodes->item(0)->textContent
      );

    if ($reference === '') {
      throw new RuntimeException(
        "予想GDPの参考値が空です: " .
        "row={$rowNo}" .
        " country={$country}"
      );
    }

    // -----------------------------------------------------
    // バフェット指数
    // -----------------------------------------------------

    $buffettRaw =
      normalize_text_(
        $cells->item(3)->textContent
      );

    $buffett =
      format_global_buffett_percent_(
        $buffettRaw,
        $country,
        $rowNo
      );

    // -----------------------------------------------------
    // 評価
    // -----------------------------------------------------

    $evaluation =
      normalize_text_(
        $cells->item(4)->textContent
      );

    if ($evaluation === '') {
      throw new RuntimeException(
        "バフェット指数の評価が空です: " .
        "row={$rowNo}" .
        " country={$country}"
      );
    }

    // -----------------------------------------------------
    // 実績差
    // -----------------------------------------------------

    $differenceRaw =
      normalize_text_(
        $cells->item(5)->textContent
      );

    $difference =
      format_global_buffett_decimal_(
        $differenceRaw,
        '実績差',
        $country,
        $rowNo,
        true
      );

    $rows[] = array(
      'country' => $country,
      'date' => $date,
      'market_cap' => $marketCap,
      'gdp' => $gdp,
      'reference' => $reference,
      'buffett' => $buffett,
      'evaluation' => $evaluation,
      'difference' => $difference,
    );
  }
  
  /*
   * バフェット指数の降順に並べ替える。
   */
  usort(
    $rows,
    function ($a, $b) {
      $aBuffett =
        (float)str_replace(
          array(',', '%'),
          '',
          $a['buffett']
        );

      $bBuffett =
        (float)str_replace(
          array(',', '%'),
          '',
          $b['buffett']
        );

      if ($aBuffett == $bBuffett) {
        return 0;
      }

      return
        ($aBuffett > $bBuffett)
          ? -1
          : 1;
    }
  );

  return array(
    'summary' => array(
      'world_market_cap' =>
        $worldMarketCap,

      'world_market_cap_caption' =>
        $worldMarketCapCaption,

      'world_gdp' =>
        $worldGdp,

      'world_gdp_caption' =>
        $worldGdpCaption,

      'world_buffett' =>
        $worldBuffett,

      'world_buffett_caption' =>
        $worldBuffettCaption,
    ),

    'countries' => $rows,
  );
}

/**
 * 指定idの要素を1件取得し、
 * 正規化した文字列を返す。
 *
 * @param DOMXPath $xpath
 * @param DOMNode $context
 * @param string $id
 * @param string $fieldName
 * @return string
 */
function get_global_buffett_text_by_id_(
  $xpath,
  $context,
  $id,
  $fieldName
) {
  $nodes = $xpath->query(
    ".//*[@id='{$id}']",
    $context
  );

  if (
    !$nodes ||
    $nodes->length !== 1
  ) {
    throw new RuntimeException(
      "{$fieldName}が1件取得できません: " .
      ($nodes ? $nodes->length : 0)
    );
  }

  $value =
    normalize_text_(
      $nodes->item(0)->textContent
    );

  if ($value === '') {
    throw new RuntimeException(
      "{$fieldName}が空です。"
    );
  }

  return $value;
}

/**
 * 要素直下のテキストノードだけを取得する。
 *
 * span等の子要素に記載された補足値を除外するために使用する。
 *
 * 例:
 *
 *   <td class="bfMc">
 *     81.9
 *     <span class="bfYr">2026/8/14</span>
 *   </td>
 *
 * → 81.9
 *
 * @param DOMXPath $xpath
 * @param DOMNode $node
 * @return string
 */
function get_global_buffett_direct_text_(
  $xpath,
  $node
) {
  $textNodes = $xpath->query(
    "./text()",
    $node
  );

  if (
    !$textNodes ||
    $textNodes->length === 0
  ) {
    return '';
  }

  $parts = array();

  foreach ($textNodes as $textNode) {
    $text =
      normalize_text_(
        $textNode->nodeValue
      );

    if ($text !== '') {
      $parts[] = $text;
    }
  }

  return normalize_text_(
    implode(
      ' ',
      $parts
    )
  );
}

/**
 * yyyy/M/d または yyyy/MM/dd形式の日付を、
 * yyyy-MM-dd形式へ変換する。
 *
 * @param string $value
 * @param string $country
 * @param int $rowNo
 * @return string
 */
function format_global_buffett_date_(
  $value,
  $country,
  $rowNo
) {
  if (
    !preg_match(
      '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/',
      trim((string)$value),
      $matches
    )
  ) {
    throw new RuntimeException(
      "国別バフェット指数の日付形式が不正です: " .
      "row={$rowNo}" .
      " country={$country}" .
      " value={$value}"
    );
  }

  $year = (int)$matches[1];
  $month = (int)$matches[2];
  $day = (int)$matches[3];

  if (
    !checkdate(
      $month,
      $day,
      $year
    )
  ) {
    throw new RuntimeException(
      "国別バフェット指数の日付が不正です: " .
      "row={$rowNo}" .
      " country={$country}" .
      " value={$value}"
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
 * 小数値を検証・整形する。
 *
 * $signed=false:
 *   9,999.00
 *
 * $signed=true:
 *   正数 +9,999.00
 *   負数 -9,999.00
 *   0    0.00
 *
 * @param string $value
 * @param string $fieldName
 * @param string $country
 * @param int $rowNo
 * @param bool $signed
 * @return string
 */
function format_global_buffett_decimal_(
  $value,
  $fieldName,
  $country,
  $rowNo,
  $signed
) {
  $original =
    trim((string)$value);

  if ($original === '') {
    throw new RuntimeException(
      "国別バフェット指数の数値が空です: " .
      "row={$rowNo}" .
      " country={$country}" .
      " field={$fieldName}"
    );
  }

  /*
   * 実績差では、差が0の場合に
   * 「±0」と表記されることがある。
   *
   * ±付きの0だけを0として正規化する。
   */
  if (
    preg_match(
      '/^±0(?:\.0+)?$/u',
      $original
    )
  ) {
    $original = '0';
  }

  $raw =
    str_replace(
      array(
        ',',
        '＋',
        '－'
      ),
      array(
        '',
        '+',
        '-'
      ),
      $original
    );

  if (
    !preg_match(
      '/^[+-]?\d+(?:\.\d+)?$/',
      $raw
    )
  ) {
    throw new RuntimeException(
      "国別バフェット指数の数値形式が不正です: " .
      "row={$rowNo}" .
      " country={$country}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  $number = (float)$raw;

  if (!$signed) {
    return number_format(
      $number,
      2,
      '.',
      ','
    );
  }

  if ($number > 0) {
    return
      '+' .
      number_format(
        $number,
        2,
        '.',
        ','
      );
  }

  if ($number < 0) {
    return
      '-' .
      number_format(
        abs($number),
        2,
        '.',
        ','
      );
  }

  return '0.00';
}

/**
 * バフェット指数を検証し、
 * 小数点以下2桁＋%へ整形する。
 *
 * @param string $value
 * @param string $country
 * @param int $rowNo
 * @return string
 */
function format_global_buffett_percent_(
  $value,
  $country,
  $rowNo
) {
  $original =
    trim((string)$value);

  if (
    !preg_match(
      '/^([\d,]+(?:\.\d+)?)%$/',
      $original,
      $matches
    )
  ) {
    throw new RuntimeException(
      "国別バフェット指数の指数形式が不正です: " .
      "row={$rowNo}" .
      " country={$country}" .
      " value={$value}"
    );
  }

  $number =
    (float)str_replace(
      ',',
      '',
      $matches[1]
    );

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
 * 全角1文字を表示幅2、
 * 半角1文字を表示幅1として扱い、
 * 指定幅まで右側を半角スペースで埋める。
 *
 * 指定幅以上の場合は切り捨てない。
 *
 * @param string $value
 * @param int $width
 * @param string $fieldName
 * @return string
 */
function pad_global_buffett_right_(
  $value,
  $width,
  $fieldName
) {
  if (
    !function_exists(
      'mb_strwidth'
    )
  ) {
    throw new RuntimeException(
      "mb_strwidth()が使用できません: " .
      $fieldName
    );
  }

  $value = (string)$value;

  $currentWidth =
    mb_strwidth(
      $value,
      'UTF-8'
    );

  if ($currentWidth >= $width) {
    return $value;
  }

  return
    $value .
    str_repeat(
      ' ',
      $width - $currentWidth
    );
}

/**
 * 世界バフェット指数の抽出結果から
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_global_buffett_indicator_message_(
  $parsed
) {
  if (
    !isset($parsed['summary']) ||
    !is_array($parsed['summary'])
  ) {
    throw new RuntimeException(
      "世界バフェット指数の概況データがありません。"
    );
  }

  if (
    !isset($parsed['countries']) ||
    !is_array($parsed['countries']) ||
    count($parsed['countries']) !== 24
  ) {
    throw new RuntimeException(
      "国別バフェット指数が24件ではありません。"
    );
  }

  $summary = $parsed['summary'];

  $lines = array();

  // =====================================================
  // 全体見出し
  // =====================================================

  $lines[] =
    "■世界バフェット指数";

  $lines[] = '';

  // =====================================================
  // 主要国合計のバフェット指数概況
  // =====================================================

  $lines[] =
    "【世界時価総額、世界予想名目GDP、世界バフェット指数】";

  /*
   * 各値は全角10文字分
   * = 表示幅20。
   */
  $worldMarketCap =
    pad_global_buffett_right_(
      $summary['world_market_cap'],
      20,
      '世界時価総額合計'
    );

  $worldGdp =
    pad_global_buffett_right_(
      $summary['world_gdp'],
      20,
      '世界予想名目GDP合計'
    );

  $worldBuffett =
    pad_global_buffett_right_(
      $summary['world_buffett'],
      20,
      '世界バフェット指数'
    );

  $lines[] =
    "世界時価総額合計\t" .
    $worldMarketCap .
    "　※" .
    $summary[
      'world_market_cap_caption'
    ];

  $lines[] =
    "世界予想名目GDP合計\t" .
    $worldGdp .
    "　※" .
    $summary[
      'world_gdp_caption'
    ];

  $lines[] =
    "世界バフェット指数\t" .
    $worldBuffett .
    "　※" .
    $summary[
      'world_buffett_caption'
    ];

  $lines[] = '';

  // =====================================================
  // 国別バフェット指数
  // =====================================================

  $lines[] =
    "【国別バフェット指数】";

  $lines[] =
    "国・地域\t" .
    "日付\t" .
    "時価総額（兆ドル）\t" .
    "予想GDP（兆ドル）\t" .
    "参考値\t" .
    "バフェット指数\t" .
    "評価\t" .
    "実績差";

  foreach ($parsed['countries'] as $row) {
    /*
     * 国・地域:
     * 全角15文字分 = 表示幅30。
     */
    $country =
      pad_global_buffett_right_(
        $row['country'],
        30,
        '国・地域'
      );

    /*
     * 日付:
     * yyyy-MM-dd = 10文字。
     */
    $date =
      str_pad(
        (string)$row['date'],
        10,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * 時価総額・予想GDP:
     * 9,999.00 = 最大8文字。
     */
    $marketCap =
      str_pad(
        (string)$row['market_cap'],
        8,
        ' ',
        STR_PAD_LEFT
      );

    $gdp =
      str_pad(
        (string)$row['gdp'],
        8,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * 参考値:
     * 全角8文字分 = 表示幅16。
     */
    $reference =
      pad_global_buffett_right_(
        $row['reference'],
        16,
        '参考値'
      );

    /*
     * バフェット指数:
     * 99999.00% = 最大9文字。
     */
    $buffett =
      str_pad(
        (string)$row['buffett'],
        9,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * 評価:
     * 全角8文字分 = 表示幅16。
     */
    $evaluation =
      pad_global_buffett_right_(
        $row['evaluation'],
        16,
        '評価'
      );

    /*
     * 実績差:
     *
     * ±9,999.00まで考慮し、
     * 9文字幅で右寄せする。
     */
    $difference =
      str_pad(
        (string)$row['difference'],
        9,
        ' ',
        STR_PAD_LEFT
      );

    $lines[] =
      $country . "\t" .
      $date . "\t" .
      $marketCap . "\t" .
      $gdp . "\t" .
      $reference . "\t" .
      $buffett . "\t" .
      $evaluation . "\t" .
      $difference;
  }

  return implode(
    "\n",
    $lines
  );
}