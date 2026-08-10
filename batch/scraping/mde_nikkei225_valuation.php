<?php
/**
 * 市況関連データ抽出：日経225バリュエーション
 *
 * market_data_extract.php から読み込まれ、
 * 日経225バリュエーションのHTML解析およびメッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 */

// =======================================================
// 日経225バリュエーション
// =======================================================

/**
 * 日経225バリュエーションHTMLを解析する。
 *
 * fetch_dom.js がHTMLへ追加したJSONデータから、
 * 指数ベースおよび加重平均のデータを取得し、レポートには最新10営業日分を出力する。
 *
 * JSONのDOM形式:
 *   <script
 *     id="mde_nikkei225_valuation_data"
 *     type="application/json"
 *   >
 *   {
 *     "index_base": [...],
 *     "weighted_average": [...]
 *   }
 *   </script>
 */
function parse_nikkei225_valuation_html_($html) {
  $xpath = load_xpath_($html);

  $dataNodes = $xpath->query(
    "//*[@id='mde_nikkei225_valuation_data']"
  );

  if (!$dataNodes || $dataNodes->length === 0) {
    throw new RuntimeException(
      "日経225バリュエーションの抽出用データが見つかりません。" .
      " fetch_dom.jsによる指数ベース・加重平均データの" .
      "DOM追加が完了しているか確認してください。"
    );
  }

  $json = trim((string)$dataNodes->item(0)->textContent);

  if ($json === '') {
    throw new RuntimeException(
      "日経225バリュエーションの抽出用JSONが空です。"
    );
  }

  $decoded = json_decode($json, true);

  if (!is_array($decoded)) {
    throw new RuntimeException(
      "日経225バリュエーションの抽出用JSONを解析できません。" .
      " json_error=" . json_last_error_msg()
    );
  }

  if (
    !isset($decoded['index_base']) ||
    !is_array($decoded['index_base'])
  ) {
    throw new RuntimeException(
      "指数ベースのデータが抽出用JSONに存在しません。"
    );
  }

  if (
    !isset($decoded['weighted_average']) ||
    !is_array($decoded['weighted_average'])
  ) {
    throw new RuntimeException(
      "加重平均のデータが抽出用JSONに存在しません。"
    );
  }

  $indexBase = normalize_nikkei225_valuation_rows_(
    $decoded['index_base'],
    '指数ベース'
  );

  $weightedAverage = normalize_nikkei225_valuation_rows_(
    $decoded['weighted_average'],
    '加重平均'
  );

  /*
   * レポートには最新10営業日分を出力する。
   *
   * 元データが10件未満の場合は異常終了する。
   * 10件を超えるデータは、先頭から10件だけを使用する。
   */
  if (count($indexBase) < 10) {
    throw new RuntimeException(
      "指数ベースの取得件数が10件未満です: " .
      count($indexBase)
    );
  }

  if (count($weightedAverage) < 10) {
    throw new RuntimeException(
      "加重平均の取得件数が10件未満です: " .
      count($weightedAverage)
    );
  }

  $indexBase = array_slice(
    $indexBase,
    0,
    10
  );

  $weightedAverage = array_slice(
    $weightedAverage,
    0,
    10
  );

  validate_nikkei225_valuation_dates_(
    $indexBase,
    $weightedAverage
  );
  
  return array(
    'index_base' => $indexBase,
    'weighted_average' => $weightedAverage,
  );
}

/**
 * 取得した各行を検証・正規化する。
 */
function normalize_nikkei225_valuation_rows_($rows, $basisName) {
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
      'nikkei225',
      'change',
      'prime_volume',
      'per',
      'pbr',
      'eps',
      'bps',
      'earnings_yield',
      'dividend_yield',
      'jgb_yield',
    );

    foreach ($requiredKeys as $key) {
      if (!array_key_exists($key, $row)) {
        throw new RuntimeException(
          "{$basisName}の{$rowNumber}行目に" .
          "必須項目がありません: {$key}"
        );
      }
    }

    $date = normalize_text_((string)$row['date']);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      throw new RuntimeException(
        "{$basisName}の日付形式が不正です: " .
        "row={$rowNumber} date={$date}"
      );
    }

    $nikkei225 = normalize_nikkei225_valuation_number_(
      $row['nikkei225'],
      $basisName,
      $rowNumber,
      '日本株225',
      2,
      false
    );

    $change = normalize_nikkei225_valuation_number_(
      $row['change'],
      $basisName,
      $rowNumber,
      '日本株225(変化)',
      2,
      true
    );

    $primeVolume = normalize_nikkei225_valuation_integer_(
      $row['prime_volume'],
      $basisName,
      $rowNumber,
      'プライム出来高(百万株)'
    );

    $per = normalize_nikkei225_valuation_number_(
      $row['per'],
      $basisName,
      $rowNumber,
      '日本225PER',
      2,
      false
    );

    $pbr = normalize_nikkei225_valuation_number_(
      $row['pbr'],
      $basisName,
      $rowNumber,
      '日本225PBR',
      2,
      false
    );

    $eps = normalize_nikkei225_valuation_number_(
      $row['eps'],
      $basisName,
      $rowNumber,
      '日本225EPS',
      2,
      false
    );

    $bps = normalize_nikkei225_valuation_number_(
      $row['bps'],
      $basisName,
      $rowNumber,
      '日本225BPS',
      2,
      false
    );

    $earningsYield = normalize_nikkei225_valuation_number_(
      $row['earnings_yield'],
      $basisName,
      $rowNumber,
      '日本225益回り',
      2,
      false
    );

    $dividendYield = normalize_nikkei225_valuation_number_(
      $row['dividend_yield'],
      $basisName,
      $rowNumber,
      '日本225配当利回り',
      2,
      false
    );

    $jgbYield = normalize_nikkei225_valuation_number_(
      $row['jgb_yield'],
      $basisName,
      $rowNumber,
      '日本国債利回り',
      3,
      false
    );

    $normalized[] = array(
      'date' => $date,
      'nikkei225' => $nikkei225,
      'change' => $change,
      'prime_volume' => $primeVolume,
      'per' => $per,
      'pbr' => $pbr,
      'eps' => $eps,
      'bps' => $bps,
      'earnings_yield' => $earningsYield,
      'dividend_yield' => $dividendYield,
      'jgb_yield' => $jgbYield,
    );
  }

  return $normalized;
}

