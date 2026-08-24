<?php
/**
 * 市況関連データ抽出：東証業種別指数
 *
 * market_data_extract.php から読み込まれ、
 * 東証業種別指数のHTML解析およびメッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 *   class_xpath_()
 */

// =======================================================
// 東証業種別指数
// =======================================================

function parse_tosho_sector_index_html_($html) {
  $xpath = load_xpath_($html);

  $updatedAt = '';
  $updatedNodes = $xpath->query("//*[@id='gyornkupdatetime']");

  if ($updatedNodes && $updatedNodes->length > 0) {
    $updatedText = normalize_text_($updatedNodes->item(0)->textContent);

    if (
      preg_match(
        '/(\d{4}\/\d{1,2}\/\d{1,2}\s+\d{1,2}:\d{2})/u',
        $updatedText,
        $matches
      )
    ) {
      $updatedAt = $matches[1];
    } else {
      $updatedAt = preg_replace(
        '/^更新日時[：:]\s*/u',
        '',
        $updatedText
      );
    }
  }

  $rankingTableNodes = $xpath->query("//table[@id='gyornk']");

  if (!$rankingTableNodes || $rankingTableNodes->length === 0) {
    throw new RuntimeException(
      "業種別株価指数ランキングが見つかりません。"
    );
  }

  $rankingTable = $rankingTableNodes->item(0);

  /*
   * ランキング本体はJavaScriptで後からtableへ追加される。
   * Web取得時は、見出し行とランキング行が別のtbodyに
   * 分かれる場合があるため、following-siblingでは探さない。
   *
   * class="tptd"かつ、配下にclass="perG"を持つtdを
   * ランキング左右のセルとして取得する。
   */
  $topCells = $xpath->query(
    ".//td[" . class_xpath_('tptd') . "]" .
    "[.//*[" . class_xpath_('perG') . "]]",
    $rankingTable
  );

  if (!$topCells || $topCells->length !== 2) {
    $rankingRows = $xpath->query(
      ".//tr[" . class_xpath_('trG') . "]",
      $rankingTable
    );

    throw new RuntimeException(
      "値上がり率・値下がり率TOP10の動的DOMが完成していません。" .
      " cells=" . ($topCells ? $topCells->length : 0) .
      " rows=" . ($rankingRows ? $rankingRows->length : 0)
    );
  }

  $gainers = parse_sector_ranking_cell_(
    $xpath,
    $topCells->item(0),
    'up'
  );

  $decliners = parse_sector_ranking_cell_(
    $xpath,
    $topCells->item(1),
    'down'
  );

  if (count($gainers) !== 10) {
    throw new RuntimeException(
      "値上がり率TOP10の取得件数が10件ではありません: " .
      count($gainers)
    );
  }

  if (count($decliners) !== 10) {
    throw new RuntimeException(
      "値下がり率TOP10の取得件数が10件ではありません: " .
      count($decliners)
    );
  }

  $changeTableNodes = $xpath->query("//table[@id='gtbl']");

  if (!$changeTableNodes || $changeTableNodes->length === 0) {
    throw new RuntimeException(
      "業種別株価指数変化率一覧が見つかりません。"
    );
  }

  $changeTable = $changeTableNodes->item(0);

  $headerNodes = $xpath->query(
    ".//thead[1]//th[" . class_xpath_('gn') . "]",
    $changeTable
  );

  $continuousRowNodes = $xpath->query(
    ".//tr[th[" . class_xpath_('gn2') .
    " and normalize-space(.)='連続']]",
    $changeTable
  );

  if (!$headerNodes || $headerNodes->length === 0) {
    throw new RuntimeException(
      "変化率一覧の業種見出しが見つかりません。"
    );
  }

  if (!$continuousRowNodes || $continuousRowNodes->length === 0) {
    throw new RuntimeException(
      "変化率一覧の連続行が見つかりません。"
    );
  }

  $continuousRow = $continuousRowNodes->item(0);
  $continuousCells = $xpath->query(
    "./td[" . class_xpath_('day2') . "]",
    $continuousRow
  );

  if ($headerNodes->length !== $continuousCells->length) {
    throw new RuntimeException(
      "業種見出し数と連続値数が一致しません。" .
      " headers={$headerNodes->length}" .
      " values={$continuousCells->length}"
    );
  }

  $continuous = array();

  for ($i = 0; $i < $headerNodes->length; $i++) {
    $sector = normalize_text_($headerNodes->item($i)->textContent);
    $cell = $continuousCells->item($i);
    $days = normalize_text_($cell->textContent);
    $style = strtolower((string)$cell->getAttribute('style'));

    $direction = '';

    // 当該サイトでは赤が下落、緑が上昇を表す。
    if (
      strpos($style, '#ff4444') !== false ||
      strpos($style, 'rgb(255, 68, 68)') !== false
    ) {
      $direction = '下落';
    } elseif (
      strpos($style, '#11cc11') !== false ||
      strpos($style, 'rgb(17, 204, 17)') !== false
    ) {
      $direction = '上昇';
    }

    /*
     * 当該ページでは、連続日数が1日の場合は数値が表示されず、
     * 空欄となるため、空欄は1として扱う。
     */
    if ($days === '' && $direction !== '') {
      $days = '1';
    }

    if ($days !== '' && !preg_match('/^\d+$/', $days)) {
      throw new RuntimeException(
        "連続日数が数値ではありません: {$sector}={$days}"
      );
    }

    $continuous[] = array(
      'sector' => $sector,
      'direction' => $direction,
      'days' => $days,
    );
  }

  if (count($continuous) !== 33) {
    throw new RuntimeException(
      "業種別連続値の取得件数が33件ではありません: " .
      count($continuous)
    );
  }

  $continuous =
    sort_sector_continuous_by_direction_($continuous);

  return array(
    'updated_at' => $updatedAt,
    'gainers' => $gainers,
    'decliners' => $decliners,
    'continuous' => $continuous,
  );
}

