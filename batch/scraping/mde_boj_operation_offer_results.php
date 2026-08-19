<?php
/**
 * 市況関連データ抽出：日銀オペレーション・オファー／落札結果
 *
 * market_data_extract.php から読み込まれ、
 * 日本銀行の「オペレーション・オファー／落札結果」XLSXを解析し、
 * オファーおよび落札結果を抽出して
 * レポート本文を生成する。
 *
 * XLSX形式:
 *   Office Open XML Spreadsheet
 *   ZIP + XML
 *
 * 日本語行のみを対象とし、
 * 英語表記は出力しない。
 */

// =======================================================
// 日銀オペレーション・オファー／落札結果
// =======================================================

/**
 * 日銀オペレーション・オファー／落札結果XLSXを解析する。
 *
 * タイトルには西暦年が存在しないため、
 * 処理対象日$targetDateの年を補完に使用する。
 *
 * @param string $xlsxData XLSXバイナリ
 * @param string $targetDate yyyy-MM-dd
 * @return array
 */
function parse_boj_operation_offer_results_xlsx_(
  $xlsxData,
  $targetDate
) {
  if (
    !is_string($xlsxData) ||
    $xlsxData === ''
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果のXLSXデータが空です。"
    );
  }

  if (
    !is_string($targetDate) ||
    !preg_match(
      '/^\d{4}-\d{2}-\d{2}$/',
      $targetDate
    )
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の処理対象日が不正です: " .
      (string)$targetDate
    );
  }

  $targetParts =
    explode(
      '-',
      $targetDate
    );

  $targetYear =
    (int)$targetParts[0];

  $targetMonth =
    (int)$targetParts[1];

  $targetDay =
    (int)$targetParts[2];

  if (
    !checkdate(
      $targetMonth,
      $targetDay,
      $targetYear
    )
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の処理対象日が実在しません: " .
      $targetDate
    );
  }

  // -------------------------------------------------------
  // XLSXセル取得
  // -------------------------------------------------------

  $cells =
    boj_operation_offer_results_extract_xlsx_cells_(
      $xlsxData
    );

  if (
    !is_array($cells) ||
    count($cells) === 0
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果のセル情報を取得できません。"
    );
  }

  // -------------------------------------------------------
  // タイトル・更新日の取得
  // -------------------------------------------------------

  $titleRow = null;
  $titleText = '';

  foreach ($cells as $row => $columns) {
    foreach ($columns as $value) {
      if (!is_string($value)) {
        continue;
      }

      $text =
        boj_operation_offer_results_normalize_text_(
          $value
        );

      if (
        strpos(
          $text,
          'オペレーション'
        ) !== false &&
        preg_match(
          '/[（(]\s*\d{1,2}月\d{1,2}日/u',
          $text
        )
      ) {
        $titleRow =
          (int)$row;

        $titleText =
          $text;

        break 2;
      }
    }
  }

  if ($titleRow === null) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果のタイトルを取得できません。"
    );
  }

  /*
   * 例:
   *
   * オペレーション（8月14日＜金＞）
   */
  if (
    !preg_match(
      '/[（(]\s*(\d{1,2})月(\d{1,2})日/u',
      $titleText,
      $dateMatches
    )
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の更新月日を取得できません: " .
      $titleText
    );
  }

  $reportMonth =
    (int)$dateMatches[1];

  $reportDay =
    (int)$dateMatches[2];

  /*
   * 通常は処理対象日と同一年。
   *
   * 年初に前年12月分を処理する場合を考慮し、
   * 更新月が処理対象月より6か月を超えて大きい場合は
   * 前年として扱う。
   */
  $reportYear =
    $targetYear;

  if (
    $reportMonth >
    $targetMonth + 6
  ) {
    $reportYear--;
  }

  if (
    !checkdate(
      $reportMonth,
      $reportDay,
      $reportYear
    )
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の更新日が不正です: " .
      $titleText
    );
  }

  $updatedAt =
    sprintf(
      '%04d-%02d-%02d',
      $reportYear,
      $reportMonth,
      $reportDay
    );

  // -------------------------------------------------------
  // 単位取得
  // -------------------------------------------------------

  $unit = '';

  foreach ($cells as $row => $columns) {
    /*
     * タイトルより後、
     * 表見出しより前付近を対象とする。
     */
    if (
      $row < $titleRow ||
      $row > $titleRow + 10
    ) {
      continue;
    }

    foreach ($columns as $value) {
      if (!is_string($value)) {
        continue;
      }

      $text =
        boj_operation_offer_results_normalize_text_(
          $value
        );

      if (
        preg_match(
          '/[（(]\s*単位\s*[:：]\s*([^）)]+)\s*[）)]/u',
          $text,
          $unitMatches
        )
      ) {
        $unit =
          trim(
            $unitMatches[1]
          );

        break 2;
      }
    }
  }

  if ($unit === '') {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の単位を取得できません。"
    );
  }

  // -------------------------------------------------------
  // 列見出しの取得
  // -------------------------------------------------------

  $headerRow = null;

  $columns = array(
    'instrument' =>
      null,

    'offer_amount' =>
      null,

    'start_date' =>
      null,

    'end_date' =>
      null,

    'loan_rate' =>
      null,

    'yield' =>
      null,

    'competitive_bid' =>
      null,

    'successful_bid' =>
      null,

    'prorata_rate' =>
      null,

    'non_prorata_rate' =>
      null,

    'average_successful_rate' =>
      null,

    'allocation_rate' =>
      null,
  );

  foreach ($cells as $row => $rowCells) {
    $found =
      array();

    foreach ($rowCells as $col => $value) {
      if (!is_string($value)) {
        continue;
      }

      $label =
        boj_operation_offer_results_normalize_label_(
          $value
        );

      if ($label === '種類') {
        $found['instrument'] =
          (int)$col;
      } elseif ($label === 'オファー額(注1)') {
        $found['offer_amount'] =
          (int)$col;
      } elseif ($label === 'スタート日(注2)') {
        $found['start_date'] =
          (int)$col;
      } elseif ($label === 'エンド日(注2)') {
        $found['end_date'] =
          (int)$col;
      } elseif ($label === '貸付利率') {
        $found['loan_rate'] =
          (int)$col;
      } elseif ($label === '利回り(注3)') {
        $found['yield'] =
          (int)$col;
      } elseif ($label === '応札総額(注4)') {
        $found['competitive_bid'] =
          (int)$col;
      } elseif ($label === '落札総額(注4)') {
        $found['successful_bid'] =
          (int)$col;
      } elseif ($label === '按分レート') {
        $found['prorata_rate'] =
          (int)$col;
      } elseif ($label === '全取レート') {
        $found['non_prorata_rate'] =
          (int)$col;
      } elseif ($label === '平均落札レート') {
        $found['average_successful_rate'] =
          (int)$col;
      } elseif ($label === '按分比率') {
        $found['allocation_rate'] =
          (int)$col;
      }
    }

    if (
      isset(
        $found['instrument'],
        $found['offer_amount'],
        $found['start_date'],
        $found['end_date'],
        $found['loan_rate'],
        $found['yield'],
        $found['competitive_bid'],
        $found['successful_bid'],
        $found['prorata_rate'],
        $found['non_prorata_rate'],
        $found['average_successful_rate'],
        $found['allocation_rate']
      )
    ) {
      $headerRow =
        (int)$row;

      foreach ($columns as $key => $unused) {
        $columns[$key] =
          $found[$key];
      }

      break;
    }
  }

  if ($headerRow === null) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の列見出しを取得できません。"
    );
  }

  /*
   * 列順が想定どおりであることを確認する。
   */
  $orderedColumns =
    array_values(
      $columns
    );

  for (
    $i = 1;
    $i < count($orderedColumns);
    $i++
  ) {
    if (
      $orderedColumns[$i - 1] >=
      $orderedColumns[$i]
    ) {
      throw new RuntimeException(
        "日銀オペレーション・オファー／落札結果の列順が不正です。"
      );
    }
  }

  // -------------------------------------------------------
  // 英語Notes開始行の取得
  // -------------------------------------------------------

  $englishNotesRow =
    null;

  foreach ($cells as $row => $rowCells) {
    if ($row <= $headerRow) {
      continue;
    }

    foreach ($rowCells as $value) {
      if (!is_string($value)) {
        continue;
      }

      $text =
        boj_operation_offer_results_normalize_text_(
          $value
        );

      if (
        $text === 'Notes:' ||
        $text === 'Notes'
      ) {
        $englishNotesRow =
          (int)$row;

        break 2;
      }
    }
  }

  if ($englishNotesRow === null) {
    $englishNotesRow =
      max(
        array_keys(
          $cells
        )
      ) + 1;
  }

  // -------------------------------------------------------
  // 日本語データ行・注記行の抽出
  // -------------------------------------------------------

  $rows =
    array();

  for (
    $row = $headerRow + 1;
    $row < $englishNotesRow;
    $row++
  ) {
    if (
      !isset(
        $cells[$row]
      )
    ) {
      continue;
    }

    /*
     * 種類・注記はB～D列に配置されている。
     *
     * 通常データ:
     *   B列 = 種類
     *
     * 注記:
     *   B列 = (注1)
     *   D列 = 説明
     */
    $itemParts =
      array();

    for (
      $col = 2;
      $col <= 4;
      $col++
    ) {
      if (
        !array_key_exists(
          $col,
          $cells[$row]
        )
      ) {
        continue;
      }

      $value =
        $cells[$row][$col];

      if (!is_string($value)) {
        continue;
      }

      $text =
        boj_operation_offer_results_normalize_text_(
          $value
        );

      if ($text === '') {
        continue;
      }

      $itemParts[] =
        $text;
    }

    if (
      count(
        $itemParts
      ) === 0
    ) {
      continue;
    }

    $item =
      implode(
        ' ',
        $itemParts
      );

    /*
     * 英語表記行は除外する。
     *
     * 数字や記号を含んでいても、
     * 日本語を1文字以上含めば対象とする。
     */
    if (
      !boj_operation_offer_results_contains_japanese_(
        $item
      )
    ) {
      continue;
    }

    /*
     * (注1)～(注6) は注記行。
     *
     * 注記行は種類だけを出力し、
     * 他列へ「ー」を出力しない。
     */
    $isNote =
      preg_match(
        '/^[（(]\s*注\s*[1-6]\s*[）)]/u',
        $item
      ) === 1;

    if ($isNote) {
      $rows[] =
        array(
          'instrument' =>
            $item,

          'is_note' =>
            true,

          'offer_amount' =>
            null,

          'start_date' =>
            null,

          'end_date' =>
            null,

          'loan_rate' =>
            null,

          'yield' =>
            null,

          'competitive_bid' =>
            null,

          'successful_bid' =>
            null,

          'prorata_rate' =>
            null,

          'non_prorata_rate' =>
            null,

          'average_successful_rate' =>
            null,

          'allocation_rate' =>
            null,
        );

      continue;
    }

    /*
     * 通常のデータ行。
     */
    $rows[] =
      array(
        'instrument' =>
          $item,

        'is_note' =>
          false,

        'offer_amount' =>
          boj_operation_offer_results_parse_number_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['offer_amount']
            ),
            $item,
            'オファー額'
          ),

        'start_date' =>
          boj_operation_offer_results_parse_date_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['start_date']
            ),
            $item,
            'スタート日'
          ),

        'end_date' =>
          boj_operation_offer_results_parse_date_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['end_date']
            ),
            $item,
            'エンド日'
          ),

        'loan_rate' =>
          boj_operation_offer_results_parse_number_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['loan_rate']
            ),
            $item,
            '貸付利率'
          ),

        'yield' =>
          boj_operation_offer_results_parse_number_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['yield']
            ),
            $item,
            '利回り'
          ),

        'competitive_bid' =>
          boj_operation_offer_results_parse_number_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['competitive_bid']
            ),
            $item,
            '応札総額'
          ),

        'successful_bid' =>
          boj_operation_offer_results_parse_number_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['successful_bid']
            ),
            $item,
            '落札総額'
          ),

        'prorata_rate' =>
          boj_operation_offer_results_parse_number_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['prorata_rate']
            ),
            $item,
            '按分レート'
          ),

        'non_prorata_rate' =>
          boj_operation_offer_results_parse_number_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['non_prorata_rate']
            ),
            $item,
            '全取レート'
          ),

        'average_successful_rate' =>
          boj_operation_offer_results_parse_number_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['average_successful_rate']
            ),
            $item,
            '平均落札レート'
          ),

        'allocation_rate' =>
          boj_operation_offer_results_parse_number_(
            boj_operation_offer_results_get_cell_(
              $cells,
              $row,
              $columns['allocation_rate']
            ),
            $item,
            '按分比率'
          ),
      );
  }

  if (
    count(
      $rows
    ) === 0
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の日本語データ行を取得できません。"
    );
  }

  // -------------------------------------------------------
  // 注1～注6取得確認
  // -------------------------------------------------------

  $notesFound =
    array();

  foreach ($rows as $dataRow) {
    if (
      empty(
        $dataRow['is_note']
      )
    ) {
      continue;
    }

    if (
      preg_match(
        '/^[（(]\s*注\s*([1-6])\s*[）)]/u',
        $dataRow['instrument'],
        $noteMatches
      )
    ) {
      $notesFound[
        (int)$noteMatches[1]
      ] = true;
    }
  }

  for (
    $noteNo = 1;
    $noteNo <= 6;
    $noteNo++
  ) {
    if (
      empty(
        $notesFound[$noteNo]
      )
    ) {
      throw new RuntimeException(
        "日銀オペレーション・オファー／落札結果の(注{$noteNo})を取得できません。"
      );
    }
  }

  return
    array(
      'updated_at' =>
        $updatedAt,

      'unit' =>
        $unit,

      'rows' =>
        $rows,
    );
}

