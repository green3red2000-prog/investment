<?php
/**
 * 市況関連データ抽出：世界の株価リアルタイム
 *
 * market_data_extract.php から読み込まれ、
 * 世界の株価リアルタイムHTMLの解析および
 * メッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 *   class_xpath_()
 */

// =======================================================
// 世界の株価リアルタイム
// =======================================================

/**
 * 世界の株価リアルタイムHTMLから、
 * 「リアルタイム主要株価指数」に掲載されている
 * 全行を取得する。
 *
 * 「オルカンeMAXIS Slim」は除外する。
 *
 * @param string $html
 * @return array
 */
function parse_global_market_realtime_html_($html) {
  $xpath = load_xpath_($html);

  /*
   * 「リアルタイム主要株価指数」を含むテーブルを取得する。
   */
  $tableNodes = $xpath->query(
    "//table[" .
      ".//caption//*[contains(" .
        "normalize-space(.)," .
        "'リアルタイム主要株価指数'" .
      ")]" .
    "]"
  );

  if (!$tableNodes || $tableNodes->length !== 1) {
    throw new RuntimeException(
      "リアルタイム主要株価指数テーブルが1件取得できません: " .
      ($tableNodes ? $tableNodes->length : 0)
    );
  }

  $tableNode = $tableNodes->item(0);

  /*
   * 各指数・商品等はclass="D1"のdivとして
   * テーブル内に並んでいる。
   */
  $rowNodes = $xpath->query(
    ".//div[" . class_xpath_('D1') . "]",
    $tableNode
  );

  if (!$rowNodes || $rowNodes->length === 0) {
    throw new RuntimeException(
      "リアルタイム主要株価指数のデータ行が取得できません。"
    );
  }

  /*
   * データ処理日時。
   * HTML上の更新日時ではなく、
   * 本プログラムで処理した現在日時を使用する。
   */
  $now = new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Tokyo')
  );

  $processedAt =
    $now->format('Y-m-d H:i');

  $rows = array();
  $excludedOrukanCount = 0;

  foreach ($rowNodes as $rowIndex => $rowNode) {
    $rowNo = $rowIndex + 1;

    /*
     * 名称要素を取得する。
     *
     * id="N111" 等、
     * N + 数字のidを持つ要素を対象とする。
     */
    $nameNodes = $xpath->query(
      ".//*[@id and " .
      "starts-with(@id, 'N') and " .
      "string-length(substring-after(@id, 'N')) > 0" .
      "]",
      $rowNode
    );

    if (!$nameNodes || $nameNodes->length !== 1) {
      throw new RuntimeException(
        "世界の株価リアルタイムの名称が1件取得できません: " .
        "row={$rowNo} count=" .
        ($nameNodes ? $nameNodes->length : 0)
      );
    }

    $nameNode = $nameNodes->item(0);
    $nameId = (string)$nameNode->getAttribute('id');

    if (
      !preg_match(
        '/^N(\d+)$/',
        $nameId,
        $idMatches
      )
    ) {
      throw new RuntimeException(
        "世界の株価リアルタイムの名称IDが不正です: " .
        "row={$rowNo} id={$nameId}"
      );
    }

    $code = $idMatches[1];

    /*
     * 名称は直接の子ノード単位で文字列を取得し、
     * 各部分を半角スペース1文字で結合する。
     *
     * 例:
     *   日本 + 日本225
     *       → 日本 日本225
     *
     *   CFD + 日本225
     *       → CFD 日本225
     *
     *   先物 + 225先物 + mini
     *       → 先物 225先物 mini
     */
    $name =
      build_global_market_realtime_name_(
        $nameNode
      );

    if ($name === '') {
      throw new RuntimeException(
        "世界の株価リアルタイムの名称が空です: " .
        "row={$rowNo}"
      );
    }

    /*
     * オルカンeMAXIS Slimは出力対象外とする。
     */
    if (
      mb_strpos(
        $name,
        'オルカン'
      ) !== false &&
      mb_strpos(
        $name,
        'eMAXIS Slim'
      ) !== false
    ) {
      $excludedOrukanCount++;
      continue;
    }

    /*
     * 日時。
     */
    $timeNodes = $xpath->query(
      ".//*[@id='T{$code}']",
      $rowNode
    );

    if (!$timeNodes || $timeNodes->length !== 1) {
      throw new RuntimeException(
        "世界の株価リアルタイムの日時が1件取得できません: " .
        "row={$rowNo} name={$name}"
      );
    }

    $dateTime =
      normalize_text_(
        $timeNodes->item(0)->textContent
      );

    validate_global_market_realtime_datetime_(
      $dateTime,
      $name,
      $rowNo
    );

    /*
     * 現在値。
     */
    $valueNodes = $xpath->query(
      ".//*[@id='V{$code}']",
      $rowNode
    );

    if (!$valueNodes || $valueNodes->length !== 1) {
      throw new RuntimeException(
        "世界の株価リアルタイムの値が1件取得できません: " .
        "row={$rowNo} name={$name}"
      );
    }

    $valueRaw =
      normalize_text_(
        $valueNodes->item(0)->textContent
      );

    $value =
      format_global_market_realtime_value_(
        $valueRaw,
        '値',
        $name,
        $rowNo,
        false
      );

    /*
     * 通常行:
     *
     *   Zxxx = 前日比
     *   Pxxx = 前日比騰落率
     *
     * CFD・先物等:
     *
     *   sakiTxxx  = 指数比: / ダウ比: 等
     *   sakiSAxxx = 比較値
     *   sakiPAxxx = 比較騰落率
     *
     * sakiTが存在する場合は、
     * 比較値側を優先して取得する。
     */
    $comparisonCaption = '';

    $captionNodes = $xpath->query(
      ".//*[@id='sakiT{$code}']",
      $rowNode
    );

    if (
      $captionNodes &&
      $captionNodes->length === 1
    ) {
      $comparisonCaption =
        normalize_text_(
          $captionNodes->item(0)->textContent
        );

      /*
       * 「指数比:」「指数比：」等の末尾の
       * コロンを除去する。
       */
      $comparisonCaption =
        preg_replace(
          '/[：:]\s*$/u',
          '',
          $comparisonCaption
        );

      $comparisonCaption =
        trim(
          (string)$comparisonCaption
        );
    }

    if ($comparisonCaption !== '') {
      /*
       * 指数比・ダウ比等。
       */
      $changeNodes = $xpath->query(
        ".//*[@id='sakiSA{$code}']",
        $rowNode
      );

      $rateNodes = $xpath->query(
        ".//*[@id='sakiPA{$code}']",
        $rowNode
      );

      if (
        !$changeNodes ||
        $changeNodes->length !== 1
      ) {
        throw new RuntimeException(
          "世界の株価リアルタイムの比較値が1件取得できません: " .
          "row={$rowNo}" .
          " name={$name}" .
          " caption={$comparisonCaption}"
        );
      }

      if (
        !$rateNodes ||
        $rateNodes->length !== 1
      ) {
        throw new RuntimeException(
          "世界の株価リアルタイムの比較騰落率が1件取得できません: " .
          "row={$rowNo}" .
          " name={$name}" .
          " caption={$comparisonCaption}"
        );
      }

      $changeRaw =
        normalize_text_(
          $changeNodes->item(0)->textContent
        );

      $rateRaw =
        normalize_text_(
          $rateNodes->item(0)->textContent
        );

    } else {
      /*
       * 通常の前日比・騰落率。
       */
      $changeNodes = $xpath->query(
        ".//*[@id='Z{$code}']",
        $rowNode
      );

      $rateNodes = $xpath->query(
        ".//*[@id='P{$code}']",
        $rowNode
      );

      if (
        !$changeNodes ||
        $changeNodes->length !== 1
      ) {
        throw new RuntimeException(
          "世界の株価リアルタイムの前日比が1件取得できません: " .
          "row={$rowNo} name={$name}"
        );
      }

      if (
        !$rateNodes ||
        $rateNodes->length !== 1
      ) {
        throw new RuntimeException(
          "世界の株価リアルタイムの前日比騰落率が1件取得できません: " .
          "row={$rowNo} name={$name}"
        );
      }

      $changeRaw =
        normalize_text_(
          $changeNodes->item(0)->textContent
        );

      $rateRaw =
        normalize_text_(
          $rateNodes->item(0)->textContent
        );
    }

    $change =
      format_global_market_realtime_value_(
        $changeRaw,
        '前日比',
        $name,
        $rowNo,
        true
      );

    $rate =
      format_global_market_realtime_percent_(
        $rateRaw,
        $name,
        $rowNo
      );

    $rows[] = array(
      'name' => $name,
      'datetime' => $dateTime,
      'value' => $value,
      'change' => $change,
      'rate' => $rate,
      'caption' => $comparisonCaption,
    );
  }

  /*
   * 除外対象のオルカンeMAXIS Slimは
   * 1件存在することを前提とする。
   *
   * HTML構造変更や名称変更を検知するため、
   * 0件または複数件の場合は異常終了する。
   */
  if ($excludedOrukanCount !== 1) {
    throw new RuntimeException(
      "オルカンeMAXIS Slimの除外件数が1件ではありません: " .
      $excludedOrukanCount
    );
  }

  if (count($rows) === 0) {
    throw new RuntimeException(
      "世界の株価リアルタイムの出力対象がありません。"
    );
  }

  return array(
    'processed_at' => $processedAt,
    'rows' => $rows,
  );
}

