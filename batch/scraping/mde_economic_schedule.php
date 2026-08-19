<?php
/**
 * 市況関連データ抽出：経済スケジュール
 *
 * market_data_extract.php から読み込まれ、
 * 経済スケジュールのHTML解析およびメッセージ生成を行う。
 *
 * 依存関数:
 *   normalize_text_()
 *   load_xpath_()
 *   class_xpath_()
 */

// =======================================================
// 経済スケジュール
// =======================================================

function parse_economic_schedule_html_($html, $targetYmd) {
  $xpath = load_xpath_($html);

  /*
   * 処理対象日を基準として、
   * 表の日付 MM/DD から年を補完する。
   *
   * 年末年始をまたぐ表にも対応できるよう、
   * targetYmd に最も近い年を採用する。
   */
  $targetDate = DateTime::createFromFormat(
    '!Y-m-d',
    (string)$targetYmd
  );

  if (
    $targetDate === false ||
    $targetDate->format('Y-m-d') !== (string)$targetYmd
  ) {
    throw new RuntimeException(
      "処理対象日が不正です: {$targetYmd}"
    );
  }

  /*
   * 経済指標テーブルを取得する。
   */
  $tableNodes = $xpath->query("//table[@id='SihyoT']");

  if (!$tableNodes || $tableNodes->length !== 1) {
    throw new RuntimeException(
      "経済指標テーブルが1件取得できません: " .
      ($tableNodes ? $tableNodes->length : 0)
    );
  }

  $table = $tableNodes->item(0);

  /*
   * 日付見出し行と経済指標行をHTML上の順序で取得する。
   *
   * 日付見出し:
   *   <td class="date">08/11(火)</td>
   *
   * 指標行:
   *   <tr class="rap">...</tr>
   *
   * 未来側の白背景行:
   *   <tr class="rap feature">...</tr>
   *
   * 今回は背景色がグレーの行だけを対象とするため、
   * feature クラスを持つ行は除外する。
   */
  $rowNodes = $xpath->query(
    ".//tbody/tr[" .
      "td[" . class_xpath_('date') . "]" .
      " or " .
      "(" .
        class_xpath_('rap') .
        " and not(" . class_xpath_('feature') . ")" .
      ")" .
    "]",
    $table
  );

  if (!$rowNodes || $rowNodes->length === 0) {
    throw new RuntimeException(
      "経済指標の対象行が取得できません。"
    );
  }

  $currentDate = '';
  $result = array();

  for ($i = 0; $i < $rowNodes->length; $i++) {
    $row = $rowNodes->item($i);

    /*
     * 日付見出し行を判定する。
     */
    $dateNodes = $xpath->query(
      "./td[" . class_xpath_('date') . "]",
      $row
    );

    if ($dateNodes && $dateNodes->length > 0) {
      if ($dateNodes->length !== 1) {
        throw new RuntimeException(
          "経済指標の日付見出しが1件ではありません。"
        );
      }

      $dateText =
        normalize_text_($dateNodes->item(0)->textContent);

      if (
        !preg_match(
          '/^(\d{1,2})\/(\d{1,2})\s*[\(（].*[\)）]$/u',
          $dateText,
          $matches
        )
      ) {
        throw new RuntimeException(
          "経済指標の日付見出しを解析できません: {$dateText}"
        );
      }

      $month = (int)$matches[1];
      $day = (int)$matches[2];

      $currentDate =
        resolve_economic_schedule_date_(
          $targetDate,
          $month,
          $day
        );

      continue;
    }

    /*
     * 経済指標行。
     */
    if ($currentDate === '') {
      throw new RuntimeException(
        "経済指標行より前に日付見出しを取得できません。"
      );
    }

    $timeNodes = $xpath->query(
      "./td[" . class_xpath_('time') . "]",
      $row
    );

    $priorityNodes = $xpath->query(
      "./td[" . class_xpath_('priority') . "]",
      $row
    );

    $eventNodes = $xpath->query(
      "./td[" . class_xpath_('event') . "]",
      $row
    );

    $resultNodes = $xpath->query(
      "./td[" . class_xpath_('result') . "]",
      $row
    );

    $expectationNodes = $xpath->query(
      "./td[" . class_xpath_('expectation') . "]",
      $row
    );

    $lastNodes = $xpath->query(
      "./td[" . class_xpath_('last') . "]",
      $row
    );

    if (
      !$timeNodes || $timeNodes->length !== 1 ||
      !$priorityNodes || $priorityNodes->length !== 1 ||
      !$eventNodes || $eventNodes->length !== 1 ||
      !$resultNodes || $resultNodes->length !== 1 ||
      !$expectationNodes || $expectationNodes->length !== 1 ||
      !$lastNodes || $lastNodes->length !== 1
    ) {
      throw new RuntimeException(
        "経済指標行の列構成が不正です: row=" . ($i + 1)
      );
    }

    $time =
      normalize_text_($timeNodes->item(0)->textContent);

    /*
     * 時間が「未定」の行は出力対象外。
     */
    if ($time === '未定') {
      continue;
    }

    /*
     * 時刻を解析する。
     *
     * サイト上では翌日深夜の時刻を
     * 24:00～29:59 の形式で表記する場合がある。
     *
     * 24時以降の場合は翌日の時刻へ変換する。
     *
     * 例:
     *   23:00 → 当日 23:00
     *   24:00 → 翌日 00:00
     *   25:30 → 翌日 01:30
     *   29:00 → 翌日 05:00
     */
    if (
      !preg_match(
        '/^(\d{1,2}):([0-5]\d)$/',
        $time,
        $timeMatches
      )
    ) {
      throw new RuntimeException(
        "経済指標の時間が不正です: {$time}"
      );
    }

    $hour = (int)$timeMatches[1];
    $minute = (int)$timeMatches[2];

    if ($hour > 29) {
      throw new RuntimeException(
        "経済指標の時間が不正です: {$time}"
      );
    }

    $date = $currentDate;

    if ($hour >= 24) {
      $hour -= 24;

      $nextDate = DateTime::createFromFormat(
        '!Y-m-d',
        $currentDate
      );

      if ($nextDate === false) {
        throw new RuntimeException(
          "経済指標の日付を翌日へ変換できません: {$currentDate}"
        );
      }

      $nextDate->modify('+1 day');
      $date = $nextDate->format('Y-m-d');
    }

    $time = sprintf(
      '%02d:%02d',
      $hour,
      $minute
    );

    $dateTime = $date . ' ' . $time;

    /*
     * 重要度。
     *
     * ★の個数を0～5で保持する。
     */
    $priorityText =
      normalize_text_($priorityNodes->item(0)->textContent);

    $priority =
      substr_count($priorityText, '★');

    if ($priority < 0 || $priority > 5) {
      throw new RuntimeException(
        "経済指標の重要度が0～5ではありません: " .
        "{$priorityText}"
      );
    }

    /*
     * 国。
     *
     * event 内の国旗spanに付与されている
     * flag1-XX クラスから国名へ変換する。
     */
    $country =
      parse_economic_schedule_country_(
        $xpath,
        $eventNodes->item(0)
      );

    /*
     * 指標文字列。
     */
    $eventText =
      normalize_text_($eventNodes->item(0)->textContent);

    if ($eventText === '') {
      throw new RuntimeException(
        "経済指標名が空です: {$dateTime}"
      );
    }

    list($period, $indicatorName) =
      split_economic_schedule_event_($eventText);

    /*
     * 結果、予想、前回。
     *
     * 数値化せず、サイト上の文字列表現をそのまま保持する。
     */
    $actual =
      normalize_text_($resultNodes->item(0)->textContent);

    $forecast =
      normalize_text_($expectationNodes->item(0)->textContent);

    $previous =
      normalize_text_($lastNodes->item(0)->textContent);

    if ($actual === '') {
      $actual = '－';
    }

    if ($forecast === '') {
      $forecast = '－';
    }

    if ($previous === '') {
      $previous = '－';
    }

    $result[] = array(
      'date' => $dateTime,
      'priority' => $priority,
      'country' => $country,
      'period' => $period,
      'indicator_name' => $indicatorName,
      'result' => $actual,
      'forecast' => $forecast,
      'previous' => $previous,
    );
  }

  if (count($result) === 0) {
    throw new RuntimeException(
      "出力対象となる経済指標が取得できません。"
    );
  }

  return $result;
}

