<?php
/**
 * 市況関連データ抽出：米国株バリュエーション
 *
 * market_data_extract.php から読み込まれ、
 * 米国株バリュエーションのHTML解析およびメッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 */

// =======================================================
// 米国株バリュエーション
// =======================================================

/**
 * 米国株バリュエーションHTMLを解析する。
 *
 * fetch_dom.js がHTMLへ追加したJSONデータから、
 * 予想PERおよび実績PERのデータを取得し、
 * レポートにはそれぞれ最新5営業日分を出力する。
 *
 * JSONのDOM形式:
 *   <script
 *     id="mde_us_market_valuation_data"
 *     type="application/json"
 *   >
 *   {
 *     "forward_per": [...],
 *     "trailing_per": [...]
 *   }
 *   </script>
 */
function parse_us_market_valuation_html_($html) {
  $xpath = load_xpath_($html);

  $dataNodes = $xpath->query(
    "//*[@id='mde_us_market_valuation_data']"
  );

  if (!$dataNodes || $dataNodes->length === 0) {
    throw new RuntimeException(
      "米国株バリュエーションの抽出用データが見つかりません。" .
      " fetch_dom.jsによる予想PER・実績PERデータの" .
      "DOM追加が完了しているか確認してください。"
    );
  }

  if ($dataNodes->length !== 1) {
    throw new RuntimeException(
      "米国株バリュエーションの抽出用データが複数存在します: " .
      $dataNodes->length
    );
  }

  $json = trim((string)$dataNodes->item(0)->textContent);

  if ($json === '') {
    throw new RuntimeException(
      "米国株バリュエーションの抽出用JSONが空です。"
    );
  }

  $decoded = json_decode($json, true);

  if (!is_array($decoded)) {
    throw new RuntimeException(
      "米国株バリュエーションの抽出用JSONを解析できません。" .
      " json_error=" . json_last_error_msg()
    );
  }

  if (
    !isset($decoded['forward_per']) ||
    !is_array($decoded['forward_per'])
  ) {
    throw new RuntimeException(
      "予想PERのデータが抽出用JSONに存在しません。"
    );
  }

  if (
    !isset($decoded['trailing_per']) ||
    !is_array($decoded['trailing_per'])
  ) {
    throw new RuntimeException(
      "実績PERのデータが抽出用JSONに存在しません。"
    );
  }

  $forwardPer = normalize_us_market_valuation_rows_(
    $decoded['forward_per'],
    '予想PER'
  );

  $trailingPer = normalize_us_market_valuation_rows_(
    $decoded['trailing_per'],
    '実績PER'
  );

  /*
   * レポートにはそれぞれ最新5営業日分を出力する。
   *
   * 元データが5件未満の場合は異常終了する。
   * 5件を超えるデータは、先頭から5件だけを使用する。
   */
  if (count($forwardPer) < 5) {
    throw new RuntimeException(
      "予想PERの取得件数が5件未満です: " .
      count($forwardPer)
    );
  }

  if (count($trailingPer) < 5) {
    throw new RuntimeException(
      "実績PERの取得件数が5件未満です: " .
      count($trailingPer)
    );
  }

  $forwardPer = array_slice(
    $forwardPer,
    0,
    5
  );

  $trailingPer = array_slice(
    $trailingPer,
    0,
    5
  );

  validate_us_market_valuation_dates_(
    $forwardPer,
    $trailingPer
  );

  return array(
    'forward_per' => $forwardPer,
    'trailing_per' => $trailingPer,
  );
}

/**
 * 取得した各行を検証・正規化する。
 */