/**
 * 名称要素の直接の子ノードを順番に取得し、
 * 各文字列を半角スペース1文字で結合する。
 *
 * @param DOMNode $nameNode
 * @return string
 */
function build_global_market_realtime_name_(
  $nameNode
) {
  $parts = array();

  foreach ($nameNode->childNodes as $childNode) {
    $text =
      normalize_text_(
        $childNode->textContent
      );

    if ($text !== '') {
      $parts[] = $text;
    }
  }

  /*
   * 子ノードから取得できなかった場合は、
   * 要素全体のtextContentを使用する。
   */
  if (count($parts) === 0) {
    return normalize_text_(
      $nameNode->textContent
    );
  }

  return implode(
    ' ',
    $parts
  );
}

/**
 * 日時を検証する。
 *
 * 許容形式:
 *   MM/DD
 *   HH:mm
 *
 * @param string $value
 * @param string $name
 * @param int $rowNo
 * @return void
 */
function validate_global_market_realtime_datetime_(
  $value,
  $name,
  $rowNo
) {
  /*
   * MM/DD
   */
  if (
    preg_match(
      '/^(\d{2})\/(\d{2})$/',
      $value,
      $matches
    )
  ) {
    $month = (int)$matches[1];
    $day = (int)$matches[2];

    /*
     * 年は取得対象ではないため、
     * 02/29を許容できるよう2000年を使用する。
     */
    if (
      !checkdate(
        $month,
        $day,
        2000
      )
    ) {
      throw new RuntimeException(
        "世界の株価リアルタイムの日付が不正です: " .
        "row={$rowNo}" .
        " name={$name}" .
        " value={$value}"
      );
    }

    return;
  }

  /*
   * HH:mm
   */
  if (
    preg_match(
      '/^(\d{2}):(\d{2})$/',
      $value,
      $matches
    )
  ) {
    $hour = (int)$matches[1];
    $minute = (int)$matches[2];

    if (
      $hour < 0 ||
      $hour > 23 ||
      $minute < 0 ||
      $minute > 59
    ) {
      throw new RuntimeException(
        "世界の株価リアルタイムの時刻が不正です: " .
        "row={$rowNo}" .
        " name={$name}" .
        " value={$value}"
      );
    }

    return;
  }

  throw new RuntimeException(
    "世界の株価リアルタイムの日時形式が不正です: " .
    "row={$rowNo}" .
    " name={$name}" .
    " value={$value}"
  );
}

