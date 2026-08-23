/**
 * Sheet News Reader - 未読ニュース一覧表示
 *
 * Spreadsheet:
 *  A: 時刻
 *  B: ソース
 *  C: 見出し
 *  D: URL
 *  E: ID
 *  F: 既読
 */

const SPREADSHEET_ID =
  '1AEegW2usuYpw2QwqRaH42PnuVwljkCX_uwnsi0VsHCs';

const SHEET_NAME = 'シート1';

// 列番号（1-based）
const COL_TIME = 1;    // A: 時刻
const COL_SOURCE = 2;  // B: ソース
const COL_TITLE = 3;   // C: 見出し
const COL_URL = 4;     // D: URL
const COL_ID = 5;      // E: ID
const COL_READAT = 6;  // F: 既読

// スクリプトプロパティのキー
const PROPERTY_AI_PROMPT = 'AI_PROMPT';

// 前回読んでいたニュースID
const PROPERTY_LAST_READ_ID =
  'LAST_READ_ID';

// 旧仕様のプロパティキー
const LEGACY_PROPERTY_LAST_READ_NUMBER =
  'LAST_READ_NUMBER';

/**
 * AIプロンプトの初期値
 */
const DEFAULT_AI_PROMPT =
  'これは前回からのニュースヘッドラインの更新分です。前回の分析結果も参考にしつつ、これを読み込んで分析して、最近の株式市場に対する影響や傾向、チャンスの兆し、警戒すべき点など、分かることを教えて。箇条書きは使わず文章で分かりやすく書いて。そして、今後の株式市場の展開にとって、重要なニュースもピックアップして。ニュース見出しを書き出して、それに解説するという形式でお願いします。';

/**
 * Webアプリ表示
 */
function doGet() {
  return HtmlService
    .createTemplateFromFile('Index')
    .evaluate()
    .setTitle('未読ニュース一覧')
    .addMetaTag(
      'viewport',
      'width=device-width, initial-scale=1'
    );
}

/**
 * 初期表示データを取得する
 *
 * この時点では既読更新を行わない。
 *
 * 保存されたニュースIDが現在の一覧に存在しなければ、
 * スクリプトプロパティを削除する。
 *
 * 戻り値:
 * {
 *   prompt: "AIプロンプト",
 *   lastReadId: "news-id",
 *   newsList: [
 *     {
 *       id: "...",
 *       time: "...",
 *       source: "...",
 *       title: "...",
 *       url: "..."
 *     }
 *   ]
 * }
 */
function getInitialData() {
  const newsList = getUnreadNews_();

  return {
    prompt: getAiPrompt_(),
    lastReadId:
      getValidatedLastReadId_(newsList),
    newsList
  };
}

/**
 * F列が空白のニュースを取得する
 *
 * 日付昇順に並び替えて返す。
 * この関数では既読更新を行わない。
 */
function getUnreadNews_() {
  const ss =
    SpreadsheetApp.openById(SPREADSHEET_ID);

  const sh =
    ss.getSheetByName(SHEET_NAME);

  if (!sh) {
    throw new Error(
      `シートが見つかりません: ${SHEET_NAME}`
    );
  }

  const lastRow = sh.getLastRow();

  // 見出し行しかない場合
  if (lastRow < 2) {
    return [];
  }

  const rowCount = lastRow - 1;

  // 日付の並び替え判定に使用する実データ
  const rawValues = sh
    .getRange(
      2,
      1,
      rowCount,
      COL_READAT
    )
    .getValues();

  // 画面表示用データ
  const displayValues = sh
    .getRange(
      2,
      1,
      rowCount,
      COL_READAT
    )
    .getDisplayValues();

  const unreadNews = [];

  for (
    let i = 0;
    i < displayValues.length;
    i++
  ) {
    const displayRow = displayValues[i];
    const rawRow = rawValues[i];

    const time =
      String(
        displayRow[COL_TIME - 1] ?? ''
      ).trim();

    const source =
      String(
        displayRow[COL_SOURCE - 1] ?? ''
      ).trim();

    const title =
      String(
        displayRow[COL_TITLE - 1] ?? ''
      ).trim();

    const url =
      String(
        displayRow[COL_URL - 1] ?? ''
      ).trim();

    const id =
      String(
        displayRow[COL_ID - 1] ?? ''
      ).trim();

    const readAt =
      String(
        displayRow[COL_READAT - 1] ?? ''
      ).trim();

    // F列に値があれば既読なので除外
    if (readAt !== '') {
      continue;
    }

    // A～D列がすべて空なら除外
    if (
      time === '' &&
      source === '' &&
      title === '' &&
      url === ''
    ) {
      continue;
    }

    /*
     * IDがないニュースは既読更新対象を
     * 一意に特定できないため除外する。
     */
    if (id === '') {
      continue;
    }

    unreadNews.push({
      id,
      time,
      source,
      title,
      url,

      // 日付昇順の並び替えに使用
      sortTime: createSortTime_(
        rawRow[COL_TIME - 1],
        time
      ),

      // 同一日時の場合の順序維持用
      originalOrder: i
    });
  }

  // 日付昇順
  // 同じ日時の場合はシート上の元の順序
  unreadNews.sort((a, b) => {
    if (a.sortTime !== b.sortTime) {
      return a.sortTime - b.sortTime;
    }

    return (
      a.originalOrder -
      b.originalOrder
    );
  });

  // 内部的な並び替え用項目は返さない
  return unreadNews.map(news => ({
    id: news.id,
    time: news.time,
    source: news.source,
    title: news.title,
    url: news.url
  }));
}