/**
 * 指定セルを取得する。
 *
 * 存在しない場合はnullを返す。
 *
 * @param array $cells
 * @param int $row
 * @param int $col
 * @return mixed
 */
function boj_operation_offer_results_get_cell_(
  $cells,
  $row,
  $col
) {
  if (
    !isset(
      $cells[$row]
    ) ||
    !array_key_exists(
      $col,
      $cells[$row]
    )
  ) {
    return
      null;
  }

  return
    $cells[$row][$col];
}

/**
 * 数値セルを解析する。
 *
 * 空欄はnull。
 *
 * @param mixed $value
 * @param string $item
 * @param string $fieldName
 * @return float|null
 */
function boj_operation_offer_results_parse_number_(
  $value,
  $item,
  $fieldName
) {
  if (
    $value === null ||
    $value === ''
  ) {
    return
      null;
  }

  if (
    is_int($value) ||
    is_float($value)
  ) {
    return
      (float)$value;
  }

  if (!is_string($value)) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の{$fieldName}の型が不正です: " .
      $item
    );
  }

  $text =
    trim(
      str_replace(
        ',',
        '',
        $value
      )
    );

  if ($text === '') {
    return
      null;
  }

  $text =
    str_replace(
      array(
        '＋',
        '－',
        '−',
        '▲'
      ),
      array(
        '+',
        '-',
        '-',
        '-'
      ),
      $text
    );

  if (
    !is_numeric(
      $text
    )
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の{$fieldName}が数値ではありません: " .
      $item .
      " / " .
      $value
    );
  }

  return
    (float)$text;
}