function normalize_us_market_valuation_rows_(
  $rows,
  $basisName
) {
  $normalized = array();

  foreach ($rows as $index => $row) {
    $rowNumber = $index + 1;

    if (!is_array($row)) {
      throw new RuntimeException(
        "{$basisName}の{$rowNumber}行目が配列ではありません。"
      );
    }

    $requiredKeys = array(
      'date',

      'nikkei225_price',
      'nikkei225_per',
      'nikkei225_dividend_yield',

      'dow30_price',
      'dow30_per',
      'dow30_dividend_yield',

      'sp500_price',
      'sp500_per',
      'sp500_dividend_yield',

      'nasdaq100_price',
      'nasdaq100_per',
      'nasdaq100_dividend_yield',

      'russell2000_price',
      'russell2000_per',
      'russell2000_dividend_yield',
    );

    foreach ($requiredKeys as $key) {
      if (!array_key_exists($key, $row)) {
        throw new RuntimeException(
          "{$basisName}の{$rowNumber}行目に" .
          "必須項目がありません: {$key}"
        );
      }
    }

    // ---------------------------------------------------
    // 日付
    // ---------------------------------------------------
    $date = normalize_text_((string)$row['date']);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      throw new RuntimeException(
        "{$basisName}の日付形式が不正です: " .
        "row={$rowNumber} date={$date}"
      );
    }

    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    $errors = DateTime::getLastErrors();

    $invalidDate =
      $dt === false ||
      (
        $errors !== false &&
        (
          $errors['warning_count'] > 0 ||
          $errors['error_count'] > 0
        )
      ) ||
      $dt->format('Y-m-d') !== $date;

    if ($invalidDate) {
      throw new RuntimeException(
        "{$basisName}の日付が不正です: " .
        "row={$rowNumber} date={$date}"
      );
    }

    // ---------------------------------------------------
    // 日本225
    // ---------------------------------------------------
    $nikkei225Price =
      normalize_us_market_valuation_number_(
        $row['nikkei225_price'],
        $basisName,
        $rowNumber,
        '日本225株価',
        2
      );

    $nikkei225Per =
      normalize_us_market_valuation_number_(
        $row['nikkei225_per'],
        $basisName,
        $rowNumber,
        '日本225PER',
        3
      );

    $nikkei225DividendYield =
      normalize_us_market_valuation_percent_(
        $row['nikkei225_dividend_yield'],
        $basisName,
        $rowNumber,
        '日本225配当利回り'
      );

    // ---------------------------------------------------
    // DOW30
    // ---------------------------------------------------
    $dow30Price =
      normalize_us_market_valuation_number_(
        $row['dow30_price'],
        $basisName,
        $rowNumber,
        'DOW30株価',
        2
      );

    $dow30Per =
      normalize_us_market_valuation_number_(
        $row['dow30_per'],
        $basisName,
        $rowNumber,
        'DOW30PER',
        3
      );

    $dow30DividendYield =
      normalize_us_market_valuation_percent_(
        $row['dow30_dividend_yield'],
        $basisName,
        $rowNumber,
        'DOW30配当利回り'
      );

    // ---------------------------------------------------
    // S&P500
    // ---------------------------------------------------
    $sp500Price =
      normalize_us_market_valuation_number_(
        $row['sp500_price'],
        $basisName,
        $rowNumber,
        'S&P500株価',
        2
      );

    $sp500Per =
      normalize_us_market_valuation_number_(
        $row['sp500_per'],
        $basisName,
        $rowNumber,
        'S&P500PER',
        3
      );

    $sp500DividendYield =
      normalize_us_market_valuation_percent_(
        $row['sp500_dividend_yield'],
        $basisName,
        $rowNumber,
        'S&P500配当利回り'
      );

    // ---------------------------------------------------
    // NASDAQ100
    // ---------------------------------------------------
    $nasdaq100Price =
      normalize_us_market_valuation_number_(
        $row['nasdaq100_price'],
        $basisName,
        $rowNumber,
        'NASDAQ100株価',
        2
      );

    $nasdaq100Per =
      normalize_us_market_valuation_number_(
        $row['nasdaq100_per'],
        $basisName,
        $rowNumber,
        'NASDAQ100PER',
        3
      );

    $nasdaq100DividendYield =
      normalize_us_market_valuation_percent_(
        $row['nasdaq100_dividend_yield'],
        $basisName,
        $rowNumber,
        'NASDAQ100配当利回り'
      );

    // ---------------------------------------------------
    // Russell2000
    // ---------------------------------------------------
    $russell2000Price =
      normalize_us_market_valuation_number_(
        $row['russell2000_price'],
        $basisName,
        $rowNumber,
        'Russell2000株価',
        2
      );

    $russell2000Per =
      normalize_us_market_valuation_number_(
        $row['russell2000_per'],
        $basisName,
        $rowNumber,
        'Russell2000PER',
        3
      );

    $russell2000DividendYield =
      normalize_us_market_valuation_percent_(
        $row['russell2000_dividend_yield'],
        $basisName,
        $rowNumber,
        'Russell2000配当利回り'
      );

    $normalized[] = array(
      'date' => $date,

      'nikkei225_price' => $nikkei225Price,
      'nikkei225_per' => $nikkei225Per,
      'nikkei225_dividend_yield' => $nikkei225DividendYield,

      'dow30_price' => $dow30Price,
      'dow30_per' => $dow30Per,
      'dow30_dividend_yield' => $dow30DividendYield,

      'sp500_price' => $sp500Price,
      'sp500_per' => $sp500Per,
      'sp500_dividend_yield' => $sp500DividendYield,

      'nasdaq100_price' => $nasdaq100Price,
      'nasdaq100_per' => $nasdaq100Per,
      'nasdaq100_dividend_yield' => $nasdaq100DividendYield,

      'russell2000_price' => $russell2000Price,
      'russell2000_per' => $russell2000Per,
      'russell2000_dividend_yield' => $russell2000DividendYield,
    );
  }

  return $normalized;
}

/**
 * 数値を検証し、指定小数桁数の表示文字列へ正規化する。
 */
function normalize_us_market_valuation_number_(
  $value,
  $basisName,
  $rowNumber,
  $fieldName,
  $decimalPlaces
) {
  $text = normalize_text_((string)$value);
  $numericText = str_replace(',', '', $text);

  if (!preg_match('/^[+-]?\d+(?:\.\d+)?$/', $numericText)) {
    throw new RuntimeException(
      "{$basisName}の数値形式が不正です: " .
      "row={$rowNumber} field={$fieldName} value={$text}"
    );
  }

  $number = (float)$numericText;

  if ($number < 0) {
    throw new RuntimeException(
      "{$basisName}の数値が負数です: " .
      "row={$rowNumber} field={$fieldName} value={$text}"
    );
  }

  return number_format(
    $number,
    $decimalPlaces,
    '.',
    ','
  );
}

