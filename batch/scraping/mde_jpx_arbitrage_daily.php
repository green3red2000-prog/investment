<?php
/**
 * 市況関連データ抽出：JPX裁定取引の状況（日別）
 *
 * market_data_extract.php から読み込まれ、
 * JPXの「裁定取引の状況（日別）」XLSを解析し、
 * 裁定取引に係る現物ポジションを抽出して
 * メッセージを生成する。
 *
 * XLS形式:
 *   Microsoft Excel 97-2003 Workbook
 *   OLE Compound Document / BIFF8
 *
 * 本ファイルでは外部Excelライブラリを使用せず、
 * 必要なOLE Compound DocumentおよびBIFF8レコードを
 * 読み取る。
 */

// =======================================================
// JPX裁定取引の状況（日別）
// =======================================================

/**
 * JPX裁定取引の状況（日別）XLSから、
 * 裁定取引に係る現物ポジションを取得する。
 *
 * @param string $xlsData XLSバイナリ
 * @return array
 */
function parse_jpx_arbitrage_daily_xls_($xlsData) {
  if (
    !is_string($xlsData) ||
    $xlsData === ''
  ) {
    throw new RuntimeException(
      "JPX裁定取引の状況（日別）のXLSデータが空です。"
    );
  }

  /*
   * BIFF8 Workbookストリームを取得する。
   */
  $workbook =
    jpx_arbitrage_extract_workbook_stream_(
      $xlsData
    );

  /*
   * Workbookストリームから
   * 共有文字列とワークシートセルを取得する。
   */
  $book =
    jpx_arbitrage_parse_biff8_workbook_(
      $workbook
    );

  if (
    !isset($book['cells']) ||
    !is_array($book['cells']) ||
    count($book['cells']) === 0
  ) {
    throw new RuntimeException(
      "JPX裁定取引の状況（日別）のセル情報を取得できません。"
    );
  }

  $cells =
    $book['cells'];

  // -------------------------------------------------------
  // 公表日の取得
  // -------------------------------------------------------

  /*
   * XLS内には以下のような公表日が存在する。
   *
   *   2026年8月13日
   *
   * 「8月10日現在」の年を決定するために使用する。
   */
  $publicationDate = '';

  foreach ($cells as $cell) {
    if (
      !isset($cell['value']) ||
      !is_string($cell['value'])
    ) {
      continue;
    }

    $text =
      trim(
        $cell['value']
      );

    if (
      preg_match(
        '/^(\d{4})年(\d{1,2})月(\d{1,2})日$/u',
        $text
      )
    ) {
      $publicationDate =
        $text;
      break;
    }
  }

  if ($publicationDate === '') {
    throw new RuntimeException(
      "JPX裁定取引の状況（日別）の公表日を取得できません。"
    );
  }

  if (
    !preg_match(
      '/^(\d{4})年(\d{1,2})月(\d{1,2})日$/u',
      $publicationDate,
      $publicationMatches
    )
  ) {
    throw new RuntimeException(
      "JPX裁定取引の状況（日別）の公表日形式が不正です: " .
      $publicationDate
    );
  }

  $publicationYear =
    (int)$publicationMatches[1];

  $publicationMonth =
    (int)$publicationMatches[2];

  // -------------------------------------------------------
  // 「2. 裁定取引に係る現物ポジション」の行取得
  // -------------------------------------------------------

  $positionTitleRow = null;
  $positionTitle = '';

  foreach ($cells as $cell) {
    if (
      !isset(
        $cell['row'],
        $cell['value']
      ) ||
      !is_string($cell['value'])
    ) {
      continue;
    }

    $text =
      jpx_arbitrage_normalize_cell_text_(
        $cell['value']
      );

    if (
      strpos(
        $text,
        '裁定取引に係る現物ポジション'
      ) !== false &&
      strpos(
        $text,
        '現在'
      ) !== false
    ) {
      $positionTitleRow =
        (int)$cell['row'];

      $positionTitle =
        $text;

      break;
    }
  }

  if ($positionTitleRow === null) {
    throw new RuntimeException(
      "「裁定取引に係る現物ポジション」の見出しを取得できません。"
    );
  }

  /*
   * 例:
   *
   * ２．裁定取引に係る現物ポジション（8月10日現在）
   */
  if (
    !preg_match(
      '/[（(]\s*(\d{1,2})月(\d{1,2})日現在\s*[）)]/u',
      $positionTitle,
      $positionDateMatches
    )
  ) {
    throw new RuntimeException(
      "裁定取引に係る現物ポジションの基準日を取得できません: " .
      $positionTitle
    );
  }

  $positionMonth =
    (int)$positionDateMatches[1];

  $positionDay =
    (int)$positionDateMatches[2];

  /*
   * 通常は公表日と基準日は同一年。
   *
   * 年初に前年12月分が公表された場合にも
   * 対応できるよう、公表月と基準月が大きく逆転している場合は
   * 前年と判定する。
   */
  $positionYear =
    $publicationYear;

  if (
    $positionMonth >
    $publicationMonth + 6
  ) {
    $positionYear--;
  }

  if (
    !checkdate(
      $positionMonth,
      $positionDay,
      $positionYear
    )
  ) {
    throw new RuntimeException(
      "裁定取引に係る現物ポジションの基準日が不正です: " .
      $positionTitle
    );
  }

  $updatedAt =
    sprintf(
      '%04d-%02d-%02d',
      $positionYear,
      $positionMonth,
      $positionDay
    );

  // -------------------------------------------------------
  // 単位確認
  // -------------------------------------------------------

  $unitConfirmed = false;

  foreach ($cells as $cell) {
    if (
      !isset(
        $cell['row'],
        $cell['value']
      ) ||
      (int)$cell['row'] !== $positionTitleRow ||
      !is_string($cell['value'])
    ) {
      continue;
    }

    $text =
      jpx_arbitrage_normalize_cell_text_(
        $cell['value']
      );

    if (
      strpos(
        $text,
        '千株'
      ) !== false
    ) {
      $unitConfirmed = true;
      break;
    }
  }

  if (!$unitConfirmed) {
    throw new RuntimeException(
      "裁定取引に係る現物ポジションの単位「千株」を確認できません。"
    );
  }

  // -------------------------------------------------------
  // 売り・買いポジション見出し列の取得
  // -------------------------------------------------------

  /*
   * 見出しは基準日行の次の行に存在する。
   *
   *   売りポジション（株数）
   *   買いポジション（株数）
   */
  $positionHeaderRow =
    $positionTitleRow + 1;

  $sellStartCol = null;
  $buyStartCol = null;

  foreach ($cells as $cell) {
    if (
      !isset(
        $cell['row'],
        $cell['col'],
        $cell['value']
      ) ||
      (int)$cell['row'] !== $positionHeaderRow ||
      !is_string($cell['value'])
    ) {
      continue;
    }

    $text =
      jpx_arbitrage_normalize_cell_text_(
        $cell['value']
      );

    if (
      strpos(
        $text,
        '売りポジション'
      ) !== false
    ) {
      $sellStartCol =
        (int)$cell['col'];
    }

    if (
      strpos(
        $text,
        '買いポジション'
      ) !== false
    ) {
      $buyStartCol =
        (int)$cell['col'];
    }
  }

  if ($sellStartCol === null) {
    throw new RuntimeException(
      "売りポジションの見出しを取得できません。"
    );
  }

  if ($buyStartCol === null) {
    throw new RuntimeException(
      "買いポジションの見出しを取得できません。"
    );
  }

  if ($sellStartCol >= $buyStartCol) {
    throw new RuntimeException(
      "売りポジションと買いポジションの列位置が不正です。"
    );
  }

  // -------------------------------------------------------
  // 「合計」列の取得
  // -------------------------------------------------------

  /*
   * 見出しの次の行:
   *
   * 売り:
   *   当限 / 翌限以降 / 合計
   *
   * 買い:
   *   当限 / 翌限以降 / 合計
   */
  $subHeaderRow =
    $positionHeaderRow + 1;

  $sellTotalCol = null;
  $buyTotalCol = null;

  foreach ($cells as $cell) {
    if (
      !isset(
        $cell['row'],
        $cell['col'],
        $cell['value']
      ) ||
      (int)$cell['row'] !== $subHeaderRow ||
      !is_string($cell['value'])
    ) {
      continue;
    }

    $text =
      jpx_arbitrage_normalize_cell_text_(
        $cell['value']
      );

    if ($text !== '合計') {
      continue;
    }

    $col =
      (int)$cell['col'];

    if (
      $col >= $sellStartCol &&
      $col < $buyStartCol
    ) {
      $sellTotalCol =
        $col;
    } elseif (
      $col >= $buyStartCol
    ) {
      $buyTotalCol =
        $col;
    }
  }

  if ($sellTotalCol === null) {
    throw new RuntimeException(
      "売りポジションの合計列を取得できません。"
    );
  }

  if ($buyTotalCol === null) {
    throw new RuntimeException(
      "買いポジションの合計列を取得できません。"
    );
  }

  // -------------------------------------------------------
  // 株数・前日比行の取得
  // -------------------------------------------------------

  $stockRow =
    $subHeaderRow + 1;

  $previousRow =
    $subHeaderRow + 2;

  /*
   * 株数行であることを確認する。
   */
  $stockRowConfirmed = false;

  foreach ($cells as $cell) {
    if (
      !isset(
        $cell['row'],
        $cell['value']
      ) ||
      (int)$cell['row'] !== $stockRow ||
      !is_string($cell['value'])
    ) {
      continue;
    }

    $text =
      jpx_arbitrage_normalize_cell_text_(
        $cell['value']
      );

    $text =
      preg_replace(
        '/\s+/u',
        '',
        $text
      );

    if ($text === '株数') {
      $stockRowConfirmed = true;
      break;
    }
  }

  if (!$stockRowConfirmed) {
    throw new RuntimeException(
      "裁定取引に係る現物ポジションの株数行を確認できません。"
    );
  }

  /*
   * 前日比行であることを確認する。
   */
  $previousRowConfirmed = false;

  foreach ($cells as $cell) {
    if (
      !isset(
        $cell['row'],
        $cell['value']
      ) ||
      (int)$cell['row'] !== $previousRow ||
      !is_string($cell['value'])
    ) {
      continue;
    }

    $text =
      jpx_arbitrage_normalize_cell_text_(
        $cell['value']
      );

    if ($text === '前日比') {
      $previousRowConfirmed = true;
      break;
    }
  }

  if (!$previousRowConfirmed) {
    throw new RuntimeException(
      "裁定取引に係る現物ポジションの前日比行を確認できません。"
    );
  }

  // -------------------------------------------------------
  // 数値取得
  // -------------------------------------------------------

  $sellPositionThousand =
    jpx_arbitrage_get_numeric_cell_(
      $cells,
      $stockRow,
      $sellTotalCol,
      '売りポジション'
    );

  $sellPreviousThousand =
    jpx_arbitrage_get_numeric_cell_(
      $cells,
      $previousRow,
      $sellTotalCol,
      '売りポジション前日比'
    );

  $buyPositionThousand =
    jpx_arbitrage_get_numeric_cell_(
      $cells,
      $stockRow,
      $buyTotalCol,
      '買いポジション'
    );

  $buyPreviousThousand =
    jpx_arbitrage_get_numeric_cell_(
      $cells,
      $previousRow,
      $buyTotalCol,
      '買いポジション前日比'
    );

  // -------------------------------------------------------
  // 千株 → 万株
  // -------------------------------------------------------

  /*
   * 千株単位の値を10で割り、
   * 小数部分は0方向へ切り捨てる。
   *
   * 例:
   *   37,888千株
   *     → 3,788万株
   *
   *   -1,292千株
   *     → -129万株
   */
  $sellPositionMan =
    jpx_arbitrage_thousand_to_man_(
      $sellPositionThousand
    );

  $sellPreviousMan =
    jpx_arbitrage_thousand_to_man_(
      $sellPreviousThousand
    );

  $buyPositionMan =
    jpx_arbitrage_thousand_to_man_(
      $buyPositionThousand
    );

  $buyPreviousMan =
    jpx_arbitrage_thousand_to_man_(
      $buyPreviousThousand
    );

  return array(
    'updated_at' =>
      $updatedAt,

    'sell_position' =>
      $sellPositionMan,

    'sell_previous' =>
      $sellPreviousMan,

    'buy_position' =>
      $buyPositionMan,

    'buy_previous' =>
      $buyPreviousMan,
  );
}