/**
 * 日付セルをyyyy-MM-ddへ変換する。
 *
 * XLSX内部のExcelシリアル値と、
 * 文字列形式の双方に対応する。
 *
 * @param mixed $value
 * @param string $item
 * @param string $fieldName
 * @return string|null
 */
function boj_operation_offer_results_parse_date_(
  $value,
  $item,
  $fieldName
) {
  if (
    $value === null ||
    $value === ''
  ) {
    return
      null;
  }

  /*
   * XLSXのExcel日付シリアル。
   *
   * 例:
   *   46248 → 2026-08-14
   */
  if (
    is_int($value) ||
    is_float($value)
  ) {
    $serial =
      (float)$value;

    if (
      $serial <= 0
    ) {
      throw new RuntimeException(
        "日銀オペレーション・オファー／落札結果の{$fieldName}が不正です: " .
        $item .
        " / " .
        (string)$serial
      );
    }

    $days =
      (int)floor(
        $serial
      );

    /*
     * Excel 1900 date system。
     *
     * 1899-12-30を基準とすることで、
     * Excelの1900年うるう年互換を吸収する。
     */
    $base =
      new DateTimeImmutable(
        '1899-12-30',
        new DateTimeZone(
          'UTC'
        )
      );

    $date =
      $base->modify(
        '+' .
        $days .
        ' days'
      );

    return
      $date->format(
        'Y-m-d'
      );
  }

  if (!is_string($value)) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の{$fieldName}の型が不正です: " .
      $item
    );
  }

  $text =
    trim(
      $value
    );

  if ($text === '') {
    return
      null;
  }

  if (
    !preg_match(
      '/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/',
      $text,
      $matches
    )
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の{$fieldName}形式が不正です: " .
      $item .
      " / " .
      $text
    );
  }

  $year =
    (int)$matches[1];

  $month =
    (int)$matches[2];

  $day =
    (int)$matches[3];

  if (
    !checkdate(
      $month,
      $day,
      $year
    )
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の{$fieldName}が実在しません: " .
      $item .
      " / " .
      $text
    );
  }

  return
    sprintf(
      '%04d-%02d-%02d',
      $year,
      $month,
      $day
    );
}