/**
 * 値・前日比を検証し、
 * 小数点以下2桁、
 * 3桁ごとのカンマ区切りへ正規化する。
 *
 * $signed=trueの場合:
 *   正数：+
 *   負数：-
 *   0：符号なし
 *
 * 欠損値:
 *   -
 *   －
 *   ー
 *
 * @param string $value
 * @param string $fieldName
 * @param string $name
 * @param int $rowNo
 * @param bool $signed
 * @return string
 */
function format_global_market_realtime_value_(
  $value,
  $fieldName,
  $name,
  $rowNo,
  $signed
) {
  $original =
    trim((string)$value);

  if (
    $original === '-' ||
    $original === '－' ||
    $original === 'ー'
  ) {
    return '-';
  }

  if ($original === '') {
    throw new RuntimeException(
      "世界の株価リアルタイムの数値が空です: " .
      "row={$rowNo}" .
      " name={$name}" .
      " field={$fieldName}"
    );
  }

  $raw =
    str_replace(
      array(
        ',',
        '＋',
        '－'
      ),
      array(
        '',
        '+',
        '-'
      ),
      $original
    );

  if (
    !preg_match(
      '/^[+-]?\d+(?:\.\d+)?$/',
      $raw
    )
  ) {
    throw new RuntimeException(
      "世界の株価リアルタイムの数値形式が不正です: " .
      "row={$rowNo}" .
      " name={$name}" .
      " field={$fieldName}" .
      " value={$value}"
    );
  }

  $number = (float)$raw;

  if (!$signed) {
    return number_format(
      $number,
      2,
      '.',
      ','
    );
  }

  if ($number > 0) {
    return
      '+' .
      number_format(
        $number,
        2,
        '.',
        ','
      );
  }

  if ($number < 0) {
    return
      '-' .
      number_format(
        abs($number),
        2,
        '.',
        ','
      );
  }

  return '0.00';
}