/**
 * 指定セルの数値を取得する。
 *
 * @param array $cells
 * @param int $row
 * @param int $col
 * @param string $fieldName
 * @return float
 */
function jpx_arbitrage_get_numeric_cell_(
  $cells,
  $row,
  $col,
  $fieldName
) {
  foreach ($cells as $cell) {
    if (
      !isset(
        $cell['row'],
        $cell['col'],
        $cell['value']
      )
    ) {
      continue;
    }

    if (
      (int)$cell['row'] !== (int)$row ||
      (int)$cell['col'] !== (int)$col
    ) {
      continue;
    }

    $value =
      $cell['value'];

    if (
      !is_int($value) &&
      !is_float($value)
    ) {
      if (
        !is_string($value) ||
        !is_numeric(
          str_replace(
            ',',
            '',
            trim($value)
          )
        )
      ) {
        throw new RuntimeException(
          "{$fieldName}が数値ではありません: " .
          (string)$value
        );
      }

      $value =
        (float)str_replace(
          ',',
          '',
          trim($value)
        );
    }

    return
      (float)$value;
  }

  throw new RuntimeException(
    "{$fieldName}のセルを取得できません: " .
    "row={$row} col={$col}"
  );
}

/**
 * 千株単位を万株単位へ変換する。
 *
 * 小数部分は0方向へ切り捨てる。
 *
 * @param float $value
 * @return int
 */