/**
 * 金額系の値を表示用へ整形する。
 *
 * 正数は+、
 * 負数は-、
 * 0は符号なし。
 *
 * 空欄は「ー」。
 *
 * @param float|null $value
 * @return string
 */
function format_boj_operation_offer_results_amount_(
  $value
) {
  if ($value === null) {
    return
      'ー';
  }

  /*
   * 金額系は整数であることを確認する。
   */
  $rounded =
    round(
      (float)$value
    );

  if (
    abs(
      (float)$value -
      $rounded
    ) >
    0.0000001
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の金額値が整数ではありません: " .
      (string)$value
    );
  }

  $intValue =
    (int)$rounded;

  if ($intValue > 0) {
    return
      '+' .
      number_format(
        $intValue
      );
  }

  if ($intValue < 0) {
    return
      '-' .
      number_format(
        abs(
          $intValue
        )
      );
  }

  return
    '0';
}

/**
 * レート系の値を小数点以下3桁へ整形する。
 *
 * 正数は+、
 * 負数は-、
 * 0は符号なし。
 *
 * 空欄は「ー」。
 *
 * @param float|null $value
 * @return string
 */
function format_boj_operation_offer_results_rate_(
  $value
) {
  if ($value === null) {
    return
      'ー';
  }

  $number =
    (float)$value;

  if ($number > 0) {
    return
      '+' .
      number_format(
        $number,
        3,
        '.',
        ''
      );
  }

  if ($number < 0) {
    return
      '-' .
      number_format(
        abs(
          $number
        ),
        3,
        '.',
        ''
      );
  }

  return
    number_format(
      0,
      3,
      '.',
      ''
    );
}

