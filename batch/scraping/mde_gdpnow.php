<?php
/**
 * 市況関連データ抽出：GDPNow
 *
 * market_data_extract.php から読み込まれ、
 * Atlanta Fed GDPNow HTMLの解析および
 * メッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 *   class_xpath_()
 */

// =======================================================
// GDPNow
// =======================================================

/**
 * Atlanta Fed GDPNow HTMLから、
 * 推定期間、推計値および更新日を取得する。
 *
 * @param string $html
 * @return array
 */
function parse_gdpnow_html_($html) {
  $xpath = load_xpath_($html);

  // -------------------------------------------------------
  // 対象ページ確認
  // -------------------------------------------------------

  /*
   * head直下のページタイトルが
   * GDPNowであることを確認する。
   *
   * SVG内にもtitle要素が存在するため、
   * //titleではなく/html/head/titleを対象とする。
   */
  $titleNodes = $xpath->query(
    "/html/head/title"
  );

  if (
    !$titleNodes ||
    $titleNodes->length !== 1
  ) {
    throw new RuntimeException(
      "GDPNowのページtitle要素が1件取得できません: " .
      ($titleNodes
        ? $titleNodes->length
        : 0)
    );
  }

  $pageTitle =
    normalize_text_(
      $titleNodes->item(0)->textContent
    );

  if (
    strpos(
      $pageTitle,
      'GDPNow'
    ) === false ||
    strpos(
      $pageTitle,
      'Federal Reserve Bank of Atlanta'
    ) === false
  ) {
    throw new RuntimeException(
      "GDPNowページを確認できません: " .
      $pageTitle
    );
  }

  // -------------------------------------------------------
  // GDPNowデータカードの取得
  // -------------------------------------------------------

  /*
   * 以下をすべて含むdata-cardを対象とする。
   *
   *   ・class="data-value"
   *   ・"GDPNow Estimate for"を含むstrong要素
   *   ・"Updated:"のstrong要素
   */
  $cardNodes = $xpath->query(
    "//div[" .
      class_xpath_('card') .
      " and " .
      class_xpath_('data-card') .
    "]" .
    "[" .
      ".//*[" .
        class_xpath_('data-value') .
      "]" .
      " and " .
      ".//strong[" .
        "contains(normalize-space(.), 'GDPNow Estimate for')" .
      "]" .
      " and " .
      ".//strong[" .
        "normalize-space(.)='Updated:'" .
      "]" .
    "]"
  );

  if (
    !$cardNodes ||
    $cardNodes->length !== 1
  ) {
    throw new RuntimeException(
      "GDPNowデータカードが1件取得できません: " .
      ($cardNodes
        ? $cardNodes->length
        : 0)
    );
  }

  $cardNode =
    $cardNodes->item(0);

  // -------------------------------------------------------
  // 推計値の取得
  // -------------------------------------------------------

  $estimateValueNodes = $xpath->query(
    ".//*[" .
      class_xpath_('data-value') .
    "]",
    $cardNode
  );

  if (
    !$estimateValueNodes ||
    $estimateValueNodes->length !== 1
  ) {
    throw new RuntimeException(
      "GDPNow推計値が1件取得できません: " .
      ($estimateValueNodes
        ? $estimateValueNodes->length
        : 0)
    );
  }

  $estimateValueRaw =
    normalize_text_(
      $estimateValueNodes
        ->item(0)
        ->textContent
    );

  $estimateValue =
    format_gdpnow_estimate_value_(
      $estimateValueRaw
    );

  // -------------------------------------------------------
  // 推定期間の取得
  // -------------------------------------------------------

  /*
   * 例:
   *
   * Third-Quarter GDPNow Estimate for 2026:Q3
   *
   * 四半期が変化しても取得できるよう、
   * 固定文字列"Third-Quarter"ではなく、
   * "GDPNow Estimate for"を識別条件とする。
   */
  $estimatePeriodNodes = $xpath->query(
    ".//strong[" .
      "contains(normalize-space(.), 'GDPNow Estimate for')" .
    "]",
    $cardNode
  );

  if (
    !$estimatePeriodNodes ||
    $estimatePeriodNodes->length !== 1
  ) {
    throw new RuntimeException(
      "GDPNow推定期間が1件取得できません: " .
      ($estimatePeriodNodes
        ? $estimatePeriodNodes->length
        : 0)
    );
  }

  $estimatePeriod =
    normalize_text_(
      $estimatePeriodNodes
        ->item(0)
        ->textContent
    );

  if ($estimatePeriod === '') {
    throw new RuntimeException(
      "GDPNow推定期間が空です。"
    );
  }

  if (
    !preg_match(
      '/^.+GDPNow Estimate for \d{4}:Q[1-4]$/u',
      $estimatePeriod
    )
  ) {
    throw new RuntimeException(
      "GDPNow推定期間の形式が不正です: " .
      $estimatePeriod
    );
  }

  // -------------------------------------------------------
  // 更新日の取得
  // -------------------------------------------------------

  /*
   * 以下のようなp要素を取得する。
   *
   * <p>
   *   <strong>Updated:</strong>
   *   August 06, 2026
   * </p>
   */
  $updatedParagraphNodes = $xpath->query(
    ".//p[" .
      "strong[" .
        "normalize-space(.)='Updated:'" .
      "]" .
    "]",
    $cardNode
  );

  if (
    !$updatedParagraphNodes ||
    $updatedParagraphNodes->length !== 1
  ) {
    throw new RuntimeException(
      "GDPNow更新日が1件取得できません: " .
      ($updatedParagraphNodes
        ? $updatedParagraphNodes->length
        : 0)
    );
  }

  $updatedRaw =
    normalize_text_(
      $updatedParagraphNodes
        ->item(0)
        ->textContent
    );

  /*
   * "Updated:"を除去する。
   */
  $updatedRaw =
    preg_replace(
      '/^Updated:\s*/u',
      '',
      $updatedRaw
    );

  $updatedRaw =
    trim(
      (string)$updatedRaw
    );

  $updatedAt =
    format_gdpnow_updated_date_(
      $updatedRaw
    );

  return array(
    'updated_at' =>
      $updatedAt,

    'estimate_period' =>
      $estimatePeriod,

    'estimate_value' =>
      $estimateValue,
  );
}

