<?php
/**
 * 市況関連データ抽出：FRB総資産
 *
 * market_data_extract.php から読み込まれ、
 * FREDのFRB総資産HTML解析およびメッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 */

// =======================================================
// FRB総資産
// =======================================================

/**
 * FREDのFRB総資産HTMLから、
 * 最新観測日、総資産額および更新日時を取得する。
 *
 * @param string $html
 * @return array
 */
function parse_fed_total_assets_html_($html) {
  $xpath = load_xpath_($html);

  // -------------------------------------------------------
  // 対象ページ確認
  // -------------------------------------------------------

  /*
   * citation_short_titleがWALCLであることを確認する。
   */
  $seriesNodes = $xpath->query(
    "//meta[@name='citation_short_title' and @content='WALCL']"
  );

  if (
    !$seriesNodes ||
    $seriesNodes->length !== 1
  ) {
    throw new RuntimeException(
      "FRB総資産のWALCLページを確認できません: " .
      ($seriesNodes ? $seriesNodes->length : 0)
    );
  }

  // -------------------------------------------------------
  // 最新観測値の取得
  // -------------------------------------------------------

  /*
   * recent-obsテーブルの先頭行を最新観測値として取得する。
   */
  $recentTableNodes = $xpath->query(
    "//table[@id='recent-obs']"
  );

  if (
    !$recentTableNodes ||
    $recentTableNodes->length !== 1
  ) {
    throw new RuntimeException(
      "FRB総資産の最新観測値テーブルが1件取得できません: " .
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
      "FRB総資産の最新観測値行が1件取得できません。"
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
      "FRB総資産の最新観測値行の列数が不足しています: " .
      ($cells ? $cells->length : 0)
    );
  }

  /*
   * 日付。
   *
   * HTML:
   *   2026-08-12:
   *
   * のように末尾にコロンが付くため除去する。
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
    format_fed_total_assets_date_(
      $dateRaw
    );

  /*
   * 総資産額。
   */
  $valueRaw =
    normalize_text_(
      $cells->item(1)->textContent
    );

  $value =
    format_fed_total_assets_value_(
      $valueRaw
    );

  // -------------------------------------------------------
  // 更新日時の取得
  // -------------------------------------------------------

  /*
   * デスクトップ表示側の更新日時要素を取得する。
   *
   * HTML例:
   *
   * <span
   *   class="updated-text default-text"
   *   title="Aug 13, 2026 3:32 PM CDT"
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
      "FRB総資産の更新日時要素が1件取得できません: " .
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
      "FRB総資産の更新日時title属性が空です。"
    );
  }

  $updatedAt =
    format_fed_total_assets_updated_at_(
      $updatedRaw
    );

  return array(
    'updated_at' => $updatedAt,
    'date' => $date,
    'value' => $value,
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
function format_fed_total_assets_date_(
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
      "FRB総資産の日付形式が不正です: " .
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
      "FRB総資産の日付が不正です: " .
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
 * FRB総資産額を検証し、
 * 3桁ごとのカンマ区切り整数へ整形する。
 *
 * @param string $value
 * @return string
 */
function format_fed_total_assets_value_(
  $value
) {
  $original =
    trim((string)$value);

  if ($original === '') {
    throw new RuntimeException(
      "FRB総資産額が空です。"
    );
  }

  $raw =
    str_replace(
      ',',
      '',
      $original
    );

  if (
    !preg_match(
      '/^\d+$/',
      $raw
    )
  ) {
    throw new RuntimeException(
      "FRB総資産額の数値形式が不正です: " .
      $value
    );
  }

  return number_format(
    (int)$raw,
    0,
    '.',
    ','
  );
}

/**
 * FREDの更新日時をAsia/Tokyoへ変換する。
 *
 * 入力例:
 *   Aug 13, 2026 3:32 PM CDT
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
function format_fed_total_assets_updated_at_(
  $value
) {
  $original =
    trim((string)$value);

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
      "FRB総資産の更新日時形式が不正です: " .
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
      "FRB総資産の更新日時を日時へ変換できません: " .
      $value
    );
  }

  /*
   * createFromFormat()で警告・エラーが
   * 発生していないことを確認する。
   */
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
      "FRB総資産の更新日時が不正です: " .
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
 * FRB総資産の抽出結果から
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_fed_total_assets_message_(
  $parsed
) {
  if (
    !isset($parsed['updated_at']) ||
    !is_string($parsed['updated_at']) ||
    $parsed['updated_at'] === ''
  ) {
    throw new RuntimeException(
      "FRB総資産の更新日時がありません。"
    );
  }

  if (
    !isset($parsed['date']) ||
    !is_string($parsed['date']) ||
    $parsed['date'] === ''
  ) {
    throw new RuntimeException(
      "FRB総資産の日付がありません。"
    );
  }

  if (
    !isset($parsed['value']) ||
    !is_string($parsed['value']) ||
    $parsed['value'] === ''
  ) {
    throw new RuntimeException(
      "FRB総資産額がありません。"
    );
  }

  $lines = array();

  /*
   * 見出し。
   */
  $lines[] =
    "■FRB総資産：Assets: Total Assets: " .
    "Total Assets (Less Eliminations from Consolidation): " .
    "Wednesday Level (WALCL)";

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
    "日付\t総資産額（百万ドル）";

  /*
   * 日付。
   */
  $date =
    str_pad(
      (string)$parsed['date'],
      10,
      ' ',
      STR_PAD_LEFT
    );

  /*
   * 総資産額:
   * 999,999,999
   * 11文字幅で右寄せ。
   */
  $value =
    str_pad(
      (string)$parsed['value'],
      11,
      ' ',
      STR_PAD_LEFT
    );

  $lines[] =
    $date . "\t" .
    $value;

  $lines[] = '';

  /*
   * 固定コメント。
   */
  $lines[] =
    "※Source: Board of Governors of the Federal Reserve System (US) " .
    "Release: H.4.1 Factors Affecting Reserve Balances";

  return implode(
    "\n",
    $lines
  );
}