<?php
/**
 * 市況関連データ抽出：JPXホーム
 *
 * market_data_extract.php から読み込まれ、
 * JPXホームのHTML解析およびメッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 *   class_xpath_()
 */

// =======================================================
// JPXホーム
// =======================================================

/**
 * JPXホームHTMLから、
 * 「マーケットサマリー」
 * 「株式市場　売買高・売買代金」
 * のデータを取得する。
 *
 * @param string $html
 * @return array
 */
function parse_jpx_home_html_($html) {
  $xpath = load_xpath_($html);

  /*
   * 「株式市場　売買高・売買代金」の見出しを取得する。
   *
   * ページ内には複数のマーケットサマリー用ブロックがあるため、
   * class="JPX-ms-title" だけではなく、
   * 見出し文字列まで指定して対象を限定する。
   */
  $titleNodes = $xpath->query(
    "//*[" .
      class_xpath_('JPX-ms-title') .
      " and normalize-space(.)='株式市場　売買高・売買代金'" .
    "]"
  );

  if (!$titleNodes || $titleNodes->length !== 1) {
    throw new RuntimeException(
      "「株式市場　売買高・売買代金」の見出しが1件取得できません: " .
      ($titleNodes ? $titleNodes->length : 0)
    );
  }

  $titleNode = $titleNodes->item(0);

  /*
   * 見出しと同じ JPX-ms-wrapper 内にある、
   * 売買高・売買代金のデータボックスを取得する。
   *
   * 対象HTMLでは以下の構造。
   *
   * <div class="JPX-ms-wrapper">
   *   <div class="JPX-ms-title">
   *     株式市場　売買高・売買代金
   *   </div>
   *
   *   <div class="JPX-ms-box -is-price">
   *     ...
   *   </div>
   * </div>
   */
  $wrapperNode = $titleNode->parentNode;

  if (
    !$wrapperNode ||
    !($wrapperNode instanceof DOMElement) ||
    strpos(
      ' ' . $wrapperNode->getAttribute('class') . ' ',
      ' JPX-ms-wrapper '
    ) === false
  ) {
    throw new RuntimeException(
      "「株式市場　売買高・売買代金」の親ブロックが取得できません。"
    );
  }

  $boxNodes = $xpath->query(
    "./div[" .
      class_xpath_('JPX-ms-box') .
      " and " .
      class_xpath_('-is-price') .
    "]",
    $wrapperNode
  );

  if (!$boxNodes || $boxNodes->length !== 1) {
    throw new RuntimeException(
      "株式市場の売買高・売買代金ブロックが1件取得できません: " .
      ($boxNodes ? $boxNodes->length : 0)
    );
  }

  $boxNode = $boxNodes->item(0);

  /*
   * データ行を取得する。
   *
   * 見出し行:
   *   class="JPX-ms-data-label"
   *
   * データ行:
   *   class="JPX-ms-data"
   *
   * そのため JPX-ms-data のみを対象とする。
   */
  $rowNodes = $xpath->query(
    "./div[" . class_xpath_('JPX-ms-data') . "]",
    $boxNode
  );

  if (!$rowNodes || $rowNodes->length !== 3) {
    throw new RuntimeException(
      "株式市場の売買高・売買代金データが3件取得できません: " .
      ($rowNodes ? $rowNodes->length : 0)
    );
  }

  $rows = array();

  for ($i = 0; $i < $rowNodes->length; $i++) {
    $rowNode = $rowNodes->item($i);

    /*
     * 市場区分。
     */
    $marketNodes = $xpath->query(
      "./div[" . class_xpath_('JPX-ms-name') . "]",
      $rowNode
    );

    /*
     * 売買高（百万株）。
     */
    $volumeNodes = $xpath->query(
      "./div[" . class_xpath_('JPX-ms-amount') . "]",
      $rowNode
    );

    /*
     * 売買代金（百万円）。
     */
    $valueNodes = $xpath->query(
      "./div[" . class_xpath_('JPX-ms-price') . "]",
      $rowNode
    );

    if (
      !$marketNodes || $marketNodes->length !== 1 ||
      !$volumeNodes || $volumeNodes->length !== 1 ||
      !$valueNodes || $valueNodes->length !== 1
    ) {
      throw new RuntimeException(
        "株式市場の売買高・売買代金の列構成が不正です: " .
        "row=" . ($i + 1)
      );
    }

    $market =
      normalize_text_($marketNodes->item(0)->textContent);

    $volumeText =
      normalize_text_($volumeNodes->item(0)->textContent);

    $valueText =
      normalize_text_($valueNodes->item(0)->textContent);

    if ($market === '') {
      throw new RuntimeException(
        "市場区分が空です: row=" . ($i + 1)
      );
    }

    /*
     * 売買高、売買代金はカンマを除去して数値判定する。
     */
    $volumeRaw = str_replace(',', '', $volumeText);
    $valueRaw = str_replace(',', '', $valueText);

    if (
      $volumeRaw === '' ||
      !preg_match('/^\d+(?:\.\d+)?$/', $volumeRaw)
    ) {
      throw new RuntimeException(
        "売買高の数値形式が不正です: " .
        "row=" . ($i + 1) .
        " market={$market}" .
        " value={$volumeText}"
      );
    }

    if (
      $valueRaw === '' ||
      !preg_match('/^\d+(?:\.\d+)?$/', $valueRaw)
    ) {
      throw new RuntimeException(
        "売買代金の数値形式が不正です: " .
        "row=" . ($i + 1) .
        " market={$market}" .
        " value={$valueText}"
      );
    }

    /*
     * 単位変換。
     *
     * 売買高:
     *   HTML = 百万株
     *   出力 = 億株
     *
     *   1億株 = 100百万株
     *
     * 売買代金:
     *   HTML = 百万円
     *   出力 = 兆円
     *
     *   1兆円 = 1,000,000百万円
     */
    $volumeOku =
      (float)$volumeRaw / 100.0;

    $valueCho =
      (float)$valueRaw / 1000000.0;

    $rows[] = array(
      'market' => $market,
      'volume_oku' => $volumeOku,
      'value_cho' => $valueCho,
    );
  }

  /*
   * 対象市場が想定どおり存在することを確認する。
   *
   * HTML構造だけが維持されたまま、
   * 表の内容そのものが変更された場合も検知する。
   */
  $expectedMarkets = array(
    'プライム',
    'スタンダード',
    'グロース',
  );

  foreach ($expectedMarkets as $index => $expectedMarket) {
    if (
      !isset($rows[$index]['market']) ||
      $rows[$index]['market'] !== $expectedMarket
    ) {
      $actualMarket =
        isset($rows[$index]['market'])
          ? $rows[$index]['market']
          : '';

      throw new RuntimeException(
        "市場区分が想定と一致しません: " .
        "row=" . ($index + 1) .
        " expected={$expectedMarket}" .
        " actual={$actualMarket}"
      );
    }
  }

  /*
   * データ処理日時。
   *
   * HTML内の更新日時ではなく、
   * 本プログラムでデータを処理した現在時刻を使用する。
   */
  $processedAt = date('Y-m-d H:i');

  return array(
    'processed_at' => $processedAt,
    'rows' => $rows,
  );
}