/**
 * MM/DD の年を処理対象日から補完する。
 *
 * 処理対象日の前年、同年、翌年の候補を作成し、
 * 処理対象日に最も近い日付を採用する。
 *
 * これにより年末年始をまたぐ表にも対応する。
 */
function resolve_economic_schedule_date_(
  $targetDate,
  $month,
  $day
) {
  $targetYear = (int)$targetDate->format('Y');

  $candidateYears = array(
    $targetYear - 1,
    $targetYear,
    $targetYear + 1,
  );

  $bestDate = null;
  $bestDiff = null;

  foreach ($candidateYears as $year) {
    if (!checkdate((int)$month, (int)$day, $year)) {
      continue;
    }

    $candidate = DateTime::createFromFormat(
      '!Y-n-j',
      "{$year}-{$month}-{$day}"
    );

    if ($candidate === false) {
      continue;
    }

    $diff = abs(
      $candidate->getTimestamp() -
      $targetDate->getTimestamp()
    );

    if ($bestDiff === null || $diff < $bestDiff) {
      $bestDate = $candidate;
      $bestDiff = $diff;
    }
  }

  if ($bestDate === null) {
    throw new RuntimeException(
      "経済指標の日付が不正です: {$month}/{$day}"
    );
  }

  return $bestDate->format('Y-m-d');
}