function jpx_arbitrage_thousand_to_man_(
  $value
) {
  return
    (int)(
      ((float)$value) / 10
    );
}

/**
 * 万株単位の値を表示用に整形する。
 *
 * 10,000万株以上は億表記にする。
 *
 * 例:
 *   3788
 *     → 3,788万株
 *
 *   45245
 *     → 4億5245万株
 *
 *   -129
 *     → -129万株
 *
 * @param int $value
 * @return string
 */
function format_jpx_arbitrage_stock_count_(
  $value,
  $withSign = false
) {
  $value =
    (int)$value;

  $negative =
    $value < 0;

  $absolute =
    abs($value);

  if ($absolute >= 10000) {
    $oku =
      intdiv(
        $absolute,
        10000
      );

    $man =
      $absolute % 10000;

    if ($man === 0) {
      $formatted =
        number_format(
          $oku
        ) .
        '億株';
    } else {
      $formatted =
        number_format(
          $oku
        ) .
        '億' .
        sprintf(
          '%d',
          $man
        ) .
        '万株';
    }
  } else {
    $formatted =
      number_format(
        $absolute
      ) .
      '万株';
  }

  if ($withSign) {
    if ($value > 0) {
      $formatted =
        '+' .
        $formatted;
    } elseif ($value < 0) {
      $formatted =
        '-' .
        $formatted;
    }
  } elseif ($negative) {
    $formatted =
      '-' .
      $formatted;
  }

  return
    $formatted;
}

/**
 * 全角文字を考慮して左側へ半角スペースを追加し、
 * 指定表示幅で右寄せする。
 *
 * 指定幅を超えている場合は切り捨てない。
 *
 * @param string $value
 * @param int $width
 * @param string $fieldName
 * @return string
 */
function pad_jpx_arbitrage_left_(
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

  if ($currentWidth >= $width) {
    return
      $value;
  }

  return
    str_repeat(
      ' ',
      $width - $currentWidth
    ) .
    $value;
}