/**
 * 表示幅を基準として右側を半角スペースで埋める。
 *
 * 全角文字は2、半角文字は1として扱う。
 *
 * @param string $value
 * @param int $width
 * @return string
 */
function pad_jpx_home_right_($value, $width) {
  $value = (string)$value;
  $width = (int)$width;

  if (!function_exists('mb_strwidth')) {
    throw new RuntimeException(
      "mb_strwidth()が使用できません。"
    );
  }

  $currentWidth =
    mb_strwidth($value, 'UTF-8');

  if ($currentWidth >= $width) {
    return $value;
  }

  return
    $value .
    str_repeat(' ', $width - $currentWidth);
}

/**
 * 表示幅を基準として左側を半角スペースで埋める。
 *
 * 全角文字は2、半角文字は1として扱う。
 *
 * @param string $value
 * @param int $width
 * @return string
 */
function pad_jpx_home_left_($value, $width) {
  $value = (string)$value;
  $width = (int)$width;

  if (!function_exists('mb_strwidth')) {
    throw new RuntimeException(
      "mb_strwidth()が使用できません。"
    );
  }

  $currentWidth =
    mb_strwidth($value, 'UTF-8');

  if ($currentWidth >= $width) {
    return $value;
  }

  return
    str_repeat(' ', $width - $currentWidth) .
    $value;
}

/**
 * JPXホームの抽出結果からレポート本文を作成する。
 *
 * 一括実行時:
 *   指定された固定幅フォーマットを適用する。
 *
 * 個別実行時:
 *   固定幅フォーマットを適用した値を列単位でtrimし、
 *   タブ区切りで出力する。
 *
 * @param array $parsed
 * @param bool $isIndividual
 * @return string
 */
function build_jpx_home_message_(
  $parsed,
  $isIndividual = false
) {
  $lines = array();

  $lines[] = "■JPXホーム";
  $lines[] =
    "データ処理日時: " .
    (string)$parsed['processed_at'];
  $lines[] = '';
  $lines[] = "【株式市場　売買高・売買代金】";

  /*
   * 列名。
   */
  $headers = array(
    '市場区分',
    '売買高（億株）',
    '売買代金（兆円）',
  );

  $lines[] = implode("\t", $headers);

  foreach ($parsed['rows'] as $row) {
    /*
     * 市場区分:
     *   全角10文字分
     *   表示幅20
     *   右側をスペース埋め
     */
    $market =
      pad_jpx_home_right_(
        (string)$row['market'],
        20
      );

    /*
     * 売買高:
     *   億株単位
     *   小数点以下2桁
     *   999.00相当の6文字幅
     *   右寄せ
     */
    $volume =
      number_format(
        (float)$row['volume_oku'],
        2,
        '.',
        ''
      );

    $volume =
      pad_jpx_home_left_(
        $volume,
        6
      );

    /*
     * 売買代金:
     *   兆円単位
     *   小数点以下4桁
     *   999.0000相当の8文字幅
     *   右寄せ
     */
    $value =
      number_format(
        (float)$row['value_cho'],
        4,
        '.',
        ''
      );

    $value =
      pad_jpx_home_left_(
        $value,
        8
      );

    /*
     * 固定幅を維持して出力する。
     */
    $lines[] =
      $market . "\t" .
      $volume . "\t" .
      $value;
  }

  return implode("\n", $lines);
}