/**
 * event列の国旗classから国名を取得する。
 */
function parse_economic_schedule_country_(
  $xpath,
  $eventNode
) {
  $flagNodes = $xpath->query(
    ".//span[" . class_xpath_('flag1') . "]",
    $eventNode
  );

  if (!$flagNodes || $flagNodes->length !== 1) {
    throw new RuntimeException(
      "経済指標の国旗が1件取得できません。"
    );
  }

  $class =
    (string)$flagNodes->item(0)->getAttribute('class');

  if (
    !preg_match(
      '/(?:^|\s)flag1-([a-z]{2})(?:\s|$)/i',
      $class,
      $matches
    )
  ) {
    throw new RuntimeException(
      "経済指標の国コードを取得できません: {$class}"
    );
  }

  $countryCode = strtolower($matches[1]);

  /*
   * HTML内で定義されている国旗classに対応する国名。
   */
  $countryMap = array(
    'jp' => '日本',
    'us' => 'アメリカ',
    'eu' => 'ユーロ圏',
    'gb' => 'イギリス',
    'au' => 'オーストラリア',
    'nz' => 'ニュージーランド',
    'ch' => 'スイス',
    'ca' => 'カナダ',
    'kr' => '韓国',
    'cn' => '中国',
    'tw' => '台湾',
    'ph' => 'フィリピン',
    'hk' => '香港',
    'vn' => 'ベトナム',
    'th' => 'タイ',
    'my' => 'マレーシア',
    'sg' => 'シンガポール',
    'id' => 'インドネシア',
    'in' => 'インド',
    'tr' => 'トルコ',
    'za' => '南アフリカ',
    'mx' => 'メキシコ',
    'br' => 'ブラジル',
    'ar' => 'アルゼンチン',
    'ru' => 'ロシア',
    'se' => 'スウェーデン',
    'no' => 'ノルウェー',
    'dk' => 'デンマーク',
    'pl' => 'ポーランド',
    'hu' => 'ハンガリー',
    'de' => 'ドイツ',
    'fr' => 'フランス',
    'cz' => 'チェコ',
  );

  if (!isset($countryMap[$countryCode])) {
    throw new RuntimeException(
      "未対応の経済指標国コードです: {$countryCode}"
    );
  }

  return $countryMap[$countryCode];
}

/**
 * 指標文字列を期間と指標名へ分割する。
 *
 * 対応形式:
 *
 *   第2四半期 雇用統計（失業率）
 *     期間   = 第2四半期
 *     指標名 = 雇用統計（失業率）
 *
 *   08/01 - 08/07 MBA住宅ローン申請指数[前週比]
 *     期間   = 08/01 - 08/07
 *     指標名 = MBA住宅ローン申請指数[前週比]
 *
 *   07月 消費者物価指数（CPI）[前年比]
 *     期間   = 07月
 *     指標名 = 消費者物価指数（CPI）[前年比]
 */
function split_economic_schedule_event_($eventText) {
  $eventText = normalize_text_($eventText);

  $patterns = array(
    '/^(第\d+四半期)\s+(.+)$/u',
    '/^(\d{2}\/\d{2}\s*-\s*\d{2}\/\d{2})\s+(.+)$/u',
    '/^(\d{2}月)\s+(.+)$/u',
  );

  foreach ($patterns as $pattern) {
    if (preg_match($pattern, $eventText, $matches)) {
      $period = normalize_text_($matches[1]);
      $indicatorName = normalize_text_($matches[2]);

      /*
       * 日付範囲については
       * MM/DD - MM/DD 形式へ統一する。
       */
      if (
        preg_match(
          '/^(\d{2}\/\d{2})\s*-\s*(\d{2}\/\d{2})$/',
          $period,
          $rangeMatches
        )
      ) {
        $period =
          $rangeMatches[1] .
          ' - ' .
          $rangeMatches[2];
      }

      if ($indicatorName === '') {
        throw new RuntimeException(
          "経済指標名が空です: {$eventText}"
        );
      }

      return array(
        $period,
        $indicatorName
      );
    }
  }

  throw new RuntimeException(
    "経済指標の期間と指標名を分割できません: {$eventText}"
  );
}