/**
 * 配当利回りを検証し、小数点以下2桁＋%の表示文字列へ正規化する。
 *
 * JSON側では、
 *   1.42
 *   1.42%
 * のどちらでも受け入れる。
 */
function normalize_us_market_valuation_percent_(
  $value,
  $basisName,
  $rowNumber,
  $fieldName
) {
  $text = normalize_text_((string)$value);

  $numericText = str_replace(
    array(',', '%', '％'),
    '',
    $text
  );

  if (!preg_match('/^\d+(?:\.\d+)?$/', $numericText)) {
    throw new RuntimeException(
      "{$basisName}の配当利回り形式が不正です: " .
      "row={$rowNumber} field={$fieldName} value={$text}"
    );
  }

  $number = (float)$numericText;

  return number_format(
    $number,
    2,
    '.',
    ''
  ) . '%';
}

/**
 * 予想PERと実績PERで、
 * 日付および行順が一致することを確認する。
 */
function validate_us_market_valuation_dates_(
  $forwardPer,
  $trailingPer
) {
  $count = count($forwardPer);

  if ($count !== count($trailingPer)) {
    throw new RuntimeException(
      "予想PERと実績PERの取得件数が一致しません: " .
      "forward_per={$count}" .
      " trailing_per=" . count($trailingPer)
    );
  }

  for ($i = 0; $i < $count; $i++) {
    $forwardDate = isset($forwardPer[$i]['date'])
      ? (string)$forwardPer[$i]['date']
      : '';

    $trailingDate = isset($trailingPer[$i]['date'])
      ? (string)$trailingPer[$i]['date']
      : '';

    if ($forwardDate !== $trailingDate) {
      throw new RuntimeException(
        "予想PERと実績PERの日付が一致しません: " .
        "row=" . ($i + 1) .
        " forward_per={$forwardDate}" .
        " trailing_per={$trailingDate}"
      );
    }
  }
}

/**
 * 米国株バリュエーションのレポート内容を作成する。
 */
function build_us_market_valuation_message_($parsed) {
  if (
    !is_array($parsed) ||
    !isset($parsed['forward_per']) ||
    !is_array($parsed['forward_per']) ||
    !isset($parsed['trailing_per']) ||
    !is_array($parsed['trailing_per'])
  ) {
    throw new RuntimeException(
      "米国株バリュエーションの解析結果が不正です。"
    );
  }

  $lines = array();

  $lines[] = '■米国株バリュエーション';
  $lines[] = '';

  append_us_market_valuation_section_(
    $lines,
    '予想PER',
    $parsed['forward_per']
  );

  $lines[] = '';

  append_us_market_valuation_section_(
    $lines,
    '実績PER',
    $parsed['trailing_per']
  );

  return implode("\n", $lines);
}

/**
 * 予想PERまたは実績PERの表をレポートへ追加する。
 */
function append_us_market_valuation_section_(
  &$lines,
  $basisName,
  $rows
) {
  $lines[] = "【{$basisName}】";

  $lines[] = implode(
    "\t",
    array(
      '日付',
      '日本225株価',
      '日本225PER',
      '日本225配当利回り',
      'DOW30株価',
      'DOW30PER',
      'DOW30配当利回り',
      'S&P500株価',
      'S&P500PER',
      'S&P500配当利回り',
      'NASDAQ100株価',
      'NASDAQ100PER',
      'NASDAQ100配当利回り',
      'Russell2000株価',
      'Russell2000PER',
      'Russell2000配当利回り',
    )
  );

  foreach ($rows as $row) {
    $lines[] = implode(
      "\t",
      array(
        $row['date'],

        sprintf(
          '%10s',
          $row['nikkei225_price']
        ),
        sprintf(
          '%7s',
          $row['nikkei225_per']
        ),
        sprintf(
          '%7s',
          $row['nikkei225_dividend_yield']
        ),

        sprintf(
          '%10s',
          $row['dow30_price']
        ),
        sprintf(
          '%7s',
          $row['dow30_per']
        ),
        sprintf(
          '%7s',
          $row['dow30_dividend_yield']
        ),

        sprintf(
          '%10s',
          $row['sp500_price']
        ),
        sprintf(
          '%7s',
          $row['sp500_per']
        ),
        sprintf(
          '%7s',
          $row['sp500_dividend_yield']
        ),

        sprintf(
          '%10s',
          $row['nasdaq100_price']
        ),
        sprintf(
          '%7s',
          $row['nasdaq100_per']
        ),
        sprintf(
          '%7s',
          $row['nasdaq100_dividend_yield']
        ),

        sprintf(
          '%10s',
          $row['russell2000_price']
        ),
        sprintf(
          '%7s',
          $row['russell2000_per']
        ),
        sprintf(
          '%7s',
          $row['russell2000_dividend_yield']
        ),
      )
    );
  }
}