/**
 * JPX裁定取引の状況（日別）の抽出結果から
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_jpx_arbitrage_daily_message_(
  $parsed
) {
  $requiredKeys = array(
    'updated_at',
    'sell_position',
    'sell_previous',
    'buy_position',
    'buy_previous',
  );

  foreach ($requiredKeys as $key) {
    if (
      !array_key_exists(
        $key,
        $parsed
      )
    ) {
      throw new RuntimeException(
        "JPX裁定取引の状況（日別）の抽出結果が不足しています: " .
        $key
      );
    }
  }

  if (
    !is_string(
      $parsed['updated_at']
    ) ||
    !preg_match(
      '/^\d{4}-\d{2}-\d{2}$/',
      $parsed['updated_at']
    )
  ) {
    throw new RuntimeException(
      "JPX裁定取引の状況（日別）の更新日時が不正です。"
    );
  }

  $lines =
    array();

  // -------------------------------------------------------
  // 見出し
  // -------------------------------------------------------

  $lines[] =
    "■JPX裁定取引の状況（日別）：" .
    "裁定取引に係る現物ポジション";

  $lines[] =
    "更新日時: " .
    $parsed['updated_at'];

  $lines[] = '';

  // -------------------------------------------------------
  // 列見出し
  // -------------------------------------------------------

  $lines[] =
    "売りポジション\t" .
    "前日比\t" .
    "買いポジション\t" .
    "前日比";

  // -------------------------------------------------------
  // 値
  // -------------------------------------------------------

  $sellPosition =
    format_jpx_arbitrage_stock_count_(
      $parsed['sell_position']
    );

  $sellPrevious =
    format_jpx_arbitrage_stock_count_(
      $parsed['sell_previous'],
      true
    );

  $buyPosition =
    format_jpx_arbitrage_stock_count_(
      $parsed['buy_position']
    );

  $buyPrevious =
    format_jpx_arbitrage_stock_count_(
      $parsed['buy_previous'],
      true
    );
  
  /*
   * 「999,999,999万株」相当の表示幅を基準として
   * 各数値列を右寄せする。
   *
   * 符号や億表記が付いても切り捨てない。
   */
  $displayWidth =
    16;

  $lines[] =
    pad_jpx_arbitrage_left_(
      $sellPosition,
      $displayWidth,
      '売りポジション'
    ) .
    "\t" .
    pad_jpx_arbitrage_left_(
      $sellPrevious,
      $displayWidth,
      '売りポジション前日比'
    ) .
    "\t" .
    pad_jpx_arbitrage_left_(
      $buyPosition,
      $displayWidth,
      '買いポジション'
    ) .
    "\t" .
    pad_jpx_arbitrage_left_(
      $buyPrevious,
      $displayWidth,
      '買いポジション前日比'
    );

  return
    implode(
      "\n",
      $lines
    );
}

// =======================================================
// XLS / OLE Compound Document
// =======================================================

/**
 * XLSからBIFF8 Workbookストリームを取り出す。
 *
 * @param string $xlsData
 * @return string
 */
function jpx_arbitrage_extract_workbook_stream_(
  $xlsData
) {
  /*
   * OLE Compound Document signature.
   */
  $signature =
    "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

  if (
    strlen($xlsData) < 512 ||
    substr(
      $xlsData,
      0,
      8
    ) !== $signature
  ) {
    throw new RuntimeException(
      "JPX裁定取引の状況（日別）のファイルがOLE形式ではありません。"
    );
  }

  $sectorShift =
    jpx_arbitrage_u16le_(
      $xlsData,
      30
    );

  $miniSectorShift =
    jpx_arbitrage_u16le_(
      $xlsData,
      32
    );

  $sectorSize =
    1 << $sectorShift;

  $miniSectorSize =
    1 << $miniSectorShift;

  if (
    $sectorSize !== 512 &&
    $sectorSize !== 4096
  ) {
    throw new RuntimeException(
      "未対応のOLEセクタサイズです: " .
      $sectorSize
    );
  }

  $fatSectorCount =
    jpx_arbitrage_u32le_(
      $xlsData,
      44
    );

  $firstDirectorySector =
    jpx_arbitrage_u32le_(
      $xlsData,
      48
    );

  $miniStreamCutoff =
    jpx_arbitrage_u32le_(
      $xlsData,
      56
    );

  $firstMiniFatSector =
    jpx_arbitrage_u32le_(
      $xlsData,
      60
    );

  $miniFatSectorCount =
    jpx_arbitrage_u32le_(
      $xlsData,
      64
    );

  $firstDifatSector =
    jpx_arbitrage_u32le_(
      $xlsData,
      68
    );

  $difatSectorCount =
    jpx_arbitrage_u32le_(
      $xlsData,
      72
    );

  $freeSector =
    0xFFFFFFFF;

  $endOfChain =
    0xFFFFFFFE;

  // -------------------------------------------------------
  // DIFAT
  // -------------------------------------------------------

  $fatSectorIds =
    array();

  for ($i = 0; $i < 109; $i++) {
    $sectorId =
      jpx_arbitrage_u32le_(
        $xlsData,
        76 + ($i * 4)
      );

    if (
      $sectorId !== $freeSector &&
      $sectorId !== $endOfChain
    ) {
      $fatSectorIds[] =
        $sectorId;
    }
  }

  /*
   * 109を超えるFATセクタが存在する場合の
   * DIFATチェーンにも対応する。
   */
  $currentDifatSector =
    $firstDifatSector;

  for (
    $difatIndex = 0;
    $difatIndex < $difatSectorCount;
    $difatIndex++
  ) {
    if (
      $currentDifatSector === $endOfChain ||
      $currentDifatSector === $freeSector
    ) {
      break;
    }

    $difatData =
      jpx_arbitrage_read_ole_sector_(
        $xlsData,
        $currentDifatSector,
        $sectorSize
      );

    $entriesPerDifat =
      (int)(
        $sectorSize / 4
      ) - 1;

    for (
      $i = 0;
      $i < $entriesPerDifat;
      $i++
    ) {
      $sectorId =
        jpx_arbitrage_u32le_(
          $difatData,
          $i * 4
        );

      if (
        $sectorId !== $freeSector &&
        $sectorId !== $endOfChain
      ) {
        $fatSectorIds[] =
          $sectorId;
      }
    }

    $currentDifatSector =
      jpx_arbitrage_u32le_(
        $difatData,
        $sectorSize - 4
      );
  }

  if (
    count($fatSectorIds) <
    $fatSectorCount
  ) {
    throw new RuntimeException(
      "OLE FATセクタ数が不足しています: " .
      "expected={$fatSectorCount} " .
      "actual=" .
      count($fatSectorIds)
    );
  }

  // -------------------------------------------------------
  // FAT
  // -------------------------------------------------------

  $fat =
    array();

  foreach ($fatSectorIds as $fatSectorId) {
    $fatData =
      jpx_arbitrage_read_ole_sector_(
        $xlsData,
        $fatSectorId,
        $sectorSize
      );

    $count =
      (int)(
        $sectorSize / 4
      );

    for ($i = 0; $i < $count; $i++) {
      $fat[] =
        jpx_arbitrage_u32le_(
          $fatData,
          $i * 4
        );
    }
  }

  // -------------------------------------------------------
  // Directory stream
  // -------------------------------------------------------

  $directoryStream =
    jpx_arbitrage_read_ole_chain_(
      $xlsData,
      $firstDirectorySector,
      $fat,
      $sectorSize
    );

  $directoryEntries =
    jpx_arbitrage_parse_ole_directory_(
      $directoryStream
    );

  $workbookEntry = null;
  $rootEntry = null;

  foreach ($directoryEntries as $entry) {
    if (
      $entry['name'] === 'Workbook' ||
      $entry['name'] === 'Book'
    ) {
      $workbookEntry =
        $entry;
    }

    if (
      $entry['type'] === 5 &&
      $entry['name'] === 'Root Entry'
    ) {
      $rootEntry =
        $entry;
    }
  }

  if ($workbookEntry === null) {
    throw new RuntimeException(
      "XLSのWorkbookストリームが見つかりません。"
    );
  }

  $workbookSize =
    (int)$workbookEntry['size'];

  /*
   * 通常のWorkbookストリーム。
   */
  if (
    $workbookSize >=
    $miniStreamCutoff
  ) {
    $stream =
      jpx_arbitrage_read_ole_chain_(
        $xlsData,
        $workbookEntry['start_sector'],
        $fat,
        $sectorSize
      );

    return
      substr(
        $stream,
        0,
        $workbookSize
      );
  }

  // -------------------------------------------------------
  // Mini Stream
  // -------------------------------------------------------

  if ($rootEntry === null) {
    throw new RuntimeException(
      "XLSのRoot Entryが見つかりません。"
    );
  }

  if ($miniFatSectorCount <= 0) {
    throw new RuntimeException(
      "XLS WorkbookストリームがMini StreamですがMiniFATがありません。"
    );
  }

  $miniFatStream =
    jpx_arbitrage_read_ole_chain_(
      $xlsData,
      $firstMiniFatSector,
      $fat,
      $sectorSize
    );

  $miniFat =
    array();

  for (
    $offset = 0;
    $offset + 4 <=
      strlen($miniFatStream);
    $offset += 4
  ) {
    $miniFat[] =
      jpx_arbitrage_u32le_(
        $miniFatStream,
        $offset
      );
  }

  $rootMiniStream =
    jpx_arbitrage_read_ole_chain_(
      $xlsData,
      $rootEntry['start_sector'],
      $fat,
      $sectorSize
    );

  $miniStream =
    jpx_arbitrage_read_mini_chain_(
      $rootMiniStream,
      $workbookEntry['start_sector'],
      $miniFat,
      $miniSectorSize
    );

  return
    substr(
      $miniStream,
      0,
      $workbookSize
    );
}

