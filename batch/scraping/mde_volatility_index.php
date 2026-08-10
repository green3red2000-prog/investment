<?php
/**
 * 市況関連データ抽出：恐怖指数
 *
 * market_data_extract.php から読み込まれ、
 * 恐怖指数のHTML解析およびレポート生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 *   class_xpath_()
 */

// =======================================================
// 恐怖指数
// =======================================================

/**
 * 恐怖指数HTMLを解析する。
 *
 * 「恐怖指数」の表から、各指数について以下を取得する。
 *
 *   指数名
 *   現在値
 *   前日比
 *   騰落率
 *   更新日
 */
function parse_volatility_index_html_($html) {
  $xpath = load_xpath_($html);

  /*
   * 「恐怖指数」の表。
   */
  $tableNodes = $xpath->query(
    "//table[@id='ajaxTbl']"
  );

  if (!$tableNodes || $tableNodes->length === 0) {
    throw new RuntimeException(
      "恐怖指数のテーブルが見つかりません。"
    );
  }

  if ($tableNodes->length !== 1) {
    throw new RuntimeException(
      "恐怖指数のテーブルが複数存在します: " .
      $tableNodes->length
    );
  }

  $table = $tableNodes->item(0);

  /*
   * データ行を取得する。
   *
   * 各データ行は、
   *
   *   th：指数名
   *   td：現在値、前日比、騰落率、更新日
   *
   * で構成される。
   *
   * 表末尾の説明行などを除外するため、
   * thとtdの両方を持つtrだけを対象とする。
   */
  $rowNodes = $xpath->query(
    ".//tbody/tr[th and td]",
    $table
  );

  if (!$rowNodes || $rowNodes->length === 0) {
    throw new RuntimeException(
      "恐怖指数のデータ行が見つかりません。"
    );
  }

  $rows = array();

  for ($i = 0; $i < $rowNodes->length; $i++) {
    $rowNode = $rowNodes->item($i);

    /*
     * 指数名
     */
    $nameNodes = $xpath->query(
      ".//th//*[" . class_xpath_('THp') . "]",
      $rowNode
    );

    if (!$nameNodes || $nameNodes->length !== 1) {
      throw new RuntimeException(
        "恐怖指数の指数名が取得できません: " .
        "row=" . ($i + 1) .
        " count=" . ($nameNodes ? $nameNodes->length : 0)
      );
    }

    $name = normalize_text_(
      $nameNodes->item(0)->textContent
    );

    if ($name === '') {
      throw new RuntimeException(
        "恐怖指数の指数名が空です: " .
        "row=" . ($i + 1)
      );
    }

    /*
     * 現在値
     */
    $currentValueNodes = $xpath->query(
      ".//td//*[" . class_xpath_('val4') . "]",
      $rowNode
    );

    if (
      !$currentValueNodes ||
      $currentValueNodes->length !== 1
    ) {
      throw new RuntimeException(
        "恐怖指数の現在値が取得できません: " .
        "row=" . ($i + 1) .
        " name={$name}" .
        " count=" .
        ($currentValueNodes ? $currentValueNodes->length : 0)
      );
    }

    $currentValue =
      normalize_volatility_index_number_(
        $currentValueNodes->item(0)->textContent,
        $i + 1,
        $name,
        '現在値',
        false
      );

    /*
     * 前日比
     */
    $changeNodes = $xpath->query(
      ".//td//*[" . class_xpath_('zen4') . "]",
      $rowNode
    );

    if (!$changeNodes || $changeNodes->length !== 1) {
      throw new RuntimeException(
        "恐怖指数の前日比が取得できません: " .
        "row=" . ($i + 1) .
        " name={$name}" .
        " count=" .
        ($changeNodes ? $changeNodes->length : 0)
      );
    }

    $change =
      normalize_volatility_index_number_(
        $changeNodes->item(0)->textContent,
        $i + 1,
        $name,
        '前日比',
        true
      );

    /*
     * 騰落率
     */
    $rateNodes = $xpath->query(
      ".//td//*[" . class_xpath_('chg4') . "]",
      $rowNode
    );

    if (!$rateNodes || $rateNodes->length !== 1) {
      throw new RuntimeException(
        "恐怖指数の騰落率が取得できません: " .
        "row=" . ($i + 1) .
        " name={$name}" .
        " count=" .
        ($rateNodes ? $rateNodes->length : 0)
      );
    }

    $rate =
      normalize_volatility_index_rate_(
        $rateNodes->item(0)->textContent,
        $i + 1,
        $name
      );

    /*
     * 更新日
     */
    $updateNodes = $xpath->query(
      ".//td//*[" . class_xpath_('tim4') .
      "]//*[" . class_xpath_('scol') . "]",
      $rowNode
    );

    if (!$updateNodes || $updateNodes->length !== 1) {
      throw new RuntimeException(
        "恐怖指数の更新日が取得できません: " .
        "row=" . ($i + 1) .
        " name={$name}" .
        " count=" .
        ($updateNodes ? $updateNodes->length : 0)
      );
    }

    $updated =
      normalize_volatility_index_updated_(
        $updateNodes->item(0)->textContent,
        $i + 1,
        $name
      );

    $rows[] = array(
      'name' => $name,
      'current_value' => $currentValue,
      'change' => $change,
      'rate' => $rate,
      'updated' => $updated,
    );
  }

  if (count($rows) === 0) {
    throw new RuntimeException(
      "恐怖指数の抽出結果が0件です。"
    );
  }

  return array(
    'rows' => $rows,
  );
}