/**
 * 前日比騰落率を検証し、
 * 符号付き、小数点以下2桁、
 * パーセント記号付きへ正規化する。
 *
 * ▲または+：正数
 * ▼または-：負数
 * 0：符号なし
 *
 * @param string $value
 * @param string $name
 * @param int $rowNo
 * @return string
 */
function format_global_market_realtime_percent_(
  $value,
  $name,
  $rowNo
) {
  $original =
    trim((string)$value);

  if (
    $original === '-' ||
    $original === '－' ||
    $original === 'ー'
  ) {
    return '-';
  }

  if ($original === '') {
    throw new RuntimeException(
      "世界の株価リアルタイムの騰落率が空です: " .
      "row={$rowNo}" .
      " name={$name}"
    );
  }

  $isPositive =
    strpos($original, '▲') !== false ||
    strpos($original, '+') !== false ||
    strpos($original, '＋') !== false;

  $isNegative =
    strpos($original, '▼') !== false ||
    strpos($original, '-') !== false ||
    strpos($original, '－') !== false;

  if (
    $isPositive &&
    $isNegative
  ) {
    throw new RuntimeException(
      "世界の株価リアルタイムの騰落率方向が不正です: " .
      "row={$rowNo}" .
      " name={$name}" .
      " value={$value}"
    );
  }

  $raw =
    str_replace(
      array(
        ',',
        '%',
        '▲',
        '▼',
        '+',
        '-',
        '＋',
        '－'
      ),
      '',
      $original
    );

  $raw = trim($raw);

  if (
    $raw === '' ||
    !preg_match(
      '/^\d+(?:\.\d+)?$/',
      $raw
    )
  ) {
    throw new RuntimeException(
      "世界の株価リアルタイムの騰落率形式が不正です: " .
      "row={$rowNo}" .
      " name={$name}" .
      " value={$value}"
    );
  }

  $number = (float)$raw;

  if ($number == 0.0) {
    return '0.00%';
  }

  if ($isPositive) {
    return
      '+' .
      number_format(
        $number,
        2,
        '.',
        ''
      ) .
      '%';
  }

  if ($isNegative) {
    return
      '-' .
      number_format(
        $number,
        2,
        '.',
        ''
      ) .
      '%';
  }

  throw new RuntimeException(
    "世界の株価リアルタイムの騰落率方向を判定できません: " .
    "row={$rowNo}" .
    " name={$name}" .
    " value={$value}"
  );
}