/**
 * 日付表示。
 *
 * 空欄の場合は「ー」。
 *
 * @param string|null $value
 * @return string
 */
function format_boj_operation_offer_results_date_(
  $value
) {
  if (
    $value === null ||
    $value === ''
  ) {
    return
      'ー';
  }

  return
    (string)$value;
}

/**
 * 種類を全角40文字分の表示幅とし、
 * 右側を半角スペースで埋める。
 *
 * 全角1文字=2、
 * 半角1文字=1として表示幅80とする。
 *
 * 表示幅80以上の場合は、
 * 切り捨てずそのまま出力する。
 *
 * @param string $value
 * @return string
 */
function pad_boj_operation_offer_results_instrument_(
  $value
) {
  if (
    !function_exists(
      'mb_strwidth'
    )
  ) {
    throw new RuntimeException(
      "mb_strwidth()が使用できません: 種類"
    );
  }

  $value =
    (string)$value;

  $width =
    mb_strwidth(
      $value,
      'UTF-8'
    );

  $targetWidth =
    80;

  if (
    $width >=
    $targetWidth
  ) {
    return
      $value;
  }

  return
    $value .
    str_repeat(
      ' ',
      $targetWidth - $width
    );
}

/**
 * 指定表示幅で右寄せする。
 *
 * @param string $value
 * @param int $targetWidth
 * @param string $fieldName
 * @return string
 */
function pad_boj_operation_offer_results_left_(
  $value,
  $targetWidth,
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

  $width =
    mb_strwidth(
      $value,
      'UTF-8'
    );

  if (
    $width >=
    $targetWidth
  ) {
    return
      $value;
  }

  return
    str_repeat(
      ' ',
      $targetWidth - $width
    ) .
    $value;
}

/**
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_boj_operation_offer_results_message_(
  $parsed
) {
  if (
    !isset(
      $parsed['updated_at']
    ) ||
    !is_string(
      $parsed['updated_at']
    ) ||
    !preg_match(
      '/^\d{4}-\d{2}-\d{2}$/',
      $parsed['updated_at']
    )
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の更新日時が不正です。"
    );
  }

  if (
    !isset(
      $parsed['unit']
    ) ||
    !is_string(
      $parsed['unit']
    ) ||
    trim(
      $parsed['unit']
    ) === ''
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の単位が不正です。"
    );
  }

  if (
    !isset(
      $parsed['rows']
    ) ||
    !is_array(
      $parsed['rows']
    ) ||
    count(
      $parsed['rows']
    ) === 0
  ) {
    throw new RuntimeException(
      "日銀オペレーション・オファー／落札結果の抽出結果がありません。"
    );
  }

  $lines =
    array();

  // -------------------------------------------------------
  // 見出し
  // -------------------------------------------------------

  $lines[] =
    "■日銀オペレーション・オファー／落札結果";

  $lines[] =
    "更新日時: " .
    $parsed['updated_at'];

  $lines[] =
    "単位: " .
    $parsed['unit'];

  $lines[] = '';

  // -------------------------------------------------------
  // 列見出し
  // -------------------------------------------------------

  $lines[] =
    "種類\t" .
    "オファー：：オファー額(注1)\t" .
    "スタート日(注2)\t" .
    "エンド日(注2)\t" .
    "貸付利率\t" .
    "利回り(注3)\t" .
    "落札結果：：応札総額(注4)\t" .
    "落札総額(注4)\t" .
    "按分レート：利回較差(注5)、価格較差(注6)\t" .
    "全取レート：利回較差(注5)、価格較差(注6)\t" .
    "平均落札レート：利回較差(注5)、価格較差(注6)\t" .
    "按分比率";

  // -------------------------------------------------------
  // データ行
  // -------------------------------------------------------

  foreach ($parsed['rows'] as $row) {
    if (
      !isset(
        $row['instrument']
      ) ||
      !is_string(
        $row['instrument']
      ) ||
      trim(
        $row['instrument']
      ) === ''
    ) {
      throw new RuntimeException(
        "日銀オペレーション・オファー／落札結果の種類が不正です。"
      );
    }

    /*
     * (注1)～(注6) は説明行。
     *
     * 数値列へ「ー」を出力しない。
     */
    if (
      !empty(
        $row['is_note']
      )
    ) {
      $lines[] =
        rtrim(
          $row['instrument']
        );

      continue;
    }

    $offerAmount =
      format_boj_operation_offer_results_amount_(
        $row['offer_amount']
      );

    $startDate =
      format_boj_operation_offer_results_date_(
        $row['start_date']
      );

    $endDate =
      format_boj_operation_offer_results_date_(
        $row['end_date']
      );

    $loanRate =
      format_boj_operation_offer_results_rate_(
        $row['loan_rate']
      );

    $yield =
      format_boj_operation_offer_results_rate_(
        $row['yield']
      );

    $competitiveBid =
      format_boj_operation_offer_results_amount_(
        $row['competitive_bid']
      );

    $successfulBid =
      format_boj_operation_offer_results_amount_(
        $row['successful_bid']
      );

    $prorataRate =
      format_boj_operation_offer_results_rate_(
        $row['prorata_rate']
      );

    $nonProrataRate =
      format_boj_operation_offer_results_rate_(
        $row['non_prorata_rate']
      );

    $averageSuccessfulRate =
      format_boj_operation_offer_results_rate_(
        $row['average_successful_rate']
      );

    $allocationRate =
      format_boj_operation_offer_results_rate_(
        $row['allocation_rate']
      );

    $lines[] =
      pad_boj_operation_offer_results_instrument_(
        $row['instrument']
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $offerAmount,
        12,
        'オファー額'
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $startDate,
        10,
        'スタート日'
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $endDate,
        10,
        'エンド日'
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $loanRate,
        8,
        '貸付利率'
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $yield,
        8,
        '利回り'
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $competitiveBid,
        12,
        '応札総額'
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $successfulBid,
        12,
        '落札総額'
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $prorataRate,
        8,
        '按分レート'
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $nonProrataRate,
        8,
        '全取レート'
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $averageSuccessfulRate,
        8,
        '平均落札レート'
      ) .
      "\t" .
      pad_boj_operation_offer_results_left_(
        $allocationRate,
        8,
        '按分比率'
      );
  }

  return
    implode(
      "\n",
      $lines
    );
}

