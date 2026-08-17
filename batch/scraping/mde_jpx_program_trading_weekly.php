<?php
/**
 * 市況関連データ抽出：JPXプログラム売買の状況（週間）
 *
 * market_data_extract.php から読み込まれ、
 * JPXの「プログラム売買の状況（週間）」XLSを解析し、
 * 裁定取引に係る現物ポジションを抽出して
 * メッセージを生成する。
 *
 * XLS形式:
 *   Microsoft Excel 97-2003 Workbook
 *   OLE Compound Document / BIFF8
 *
 * OLE Compound Document / BIFF8の共通解析には、
 * mde_jpx_arbitrage_daily.phpで定義された
 * jpx_arbitrage_*()系共通関数を使用する。
 */

// =======================================================
// JPXプログラム売買の状況（週間）
// =======================================================

/**
 * JPXプログラム売買の状況（週間）XLSから、
 * 裁定取引に係る現物ポジションの金額を取得する。
 *
 * @param string $xlsData XLSバイナリ
 * @return array
 */
function parse_jpx_program_trading_weekly_xls_(
  $xlsData
) {
  if (
    !is_string($xlsData) ||
    $xlsData === ''
  ) {
    throw new RuntimeException(
      "JPXプログラム売買の状況（週間）のXLSデータが空です。"
    );
  }

  // -------------------------------------------------------
  // XLS共通解析関数の存在確認
  // -------------------------------------------------------

  $requiredFunctions = array(
    'jpx_arbitrage_extract_workbook_stream_',
    'jpx_arbitrage_parse_biff8_workbook_',
    'jpx_arbitrage_normalize_cell_text_',
    'jpx_arbitrage_get_numeric_cell_',
  );

  foreach ($requiredFunctions as $functionName) {
    if (
      !function_exists(
        $functionName
      )
    ) {
      throw new RuntimeException(
        "JPXプログラム売買の状況（週間）のXLS解析に必要な関数がありません: " .
        $functionName
      );
    }
  }

  // -------------------------------------------------------
  // Workbookストリーム取得
  // -------------------------------------------------------

  $workbook =
    jpx_arbitrage_extract_workbook_stream_(
      $xlsData
    );

  /*
   * Workbookストリームから、
   * 共有文字列および先頭ワークシートの
   * セル情報を取得する。
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
      "JPXプログラム売買の状況（週間）のセル情報を取得できません。"
    );
  }

  $cells =
    $book['cells'];

  // -------------------------------------------------------
  // 公表日の取得
  // -------------------------------------------------------

  /*
   * XLS内の
   *
   *   2026年8月13日
   *
   * のような公表日を取得する。
   *
   * 「8月7日現在」の年を決定するために使用する。
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
      "JPXプログラム売買の状況（週間）の公表日を取得できません。"
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
      "JPXプログラム売買の状況（週間）の公表日形式が不正です: " .
      $publicationDate
    );
  }

  $publicationYear =
    (int)$publicationMatches[1];

  $publicationMonth =
    (int)$publicationMatches[2];

  // -------------------------------------------------------
  // 「2. 裁定取引に係る現物ポジション」の取得
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
      "JPXプログラム売買の状況（週間）の" .
      "「裁定取引に係る現物ポジション」見出しを取得できません。"
    );
  }

  /*
   * 例:
   *
   * ２．裁定取引に係る現物ポジション（8月7日現在）
   */
  if (
    !preg_match(
      '/[（(]\s*(\d{1,2})月(\d{1,2})日現在\s*[）)]/u',
      $positionTitle,
      $positionDateMatches
    )
  ) {
    throw new RuntimeException(
      "JPXプログラム売買の状況（週間）の基準日を取得できません: " .
      $positionTitle
    );
  }

  $positionMonth =
    (int)$positionDateMatches[1];

  $positionDay =
    (int)$positionDateMatches[2];

  // -------------------------------------------------------
  // 基準日の年判定
  // -------------------------------------------------------

  /*
   * 通常は公表日と基準日は同一年。
   *
   * 年初に前年12月末現在の値が公表された場合にも
   * 対応できるよう、
   * 基準月が公表月より6か月を超えて大きい場合は
   * 前年として扱う。
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
      "JPXプログラム売買の状況（週間）の基準日が不正です: " .
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

  /*
   * 今回取得する金額は百万円単位。
   *
   * タイトル行に、
   *
   *   （単位：千株、百万円）
   *
   * が存在することを確認する。
   */
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
      jpx_program_trading_weekly_normalize_text_(
        $cell['value']
      );

    if (
      strpos(
        $text,
        '百万円'
      ) !== false
    ) {
      $unitConfirmed = true;

      break;
    }
  }

  if (!$unitConfirmed) {
    throw new RuntimeException(
      "JPXプログラム売買の状況（週間）の" .
      "現物ポジション単位「百万円」を確認できません。"
    );
  }

  // -------------------------------------------------------
  // 売り・買いポジション開始列の取得
  // -------------------------------------------------------

  /*
   * 基準日見出しの次の行:
   *
   *   売りポジション
   *   買いポジション
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
      jpx_program_trading_weekly_normalize_text_(
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
      "JPXプログラム売買の状況（週間）の" .
      "売りポジション見出しを取得できません。"
    );
  }

  if ($buyStartCol === null) {
    throw new RuntimeException(
      "JPXプログラム売買の状況（週間）の" .
      "買いポジション見出しを取得できません。"
    );
  }

  if (
    $sellStartCol >=
    $buyStartCol
  ) {
    throw new RuntimeException(
      "JPXプログラム売買の状況（週間）の" .
      "売りポジションと買いポジションの列位置が不正です。"
    );
  }

  // -------------------------------------------------------
  // 「合計」列取得
  // -------------------------------------------------------

  /*
   * ポジション見出しの次の行:
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
      jpx_program_trading_weekly_normalize_text_(
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
      "JPXプログラム売買の状況（週間）の" .
      "売りポジション合計列を取得できません。"
    );
  }

  if ($buyTotalCol === null) {
    throw new RuntimeException(
      "JPXプログラム売買の状況（週間）の" .
      "買いポジション合計列を取得できません。"
    );
  }

  // -------------------------------------------------------
  // 「金額」行の取得
  // -------------------------------------------------------

  /*
   * subHeaderRowの下には、
   *
   *   株数
   *   前週末比
   *   金額
   *   前週末比
   *
   * の順で存在する。
   *
   * 固定行番号だけに依存せず、
   * 「金額」を検索して対象行を決定する。
   */
  $amountRow = null;

  for (
    $row =
      $subHeaderRow + 1;
    $row <=
      $subHeaderRow + 6;
    $row++
  ) {
    foreach ($cells as $cell) {
      if (
        !isset(
          $cell['row'],
          $cell['value']
        ) ||
        (int)$cell['row'] !== $row ||
        !is_string($cell['value'])
      ) {
        continue;
      }

      $text =
        jpx_program_trading_weekly_normalize_label_(
          $cell['value']
        );

      if ($text === '金額') {
        $amountRow =
          $row;

        break 2;
      }
    }
  }

  if ($amountRow === null) {
    throw new RuntimeException(
      "JPXプログラム売買の状況（週間）の" .
      "金額行を取得できません。"
    );
  }

  // -------------------------------------------------------
  // 金額前週末比行の取得
  // -------------------------------------------------------

  /*
   * 「金額」の直後の行が
   * 「前週末比」であることを確認する。
   */
  $previousRow =
    $amountRow + 1;

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
      jpx_program_trading_weekly_normalize_text_(
        $cell['value']
      );

    if ($text === '前週末比') {
      $previousRowConfirmed = true;

      break;
    }
  }

  if (!$previousRowConfirmed) {
    throw new RuntimeException(
      "JPXプログラム売買の状況（週間）の" .
      "金額前週末比行を確認できません。"
    );
  }

  // -------------------------------------------------------
  // 金額の取得
  // -------------------------------------------------------

  $sellPositionMillion =
    jpx_arbitrage_get_numeric_cell_(
      $cells,
      $amountRow,
      $sellTotalCol,
      '売りポジション金額'
    );

  $sellPreviousMillion =
    jpx_arbitrage_get_numeric_cell_(
      $cells,
      $previousRow,
      $sellTotalCol,
      '売りポジション前週末比'
    );

  $buyPositionMillion =
    jpx_arbitrage_get_numeric_cell_(
      $cells,
      $amountRow,
      $buyTotalCol,
      '買いポジション金額'
    );

  $buyPreviousMillion =
    jpx_arbitrage_get_numeric_cell_(
      $cells,
      $previousRow,
      $buyTotalCol,
      '買いポジション前週末比'
    );

  // -------------------------------------------------------
  // 百万円 → 億円
  // -------------------------------------------------------

  /*
   * 100百万円 = 1億円。
   *
   * 小数部分は0方向へ切り捨てる。
   *
   * 例:
   *   192,597百万円
   *     → 1,925億円
   *
   *   -49,441百万円
   *     → -494億円
   *
   *   2,236,727百万円
   *     → 22,367億円
   *     → 2兆2367億円
   */
  $sellPositionOku =
    jpx_program_trading_weekly_million_to_oku_(
      $sellPositionMillion
    );

  $sellPreviousOku =
    jpx_program_trading_weekly_million_to_oku_(
      $sellPreviousMillion
    );

  $buyPositionOku =
    jpx_program_trading_weekly_million_to_oku_(
      $buyPositionMillion
    );

  $buyPreviousOku =
    jpx_program_trading_weekly_million_to_oku_(
      $buyPreviousMillion
    );

  return array(
    'updated_at' =>
      $updatedAt,

    'sell_position' =>
      $sellPositionOku,

    'sell_previous' =>
      $sellPreviousOku,

    'buy_position' =>
      $buyPositionOku,

    'buy_previous' =>
      $buyPreviousOku,
  );
}