/**
 * 全角文字を2、半角文字を1として指定表示幅へ右側を空白で埋める。
 */
function pad_economic_schedule_right_($value, $width) {
  $value = (string)$value;

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

  return $value .
    str_repeat(' ', $width - $currentWidth);
}

/**
 * 全角文字を2、半角文字を1として指定表示幅へ左側を空白で埋める。
 */
function pad_economic_schedule_left_($value, $width) {
  $value = (string)$value;

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

  return str_repeat(
    ' ',
    $width - $currentWidth
  ) . $value;
}

/**
 * 経済スケジュールのテキスト内容を作成する。
 *
 * 一括実行時:
 *   指定された固定表示幅で整形する。
 *
 * 個別実行時:
 *   一括実行時と同じ列データを使用するが、
 *   各項目をtrimした上でタブ区切りにする。
 */
function build_economic_schedule_message_(
  $parsed,
  $isIndividual = false
) {
  $lines = array();

  $lines[] = "■経済スケジュール";
  $lines[] = '';

  $headers = array(
    '日付',
    '重要度',
    '国',
    '期間',
    '指標名',
    '結果',
    '予想',
    '前回',
  );

  if ($isIndividual) {
    $lines[] = implode("\t", $headers);
  } else {
    $lines[] =
      pad_economic_schedule_right_('日付', 16) . "\t" .
      str_pad('重要度', 6, ' ', STR_PAD_RIGHT) . "\t" .
      pad_economic_schedule_right_('国', 20) . "\t" .
      pad_economic_schedule_right_('期間', 30) . "\t" .
      pad_economic_schedule_right_('指標名', 60) . "\t" .
      pad_economic_schedule_left_('結果', 20) . "\t" .
      pad_economic_schedule_left_('予想', 20) . "\t" .
      pad_economic_schedule_left_('前回', 20);
  }

  foreach ($parsed as $row) {
    if ($isIndividual) {
      $lines[] =
        trim((string)$row['date']) . "\t" .
        trim((string)$row['priority']) . "\t" .
        trim((string)$row['country']) . "\t" .
        trim((string)$row['period']) . "\t" .
        trim((string)$row['indicator_name']) . "\t" .
        trim((string)$row['result']) . "\t" .
        trim((string)$row['forecast']) . "\t" .
        trim((string)$row['previous']);

      continue;
    }

    /*
     * 一括実行時の固定幅。
     *
     * 日付:
     *   YYYY-MM-DD HH:mm = 16文字
     *
     * 重要度:
     *   1文字幅右寄せ
     *
     * 国:
     *   全角10文字分 = 表示幅20
     *
     * 期間:
     *   全角15文字分 = 表示幅30
     *
     * 指標名:
     *   全角30文字分 = 表示幅60
     *
     * 結果、予想、前回:
     *   全角10文字分 = 表示幅20、左スペース埋め
     */
    $date =
      pad_economic_schedule_right_(
        (string)$row['date'],
        16
      );

    $priority =
      str_pad(
        (string)$row['priority'],
        1,
        ' ',
        STR_PAD_LEFT
      );

    $country =
      pad_economic_schedule_right_(
        (string)$row['country'],
        20
      );

    $period =
      pad_economic_schedule_right_(
        (string)$row['period'],
        30
      );

    $indicatorName =
      pad_economic_schedule_right_(
        (string)$row['indicator_name'],
        60
      );

    $actual =
      pad_economic_schedule_left_(
        (string)$row['result'],
        20
      );

    $forecast =
      pad_economic_schedule_left_(
        (string)$row['forecast'],
        20
      );

    $previous =
      pad_economic_schedule_left_(
        (string)$row['previous'],
        20
      );

    $lines[] =
      $date . "\t" .
      $priority . "\t" .
      $country . "\t" .
      $period . "\t" .
      $indicatorName . "\t" .
      $actual . "\t" .
      $forecast . "\t" .
      $previous;
  }

  return implode("\n", $lines);
}