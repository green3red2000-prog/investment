<?php
/**
 * 市況関連データ抽出：米国ハイイールドスプレッド
 *
 * market_data_extract.php から読み込まれ、
 * FREDの米国ハイイールドスプレッドHTML解析および
 * メッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 */

// =======================================================
// 米国ハイイールドスプレッド
// =======================================================

/**
 * FREDの米国ハイイールドスプレッドHTMLから、
 * 最新観測日、レートおよび更新日時を取得する。
 *
 * @param string $html
 * @return array
 */
function parse_us_high_yield_spread_html_($html) {
  $xpath = load_xpath_($html);

  // -------------------------------------------------------
  // 対象ページ確認
  // -------------------------------------------------------

  /*
   * citation_short_titleがBAMLH0A0HYM2であることを確認する。
   */
  $seriesNodes = $xpath->query(
    "//meta[" .
      "@name='citation_short_title'" .
      " and @content='BAMLH0A0HYM2'" .
    "]"
  );

  if (
    !$seriesNodes ||
    $seriesNodes->length !== 1
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドのBAMLH0A0HYM2ページを確認できません: " .
      ($seriesNodes ? $seriesNodes->length : 0)
    );
  }

  // -------------------------------------------------------
  // 最新観測値の取得
  // -------------------------------------------------------

  /*
   * recent-obsテーブルの先頭行を
   * 最新観測値として取得する。
   */
  $recentTableNodes = $xpath->query(
    "//table[@id='recent-obs']"
  );

  if (
    !$recentTableNodes ||
    $recentTableNodes->length !== 1
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの最新観測値テーブルが1件取得できません: " .
      ($recentTableNodes
        ? $recentTableNodes->length
        : 0)
    );
  }

  $recentTable =
    $recentTableNodes->item(0);

  $latestRowNodes = $xpath->query(
    "./tbody/tr[1]",
    $recentTable
  );

  if (
    !$latestRowNodes ||
    $latestRowNodes->length !== 1
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの最新観測値行が1件取得できません。"
    );
  }

  $latestRow =
    $latestRowNodes->item(0);

  $cells = $xpath->query(
    "./td",
    $latestRow
  );

  if (
    !$cells ||
    $cells->length < 2
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの最新観測値行の列数が不足しています: " .
      ($cells ? $cells->length : 0)
    );
  }

  // -------------------------------------------------------
  // 日付
  // -------------------------------------------------------

  /*
   * HTML:
   *
   *   2026-08-12:
   *
   * のように末尾へコロンが付くため除去する。
   */
  $dateRaw =
    normalize_text_(
      $cells->item(0)->textContent
    );

  $dateRaw =
    preg_replace(
      '/[：:]\s*$/u',
      '',
      $dateRaw
    );

  $dateRaw =
    trim(
      (string)$dateRaw
    );

  $date =
    format_us_high_yield_spread_date_(
      $dateRaw
    );

  // -------------------------------------------------------
  // レート
  // -------------------------------------------------------

  $rateRaw =
    normalize_text_(
      $cells->item(1)->textContent
    );

  $rate =
    format_us_high_yield_spread_rate_(
      $rateRaw
    );

  // -------------------------------------------------------
  // 更新日時
  // -------------------------------------------------------

  /*
   * デスクトップ表示側の更新日時要素を取得する。
   *
   * HTML例:
   *
   * <span
   *   class="updated-text default-text"
   *   title="Aug 13, 2026 8:52 AM CDT"
   * >
   *   Updated:
   *   ...
   * </span>
   */
  $updatedNodes = $xpath->query(
    "//*[@id='series-meta-row']" .
    "//*[contains(" .
      "concat(' ', normalize-space(@class), ' ')," .
      "' updated-text '" .
    ")" .
    " and contains(" .
      "concat(' ', normalize-space(@class), ' ')," .
      "' default-text '" .
    ")" .
    " and contains(normalize-space(.), 'Updated:')" .
    "]"
  );

  if (
    !$updatedNodes ||
    $updatedNodes->length !== 1
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの更新日時要素が1件取得できません: " .
      ($updatedNodes
        ? $updatedNodes->length
        : 0)
    );
  }

  $updatedRaw =
    trim(
      (string)$updatedNodes
        ->item(0)
        ->getAttribute('title')
    );

  if ($updatedRaw === '') {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの更新日時title属性が空です。"
    );
  }

  $updatedAt =
    format_us_high_yield_spread_updated_at_(
      $updatedRaw
    );

  return array(
    'updated_at' => $updatedAt,
    'date' => $date,
    'rate' => $rate,
  );
}

/**
 * 観測日を検証する。
 *
 * yyyy-MM-dd形式とする。
 *
 * @param string $value
 * @return string
 */
