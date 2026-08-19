<?php
/**
 * 市況関連データ抽出：日銀当座預金増減要因（確報）
 *
 * market_data_extract.php から読み込まれ、
 * 日本銀行の「日銀当座預金増減要因と金融調節（確報）」XLSXを解析し、
 * 日本語項目、予想、速報、確報を抽出して
 * レポート本文を生成する。
 *
 * XLSX形式:
 *   Office Open XML Spreadsheet
 *   ZIP + XML
 *
 * 日本語行のみを対象とし、
 * 英語訳行は出力しない。
 */

// =======================================================
// 日銀当座預金増減要因（確報）
// =======================================================

/**
 * 日銀当座預金増減要因（確報）XLSXを解析する。
 *
 * タイトルには年が存在しないため、
 * 処理対象日$targetDateの年を補完に使用する。
 *
 * @param string $xlsxData XLSXバイナリ
 * @param string $targetDate yyyy-MM-dd
 * @return array
 */
function parse_boj_current_account_final_xlsx_(
  $xlsxData,
  $targetDate
) {
  if (
    !is_string($xlsxData) ||
    $xlsxData === ''
  ) {
    throw new RuntimeException(
      "日銀当座預金増減要因（確報）のXLSXデータが空です。"
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
      "日銀当座預金増減要因（確報）の処理対象日が不正です: " .
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
      "日銀当座預金増減要因（確報）の処理対象日が実在しません: " .
      $targetDate
    );
  }

  // -------------------------------------------------------
  // XLSXセル取得
  // -------------------------------------------------------

  $cells =
    boj_current_account_final_extract_xlsx_cells_(
      $xlsxData
    );

  if (
    !is_array($cells) ||
    count($cells) === 0
  ) {
    throw new RuntimeException(
      "日銀当座預金増減要因（確報）のセル情報を取得できません。"
    );
  }

  // -------------------------------------------------------
  // タイトル・更新日の取得
  // -------------------------------------------------------

  $titleRow = null;
  $titleText = '';

  foreach ($cells as $row => $columns) {
    foreach ($columns as $col => $value) {
      if (!is_string($value)) {
        continue;
      }

      $text =
        boj_current_account_final_normalize_text_(
          $value
        );

      if (
        strpos(
          $text,
          '日銀当座預金増減要因と金融調節'
        ) !== false
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
      "日銀当座預金増減要因と金融調節のタイトルを取得できません。"
    );
  }

  /*
   * 例:
   *
   * 日銀当座預金増減要因と金融調節（8月13日＜木＞分）
   */
  if (
    !preg_match(
      '/[（(]\s*(\d{1,2})月(\d{1,2})日/u',
      $titleText,
      $dateMatches
    )
  ) {
    throw new RuntimeException(
      "日銀当座預金増減要因（確報）の更新月日を取得できません: " .
      $titleText
    );
  }

  $reportMonth =
    (int)$dateMatches[1];

  $reportDay =
    (int)$dateMatches[2];

  /*
   * 原則として処理対象日と同一年。
   *
   * 1月に前年12月分を処理する場合などを考慮し、
   * 対象月より6か月を超えて先の月であれば
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
      "日銀当座預金増減要因（確報）の更新日が不正です: " .
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
  // 予想・速報・確報列の取得
  // -------------------------------------------------------

  $headerRow = null;

  $projectionCol = null;
  $provisionalCol = null;
  $finalCol = null;

  foreach ($cells as $row => $columns) {
    $foundProjection = null;
    $foundProvisional = null;
    $foundFinal = null;

    foreach ($columns as $col => $value) {
      if (!is_string($value)) {
        continue;
      }

      $label =
        boj_current_account_final_normalize_label_(
          $value
        );

      if ($label === '予想') {
        $foundProjection =
          (int)$col;
      }

      if ($label === '速報') {
        $foundProvisional =
          (int)$col;
      }

      if ($label === '確報') {
        $foundFinal =
          (int)$col;
      }
    }

    if (
      $foundProjection !== null &&
      $foundProvisional !== null &&
      $foundFinal !== null
    ) {
      $headerRow =
        (int)$row;

      $projectionCol =
        $foundProjection;

      $provisionalCol =
        $foundProvisional;

      $finalCol =
        $foundFinal;

      break;
    }
  }

  if ($headerRow === null) {
    throw new RuntimeException(
      "日銀当座預金増減要因（確報）の列見出しを取得できません。"
    );
  }

  if (
    $projectionCol >= $provisionalCol ||
    $provisionalCol >= $finalCol
  ) {
    throw new RuntimeException(
      "日銀当座預金増減要因（確報）の列順が不正です。"
    );
  }

  // -------------------------------------------------------
  // 単位確認
  // -------------------------------------------------------

  $unitConfirmed = false;

  foreach ($cells as $row => $columns) {
    if (
      $row <= $titleRow ||
      $row > $headerRow
    ) {
      continue;
    }

    foreach ($columns as $value) {
      if (!is_string($value)) {
        continue;
      }

      $text =
        boj_current_account_final_normalize_text_(
          $value
        );

      if (
        strpos(
          $text,
          '億円'
        ) !== false
      ) {
        $unitConfirmed = true;
        break 2;
      }
    }
  }

  if (!$unitConfirmed) {
    throw new RuntimeException(
      "日銀当座預金増減要因（確報）の単位「億円」を確認できません。"
    );
  }

  // -------------------------------------------------------
  // 英語Notes開始行の取得
  // -------------------------------------------------------

  $englishNotesRow = null;

  foreach ($cells as $row => $columns) {
    if ($row <= $headerRow) {
      continue;
    }

    foreach ($columns as $value) {
      if (!is_string($value)) {
        continue;
      }

      $text =
        boj_current_account_final_normalize_text_(
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

  /*
   * 英語Notesが存在しない場合は
   * 最終セル行までを対象とする。
   */
  if ($englishNotesRow === null) {
    $englishNotesRow =
      max(
        array_keys(
          $cells
        )
      ) + 1;
  }

  // -------------------------------------------------------
  // 日本語行の抽出
  // -------------------------------------------------------

  $rows =
    array();

  /*
   * 項目はB～E列に階層的に格納されている。
   *
   * 同一行のB～Eに存在する文字列を連結し、
   * 日本語を含む行だけを対象とする。
   *
   * これにより、
   *
   * B: 1.
   * C: 計数は、……
   *
   * の備考行も、
   *
   * 1. 計数は、……
   *
   * として取得できる。
   */
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

    $itemParts =
      array();

    /*
     * Excel列 B～E。
     */
    for (
      $col = 2;
      $col <= 5;
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

      if (
        !is_string($value)
      ) {
        continue;
      }

      $text =
        boj_current_account_final_normalize_text_(
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
     * 日本語を含まない行は英語訳行とみなし、
     * 出力対象外とする。
     *
     * 「1. 日本語備考」のように、
     * 数字や記号を含んでいても日本語が存在すれば対象。
     */
    if (
      !boj_current_account_final_contains_japanese_(
        $item
      )
    ) {
      continue;
    }

    $projection =
      boj_current_account_final_get_cell_value_(
        $cells,
        $row,
        $projectionCol
      );

    $provisional =
      boj_current_account_final_get_cell_value_(
        $cells,
        $row,
        $provisionalCol
      );

    $final =
      boj_current_account_final_get_cell_value_(
        $cells,
        $row,
        $finalCol
      );

    $rows[] =
      array(
        'item' =>
          $item,

        'projection' =>
          boj_current_account_final_parse_amount_(
            $projection,
            $item,
            '予想'
          ),

        'provisional' =>
          boj_current_account_final_parse_amount_(
            $provisional,
            $item,
            '速報'
          ),

        'final' =>
          boj_current_account_final_parse_amount_(
            $final,
            $item,
            '確報'
          ),
      );
  }

  if (
    count(
      $rows
    ) === 0
  ) {
    throw new RuntimeException(
      "日銀当座預金増減要因（確報）の日本語データ行を取得できません。"
    );
  }

  // -------------------------------------------------------
  // 「備考」まで取得できていることを確認
  // -------------------------------------------------------

  $remarksFound = false;

  foreach ($rows as $row) {
    if (
      boj_current_account_final_normalize_label_(
        $row['item']
      ) === '備考'
    ) {
      $remarksFound = true;
      break;
    }
  }

  if (!$remarksFound) {
    throw new RuntimeException(
      "日銀当座預金増減要因（確報）の「備考」を取得できません。"
    );
  }

  return
    array(
      'updated_at' =>
        $updatedAt,

      'rows' =>
        $rows,
    );
}

/**
 * 指定セルの値を取得する。
 *
 * 存在しない場合はnullを返す。
 *
 * @param array $cells
 * @param int $row
 * @param int $col
 * @return mixed
 */
function boj_current_account_final_get_cell_value_(
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
 * 予想・速報・確報の値を解析する。
 *
 * 空欄はnullとして保持する。
 *
 * @param mixed $value
 * @param string $item
 * @param string $fieldName
 * @return int|null
 */
function boj_current_account_final_parse_amount_(
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
    $number =
      (float)$value;
  } elseif (
    is_string($value)
  ) {
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

    /*
     * 全角符号にも念のため対応する。
     */
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
        "日銀当座預金増減要因（確報）の{$fieldName}が数値ではありません: " .
        $item .
        " / " .
        (string)$value
      );
    }

    $number =
      (float)$text;
  } else {
    throw new RuntimeException(
      "日銀当座預金増減要因（確報）の{$fieldName}の型が不正です: " .
      $item
    );
  }

  /*
   * 出力仕様は整数。
   * XLSX上で小数が出現した場合は、
   * フォーマット変更とみなして異常終了する。
   */
  $rounded =
    round(
      $number
    );

  if (
    abs(
      $number - $rounded
    ) >
    0.0000001
  ) {
    throw new RuntimeException(
      "日銀当座預金増減要因（確報）の{$fieldName}が整数ではありません: " .
      $item .
      " / " .
      (string)$number
    );
  }

  return
    (int)$rounded;
}