/**
 * 日付並び替え用の数値を作る
 *
 * @param {*} rawTime
 *   スプレッドシートの実データ
 *
 * @param {string} displayTime
 *   表示上の日時
 *
 * @return {number}
 */
function createSortTime_(
  rawTime,
  displayTime
) {
  // スプレッドシート上で日時型の場合
  if (
    rawTime instanceof Date &&
    !Number.isNaN(rawTime.getTime())
  ) {
    return rawTime.getTime();
  }

  const text =
    String(displayTime ?? '').trim();

  /*
   * 例:
   * 2026-08-02 5:00:00
   * 2026-08-02 05:00:00
   * 2026/08/02 5:00:00
   */
  const match = text.match(
    /^(\d{4})[-/](\d{1,2})[-/](\d{1,2})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$/
  );

  if (match) {
    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const hour = Number(match[4]);
    const minute = Number(match[5]);
    const second =
      Number(match[6] ?? 0);

    const date = new Date(
      year,
      month - 1,
      day,
      hour,
      minute,
      second
    );

    if (
      !Number.isNaN(date.getTime())
    ) {
      return date.getTime();
    }
  }

  // 日付として解釈できない値は末尾へ
  return Number.MAX_SAFE_INTEGER;
}

/**
 * ニュースIDを前回位置として保存する
 *
 * スクリプトプロパティには
 * 常に1件だけ保存される。
 *
 * @param {string} id
 * @return {{
 *   saved: boolean,
 *   id: string
 * }}
 */
function saveLastReadId(id) {
  const value =
    String(id ?? '').trim();

  if (value === '') {
    throw new Error(
      '保存するニュースIDが不正です。'
    );
  }

  const properties =
    PropertiesService.getScriptProperties();

  properties.setProperty(
    PROPERTY_LAST_READ_ID,
    value
  );

  // 旧仕様の番号プロパティが残っていれば削除
  properties.deleteProperty(
    LEGACY_PROPERTY_LAST_READ_NUMBER
  );

  return {
    saved: true,
    id: value
  };
}

/**
 * 保存された前回位置のニュースIDを取得する
 *
 * 現在の未読ニュース一覧に存在しないIDなら、
 * スクリプトプロパティを削除してnullを返す。
 *
 * @param {Object[]} newsList
 * @return {string|null}
 */
function getValidatedLastReadId_(newsList) {
  const properties =
    PropertiesService.getScriptProperties();

  // 旧仕様の番号プロパティは使用しない
  properties.deleteProperty(
    LEGACY_PROPERTY_LAST_READ_NUMBER
  );

  const savedId =
    String(
      properties.getProperty(
        PROPERTY_LAST_READ_ID
      ) ?? ''
    ).trim();

  if (savedId === '') {
    return null;
  }

  const exists =
    Array.isArray(newsList) &&
    newsList.some(news => {
      return String(
        news?.id ?? ''
      ).trim() === savedId;
    });

  if (!exists) {
    properties.deleteProperty(
      PROPERTY_LAST_READ_ID
    );

    return null;
  }

  return savedId;
}

/**
 * 「全てコピー」で表示したニュースを
 * IDで既読にする
 *
 * @param {string[]} ids
 *   画面に表示したニュースID
 *
 * @return {{
 *   updated: boolean,
 *   updatedCount: number,
 *   alreadyReadCount: number,
 *   notFoundCount: number,
 *   readAt: string
 * }}
 */