/**
 * BIFF8 Workbookストリームを解析する。
 *
 * @param string $workbook
 * @return array
 */
function jpx_arbitrage_parse_biff8_workbook_(
  $workbook
) {
  $sst =
    array();

  $sheetOffsets =
    array();

  $offset =
    0;

  $length =
    strlen($workbook);

  while (
    $offset + 4 <= $length
  ) {
    $recordId =
      jpx_arbitrage_u16le_(
        $workbook,
        $offset
      );

    $recordLength =
      jpx_arbitrage_u16le_(
        $workbook,
        $offset + 2
      );

    $payloadOffset =
      $offset + 4;

    if (
      $payloadOffset +
      $recordLength >
      $length
    ) {
      throw new RuntimeException(
        "BIFF8レコード長が不正です。"
      );
    }

    $payload =
      substr(
        $workbook,
        $payloadOffset,
        $recordLength
      );

    /*
     * BOUNDSHEET
     */
    if ($recordId === 0x0085) {
      if ($recordLength >= 8) {
        $sheetOffsets[] =
          jpx_arbitrage_u32le_(
            $payload,
            0
          );
      }
    }

    /*
     * SST
     */
    if ($recordId === 0x00FC) {
      $sst =
        jpx_arbitrage_parse_sst_(
          $payload
        );
    }

    $offset =
      $payloadOffset +
      $recordLength;
  }

  if (count($sheetOffsets) === 0) {
    throw new RuntimeException(
      "XLSのワークシートを取得できません。"
    );
  }

  /*
   * 今回のJPXファイルは1シート構成。
   * 最初のワークシートを対象とする。
   */
  $cells =
    jpx_arbitrage_parse_sheet_cells_(
      $workbook,
      $sheetOffsets[0],
      $sst
    );

  return array(
    'cells' =>
      $cells,
  );
}

/**
 * BIFF8 SSTを解析する。
 *
 * 今回のJPX XLSではSSTが単一レコード内に収まる。
 *
 * @param string $payload
 * @return array
 */