/**
 * 数値を表示用へ整形する。
 *
 * 正数:
 *   +100
 *
 * 負数:
 *   -3,400
 *
 * 0:
 *   0
 *
 * 空欄:
 *   ー
 *
 * @param int|null $value
 * @return string
 */
function format_boj_current_account_final_amount_(
  $value
) {
  if ($value === null) {
    return
      'ー';
  }

  $value =
    (int)$value;

  if ($value > 0) {
    return
      '+' .
      number_format(
        $value
      );
  }

  if ($value < 0) {
    return
      '-' .
      number_format(
        abs($value)
      );
  }

  return
    '0';
}

/**
 * 項目を全角40文字分の表示幅とし、
 * 右側を半角スペースで埋める。
 *
 * 全角1文字=2、
 * 半角1文字=1として表示幅80とする。
 *
 * 表示幅80以上の場合は、
 * 文字列を切り捨てずそのまま出力する。
 *
 * @param string $value
 * @return string
 */
function pad_boj_current_account_final_item_(
  $value
) {
  if (
    !function_exists(
      'mb_strwidth'
    )
  ) {
    throw new RuntimeException(
      "mb_strwidth()が使用できません: 項目"
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
 * 予想・速報・確報を12文字幅で右寄せする。
 *
 * 「+999,999,999」「-999,999,999」を
 * 収容できる表示幅とする。
 *
 * @param string $value
 * @param string $fieldName
 * @return string
 */
function pad_boj_current_account_final_amount_(
  $value,
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

  $targetWidth =
    12;

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
 * レポート本文を生成する。
 *
 * @param array $parsed
 * @return string
 */
function build_boj_current_account_final_message_(
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
      "日銀当座預金増減要因（確報）の更新日時が不正です。"
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
      "日銀当座預金増減要因（確報）の抽出結果がありません。"
    );
  }

  $lines =
    array();

  // -------------------------------------------------------
  // 見出し
  // -------------------------------------------------------

  $lines[] =
    "■日銀当座預金増減要因と金融調節（確報）";

  $lines[] =
    "更新日時: " .
    $parsed['updated_at'];

  $lines[] = '';

  // -------------------------------------------------------
  // 列見出し
  // -------------------------------------------------------

  $lines[] =
    "項目\t" .
    "予想\t" .
    "速報\t" .
    "確報";

  // -------------------------------------------------------
  // データ行
  // -------------------------------------------------------

  $inRemarks = false;

  foreach ($parsed['rows'] as $row) {
    if (
      !isset(
        $row['item']
      ) ||
      !is_string(
        $row['item']
      ) ||
      $row['item'] === ''
    ) {
      throw new RuntimeException(
        "日銀当座預金増減要因（確報）の項目が不正です。"
      );
    }

    $projection =
      format_boj_current_account_final_amount_(
        $row['projection']
      );

    $provisional =
      format_boj_current_account_final_amount_(
        $row['provisional']
      );

    $final =
      format_boj_current_account_final_amount_(
        $row['final']
      );

    $item =
      pad_boj_current_account_final_item_(
        $row['item']
      );

    /*
     * 「備考」以降は説明文のため、
     * 予想・速報・確報を出力しない。
     */
    if ($inRemarks) {
      $lines[] =
        rtrim(
          $item
        );

      continue;
    }

    /*
     * 「備考」行から備考セクション開始。
     *
     * 「備考」自身についても、
     * 予想・速報・確報は出力しない。
     */
    if (
      boj_current_account_final_normalize_label_(
        $row['item']
      ) === '備考'
    ) {
      $inRemarks = true;

      $lines[] =
        rtrim(
          $item
        );

      continue;
    }

    $lines[] =
      $item .
      "\t" .
      pad_boj_current_account_final_amount_(
        $projection,
        '予想'
      ) .
      "\t" .
      pad_boj_current_account_final_amount_(
        $provisional,
        '速報'
      ) .
      "\t" .
      pad_boj_current_account_final_amount_(
        $final,
        '確報'
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
function boj_current_account_final_extract_xlsx_cells_(
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

  /*
   * XLSXはZIPファイルのため、
   * 一時ファイルへ保存してZipArchiveで開く。
   */
  $tmpPath =
    tempnam(
      sys_get_temp_dir(),
      'boj_xlsx_'
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
        "日銀当座預金増減要因（確報）のXLSXをZIPとして開けません: " .
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
          boj_current_account_final_parse_shared_strings_(
            $sharedStringsXml
          );
      }

      // ---------------------------------------------------
      // 先頭ワークシート
      // ---------------------------------------------------

      $sheetPath =
        boj_current_account_final_get_first_sheet_path_(
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
        boj_current_account_final_parse_sheet_xml_(
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
function boj_current_account_final_get_first_sheet_path_(
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
    /*
     * 標準的なXLSX構造の場合のフォールバック。
     */
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

  $relationshipId = '';

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
        $target =
          ltrim(
            $target,
            '/'
          );

        return
          $target;
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
 * Rich Textの場合は、
 * si配下のすべてのt要素を連結する。
 *
 * @param string $xml
 * @return array
 */
function boj_current_account_final_parse_shared_strings_(
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
     * rPh要素はExcelのふりがな（phonetic text）なので、
     * 本文には含めない。
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
 * 戻り値:
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
function boj_current_account_final_parse_sheet_xml_(
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

  if (
    !$cellNodes
  ) {
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
      boj_current_account_final_column_to_number_(
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
      /*
       * rPh要素はExcelのふりがな（phonetic text）なので、
       * 本文には含めない。
       */
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
        /*
         * セル自体は存在するが値なし。
         */
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
        } elseif (
          $type === 'str'
        ) {
          $value =
            $raw;
        } elseif (
          $type === 'b'
        ) {
          $value =
            ((string)$raw === '1');
        } else {
          if (
            $raw === ''
          ) {
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
                round($number)
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

  foreach ($cells as &$columns) {
    ksort(
      $columns
    );
  }

  unset(
    $columns
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
function boj_current_account_final_column_to_number_(
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
 * 日本語文字を含むか判定する。
 *
 * @param string $value
 * @return bool
 */
function boj_current_account_final_contains_japanese_(
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
function boj_current_account_final_normalize_text_(
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
 * 見出し判定用の文字列正規化。
 *
 * 予　想 → 予想
 * 速　報 → 速報
 * 確　報 → 確報
 * （参　考） → （参考）
 *
 * @param string $value
 * @return string
 */
function boj_current_account_final_normalize_label_(
  $value
) {
  $value =
    boj_current_account_final_normalize_text_(
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