/**
 * 小数値を検証し、指定桁数の表示文字列へ正規化する。
 */
function normalize_nikkei225_valuation_number_(
  $value,
  $basisName,
  $rowNumber,
  $fieldName,
  $decimalPlaces,
  $withSign
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

  if ($withSign) {
    if ($number > 0) {
      return '+' . number_format(
        $number,
        $decimalPlaces,
        '.',
        ','
      );
    }

    if ($number < 0) {
      return '-' . number_format(
        abs($number),
        $decimalPlaces,
        '.',
        ','
      );
    }
  }

  return number_format(
    $number,
    $decimalPlaces,
    '.',
    ','
  );
}

/**
 * 整数値を検証し、3桁ごとのカンマ区切りへ正規化する。
 */
function normalize_nikkei225_valuation_integer_(
  $value,
  $basisName,
  $rowNumber,
  $fieldName
) {
  $text = normalize_text_((string)$value);
  $numericText = str_replace(',', '', $text);

  if (!preg_match('/^\d+$/', $numericText)) {
    throw new RuntimeException(
      "{$basisName}の整数形式が不正です: " .
      "row={$rowNumber} field={$fieldName} value={$text}"
    );
  }

  return number_format((int)$numericText, 0, '.', ',');
}

/**
 * 指数ベースと加重平均で、日付および行順が一致することを確認する。
 */
function validate_nikkei225_valuation_dates_(
  $indexBase,
  $weightedAverage
) {
  $count = count($indexBase);

  for ($i = 0; $i < $count; $i++) {
    $indexDate = isset($indexBase[$i]['date'])
      ? (string)$indexBase[$i]['date']
      : '';

    $weightedDate = isset($weightedAverage[$i]['date'])
      ? (string)$weightedAverage[$i]['date']
      : '';

    if ($indexDate !== $weightedDate) {
      throw new RuntimeException(
        "指数ベースと加重平均の日付が一致しません: " .
        "row=" . ($i + 1) .
        " index_base={$indexDate}" .
        " weighted_average={$weightedDate}"
      );
    }
  }
}

/**
 * 日経225バリュエーションのレポート内容を作成する。
 */
function build_nikkei225_valuation_message_($parsed) {
  if (
    !is_array($parsed) ||
    !isset($parsed['index_base']) ||
    !is_array($parsed['index_base']) ||
    !isset($parsed['weighted_average']) ||
    !is_array($parsed['weighted_average'])
  ) {
    throw new RuntimeException(
      "日経225バリュエーションの解析結果が不正です。"
    );
  }

  $lines = array();
  $lines[] = '■日経225バリュエーション';
  $lines[] = '';

  append_nikkei225_valuation_section_(
    $lines,
    '指数ベース',
    $parsed['index_base']
  );

  $lines[] = '';

  append_nikkei225_valuation_section_(
    $lines,
    '加重平均',
    $parsed['weighted_average']
  );
  
  
  if (
    empty($parsed['weighted_average']) ||
    !isset($parsed['weighted_average'][0]['eps']) ||
    !isset($parsed['weighted_average'][0]['bps'])
  ) {
    throw new RuntimeException(
      "日経平均想定値の計算に必要な加重平均の最新EPS/BPSがありません。"
    );
  }

  // 加重平均の最新行を使用する。
  $latest = $parsed['weighted_average'][0];

  $eps = (float)str_replace(',', '', $latest['eps']);
  $bps = (float)str_replace(',', '', $latest['bps']);

  $lines[] = '';
  $lines[] = '【日経平均想定値】';

  // PERベース
  foreach ([25.0, 22.5, 20.0, 17.5, 15.0, 12.5, 10.0] as $per) {
    $lines[] = sprintf("PER = %-4.1f\t%s", $per, number_format($eps * $per, 0));
  }

  $lines[] = '';

  // PBRベース
  foreach ([2.8, 2.6, 2.4, 2.2, 2.0, 1.8, 1.6, 1.4, 1.2, 1.0, 0.8] as $pbr) {
    $lines[] = sprintf("PBR = %-3.1f\t%s",$pbr, number_format($bps * $pbr, 0));
  }

  return implode("\n", $lines);
}

/**
 * 指数ベースまたは加重平均の表をレポートへ追加する。
 */
function append_nikkei225_valuation_section_(
  &$lines,
  $basisName,
  $rows
) {
  $lines[] = "【{$basisName}】";
  $lines[] = implode(
    "\t",
    array(
    '日付',
    '日本225PER',
    '日本225PBR',
    '日本225EPS',
    '日本225BPS',
    '日本225益回り',
    '日本225配当利回り',
    '日本国債利回り',
    '日本株225',
    '日本株225(変化)',
    'プライム出来高(百万株)',
    )
  );

  foreach ($rows as $row) {
    $lines[] = implode(
      "\t",
      array(
        $row['date'],
        $row['per'],
        $row['pbr'],
        $row['eps'],
        $row['bps'],
        $row['earnings_yield'],
        $row['dividend_yield'],
        $row['jgb_yield'],
        $row['nikkei225'],
        $row['change'],
        $row['prime_volume'],
      )
    );
  }
}