function jpx_arbitrage_parse_sst_(
  $payload
) {
  if (
    strlen($payload) < 8
  ) {
    throw new RuntimeException(
      "BIFF8 SSTレコードが不正です。"
    );
  }

  $uniqueCount =
    jpx_arbitrage_u32le_(
      $payload,
      4
    );

  $offset =
    8;

  $strings =
    array();

  for (
    $index = 0;
    $index < $uniqueCount;
    $index++
  ) {
    if (
      $offset + 3 >
      strlen($payload)
    ) {
      throw new RuntimeException(
        "BIFF8 SST文字列が途中で終了しました。"
      );
    }

    $charCount =
      jpx_arbitrage_u16le_(
        $payload,
        $offset
      );

    $offset += 2;

    $flags =
      ord(
        $payload[$offset]
      );

    $offset++;

    $is16Bit =
      ($flags & 0x01) !== 0;

    $hasExt =
      ($flags & 0x04) !== 0;

    $hasRich =
      ($flags & 0x08) !== 0;

    $richCount =
      0;

    $extLength =
      0;

    if ($hasRich) {
      $richCount =
        jpx_arbitrage_u16le_(
          $payload,
          $offset
        );

      $offset += 2;
    }

    if ($hasExt) {
      $extLength =
        jpx_arbitrage_u32le_(
          $payload,
          $offset
        );

      $offset += 4;
    }

    $byteLength =
      $charCount *
      ($is16Bit ? 2 : 1);

    if (
      $offset +
      $byteLength >
      strlen($payload)
    ) {
      throw new RuntimeException(
        "BIFF8 SST文字列がレコード境界を超えています。" .
        " CONTINUEレコード形式には対応できません。"
      );
    }

    $raw =
      substr(
        $payload,
        $offset,
        $byteLength
      );

    $offset +=
      $byteLength;

    if ($is16Bit) {
      $text =
        jpx_arbitrage_utf16le_to_utf8_(
          $raw
        );
    } else {
      /*
       * BIFF8のcompressed Unicodeは
       * 1文字1byteのUnicode下位8bit。
       */
      $text =
        jpx_arbitrage_latin1_to_utf8_(
          $raw
        );
    }

    $offset +=
      ($richCount * 4);

    $offset +=
      $extLength;

    $strings[] =
      $text;
  }

  return
    $strings;
}

/**
 * ワークシート内のセルを解析する。
 *
 * @param string $workbook
 * @param int $sheetOffset
 * @param array $sst
 * @return array
 */
function jpx_arbitrage_parse_sheet_cells_(
  $workbook,
  $sheetOffset,
  $sst
) {
  $cells =
    array();

  $offset =
    (int)$sheetOffset;

  $length =
    strlen($workbook);

  while (
    $offset + 4 <=
    $length
  ) {
    $recordId =
      jpx_arbitrage_u16le_(
        $workbook,
        $offset
      );

    $recordLength =
      jpx_arbitrage_u16le_(
        $workbook,
        $offset + 2
      );

    $payloadOffset =
      $offset + 4;

    if (
      $payloadOffset +
      $recordLength >
      $length
    ) {
      break;
    }

    $payload =
      substr(
        $workbook,
        $payloadOffset,
        $recordLength
      );

    /*
     * EOF
     */
    if ($recordId === 0x000A) {
      break;
    }

    /*
     * LABELSST
     */
    if (
      $recordId === 0x00FD &&
      $recordLength >= 10
    ) {
      $row =
        jpx_arbitrage_u16le_(
          $payload,
          0
        );

      $col =
        jpx_arbitrage_u16le_(
          $payload,
          2
        );

      $sstIndex =
        jpx_arbitrage_u32le_(
          $payload,
          6
        );

      if (
        !array_key_exists(
          $sstIndex,
          $sst
        )
      ) {
        throw new RuntimeException(
          "BIFF8 LABELSSTのSSTインデックスが不正です: " .
          $sstIndex
        );
      }

      $cells[] =
        array(
          'row' =>
            $row,

          'col' =>
            $col,

          'value' =>
            $sst[$sstIndex],
        );
    }

    /*
     * NUMBER
     */
    if (
      $recordId === 0x0203 &&
      $recordLength >= 14
    ) {
      $row =
        jpx_arbitrage_u16le_(
          $payload,
          0
        );

      $col =
        jpx_arbitrage_u16le_(
          $payload,
          2
        );

      $value =
        jpx_arbitrage_double_le_(
          $payload,
          6
        );

      $cells[] =
        array(
          'row' =>
            $row,

          'col' =>
            $col,

          'value' =>
            $value,
        );
    }

    /*
     * RK
     */
    if (
      $recordId === 0x027E &&
      $recordLength >= 10
    ) {
      $row =
        jpx_arbitrage_u16le_(
          $payload,
          0
        );

      $col =
        jpx_arbitrage_u16le_(
          $payload,
          2
        );

      $rk =
        jpx_arbitrage_u32le_(
          $payload,
          6
        );

      $cells[] =
        array(
          'row' =>
            $row,

          'col' =>
            $col,

          'value' =>
            jpx_arbitrage_decode_rk_(
              $rk
            ),
        );
    }

    /*
     * MULRK
     */
    if (
      $recordId === 0x00BD &&
      $recordLength >= 12
    ) {
      $row =
        jpx_arbitrage_u16le_(
          $payload,
          0
        );

      $firstCol =
        jpx_arbitrage_u16le_(
          $payload,
          2
        );

      $lastCol =
        jpx_arbitrage_u16le_(
          $payload,
          $recordLength - 2
        );

      $entryOffset =
        4;

      for (
        $col = $firstCol;
        $col <= $lastCol;
        $col++
      ) {
        if (
          $entryOffset + 6 >
          $recordLength - 2
        ) {
          throw new RuntimeException(
            "BIFF8 MULRKレコードが不正です。"
          );
        }

        /*
         * XF index 2byteの後ろにRK 4byte。
         */
        $rk =
          jpx_arbitrage_u32le_(
            $payload,
            $entryOffset + 2
          );

        $cells[] =
          array(
            'row' =>
              $row,

            'col' =>
              $col,

            'value' =>
              jpx_arbitrage_decode_rk_(
                $rk
              ),
          );

        $entryOffset += 6;
      }
    }

    /*
     * FORMULA
     *
     * キャッシュ済み結果が数値の場合だけ取得する。
     */
    if (
      $recordId === 0x0006 &&
      $recordLength >= 20
    ) {
      $row =
        jpx_arbitrage_u16le_(
          $payload,
          0
        );

      $col =
        jpx_arbitrage_u16le_(
          $payload,
          2
        );

      $result =
        substr(
          $payload,
          6,
          8
        );

      /*
       * 非数値結果の場合、
       * 末尾2byteがFFFFとなる。
       */
      if (
        strlen($result) === 8 &&
        substr(
          $result,
          6,
          2
        ) !== "\xFF\xFF"
      ) {
        $cells[] =
          array(
            'row' =>
              $row,

            'col' =>
              $col,

            'value' =>
              jpx_arbitrage_double_le_(
                $result,
                0
              ),
          );
      }
    }

    $offset =
      $payloadOffset +
      $recordLength;
  }

  return
    $cells;
}