function format_us_high_yield_spread_date_(
  $value
) {
  if (
    !preg_match(
      '/^(\d{4})-(\d{2})-(\d{2})$/',
      trim((string)$value),
      $matches
    )
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの日付形式が不正です: " .
      $value
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
      "米国ハイイールドスプレッドの日付が不正です: " .
      $value
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
 * 米国ハイイールドスプレッドのレートを検証し、
 * 小数点以下2桁＋パーセント記号へ整形する。
 *
 * HTML上の単位はPercent。
 *
 * @param string $value
 * @return string
 */
function format_us_high_yield_spread_rate_(
  $value
) {
  $original =
    trim(
      (string)$value
    );

  if ($original === '') {
    throw new RuntimeException(
      "米国ハイイールドスプレッドのレートが空です。"
    );
  }

  /*
   * 念のためHTML側にカンマや%が含まれても
   * 数値として扱えるよう除去する。
   */
  $raw =
    str_replace(
      array(
        ',',
        '%'
      ),
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
      "米国ハイイールドスプレッドのレート形式が不正です: " .
      $value
    );
  }

  $number =
    (float)$raw;

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
 * FREDの更新日時をAsia/Tokyoへ変換する。
 *
 * 入力例:
 *   Aug 13, 2026 8:52 AM CDT
 *
 * 出力:
 *   yyyy-MM-dd HH:mm
 *
 * CDT:
 *   UTC-05:00
 *
 * CST:
 *   UTC-06:00
 *
 * @param string $value
 * @return string
 */
function format_us_high_yield_spread_updated_at_(
  $value
) {
  $original =
    trim(
      (string)$value
    );

  /*
   * FREDで使用される米国中部時間の
   * CDT / CSTを明示的に判定する。
   */
  if (
    !preg_match(
      '/^([A-Z][a-z]{2})\s+' .
      '(\d{1,2}),\s+' .
      '(\d{4})\s+' .
      '(\d{1,2}):(\d{2})\s+' .
      '(AM|PM)\s+' .
      '(CDT|CST)$/',
      $original,
      $matches
    )
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの更新日時形式が不正です: " .
      $value
    );
  }

  $timezoneName =
    ($matches[7] === 'CDT')
      ? '-05:00'
      : '-06:00';

  $sourceTimezone =
    new DateTimeZone(
      $timezoneName
    );

  $dateText =
    sprintf(
      '%s %d, %d %d:%s %s',
      $matches[1],
      (int)$matches[2],
      (int)$matches[3],
      (int)$matches[4],
      $matches[5],
      $matches[6]
    );

  $date =
    DateTimeImmutable::createFromFormat(
      '!M j, Y g:i A',
      $dateText,
      $sourceTimezone
    );

  if ($date === false) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの更新日時を日時へ変換できません: " .
      $value
    );
  }

  $errors =
    DateTimeImmutable::getLastErrors();

  if (
    is_array($errors) &&
    (
      $errors['warning_count'] > 0 ||
      $errors['error_count'] > 0
    )
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの更新日時が不正です: " .
      $value
    );
  }

  $tokyoTimezone =
    new DateTimeZone(
      'Asia/Tokyo'
    );

  return
    $date
      ->setTimezone(
        $tokyoTimezone
      )
      ->format(
        'Y-m-d H:i'
      );
}

/**
 * 米国ハイイールドスプレッドの抽出結果から
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_us_high_yield_spread_message_(
  $parsed
) {
  if (
    !isset($parsed['updated_at']) ||
    !is_string($parsed['updated_at']) ||
    $parsed['updated_at'] === ''
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの更新日時がありません。"
    );
  }

  if (
    !isset($parsed['date']) ||
    !is_string($parsed['date']) ||
    $parsed['date'] === ''
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドの日付がありません。"
    );
  }

  if (
    !isset($parsed['rate']) ||
    !is_string($parsed['rate']) ||
    $parsed['rate'] === ''
  ) {
    throw new RuntimeException(
      "米国ハイイールドスプレッドのレートがありません。"
    );
  }

  $lines = array();

  /*
   * 見出し。
   */
  $lines[] =
    "■米国ハイイールドスプレッド：" .
    "ICE BofA US High Yield Index Option-Adjusted Spread " .
    "(BAMLH0A0HYM2)";

  /*
   * 更新日時。
   */
  $lines[] =
    "更新日時: " .
    $parsed['updated_at'];

  $lines[] = '';

  /*
   * 列見出し。
   */
  $lines[] =
    "日付\tレート";

  /*
   * 日付:
   * yyyy-MM-dd
   */
  $date =
    str_pad(
      (string)$parsed['date'],
      10,
      ' ',
      STR_PAD_LEFT
    );

  /*
   * レート:
   * 999.00%
   * 7文字幅で右寄せする。
   */
  $rate =
    str_pad(
      (string)$parsed['rate'],
      7,
      ' ',
      STR_PAD_LEFT
    );

  $lines[] =
    $date . "\t" .
    $rate;

  $lines[] = '';

  /*
   * 固定コメント。
   *
   * 指定された文字列をそのまま使用する。
   */
  $lines[] =
    "※Source: Ice Data Indices, LLC";

  return implode(
    "\n",
    $lines
  );
}