/**
 * 業種別連続情報を方向および連続日数で並べ替える。
 *
 * 並び順:
 *   1. 方向
 *        上昇
 *        下落
 *        方向不明
 *
 *   2. 連続日数
 *        上昇：降順
 *        下落：昇順
 *        方向不明：降順
 *
 * 方向および連続日数が同じ場合は、
 * HTML上の元の業種順を維持する。
 */
function sort_sector_continuous_by_direction_($continuous) {
  /*
   * 安定ソートを実現するため、元の並び順を保持しておく。
   * PHPのusort()は同値要素の順序を保証しないため、
   * 最後の比較条件として元の位置を使用する。
   */
  foreach ($continuous as $index => &$row) {
    $row['_original_index'] = $index;
  }
  unset($row);

  usort(
    $continuous,
    function ($a, $b) {
      $directionOrder = array(
        '上昇' => 1,
        '下落' => 2,
        ''     => 3,
      );

      $directionA = isset($a['direction'])
        ? (string)$a['direction']
        : '';

      $directionB = isset($b['direction'])
        ? (string)$b['direction']
        : '';

      $orderA = isset($directionOrder[$directionA])
        ? $directionOrder[$directionA]
        : 3;

      $orderB = isset($directionOrder[$directionB])
        ? $directionOrder[$directionB]
        : 3;

      /*
       * 第1ソートキー：方向
       */
      if ($orderA !== $orderB) {
        return $orderA < $orderB ? -1 : 1;
      }

      $daysA = isset($a['days']) && is_numeric($a['days'])
        ? (int)$a['days']
        : 0;

      $daysB = isset($b['days']) && is_numeric($b['days'])
        ? (int)$b['days']
        : 0;

      /*
       * 第2ソートキー：連続日数
       *
       * 上昇は降順
       * 下落は昇順
       * 方向不明は降順
       */
      if ($daysA !== $daysB) {
        if ($directionA === '下落') {
          return $daysA < $daysB ? -1 : 1;
        }

        return $daysA > $daysB ? -1 : 1;
      }

      /*
       * 方向と連続日数が同じ場合は元の順番を維持する。
       */
      $indexA = isset($a['_original_index'])
        ? (int)$a['_original_index']
        : 0;

      $indexB = isset($b['_original_index'])
        ? (int)$b['_original_index']
        : 0;

      if ($indexA === $indexB) {
        return 0;
      }

      return $indexA < $indexB ? -1 : 1;
    }
  );

  /*
   * ソート用に追加した内部項目を削除する。
   */
  foreach ($continuous as &$row) {
    unset($row['_original_index']);
  }
  unset($row);

  return $continuous;
}
/**
 * 指数値を小数点以下2桁、桁区切り付きに整形する。
 *
 * 例:
 *   732.96    → "   732.96"
 *   2883.74   → " 2,883.74"
 *   14219.70  → "14,219.70"
 */