/**
 * 名称を指定表示幅になるまで
 * 右側へ半角スペースで埋める。
 *
 * 全角1文字を表示幅2、
 * 半角1文字を表示幅1として扱う。
 *
 * 指定幅以上の場合は切り捨てない。
 *
 * @param string $value
 * @param int $width
 * @return string
 */
function pad_global_market_realtime_name_(
  $value,
  $width
) {
  if (!function_exists('mb_strwidth')) {
    throw new RuntimeException(
      "mb_strwidth()が使用できません: 名称"
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
 * 世界の株価リアルタイムの抽出結果から
 * レポート本文を作成する。
 *
 * @param array $parsed
 * @return string
 */
function build_global_market_realtime_message_(
  $parsed
) {
  if (
    !isset($parsed['processed_at']) ||
    !is_string($parsed['processed_at']) ||
    $parsed['processed_at'] === ''
  ) {
    throw new RuntimeException(
      "世界の株価リアルタイムのデータ処理日時がありません。"
    );
  }

  if (
    !isset($parsed['rows']) ||
    !is_array($parsed['rows']) ||
    count($parsed['rows']) === 0
  ) {
    throw new RuntimeException(
      "世界の株価リアルタイムのレポート対象がありません。"
    );
  }

  $lines = array();

  $lines[] =
    "■世界の株価リアルタイム";

  $lines[] =
    "データ処理日時: " .
    $parsed['processed_at'];

  $lines[] = '';

  /*
   * ユーザー指定の見出し。
   */
  $lines[] =
    "【主要株価指数】";

  $lines[] =
    "名称\t日時\t値\t前日比\t前日比騰落率";

  foreach ($parsed['rows'] as $row) {
    /*
     * 名称:
     * 全角20文字分 = 表示幅40。
     *
     * 指定文中の「半角30文字」とは幅が矛盾するため、
     * 全角20文字分を優先する。
     */
    $name =
      pad_global_market_realtime_name_(
        $row['name'],
        40
      );

    /*
     * 日時:
     * MM/DDまたはHH:mm。
     * どちらも5文字。
     */
    $dateTime =
      str_pad(
        (string)$row['datetime'],
        5,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * 値:
     * 999,999,999.00
     *
     * 符号付き値まで余裕を持たせ、
     * 15文字幅で右寄せする。
     */
    $value =
      str_pad(
        (string)$row['value'],
        15,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * 前日比:
     * ±999,999,999.00
     * 15文字幅で右寄せする。
     */
    $change =
      str_pad(
        (string)$row['change'],
        15,
        ' ',
        STR_PAD_LEFT
      );

    /*
     * 前日比騰落率:
     * ±999.00%
     * 8文字幅で右寄せする。
     */
    $rate =
      str_pad(
        (string)$row['rate'],
        8,
        ' ',
        STR_PAD_LEFT
      );

    $line =
      $name . "\t" .
      $dateTime . "\t" .
      $value . "\t" .
      $change . "\t" .
      $rate;

    /*
     * 指数比・ダウ比等の場合は、
     * 行末へキャプションを付加する。
     *
     * 全角スペース1文字 + ※ + キャプション。
     */
    if (
      isset($row['caption']) &&
      $row['caption'] !== ''
    ) {
      $line .=
        "　※" .
        $row['caption'];
    }

    $lines[] = $line;
  }

  return implode(
    "\n",
    $lines
  );
}