/**
 * 百万円単位を億円単位へ変換する。
 *
 * 小数部分は0方向へ切り捨てる。
 *
 * @param float $value
 * @return int
 */
function jpx_program_trading_weekly_million_to_oku_(
  $value
) {
  return
    (int)(
      ((float)$value) / 100
    );
}

/**
 * 億円単位の値を表示用へ整形する。
 *
 * 前週末比の場合は$withSign=trueを指定する。
 *
 * 例:
 *
 *   1925
 *     → 1,925億円
 *
 *   22367
 *     → 2兆2367億円
 *
 *   389
 *     → +389億円
 *
 *   -494
 *     → -494億円
 *
 * @param int $value
 * @param bool $withSign
 * @return string
 */
function format_jpx_program_trading_weekly_amount_(
  $value,
  $withSign = false
) {
  $value =
    (int)$value;

  $negative =
    $value < 0;

  $absolute =
    abs($value);

  /*
   * 10,000億円 = 1兆円。
   */
  if ($absolute >= 10000) {
    $cho =
      intdiv(
        $absolute,
        10000
      );

    $oku =
      $absolute % 10000;

    if ($oku === 0) {
      $formatted =
        number_format(
          $cho
        ) .
        '兆円';
    } else {
      $formatted =
        number_format(
          $cho
        ) .
        '兆' .
        sprintf(
          '%d',
          $oku
        ) .
        '億円';
    }
  } else {
    $formatted =
      number_format(
        $absolute
      ) .
      '億円';
  }

  /*
   * 前週末比だけ符号を明示する。
   */
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
    /*
     * ポジション本体で負数が発生した場合にも
     * 値を正数へ見せないようにする。
     */
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
function pad_jpx_program_trading_weekly_left_(
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
 * JPXプログラム売買の状況（週間）の
 * 抽出結果からレポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_jpx_program_trading_weekly_message_(
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
        "JPXプログラム売買の状況（週間）の" .
        "抽出結果が不足しています: " .
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
      "JPXプログラム売買の状況（週間）の更新日時が不正です。"
    );
  }

  $lines =
    array();

  // -------------------------------------------------------
  // 見出し
  // -------------------------------------------------------

  $lines[] =
    "■JPXプログラム売買の状況（週間）：" .
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
    "前週末比\t" .
    "買いポジション\t" .
    "前週末比";

  // -------------------------------------------------------
  // 表示値
  // -------------------------------------------------------

  $sellPosition =
    format_jpx_program_trading_weekly_amount_(
      $parsed['sell_position']
    );

  $sellPrevious =
    format_jpx_program_trading_weekly_amount_(
      $parsed['sell_previous'],
      true
    );

  $buyPosition =
    format_jpx_program_trading_weekly_amount_(
      $parsed['buy_position']
    );

  $buyPrevious =
    format_jpx_program_trading_weekly_amount_(
      $parsed['buy_previous'],
      true
    );

  /*
   * 「999,999,999億円」相当の表示幅を基準として
   * 各数値列を右寄せする。
   *
   * 符号または兆表記が付いた場合も切り捨てない。
   */
  $displayWidth =
    16;

  $lines[] =
    pad_jpx_program_trading_weekly_left_(
      $sellPosition,
      $displayWidth,
      '売りポジション'
    ) .
    "\t" .
    pad_jpx_program_trading_weekly_left_(
      $sellPrevious,
      $displayWidth,
      '売りポジション前週末比'
    ) .
    "\t" .
    pad_jpx_program_trading_weekly_left_(
      $buyPosition,
      $displayWidth,
      '買いポジション'
    ) .
    "\t" .
    pad_jpx_program_trading_weekly_left_(
      $buyPrevious,
      $displayWidth,
      '買いポジション前週末比'
    );

  return
    implode(
      "\n",
      $lines
    );
}

/**
 * セル文字列を正規化する。
 *
 * @param string $value
 * @return string
 */
function jpx_program_trading_weekly_normalize_text_(
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
    trim(
      $value
    );
}

/**
 * 行見出し判定用に文字列を正規化する。
 *
 * 「金　　額」
 * 「株　　数」
 * などに含まれる空白を除去する。
 *
 * @param string $value
 * @return string
 */
function jpx_program_trading_weekly_normalize_label_(
  $value
) {
  $value =
    jpx_program_trading_weekly_normalize_text_(
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