// =======================================================
// XLSX / Office Open XML
// =======================================================

/**
 * XLSXバイナリから先頭ワークシートのセルを取得する。
 *
 * @param string $xlsxData
 * @return array
 */
function boj_operation_offer_results_extract_xlsx_cells_(
  $xlsxData
) {
  if (
    !class_exists(
      'ZipArchive'
    )
  ) {
    throw new RuntimeException(
      "ZipArchiveが使用できません。"
    );
  }

  if (
    !class_exists(
      'DOMDocument'
    )
  ) {
    throw new RuntimeException(
      "DOMDocumentが使用できません。"
    );
  }

  $tmpPath =
    tempnam(
      sys_get_temp_dir(),
      'boj_ope_xlsx_'
    );

  if ($tmpPath === false) {
    throw new RuntimeException(
      "XLSX解析用の一時ファイルを作成できません。"
    );
  }

  try {
    if (
      file_put_contents(
        $tmpPath,
        $xlsxData
      ) === false
    ) {
      throw new RuntimeException(
        "XLSX解析用の一時ファイルへ書き込めません。"
      );
    }

    $zip =
      new ZipArchive();

    $result =
      $zip->open(
        $tmpPath
      );

    if ($result !== true) {
      throw new RuntimeException(
        "日銀オペレーション・オファー／落札結果のXLSXをZIPとして開けません: " .
        (string)$result
      );
    }

    try {
      // ---------------------------------------------------
      // Shared Strings
      // ---------------------------------------------------

      $sharedStrings =
        array();

      $sharedStringsXml =
        $zip->getFromName(
          'xl/sharedStrings.xml'
        );

      if ($sharedStringsXml !== false) {
        $sharedStrings =
          boj_operation_offer_results_parse_shared_strings_(
            $sharedStringsXml
          );
      }

      // ---------------------------------------------------
      // 先頭ワークシート
      // ---------------------------------------------------

      $sheetPath =
        boj_operation_offer_results_get_first_sheet_path_(
          $zip
        );

      $sheetXml =
        $zip->getFromName(
          $sheetPath
        );

      if ($sheetXml === false) {
        throw new RuntimeException(
          "XLSXのワークシートXMLを取得できません: " .
          $sheetPath
        );
      }

      return
        boj_operation_offer_results_parse_sheet_xml_(
          $sheetXml,
          $sharedStrings
        );
    } finally {
      $zip->close();
    }
  } finally {
    if (
      is_file(
        $tmpPath
      )
    ) {
      @unlink(
        $tmpPath
      );
    }
  }
}