/**
 * RK数値をdoubleへ変換する。
 *
 * @param int $rk
 * @return float
 */
function jpx_arbitrage_decode_rk_(
  $rk
) {
  $divideBy100 =
    ($rk & 0x01) !== 0;

  $isInteger =
    ($rk & 0x02) !== 0;

  if ($isInteger) {
    /*
     * RK整数は上位30bitに
     * 符号付き整数を保持する。
     */
    $signed =
      $rk;

    if (
      $signed >=
      0x80000000
    ) {
      $signed -=
        0x100000000;
    }

    $value =
      (float)(
        $signed >> 2
      );
  } else {
    /*
     * IEEE754 doubleの上位32bitをRKに保持する。
     */
    $high =
      $rk &
      0xFFFFFFFC;

    $binary =
      pack(
        'V2',
        0,
        $high
      );

    $unpacked =
      unpack(
        'dvalue',
        $binary
      );

    $value =
      isset($unpacked['value'])
        ? (float)$unpacked['value']
        : 0.0;
  }

  if ($divideBy100) {
    $value /=
      100;
  }

  return
    $value;
}

/**
 * OLE Directoryを解析する。
 *
 * @param string $stream
 * @return array
 */
function jpx_arbitrage_parse_ole_directory_(
  $stream
) {
  $entries =
    array();

  $length =
    strlen($stream);

  for (
    $offset = 0;
    $offset + 128 <= $length;
    $offset += 128
  ) {
    $entry =
      substr(
        $stream,
        $offset,
        128
      );

    $nameLength =
      jpx_arbitrage_u16le_(
        $entry,
        64
      );

    $type =
      ord(
        $entry[66]
      );

    if (
      $nameLength < 2 ||
      $nameLength > 64
    ) {
      continue;
    }

    $nameRaw =
      substr(
        $entry,
        0,
        $nameLength - 2
      );

    $name =
      jpx_arbitrage_utf16le_to_utf8_(
        $nameRaw
      );

    if ($name === '') {
      continue;
    }

    $startSector =
      jpx_arbitrage_u32le_(
        $entry,
        116
      );

    /*
     * JPX XLSのサイズでは32bitで十分だが、
     * Directory Entry上は64bitのため両方読む。
     */
    $sizeLow =
      jpx_arbitrage_u32le_(
        $entry,
        120
      );

    $sizeHigh =
      jpx_arbitrage_u32le_(
        $entry,
        124
      );

    $size =
      $sizeLow +
      ($sizeHigh * 4294967296);

    $entries[] =
      array(
        'name' =>
          $name,

        'type' =>
          $type,

        'start_sector' =>
          $startSector,

        'size' =>
          $size,
      );
  }

  return
    $entries;
}

/**
 * OLE通常セクタチェーンを読み込む。
 *
 * @param string $xlsData
 * @param int $startSector
 * @param array $fat
 * @param int $sectorSize
 * @return string
 */
function jpx_arbitrage_read_ole_chain_(
  $xlsData,
  $startSector,
  $fat,
  $sectorSize
) {
  $endOfChain =
    0xFFFFFFFE;

  $freeSector =
    0xFFFFFFFF;

  $sector =
    $startSector;

  $result =
    '';

  $visited =
    array();

  while (
    $sector !== $endOfChain &&
    $sector !== $freeSector
  ) {
    if (
      !is_int($sector) &&
      !is_float($sector)
    ) {
      throw new RuntimeException(
        "OLEセクタ番号が不正です。"
      );
    }

    $sector =
      (int)$sector;

    if ($sector < 0) {
      throw new RuntimeException(
        "OLEセクタ番号が負数です。"
      );
    }

    if (
      isset(
        $visited[$sector]
      )
    ) {
      throw new RuntimeException(
        "OLEセクタチェーンが循環しています: " .
        $sector
      );
    }

    $visited[$sector] =
      true;

    $result .=
      jpx_arbitrage_read_ole_sector_(
        $xlsData,
        $sector,
        $sectorSize
      );

    if (
      !array_key_exists(
        $sector,
        $fat
      )
    ) {
      throw new RuntimeException(
        "OLE FATにセクタが存在しません: " .
        $sector
      );
    }

    $sector =
      $fat[$sector];
  }

  return
    $result;
}

/**
 * Mini Streamチェーンを読み込む。
 *
 * @param string $miniStream
 * @param int $startSector
 * @param array $miniFat
 * @param int $miniSectorSize
 * @return string
 */