function format_sector_index_value_($value) {
  $value = normalize_text_($value);
  $numericText = str_replace(',', '', $value);

  if (
    $numericText === '' ||
    !is_numeric($numericText)
  ) {
    return str_pad($value, 9, ' ', STR_PAD_LEFT);
  }

  $formatted = number_format(
    (float)$numericText,
    2,
    '.',
    ','
  );

  return str_pad($formatted, 9, ' ', STR_PAD_LEFT);
}
function parse_sector_ranking_cell_($xpath, $cell, $expectedDirection) {
  $rows = $xpath->query(
    ".//tr[" . class_xpath_('trG') . "]",
    $cell
  );

  $result = array();

  if (!$rows) {
    return $result;
  }

  for ($i = 0; $i < $rows->length; $i++) {
    $row = $rows->item($i);

    $rateNodes = $xpath->query(
      ".//*[" . class_xpath_('perG') . "]",
      $row
    );

    $sectorNodes = $xpath->query(
      ".//*[" . class_xpath_('texG') . "]",
      $row
    );

    $valueNodes = $xpath->query(
      ".//*[" . class_xpath_('valG') . "]",
      $row
    );

    if (
      !$rateNodes || $rateNodes->length === 0 ||
      !$sectorNodes || $sectorNodes->length === 0 ||
      !$valueNodes || $valueNodes->length === 0
    ) {
      throw new RuntimeException(
        "業種別ランキング行の解析に失敗しました。順位=" . ($i + 1)
      );
    }

    $rateText = normalize_text_($rateNodes->item(0)->textContent);
    $sector = normalize_text_($sectorNodes->item(0)->textContent);
    $indexValue = normalize_text_($valueNodes->item(0)->textContent);

    if (!preg_match('/([0-9]+(?:\.[0-9]+)?)\s*%/u', $rateText, $matches)) {
      throw new RuntimeException(
        "騰落率を取得できませんでした: {$rateText}"
      );
    }

    $rate = $matches[1];

    if ($expectedDirection === 'down') {
      $rate = '-' . $rate;
    } else {
      $rate = '+' . $rate;
    }

    $result[] = array(
      'rank' => $i + 1,
      'sector' => $sector,
      'rate' => $rate,
      'index_value' => $indexValue,
    );
  }

  return $result;
}

function build_tosho_sector_index_message_($parsed) {
  $lines = array();
  $lines[] = "■東証業種別指数";

  if ($parsed['updated_at'] !== '') {
    $lines[] = "更新日時: {$parsed['updated_at']}";
  }

  $lines[] = '';
  $lines[] = "【値上がり率 TOP10】";
  $lines[] = "順位\t騰落率\t指数値\t業種";

  foreach ($parsed['gainers'] as $row) {
    $rank = str_pad(
      (string)$row['rank'],
      2,
      ' ',
      STR_PAD_LEFT
    );

    $rate = str_pad(
      (string)$row['rate'] . '%',
      7,
      ' ',
      STR_PAD_LEFT
    );

    $indexValue =
      format_sector_index_value_($row['index_value']);

    $lines[] =
      $rank . "\t" .
      $rate . "\t" .
      $indexValue . "\t" .
      $row['sector'];
  }

  $lines[] = '';
  $lines[] = "【値下がり率 TOP10】";
  $lines[] = "順位\t騰落率\t指数値\t業種";

  foreach ($parsed['decliners'] as $row) {
    $rank = str_pad(
      (string)$row['rank'],
      2,
      ' ',
      STR_PAD_LEFT
    );

    $rate = str_pad(
      (string)$row['rate'] . '%',
      7,
      ' ',
      STR_PAD_LEFT
    );

    $indexValue =
      format_sector_index_value_($row['index_value']);

    $lines[] =
      $rank . "\t" .
      $rate . "\t" .
      $indexValue . "\t" .
      $row['sector'];
  }
  
  $lines[] = '';
  $lines[] = "【業種別株価指数 上昇・下落連続日数】";
  $lines[] = "方向\t連続日数\t業種";

  foreach ($parsed['continuous'] as $row) {
    $days = str_pad(
      (string)$row['days'],
      2,
      ' ',
      STR_PAD_LEFT
    );

    $lines[] =
      $row['direction'] . "\t" .
      $days . "\t" .
      $row['sector'];
  }

  return implode("\n", $lines);
}