/**
 * workbook.xmlから先頭ワークシートのXMLパスを取得する。
 *
 * @param ZipArchive $zip
 * @return string
 */
function boj_operation_offer_results_get_first_sheet_path_(
  $zip
) {
  $workbookXml =
    $zip->getFromName(
      'xl/workbook.xml'
    );

  $relsXml =
    $zip->getFromName(
      'xl/_rels/workbook.xml.rels'
    );

  if (
    $workbookXml === false ||
    $relsXml === false
  ) {
    if (
      $zip->locateName(
        'xl/worksheets/sheet1.xml'
      ) !== false
    ) {
      return
        'xl/worksheets/sheet1.xml';
    }

    throw new RuntimeException(
      "XLSXのworkbook情報を取得できません。"
    );
  }

  $workbookDom =
    new DOMDocument();

  if (
    !@$workbookDom->loadXML(
      $workbookXml
    )
  ) {
    throw new RuntimeException(
      "XLSXのworkbook.xmlを解析できません。"
    );
  }

  $workbookXpath =
    new DOMXPath(
      $workbookDom
    );

  $sheetNodes =
    $workbookXpath->query(
      '//*[local-name()="sheets"]/*[local-name()="sheet"]'
    );

  if (
    !$sheetNodes ||
    $sheetNodes->length < 1
  ) {
    throw new RuntimeException(
      "XLSXにワークシートが存在しません。"
    );
  }

  $firstSheet =
    $sheetNodes->item(0);

  $relationshipId =
    '';

  foreach (
    $firstSheet->attributes
    as $attribute
  ) {
    if (
      $attribute->localName === 'id'
    ) {
      $relationshipId =
        $attribute->nodeValue;

      break;
    }
  }

  if ($relationshipId === '') {
    throw new RuntimeException(
      "先頭ワークシートのRelationship IDを取得できません。"
    );
  }

  $relsDom =
    new DOMDocument();

  if (
    !@$relsDom->loadXML(
      $relsXml
    )
  ) {
    throw new RuntimeException(
      "XLSXのworkbook.xml.relsを解析できません。"
    );
  }

  $relsXpath =
    new DOMXPath(
      $relsDom
    );

  $relationshipNodes =
    $relsXpath->query(
      '//*[local-name()="Relationship"]'
    );

  if ($relationshipNodes) {
    foreach ($relationshipNodes as $relationship) {
      if (
        $relationship->getAttribute(
          'Id'
        ) !== $relationshipId
      ) {
        continue;
      }

      $target =
        $relationship->getAttribute(
          'Target'
        );

      if ($target === '') {
        break;
      }

      $target =
        str_replace(
          '\\',
          '/',
          $target
        );

      if (
        strpos(
          $target,
          '/'
        ) === 0
      ) {
        return
          ltrim(
            $target,
            '/'
          );
      }

      if (
        strpos(
          $target,
          'xl/'
        ) === 0
      ) {
        return
          $target;
      }

      return
        'xl/' .
        $target;
    }
  }

  throw new RuntimeException(
    "先頭ワークシートのXMLパスを取得できません。"
  );
}

/**
 * sharedStrings.xmlを解析する。
 *
 * rPhはExcelのふりがな情報なので除外する。
 *
 * @param string $xml
 * @return array
 */
function boj_operation_offer_results_parse_shared_strings_(
  $xml
) {
  $dom =
    new DOMDocument();

  if (
    !@$dom->loadXML(
      $xml
    )
  ) {
    throw new RuntimeException(
      "XLSXのsharedStrings.xmlを解析できません。"
    );
  }

  $xpath =
    new DOMXPath(
      $dom
    );

  $siNodes =
    $xpath->query(
      '//*[local-name()="si"]'
    );

  $strings =
    array();

  if (!$siNodes) {
    return
      $strings;
  }

  foreach ($siNodes as $siNode) {
    $text =
      '';

    /*
     * rPh配下のt要素はふりがななので除外する。
     */
    $tNodes =
      $xpath->query(
        './/*[local-name()="t" and ' .
        'not(ancestor::*[local-name()="rPh"])]',
        $siNode
      );

    if ($tNodes) {
      foreach ($tNodes as $tNode) {
        $text .=
          $tNode->textContent;
      }
    }

    $strings[] =
      $text;
  }

  return
    $strings;
}

/**
 * worksheet XMLを解析してセル配列を返す。
 *
 * [
 *   行番号 => [
 *     列番号 => 値
 *   ]
 * ]
 *
 * @param string $xml
 * @param array $sharedStrings
 * @return array
 */