function jpx_arbitrage_read_mini_chain_(
  $miniStream,
  $startSector,
  $miniFat,
  $miniSectorSize
) {
  $endOfChain =
    0xFFFFFFFE;

  $freeSector =
    0xFFFFFFFF;

  $sector =
    $startSector;

  $result =
    '';

  $visited =
    array();

  while (
    $sector !== $endOfChain &&
    $sector !== $freeSector
  ) {
    $sector =
      (int)$sector;

    if (
      isset(
        $visited[$sector]
      )
    ) {
      throw new RuntimeException(
        "OLE Mini Streamチェーンが循環しています。"
      );
    }

    $visited[$sector] =
      true;

    $offset =
      $sector *
      $miniSectorSize;

    if (
      $offset +
      $miniSectorSize >
      strlen($miniStream)
    ) {
      throw new RuntimeException(
        "OLE Mini Streamのセクタ範囲が不正です。"
      );
    }

    $result .=
      substr(
        $miniStream,
        $offset,
        $miniSectorSize
      );

    if (
      !array_key_exists(
        $sector,
        $miniFat
      )
    ) {
      throw new RuntimeException(
        "OLE MiniFATにセクタが存在しません。"
      );
    }

    $sector =
      $miniFat[$sector];
  }

  return
    $result;
}

/**
 * OLEセクタを1件取得する。
 *
 * @param string $xlsData
 * @param int $sectorId
 * @param int $sectorSize
 * @return string
 */
function jpx_arbitrage_read_ole_sector_(
  $xlsData,
  $sectorId,
  $sectorSize
) {
  /*
   * OLEファイル先頭の512byteヘッダの後から
   * sector 0が始まる。
   */
  $offset =
    ($sectorId + 1) *
    $sectorSize;

  if (
    $offset < 0 ||
    $offset + $sectorSize >
    strlen($xlsData)
  ) {
    throw new RuntimeException(
      "OLEセクタ範囲が不正です: " .
      $sectorId
    );
  }

  return
    substr(
      $xlsData,
      $offset,
      $sectorSize
    );
}

/**
 * セル文字列の軽微な正規化。
 *
 * @param string $value
 * @return string
 */
function jpx_arbitrage_normalize_cell_text_(
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

  return
    trim($value);
}

/**
 * UTF-16LE → UTF-8
 *
 * @param string $value
 * @return string
 */
function jpx_arbitrage_utf16le_to_utf8_(
  $value
) {
  if (
    function_exists(
      'mb_convert_encoding'
    )
  ) {
    return
      mb_convert_encoding(
        $value,
        'UTF-8',
        'UTF-16LE'
      );
  }

  if (
    function_exists(
      'iconv'
    )
  ) {
    $converted =
      iconv(
        'UTF-16LE',
        'UTF-8//IGNORE',
        $value
      );

    if ($converted !== false) {
      return
        $converted;
    }
  }

  throw new RuntimeException(
    "UTF-16LEからUTF-8へ変換できる関数がありません。"
  );
}

/**
 * ISO-8859-1相当の1byte Unicode → UTF-8
 *
 * @param string $value
 * @return string
 */
function jpx_arbitrage_latin1_to_utf8_(
  $value
) {
  if (
    function_exists(
      'mb_convert_encoding'
    )
  ) {
    return
      mb_convert_encoding(
        $value,
        'UTF-8',
        'ISO-8859-1'
      );
  }

  if (
    function_exists(
      'iconv'
    )
  ) {
    $converted =
      iconv(
        'ISO-8859-1',
        'UTF-8//IGNORE',
        $value
      );

    if ($converted !== false) {
      return
        $converted;
    }
  }

  return
    $value;
}

/**
 * unsigned 16bit little-endian.
 *
 * @param string $data
 * @param int $offset
 * @return int
 */
function jpx_arbitrage_u16le_(
  $data,
  $offset
) {
  if (
    $offset < 0 ||
    $offset + 2 >
    strlen($data)
  ) {
    throw new RuntimeException(
      "16bit値の読込範囲が不正です。"
    );
  }

  $value =
    unpack(
      'vvalue',
      substr(
        $data,
        $offset,
        2
      )
    );

  return
    (int)$value['value'];
}

/**
 * unsigned 32bit little-endian.
 *
 * PHP 64bit環境を前提とする。
 *
 * @param string $data
 * @param int $offset
 * @return int
 */
function jpx_arbitrage_u32le_(
  $data,
  $offset
) {
  if (
    $offset < 0 ||
    $offset + 4 >
    strlen($data)
  ) {
    throw new RuntimeException(
      "32bit値の読込範囲が不正です。"
    );
  }

  $value =
    unpack(
      'Vvalue',
      substr(
        $data,
        $offset,
        4
      )
    );

  return
    (int)$value['value'];
}

/**
 * little-endian IEEE754 double.
 *
 * @param string $data
 * @param int $offset
 * @return float
 */
function jpx_arbitrage_double_le_(
  $data,
  $offset
) {
  if (
    $offset < 0 ||
    $offset + 8 >
    strlen($data)
  ) {
    throw new RuntimeException(
      "double値の読込範囲が不正です。"
    );
  }

  $raw =
    substr(
      $data,
      $offset,
      8
    );

  /*
   * XLSはlittle-endian。
   * 現行実行環境はx86_64 Linuxを想定。
   */
  $value =
    unpack(
      'dvalue',
      $raw
    );

  if (
    !isset(
      $value['value']
    )
  ) {
    throw new RuntimeException(
      "double値を変換できません。"
    );
  }

  return
    (float)$value['value'];
}