/**
 * 現在値または前日比を検証・正規化する。
 *
 * 現在値：
 *   小数点以下2桁
 *   3桁ごとのカンマ区切り
 *
 * 前日比：
 *   小数点以下2桁
 *   3桁ごとのカンマ区切り
 *   正数にはプラス記号を付加
 *   負数にはマイナス記号を付加
 *   0には符号を付加しない
 */
function normalize_volatility_index_number_(
  $value,
  $rowNumber,
  $indexName,
  $fieldName,
  $withSign
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
      "恐怖指数の数値形式が不正です: " .
      "row={$rowNumber}" .
      " name={$indexName}" .
      " field={$fieldName}" .
      " value={$text}"
    );
  }

  $number = (float)$numericText;

  if ($withSign) {
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
  }

  return number_format(
    $number,
    2,
    '.',
    ','
  );
}

/**
 * 騰落率を検証・正規化する。
 *
 * HTMLでは、
 *
 *   ▲1.21%
 *   ▼6.44%
 *   0.00%
 *
 * の形式で表示される。
 *
 * レポートでは、
 *
 *   +1.21%
 *   -6.44%
 *   0.00%
 *
 * とする。
 */
function normalize_volatility_index_rate_(
  $value,
  $rowNumber,
  $indexName
) {
  $text = normalize_text_((string)$value);

  $isNegative =
    strpos($text, '▼') !== false ||
    strpos($text, '-') !== false;

  $isPositive =
    strpos($text, '▲') !== false ||
    strpos($text, '+') !== false;

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

  $numericText = trim($numericText);

  if (
    !preg_match(
      '/^\d+(?:\.\d+)?$/',
      $numericText
    )
  ) {
    throw new RuntimeException(
      "恐怖指数の騰落率形式が不正です: " .
      "row={$rowNumber}" .
      " name={$indexName}" .
      " value={$text}"
    );
  }

  $number = (float)$numericText;

  if ($number == 0.0) {
    return number_format(
      0,
      2,
      '.',
      ''
    ) . '%';
  }

  if ($isNegative) {
    return '-' .
      number_format(
        $number,
        2,
        '.',
        ''
      ) .
      '%';
  }

  if ($isPositive) {
    return '+' .
      number_format(
        $number,
        2,
        '.',
        ''
      ) .
      '%';
  }

  /*
   * 数値が0ではないにもかかわらず、
   * 上昇・下落の方向を判定できない場合は異常とする。
   */
  throw new RuntimeException(
    "恐怖指数の騰落率の方向を判定できません: " .
    "row={$rowNumber}" .
    " name={$indexName}" .
    " value={$text}"
  );
}

/**
 * 更新日を検証・正規化する。
 *
 * 以下のいずれかを許可する。
 *
 *   MM/DD
 *   HH:mm
 */