function boj_operation_offer_results_parse_sheet_xml_(
  $xml,
  $sharedStrings
) {
  $dom =
    new DOMDocument();

  if (
    !@$dom->loadXML(
      $xml
    )
  ) {
    throw new RuntimeException(
      "XLSXのワークシートXMLを解析できません。"
    );
  }

  $xpath =
    new DOMXPath(
      $dom
    );

  $cellNodes =
    $xpath->query(
      '//*[local-name()="sheetData"]//*[local-name()="c"]'
    );

  if (!$cellNodes) {
    throw new RuntimeException(
      "XLSXのセルを取得できません。"
    );
  }

  $cells =
    array();

  foreach ($cellNodes as $cellNode) {
    $reference =
      $cellNode->getAttribute(
        'r'
      );

    if (
      !preg_match(
        '/^([A-Z]+)(\d+)$/',
        $reference,
        $matches
      )
    ) {
      continue;
    }

    $col =
      boj_operation_offer_results_column_to_number_(
        $matches[1]
      );

    $row =
      (int)$matches[2];

    $type =
      $cellNode->getAttribute(
        't'
      );

    $value =
      null;

    /*
     * inlineStr
     */
    if ($type === 'inlineStr') {
      $tNodes =
        $xpath->query(
          './/*[local-name()="t" and ' .
          'not(ancestor::*[local-name()="rPh"])]',
          $cellNode
        );

      $text =
        '';

      if ($tNodes) {
        foreach ($tNodes as $tNode) {
          $text .=
            $tNode->textContent;
        }
      }

      $value =
        $text;
    } else {
      $vNodes =
        $xpath->query(
          './*[local-name()="v"]',
          $cellNode
        );

      if (
        !$vNodes ||
        $vNodes->length === 0
      ) {
        $value =
          null;
      } else {
        $raw =
          $vNodes->item(0)->textContent;

        if ($type === 's') {
          $index =
            (int)$raw;

          if (
            !array_key_exists(
              $index,
              $sharedStrings
            )
          ) {
            throw new RuntimeException(
              "XLSXの共有文字列インデックスが不正です: " .
              $index
            );
          }

          $value =
            $sharedStrings[$index];
        } elseif ($type === 'str') {
          $value =
            $raw;
        } elseif ($type === 'b') {
          $value =
            ((string)$raw === '1');
        } else {
          if ($raw === '') {
            $value =
              '';
          } elseif (
            is_numeric(
              $raw
            )
          ) {
            $number =
              (float)$raw;

            if (
              abs(
                $number -
                round(
                  $number
                )
              ) <
              0.0000001
            ) {
              $value =
                (int)round(
                  $number
                );
            } else {
              $value =
                $number;
            }
          } else {
            $value =
              $raw;
          }
        }
      }
    }

    if (
      !isset(
        $cells[$row]
      )
    ) {
      $cells[$row] =
        array();
    }

    $cells[$row][$col] =
      $value;
  }

  ksort(
    $cells
  );

  foreach ($cells as &$rowCells) {
    ksort(
      $rowCells
    );
  }

  unset(
    $rowCells
  );

  return
    $cells;
}

/**
 * Excel列記号を列番号へ変換する。
 *
 * A → 1
 * B → 2
 * Z → 26
 * AA → 27
 *
 * @param string $letters
 * @return int
 */
function boj_operation_offer_results_column_to_number_(
  $letters
) {
  $letters =
    strtoupper(
      $letters
    );

  $number =
    0;

  $length =
    strlen(
      $letters
    );

  for (
    $i = 0;
    $i < $length;
    $i++
  ) {
    $number =
      ($number * 26) +
      (
        ord(
          $letters[$i]
        ) -
        ord('A') +
        1
      );
  }

  return
    $number;
}

/**
 * 日本語を含むか判定する。
 *
 * @param string $value
 * @return bool
 */
function boj_operation_offer_results_contains_japanese_(
  $value
) {
  return
    preg_match(
      '/[ぁ-んァ-ヶ一-龠々〆〤]/u',
      (string)$value
    ) === 1;
}

/**
 * 一般文字列を正規化する。
 *
 * @param string $value
 * @return string
 */
function boj_operation_offer_results_normalize_text_(
  $value
) {
  $value =
    str_replace(
      array(
        "\r",
        "\n",
        "\t",
        "\xC2\xA0"
      ),
      array(
        '',
        '',
        ' ',
        ' '
      ),
      (string)$value
    );

  $value =
    preg_replace(
      '/[ ]+/u',
      ' ',
      $value
    );

  return
    trim(
      (string)$value
    );
}

/**
 * 見出し判定用に空白を除去する。
 *
 * @param string $value
 * @return string
 */
function boj_operation_offer_results_normalize_label_(
  $value
) {
  $value =
    boj_operation_offer_results_normalize_text_(
      $value
    );

  $value =
    preg_replace(
      '/[\s　]+/u',
      '',
      $value
    );

  return
    (string)$value;
}