function markDisplayedNewsAsRead(ids) {
  if (!Array.isArray(ids)) {
    throw new Error(
      '既読更新対象のIDが不正です。'
    );
  }

  // 空白除外・重複排除
  const targetIds = [
    ...new Set(
      ids
        .map(id =>
          String(id ?? '').trim()
        )
        .filter(id => id !== '')
    )
  ];

  if (targetIds.length === 0) {
    return {
      updated: false,
      updatedCount: 0,
      alreadyReadCount: 0,
      notFoundCount: 0,
      readAt: ''
    };
  }

  const targetIdSet =
    new Set(targetIds);

  const lock =
    LockService.getScriptLock();

  // 同時実行による競合防止
  lock.waitLock(30000);

  try {
    const ss =
      SpreadsheetApp.openById(
        SPREADSHEET_ID
      );

    const sh =
      ss.getSheetByName(
        SHEET_NAME
      );

    if (!sh) {
      throw new Error(
        `シートが見つかりません: ${SHEET_NAME}`
      );
    }

    const lastRow = sh.getLastRow();

    if (lastRow < 2) {
      return {
        updated: false,
        updatedCount: 0,
        alreadyReadCount: 0,
        notFoundCount:
          targetIds.length,
        readAt: ''
      };
    }

    const rowCount = lastRow - 1;

    /*
     * コピー押下時点の最新状態で
     * E列とF列を再取得する。
     */
    const idValues = sh
      .getRange(
        2,
        COL_ID,
        rowCount,
        1
      )
      .getDisplayValues();

    const readAtValues = sh
      .getRange(
        2,
        COL_READAT,
        rowCount,
        1
      )
      .getValues();

    const now = new Date();

    let updatedCount = 0;
    let alreadyReadCount = 0;

    const foundIdSet = new Set();

    for (
      let i = 0;
      i < idValues.length;
      i++
    ) {
      const currentId =
        String(
          idValues[i][0] ?? ''
        ).trim();

      if (
        !targetIdSet.has(currentId)
      ) {
        continue;
      }

      foundIdSet.add(currentId);

      const currentReadAt =
        String(
          readAtValues[i][0] ?? ''
        ).trim();

      // すでに既読なら上書きしない
      if (currentReadAt !== '') {
        alreadyReadCount++;
        continue;
      }

      readAtValues[i][0] = now;
      updatedCount++;
    }

    /*
     * F列だけを書き換える。
     * E列のIDには触れない。
     */
    if (updatedCount > 0) {
      sh
        .getRange(
          2,
          COL_READAT,
          rowCount,
          1
        )
        .setValues(readAtValues)
        .setNumberFormat(
          'yyyy-MM-dd HH:mm:ss'
        );

      SpreadsheetApp.flush();
    }

    const notFoundCount =
      targetIds.length -
      foundIdSet.size;

    const tz =
      Session.getScriptTimeZone() ||
      'Asia/Tokyo';

    const formattedNow =
      Utilities.formatDate(
        now,
        tz,
        'yyyy-MM-dd HH:mm:ss'
      );

    return {
      updated: updatedCount > 0,
      updatedCount,
      alreadyReadCount,
      notFoundCount,
      readAt: formattedNow
    };

  } finally {
    lock.releaseLock();
  }
}

/**
 * AIプロンプトを取得する
 *
 * スクリプトプロパティに保存値がなければ、
 * DEFAULT_AI_PROMPTを返す。
 */
function getAiPrompt_() {
  const properties =
    PropertiesService.getScriptProperties();

  const savedPrompt =
    properties.getProperty(
      PROPERTY_AI_PROMPT
    );

  if (savedPrompt === null) {
    return DEFAULT_AI_PROMPT;
  }

  return savedPrompt;
}

/**
 * AIプロンプトを
 * スクリプトプロパティへ保存する
 *
 * @param {string} prompt
 *   保存するプロンプト
 *
 * @return {{saved:boolean}}
 */
function saveAiPrompt(prompt) {
  const text =
    String(prompt ?? '').trim();

  if (!text) {
    throw new Error(
      'AIプロンプトが空白です。'
    );
  }

  PropertiesService
    .getScriptProperties()
    .setProperty(
      PROPERTY_AI_PROMPT,
      text
    );

  return {
    saved: true
  };
}