function normalize_volatility_index_updated_(
  $value,
  $rowNumber,
  $indexName
) {
  $text = normalize_text_((string)$value);

  if (
    preg_match(
      '/^(\d{2})\/(\d{2})$/',
      $text,
      $matches
    )
  ) {
    $month = (int)$matches[1];
    $day = (int)$matches[2];

    /*
     * 年は取得対象ではないため、
     * 月日として成立し得るかだけを確認する。
     *
     * うるう年の02/29も許容するため、
     * 2000年を検証用の年として使用する。
     */
    if (!checkdate($month, $day, 2000)) {
      throw new RuntimeException(
        "恐怖指数の更新日が不正です: " .
        "row={$rowNumber}" .
        " name={$indexName}" .
        " value={$text}"
      );
    }

    return sprintf(
      '%02d/%02d',
      $month,
      $day
    );
  }

  if (
    preg_match(
      '/^(\d{2}):(\d{2})$/',
      $text,
      $matches
    )
  ) {
    $hour = (int)$matches[1];
    $minute = (int)$matches[2];

    if (
      $hour < 0 ||
      $hour > 23 ||
      $minute < 0 ||
      $minute > 59
    ) {
      throw new RuntimeException(
        "恐怖指数の更新時刻が不正です: " .
        "row={$rowNumber}" .
        " name={$indexName}" .
        " value={$text}"
      );
    }

    return sprintf(
      '%02d:%02d',
      $hour,
      $minute
    );
  }

  throw new RuntimeException(
    "恐怖指数の更新日形式が不正です: " .
    "row={$rowNumber}" .
    " name={$indexName}" .
    " value={$text}"
  );
}

/**
 * 指数名を全角15文字分の表示幅へ整形する。
 *
 * 全角1文字を表示幅2として、
 * 表示幅30未満の場合は右側を半角スペースで埋める。
 *
 * 表示幅30以上の場合は切り捨てない。
 */
function format_volatility_index_name_($name) {
  $name = (string)$name;

  if (!function_exists('mb_strwidth')) {
    throw new RuntimeException(
      "指数名の表示幅計算に必要なmb_strwidth()が使用できません。"
    );
  }

  $width = mb_strwidth(
    $name,
    'UTF-8'
  );

  if ($width >= 30) {
    return $name;
  }

  return $name . str_repeat(
    ' ',
    30 - $width
  );
}

/**
 * 恐怖指数のレポート内容を作成する。
 */
function build_volatility_index_message_($parsed) {
  if (
    !is_array($parsed) ||
    !isset($parsed['rows']) ||
    !is_array($parsed['rows'])
  ) {
    throw new RuntimeException(
      "恐怖指数の解析結果が不正です。"
    );
  }

  if (count($parsed['rows']) === 0) {
    throw new RuntimeException(
      "恐怖指数のレポート出力件数が0件です。"
    );
  }

  $lines = array();

  $lines[] = '■恐怖指数';
  $lines[] = '';

  $lines[] = implode(
    "\t",
    array(
      '指数名',
      '現在値',
      '前日比',
      '騰落率',
      '更新日',
    )
  );

  foreach ($parsed['rows'] as $row) {
    $name =
      format_volatility_index_name_(
        $row['name']
      );

    /*
     * 表示幅
     *
     * 現在値:
     *   999,999,999.00
     *   14文字幅
     *
     * 前日比:
     *   ±9,999,999.99
     *   符号を含め最大13文字幅
     *
     * 騰落率:
     *   ±999.99%
     *   符号を含め最大8文字幅
     *
     * 更新日:
     *   MM/DD または HH:mm
     *   5文字幅
     */
    $currentValue = str_pad(
      $row['current_value'],
      14,
      ' ',
      STR_PAD_LEFT
    );

    $change = str_pad(
      $row['change'],
      13,
      ' ',
      STR_PAD_LEFT
    );

    $rate = str_pad(
      $row['rate'],
      8,
      ' ',
      STR_PAD_LEFT
    );

    $updated = str_pad(
      $row['updated'],
      5,
      ' ',
      STR_PAD_LEFT
    );

    $lines[] = implode(
      "\t",
      array(
        $name,
        $currentValue,
        $change,
        $rate,
        $updated,
      )
    );
  }

  return implode("\n", $lines);
}