/**
 * GDPNow更新日を検証し、
 * yyyy-MM-dd形式へ変換する。
 *
 * 入力例:
 *   August 06, 2026
 *
 * 出力:
 *   2026-08-06
 *
 * HTML上には時刻およびタイムゾーンが存在しないため、
 * 日付のみを正規化する。
 *
 * @param string $value
 * @return string
 */
function format_gdpnow_updated_date_(
  $value
) {
  $original =
    trim(
      (string)$value
    );

  if (
    !preg_match(
      '/^[A-Z][a-z]+\s+\d{1,2},\s+\d{4}$/',
      $original
    )
  ) {
    throw new RuntimeException(
      "GDPNow更新日の形式が不正です: " .
      $value
    );
  }

  $date =
    DateTimeImmutable::createFromFormat(
      '!F j, Y',
      $original,
      new DateTimeZone(
        'Asia/Tokyo'
      )
    );

  if ($date === false) {
    throw new RuntimeException(
      "GDPNow更新日を日付へ変換できません: " .
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
      "GDPNow更新日が不正です: " .
      $value
    );
  }

  /*
   * 元データが日付のみであるため、
   * タイムゾーンによる日付移動は行わない。
   */
  return
    $date->format(
      'Y-m-d'
    );
}

/**
 * GDPNow推計値を検証し、
 * 小数点以下2桁＋パーセント記号へ整形する。
 *
 * 入力例:
 *   5.8%
 *
 * 出力:
 *   5.80%
 *
 * @param string $value
 * @return string
 */
function format_gdpnow_estimate_value_(
  $value
) {
  $original =
    trim(
      (string)$value
    );

  if ($original === '') {
    throw new RuntimeException(
      "GDPNow推計値が空です。"
    );
  }

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
      '/^[+-]?\d+(?:\.\d+)?$/',
      $raw
    )
  ) {
    throw new RuntimeException(
      "GDPNow推計値の数値形式が不正です: " .
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
function pad_gdpnow_right_(
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

  $value =
    (string)$value;

  $currentWidth =
    mb_strwidth(
      $value,
      'UTF-8'
    );

  if (
    $currentWidth >= $width
  ) {
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
 * GDPNowの抽出結果から
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_gdpnow_message_(
  $parsed
) {
  if (
    !isset($parsed['updated_at']) ||
    !is_string($parsed['updated_at']) ||
    $parsed['updated_at'] === ''
  ) {
    throw new RuntimeException(
      "GDPNow更新日がありません。"
    );
  }

  if (
    !isset($parsed['estimate_period']) ||
    !is_string($parsed['estimate_period']) ||
    $parsed['estimate_period'] === ''
  ) {
    throw new RuntimeException(
      "GDPNow推定期間がありません。"
    );
  }

  if (
    !isset($parsed['estimate_value']) ||
    !is_string($parsed['estimate_value']) ||
    $parsed['estimate_value'] === ''
  ) {
    throw new RuntimeException(
      "GDPNow推計値がありません。"
    );
  }

  $lines = array();

  /*
   * 見出し。
   */
  $lines[] =
    "■GDPNow：Federal Reserve Bank of Atlanta";

  /*
   * 更新日時。
   *
   * 元HTMLに時刻がないため、
   * yyyy-MM-ddのみ出力する。
   */
  $lines[] =
    "更新日時（米国時間）: " .
    $parsed['updated_at'];

  $lines[] = '';

  /*
   * 列見出し。
   */
  $lines[] =
    "推定期間\t推計値";

  /*
   * 推定期間:
   * 全角25文字分
   * = 表示幅50。
   */
  $estimatePeriod =
    pad_gdpnow_right_(
      $parsed['estimate_period'],
      50,
      '推定期間'
    );

  /*
   * 推計値:
   * 999.00%
   * 7文字幅で右寄せする。
   */
  $estimateValue =
    str_pad(
      (string)$parsed['estimate_value'],
      7,
      ' ',
      STR_PAD_LEFT
    );

  $lines[] =
    $estimatePeriod .
    "\t" .
    $estimateValue;

  $lines[] = '';

  /*
   * 固定コメント。
   */
  $lines[] =
    "※GDPNow is not an official forecast of the Atlanta Fed. Rather,";

  $lines[] =
    "　　it is best viewed as a running estimate of real GDP growth " .
    "based on available economic data for the current measured quarter.";

  $lines[] =
    "　　There are no subjective adjustments made to GDPNow—the estimate " .
    "is based solely on the mathematical results of the model.";

  return implode(
    "\n",
    $lines
  );
}