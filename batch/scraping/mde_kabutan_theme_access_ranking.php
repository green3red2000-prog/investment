<?php
/**
 * 市況関連データ抽出：株探テーマアクセスランキング
 *
 * market_data_extract.php から読み込まれ、
 * 株探テーマアクセスランキングのHTML解析および
 * メッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 *   class_xpath_()
 */

// =======================================================
// 株探テーマアクセスランキング
// =======================================================

/**
 * 株探テーマアクセスランキングHTMLから、
 * 「人気テーマ【ベスト30】」の更新日時および
 * 上位10件を取得する。
 *
 * @param string $html
 * @return array
 */
function parse_kabutan_theme_access_ranking_html_($html) {
  $xpath = load_xpath_($html);

  /*
   * 「人気テーマ【ベスト30】」の見出しを取得する。
   */
  $titleNodes = $xpath->query(
    "//h1[" .
    class_xpath_('actitle') .
    " and contains(normalize-space(.), '人気テーマ【ベスト30】')]"
  );

  if (!$titleNodes || $titleNodes->length !== 1) {
    throw new RuntimeException(
      "人気テーマ【ベスト30】の見出しが1件取得できません: " .
      ($titleNodes ? $titleNodes->length : 0)
    );
  }

  $titleNode = $titleNodes->item(0);

  /*
   * 見出しを含むブロックを取得する。
   */
  $titleBlock = $titleNode->parentNode;

  if (!$titleBlock) {
    throw new RuntimeException(
      "人気テーマの見出しブロックを取得できません。"
    );
  }

  /*
   * 更新日時を取得する。
   *
   * 同じ見出しブロックには
   * テーマランキングの説明用p要素も存在するため、
   * yyyy年MM月dd日 HH時mm分形式を持つp要素を検索する。
   */
  $dateNodes = $xpath->query(
    ".//p",
    $titleBlock
  );

  if (!$dateNodes || $dateNodes->length === 0) {
    throw new RuntimeException(
      "人気テーマの更新日時候補を取得できません。"
    );
  }

  $updatedAt = '';

  for ($i = 0; $i < $dateNodes->length; $i++) {
    $text =
      normalize_text_(
        $dateNodes->item($i)->textContent
      );

    if (
      preg_match(
        '/^(\d{4})年(\d{1,2})月(\d{1,2})日\s+' .
        '(\d{1,2})時(\d{2})分$/u',
        $text,
        $matches
      )
    ) {
      $year = (int)$matches[1];
      $month = (int)$matches[2];
      $day = (int)$matches[3];
      $hour = (int)$matches[4];
      $minute = (int)$matches[5];

      if (!checkdate($month, $day, $year)) {
        throw new RuntimeException(
          "人気テーマの更新日が不正です: {$text}"
        );
      }

      if (
        $hour < 0 ||
        $hour > 23 ||
        $minute < 0 ||
        $minute > 59
      ) {
        throw new RuntimeException(
          "人気テーマの更新時刻が不正です: {$text}"
        );
      }

      $updatedAt =
        sprintf(
          '%04d-%02d-%02d %02d:%02d',
          $year,
          $month,
          $day,
          $hour,
          $minute
        );

      break;
    }
  }

  if ($updatedAt === '') {
    throw new RuntimeException(
      "人気テーマの更新日時を取得できません。"
    );
  }

  /*
   * 人気テーマランキング本体を取得する。
   *
   * 見出しブロックの後ろにある、
   * class="acrank acrank_theme" のdivを対象とする。
   */
  $rankingNodes = $xpath->query(
    "following-sibling::div[" .
    class_xpath_('acrank') .
    " and " .
    class_xpath_('acrank_theme') .
    "][1]",
    $titleBlock
  );

  if (!$rankingNodes || $rankingNodes->length !== 1) {
    throw new RuntimeException(
      "人気テーマランキング本体が1件取得できません: " .
      ($rankingNodes ? $rankingNodes->length : 0)
    );
  }

  $rankingNode = $rankingNodes->item(0);

  /*
   * ランキングデータ行を取得する。
   *
   * 広告行等もtrとして存在するため、
   * class="acrank_num" のtdと
   * class="acrank_url" のtdの両方を持つ行だけを対象とする。
   */
  $rowNodes = $xpath->query(
    ".//tr[" .
      "td[" . class_xpath_('acrank_num') . "]" .
      " and " .
      "td[" . class_xpath_('acrank_url') . "]" .
    "]",
    $rankingNode
  );

  if (!$rowNodes || $rowNodes->length < 10) {
    throw new RuntimeException(
      "人気テーマランキング行が10件以上取得できません: " .
      ($rowNodes ? $rowNodes->length : 0)
    );
  }

  $rows = array();

  /*
   * 上位10件を取得する。
   */
  for ($i = 0; $i < 10; $i++) {
    $rowNode = $rowNodes->item($i);

    $cells = $xpath->query(
      "./td",
      $rowNode
    );

    if (!$cells || $cells->length < 3) {
      throw new RuntimeException(
        "人気テーマランキングの列数が不足しています: " .
        "row=" . ($i + 1) .
        " columns=" .
        ($cells ? $cells->length : 0)
      );
    }

    /*
     * 順位。
     *
     * 1～3位は文字列ではなく、
     * acrank_num1 / acrank_num2 / acrank_num3
     * のclassで順位が表現される。
     *
     * 4位以降はclass="acrank_num"のdiv内に
     * 数値が記載される。
     */
    $rank =
      parse_kabutan_theme_access_rank_(
        $xpath,
        $cells->item(0),
        $i + 1
      );

    /*
     * テーマ。
     */
    $themeNodes = $xpath->query(
      ".//a",
      $cells->item(1)
    );

    if (!$themeNodes || $themeNodes->length !== 1) {
      throw new RuntimeException(
        "人気テーマランキングのテーマが1件取得できません: " .
        "row=" . ($i + 1)
      );
    }

    $theme =
      normalize_text_(
        $themeNodes->item(0)->textContent
      );

    if ($theme === '') {
      throw new RuntimeException(
        "人気テーマランキングのテーマが空です: " .
        "row=" . ($i + 1)
      );
    }

    /*
     * 参考銘柄。
     *
     * 3列目には複数のa要素と区切り文字「、」が含まれるため、
     * td全体のtextContentを取得する。
     */
    $referenceStocks =
      normalize_text_(
        $cells->item(2)->textContent
      );

    if ($referenceStocks === '') {
      throw new RuntimeException(
        "人気テーマランキングの参考銘柄が空です: " .
        "row=" . ($i + 1)
      );
    }

    $rows[] = array(
      'rank' => $rank,
      'theme' => $theme,
      'reference_stocks' => $referenceStocks,
    );
  }

  /*
   * 順位が1～10の連番になっていることを確認する。
   */
  for ($i = 0; $i < count($rows); $i++) {
    $expectedRank = $i + 1;

    if ((int)$rows[$i]['rank'] !== $expectedRank) {
      throw new RuntimeException(
        "人気テーマランキングの順位が連番ではありません: " .
        "row={$expectedRank}" .
        " rank={$rows[$i]['rank']}"
      );
    }
  }

  return array(
    'updated_at' => $updatedAt,
    'rows' => $rows,
  );
}

/**
 * ランキング順位を取得する。
 *
 * 1～3位:
 *   acrank_num1
 *   acrank_num2
 *   acrank_num3
 *
 * 4位以降:
 *   acrank_num のtextContent
 *
 * @param DOMXPath $xpath
 * @param DOMNode $cell
 * @param int $rowNo
 * @return int
 */
function parse_kabutan_theme_access_rank_(
  $xpath,
  $cell,
  $rowNo
) {
  $divNodes = $xpath->query(
    ".//div",
    $cell
  );

  if (!$divNodes || $divNodes->length !== 1) {
    throw new RuntimeException(
      "人気テーマランキングの順位要素が1件取得できません: " .
      "row={$rowNo}"
    );
  }

  $div = $divNodes->item(0);
  $class =
    ' ' .
    preg_replace(
      '/\s+/u',
      ' ',
      trim((string)$div->getAttribute('class'))
    ) .
    ' ';

  /*
   * 1～3位はclass名から判定する。
   */
  if (strpos($class, ' acrank_num1 ') !== false) {
    return 1;
  }

  if (strpos($class, ' acrank_num2 ') !== false) {
    return 2;
  }

  if (strpos($class, ' acrank_num3 ') !== false) {
    return 3;
  }

  /*
   * 4位以降は要素内の数値を取得する。
   */
  $rankText =
    normalize_text_(
      $div->textContent
    );

  if (
    $rankText === '' ||
    !preg_match('/^\d+$/', $rankText)
  ) {
    throw new RuntimeException(
      "人気テーマランキングの順位形式が不正です: " .
      "row={$rowNo} value={$rankText}"
    );
  }

  $rank = (int)$rankText;

  if ($rank < 1 || $rank > 30) {
    throw new RuntimeException(
      "人気テーマランキングの順位が範囲外です: " .
      "row={$rowNo} value={$rank}"
    );
  }

  return $rank;
}

/**
 * 全角文字を表示幅2、
 * 半角文字を表示幅1として右側を半角スペースで埋める。
 *
 * 指定表示幅以上の文字列は切り捨てない。
 *
 * @param string $value
 * @param int $width
 * @param string $fieldName
 * @return string
 */
function pad_kabutan_theme_access_right_(
  $value,
  $width,
  $fieldName
) {
  if (!function_exists('mb_strwidth')) {
    throw new RuntimeException(
      "mb_strwidth()が使用できません: {$fieldName}"
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
 * 株探テーマアクセスランキングの抽出結果から
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_kabutan_theme_access_ranking_message_(
  $parsed
) {
  if (
    !isset($parsed['updated_at']) ||
    !is_string($parsed['updated_at']) ||
    $parsed['updated_at'] === ''
  ) {
    throw new RuntimeException(
      "人気テーマの更新日時がありません。"
    );
  }

  if (
    !isset($parsed['rows']) ||
    !is_array($parsed['rows']) ||
    count($parsed['rows']) !== 10
  ) {
    throw new RuntimeException(
      "人気テーマランキングのレポート対象が10件ではありません。"
    );
  }

  $lines = array();

  $lines[] =
    "■株探テーマアクセスランキング";

  $lines[] =
    "更新日時: " .
    $parsed['updated_at'];

  $lines[] = '';

  $lines[] =
    "【人気テーマ】";

  $lines[] =
    "順位\tテーマ\t参考銘柄";

  foreach ($parsed['rows'] as $row) {
    /*
     * 順位:
     * 99
     * 2文字幅で右寄せ。
     */
    $rank =
      str_pad(
        (string)$row['rank'],
        2,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * テーマ:
     * 全角15文字分
     * = 表示幅30。
     */
    $theme =
      pad_kabutan_theme_access_right_(
        $row['theme'],
        30,
        'テーマ'
      );

    /*
     * 参考銘柄:
     * 全角40文字分
     * = 表示幅80。
     */
    $referenceStocks =
      pad_kabutan_theme_access_right_(
        $row['reference_stocks'],
        80,
        '参考銘柄'
      );

    $lines[] =
      $rank . "\t" .
      $theme . "\t" .
      $referenceStocks;
  }

  return implode("\n", $lines);
}