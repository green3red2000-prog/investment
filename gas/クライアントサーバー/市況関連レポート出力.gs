/******************************************************
 * 市況レポート作成 Webアプリ
 *
 * 対象フォルダ:
 * マイドライブ
 *   └ 投資
 *      └ プログラミング
 *         └ GAS
 *            └ スクレイピング
 *               └ 出力結果
 ******************************************************/


/***** 設定ここから *****/

const CONFIG = {

  TIME_ZONE: 'Asia/Tokyo',

  OUTPUT_FOLDER_PATH: [
    '投資',
    'プログラミング',
    'GAS',
    'スクレイピング',
    '出力結果'
  ],

  MASTER_FOLDER_PATH: [
    '投資',
    'プログラミング',
    'GAS',
    'マスタ'
  ],

  OBSERVATION_NOTE_MASTER_NAME:
    '定点観測備考マスタ',

  PROPERTY_HANDWRITE: 'HANDWRITE_ITEMS',

  PROPERTY_AI_PROMPT: 'AI_PROMPT',

  REPORT_BLOCK_ORDER: [
    '■カテゴリ別市況分析',
    '■世界の株価リアルタイム',
    '■国債利回り',
    '■JPXホーム',
    '■信用残・評価損益',
    '■日経225バリュエーション',
    '■米国株バリュエーション',
    '■新高値・新安値',
    '■騰落レシオ',
    '■空売り比率',
    '■日経225寄与度',
    '■JPXプログラム売買の状況（週間）：裁定取引に係る現物ポジション',
    '■JPX裁定取引の状況（日別）：裁定取引に係る現物ポジション',
    '■投資部門別売買状況',
    '■東証業種別指数',
    '■決算速報 分類サマリ',
    '■株探テーマアクセスランキング',
    '■個別好悪材料',
    '■大量保有速報 区分サマリ',
    '■決算発表予定銘柄',
    '■恐怖指数',
    '■世界バフェット指数',
    '■短期プライムレート',
    '■長期プライムレート',
    '■住宅ローン',
    '■日本円TIBOR',
    '■米ドルMMF利回り',
    '■日銀当座預金増減要因と金融調節（確報）',
    '■日銀オペレーション・オファー／落札結果',
    '■FRB総資産：Assets: Total Assets: Total Assets (Less Eliminations from Consolidation): Wednesday Level (WALCL)',
    '■米国ハイイールドスプレッド：ICE BofA US High Yield Index Option-Adjusted Spread (BAMLH0A0HYM2)',
    '■米国社債スプレッド：ICE BofA US Corporate Index Option-Adjusted Spread (BAMLC0A0CM)',
    '■GDPNow：Federal Reserve Bank of Atlanta',
    '■経済スケジュール',
    '■本日の株価動向',
    '■決算速報 銘柄別内容',
    '■大量保有速報　大量保有報告',
    '■適時開示'
  ]

};

/***** 設定ここまで *****/


/**
 * Webアプリ表示
 */
function doGet() {

  return HtmlService
    .createTemplateFromFile('index')
    .evaluate()
    .setTitle('市況レポート作成')
    .addMetaTag(
      'viewport',
      'width=device-width, initial-scale=1'
    );
}


/**
 * 初期表示データ取得
 */
function getInitialData() {

  const props =
    PropertiesService.getScriptProperties();

  return {

    date:
      Utilities.formatDate(
        new Date(),
        CONFIG.TIME_ZONE,
        'yyyy-MM-dd'
      ),

    handwriteItems:
      props.getProperty(
        CONFIG.PROPERTY_HANDWRITE
      ) || '',

    aiPrompt:
      props.getProperty(
        CONFIG.PROPERTY_AI_PROMPT
      ) || ''

  };
}


/* ==================================================
 * Script Properties
 * ================================================== */


/**
 * 手書き確認項目保存
 */
function saveHandwriteItems(text) {

  PropertiesService
    .getScriptProperties()
    .setProperty(
      CONFIG.PROPERTY_HANDWRITE,
      String(text || '')
    );

  return {
    success: true,
    message: '手書き確認項目を保存しました。'
  };
}


/**
 * AIプロンプト保存
 */
function saveAiPrompt(text) {

  PropertiesService
    .getScriptProperties()
    .setProperty(
      CONFIG.PROPERTY_AI_PROMPT,
      String(text || '')
    );

  return {
    success: true,
    message: 'AIプロンプトを保存しました。'
  };
}


/* ==================================================
 * WEBレポート
 * ================================================== */


/**
 * WEBレポート生成
 */
function createWebReport(targetDate) {

  validateDate_(targetDate);

  const folder =
    getOutputFolder_();

  const nextDate =
    addDays_(targetDate, 1);


  /* ------------------------------------------
   * 市況関連データ抽出_ALL
   * ------------------------------------------ */

  const allName =
    '市況関連データ抽出_ALL_レポート_' +
    targetDate;

  let allText =
    readRequiredTextFileByBaseName_(
      folder,
      allName
    );


  /* ------------------------------------------
   * 翌日 経済スケジュール
   * ------------------------------------------ */

  const scheduleName =
    '市況関連データ抽出＃経済スケジュール_レポート_' +
    nextDate;

  const scheduleText =
    readOptionalTextFileByBaseName_(
      folder,
      scheduleName
    );

  if (scheduleText !== null) {

    allText =
      overwriteMatchingBlocks_(
        allText,
        scheduleText
      );

  }


  /* ------------------------------------------
   * 翌日 GROUP1
   * ------------------------------------------ */

  const group1Name =
    '市況関連データ抽出_GROUP1_レポート_' +
    nextDate;

  const group1Text =
    readOptionalTextFileByBaseName_(
      folder,
      group1Name
    );

  if (group1Text !== null) {

    allText =
      overwriteMatchingBlocks_(
        allText,
        group1Text
      );

  }


  /* ------------------------------------------
   * 手書き確認項目
   * ------------------------------------------ */

  const handwriteItems =
    PropertiesService
      .getScriptProperties()
      .getProperty(
        CONFIG.PROPERTY_HANDWRITE
      ) || '';


  /* ------------------------------------------
   * 決算速報
   * ------------------------------------------ */

  const earningsData =
    readGoogleSpreadsheet_(
      folder,
      '決算速報_' + targetDate
    );

  const earningsSummary =
    createEarningsSummary_(
      earningsData
    );


  /* ------------------------------------------
   * 大量保有速報
   * ------------------------------------------ */

  const largeHoldingData =
    readGoogleSpreadsheet_(
      folder,
      '大量保有速報_' + targetDate
    );

  const largeHoldingSummary =
    createLargeHoldingSummary_(
      largeHoldingData
    );


  /* ------------------------------------------
   * 連結
   * ------------------------------------------ */

  const blocks = [];

  blocks.push(
    allText
  );

  if (
    handwriteItems.trim() !== ''
  ) {

    blocks.push(
      handwriteItems
    );

  }

  blocks.push(
    [
      '■決算速報 分類サマリ',
      earningsSummary
    ].join('\n')
  );

  blocks.push(
    [
      '■大量保有速報 区分サマリ',
      largeHoldingSummary
    ].join('\n')
  );


  const text =
    reorderReportBlocks_(
      joinTextBlocks_(
        blocks
      )
    );


  return {

    success: true,

    fileName:
      'WEBレポート_' +
      targetDate +
      '.txt',

    text: text

  };
}

/* ==================================================
 * WBコード
 * ================================================== */


/**
 * WordPress貼り付け用コード生成
 *
 * WEBレポートを通常どおり最後まで生成したあと、
 * タブ区切りの表をHTML tableへ変換する。
 */
function createWbReport(targetDate) {

  /*
   * WEBレポートと取得・加工・並び順を
   * 完全に共通化する。
   */
  const webReport =
    createWebReport(
      targetDate
    );


  /*
   * 定点観測備考マスタ読み込み
   */
  const blockNotes =
    readObservationNoteMaster_();


  const html =
    convertReportToWordPressHtml_(
      webReport.text,
      blockNotes
    );

  return {

    success: true,

    fileName:
      'WBコード_' +
      targetDate +
      '.html',

    text: html

  };
}
/* ==================================================
 * 統合レポート
 * ================================================== */


/**
 * 統合レポート生成
 */
function createIntegratedReport(targetDate) {

  validateDate_(targetDate);

  const folder =
    getOutputFolder_();

  const nextDate =
    addDays_(targetDate, 1);


  /* ------------------------------------------
   * カテゴリ別市況分析
   * ------------------------------------------ */

  const categoryText =
    readRequiredTextFileByBaseName_(
      folder,
      'カテゴリ別市況分析_レポート_' +
      targetDate
    );


  /* ------------------------------------------
   * 市況関連データ抽出_ALL
   * ------------------------------------------ */

  let allText =
    readRequiredTextFileByBaseName_(
      folder,
      '市況関連データ抽出_ALL_レポート_' +
      targetDate
    );


  /* ------------------------------------------
   * 翌日 経済スケジュール
   * ------------------------------------------ */

  const scheduleText =
    readOptionalTextFileByBaseName_(
      folder,
      '市況関連データ抽出＃経済スケジュール_レポート_' +
      nextDate
    );

  if (scheduleText !== null) {

    allText =
      overwriteMatchingBlocks_(
        allText,
        scheduleText
      );

  }


  /* ------------------------------------------
   * 翌日 GROUP1
   * ------------------------------------------ */

  const group1Text =
    readOptionalTextFileByBaseName_(
      folder,
      '市況関連データ抽出_GROUP1_レポート_' +
      nextDate
    );

  if (group1Text !== null) {

    allText =
      overwriteMatchingBlocks_(
        allText,
        group1Text
      );

  }


  /* ------------------------------------------
   * 手書き確認項目
   * ------------------------------------------ */

  const handwriteItems =
    PropertiesService
      .getScriptProperties()
      .getProperty(
        CONFIG.PROPERTY_HANDWRITE
      ) || '';


  /* ------------------------------------------
   * 決算速報
   * ------------------------------------------ */

  const earningsData =
    readGoogleSpreadsheet_(
      folder,
      '決算速報_' + targetDate
    );

  const earningsSummary =
    createEarningsSummary_(
      earningsData
    );

  const earningsDetail =
    createEarningsDetailText_(
      earningsData
    );


  /* ------------------------------------------
   * 大量保有速報
   * ------------------------------------------ */

  const largeHoldingData =
    readGoogleSpreadsheet_(
      folder,
      '大量保有速報_' + targetDate
    );

  const largeHoldingSummary =
    createLargeHoldingSummary_(
      largeHoldingData
    );

  const largeHoldingDetail =
    createLargeHoldingDetailText_(
      largeHoldingData
    );


  /* ------------------------------------------
   * 本日の株価動向
   * ------------------------------------------ */

  const stockMovementData =
    readGoogleSpreadsheet_(
      folder,
      '本日の株価動向_' + targetDate
    );

  const stockMovementText =
    createStockMovementText_(
      stockMovementData
    );


  /* ------------------------------------------
   * 適時開示
   * ------------------------------------------ */

  const disclosureData =
    readGoogleSpreadsheet_(
      folder,
      '適時開示_' + targetDate
    );

  const disclosureText =
    createDisclosureText_(
      disclosureData
    );


  /* ------------------------------------------
   * 連結
   * ------------------------------------------ */

  const blocks = [];

  blocks.push(
    categoryText
  );

  blocks.push(
    allText
  );

  if (
    handwriteItems.trim() !== ''
  ) {

    blocks.push(
      handwriteItems
    );

  }

  blocks.push(
    [
      '■決算速報 分類サマリ',
      earningsSummary
    ].join('\n')
  );

  blocks.push(
    [
      '■決算速報 銘柄別内容',
      '',
      earningsDetail
    ].join('\n')
  );

  blocks.push(
    [
      '■大量保有速報 区分サマリ',
      largeHoldingSummary
    ].join('\n')
  );

  blocks.push(
    [
      '■大量保有速報　大量保有報告',
      '',
      largeHoldingDetail
    ].join('\n')
  );

  blocks.push(
    [
      '■本日の株価動向',
      '',
      stockMovementText
    ].join('\n')
  );

  blocks.push(
    [
      '■適時開示',
      '',
      disclosureText
    ].join('\n')
  );


  const text =
    reorderReportBlocks_(
      joinTextBlocks_(
        blocks
      )
    );


  return {

    success: true,

    fileName:
      '統合レポート_' +
      targetDate +
      '.txt',

    text: text

  };
}


/* ==================================================
 * 決算速報
 * ================================================== */


/**
 * 分類サマリ
 *
 * 例:
 *
 * 最高益1件
 * 上方修正1件
 * 減益2件
 * 未分類2件
 */
function createEarningsSummary_(data) {

  const lines = [];


  if (
    !data ||
    data.rows.length === 0
  ) {

    lines.push(
      '対象データなし'
    );

    return lines.join('\n');
  }


  const classificationIndex =
    findHeaderIndex_(
      data.headers,
      '分類'
    );

  if (
    classificationIndex === -1
  ) {

    throw new Error(
      '「決算速報」に「分類」列がありません。'
    );

  }


  const counts = {};

  const order = [];


  data.rows.forEach(
    function(row) {

      let classification =
        normalizeCellValue_(
          row[classificationIndex]
        );


      /*
       * 空欄・ー・－ は未分類として扱う
       */
      if (
        classification === '' ||
        classification === 'ー' ||
        classification === '－' ||
        classification === '-'
      ) {

        classification =
          '未分類';

      }


      if (
        typeof counts[classification] ===
        'undefined'
      ) {

        counts[classification] = 0;

        order.push(
          classification
        );

      }


      counts[classification]++;

    }
  );


  order.forEach(
    function(classification) {

      lines.push(
        classification +
        counts[classification] +
        '件'
      );

    }
  );


  return lines.join('\n');
}


/**
 * 統合レポート用 決算速報 銘柄別内容
 */
function createEarningsDetailText_(data) {

  const fields = [
    '日付',
    '時刻',
    '証券コード',
    '会社名',
    '速報内容',
    '分類',
    '業種',
    '特色',
    '連結事業',
    '時価総額',
    '上場区分',
    'PER',
    'PBR',
    '利回り'
  ];


  return createTsvTable_(
    data,
    fields
  );
}


/* ==================================================
 * 大量保有速報
 * ================================================== */


/**
 * 区分別件数＋合計
 *
 * 例:
 *
 * 新規	5件
 * 変更	14件
 * 保有減少	11件
 * ーーー
 * 合計	30件
 */
function createLargeHoldingSummary_(data) {

  const lines = [];


  if (
    !data ||
    data.rows.length === 0
  ) {

    lines.push(
      '合計\t0件'
    );

    return lines.join('\n');
  }


  const typeIndex =
    findHeaderIndex_(
      data.headers,
      '区分'
    );

  if (
    typeIndex === -1
  ) {

    throw new Error(
      '「大量保有速報」に「区分」列がありません。'
    );

  }


  const counts = {};

  const order = [];


  data.rows.forEach(
    function(row) {

      let type =
        normalizeCellValue_(
          row[typeIndex]
        );


      if (
        type === '' ||
        type === 'ー' ||
        type === '－' ||
        type === '-'
      ) {

        type =
          '未分類';

      }


      if (
        typeof counts[type] ===
        'undefined'
      ) {

        counts[type] = 0;

        order.push(
          type
        );

      }


      counts[type]++;

    }
  );


  order.forEach(
    function(type) {

      lines.push(
        type +
        '\t' +
        counts[type] +
        '件'
      );

    }
  );


  lines.push(
    'ーーー'
  );


  lines.push(
    '合計\t' +
    data.rows.length +
    '件'
  );


  return lines.join('\n');
}


/**
 * 統合レポート用 大量保有速報 大量保有報告
 */
function createLargeHoldingDetailText_(data) {

  const fields = [
    '日付',
    '区分',
    '報告者',
    '対象者',
    '証券コード',
    '内容',
    '業種',
    '特色',
    '連結事業'
  ];


  return createTsvTable_(
    data,
    fields
  );
}


/* ==================================================
 * 本日の株価動向
 * ================================================== */


/**
 * 種別をブロック見出しとして出力
 *
 * 種別セルが空欄の場合は
 * 直前の種別を引き継ぐ。
 */
function createStockMovementText_(data) {

  const fields = [
    '証券コード',
    '銘柄名',
    '市場',
    '株価',
    '値幅制限',
    '前日比',
    '前日比(%)',
    '基準値/取引量/代金',
    '業種',
    '特色',
    '連結事業'
  ];


  const typeIndex =
    findHeaderIndex_(
      data.headers,
      '種別'
    );


  if (
    typeIndex === -1
  ) {

    throw new Error(
      '「本日の株価動向」に「種別」列がありません。'
    );

  }


  const groups = {};

  const order = [];

  let currentType = '';


  data.rows.forEach(
    function(row) {

      const typeValue =
        normalizeCellValue_(
          row[typeIndex]
        );


      /*
       * 種別値がある場合、
       * その行から新しいブロック開始
       */
      if (
        typeValue !== ''
      ) {

        currentType =
          typeValue;


        if (
          !groups[currentType]
        ) {

          groups[currentType] = [];

          order.push(
            currentType
          );

        }

      }


      /*
       * 種別が1回も登場していない
       * 不正な先頭行は無視
       */
      if (
        currentType === ''
      ) {

        return;

      }


      groups[currentType].push(
        row
      );

    }
  );


  if (
    order.length === 0
  ) {

    return '対象データなし';

  }


  const lines = [];


  order.forEach(
    function(type) {

      lines.push(
        '【' +
        type +
        '】'
      );


      const groupData = {

        headers:
          data.headers,

        rows:
          groups[type]

      };


      lines.push(
        createTsvTable_(
          groupData,
          fields
        )
      );


      lines.push('');

    }
  );


  return lines
    .join('\n')
    .trimEnd();
}


/* ==================================================
 * 適時開示
 * ================================================== */


function createDisclosureText_(data) {

  const fields = [
    'コード',
    '会社名',
    '市場',
    '情報種別',
    'タイトル',
    '開示日時',
    '業種',
    '特色',
    '連結事業'
  ];


  return createTsvTable_(
    data,
    fields
  );
}


/* ==================================================
 * TSV出力
 * ================================================== */


/**
 * 指定した列を
 * タブ区切りの行列形式で出力する。
 *
 * 例:
 *
 * 日付	証券コード	会社名
 * 2026-08-19	1234	○○株式会社
 */
function createTsvTable_(
  data,
  fields
) {

  const lines = [];


  /*
   * ヘッダー
   */
  lines.push(
    fields.join('\t')
  );


  if (
    !data ||
    data.rows.length === 0
  ) {

    return lines.join('\n');

  }


  data.rows.forEach(
    function(row) {

      const values =
        fields.map(
          function(field) {

            let value =
              getValueByHeader_(
                data.headers,
                row,
                field
              );


            /*
             * 空欄
             */
            if (
              value === '' ||
              value === null ||
              typeof value ===
                'undefined'
            ) {

              value = 'ー';

            }


            /*
             * TSVの行列を壊さないように
             * タブ・改行を空白化
             */
            value =
              String(value)
                .replace(
                  /\r\n/g,
                  ' '
                )
                .replace(
                  /\r/g,
                  ' '
                )
                .replace(
                  /\n/g,
                  ' '
                )
                .replace(
                  /\t/g,
                  ' '
                );


            return value;

          }
        );


      lines.push(
        values.join('\t')
      );

    }
  );


  return lines.join('\n');
}


/* ==================================================
 * ■ブロック上書き
 * ================================================== */


/**
 * overlayText内の
 * 「■見出し」単位のブロックを取得し、
 * baseText側の同名ブロックを置換する。
 *
 * base側に存在しないブロックは追加しない。
 */
function overwriteMatchingBlocks_(
  baseText,
  overlayText
) {

  const overlayBlocks =
    extractNamedBlocks_(
      overlayText
    );


  let result =
    normalizeNewlines_(
      baseText
    );


  Object.keys(
    overlayBlocks
  ).forEach(
    function(blockName) {

      result =
        replaceNamedBlock_(
          result,
          blockName,
          overlayBlocks[blockName]
        );

    }
  );


  return result;
}


/**
 * 「■」で始まる見出し単位に
 * ブロックを抽出する。
 */
function extractNamedBlocks_(text) {

  const lines =
    normalizeNewlines_(
      text
    ).split('\n');


  const blocks = {};

  let currentName = null;

  let currentLines = [];


  function flush() {

    if (
      currentName !== null
    ) {

      blocks[currentName] =
        currentLines
          .join('\n')
          .trimEnd();

    }

  }


  lines.forEach(
    function(line) {

      if (
        /^■/.test(
          line.trim()
        )
      ) {

        flush();


        currentName =
          line.trim();


        currentLines = [
          line
        ];

      } else if (
        currentName !== null
      ) {

        currentLines.push(
          line
        );

      }

    }
  );


  flush();


  return blocks;
}


/**
 * baseText内の指定ブロックを置換する。
 */
function replaceNamedBlock_(
  text,
  blockName,
  replacement
) {

  const lines =
    normalizeNewlines_(
      text
    ).split('\n');


  let start = -1;

  let end =
    lines.length;


  /*
   * 対象見出し検索
   */
  for (
    let i = 0;
    i < lines.length;
    i++
  ) {

    if (
      lines[i].trim() ===
      blockName
    ) {

      start = i;

      break;

    }

  }


  /*
   * base側に存在しないなら
   * 何もしない
   */
  if (
    start === -1
  ) {

    return text;

  }


  /*
   * 次の■見出しを探す
   */
  for (
    let i = start + 1;
    i < lines.length;
    i++
  ) {

    if (
      /^■/.test(
        lines[i].trim()
      )
    ) {

      end = i;

      break;

    }

  }


  const before =
    lines.slice(
      0,
      start
    );


  const after =
    lines.slice(
      end
    );


  const replacementLines =
    normalizeNewlines_(
      replacement
    ).split('\n');


  return before
    .concat(
      replacementLines
    )
    .concat(
      after
    )
    .join('\n');
}


/* ==================================================
 * Driveフォルダ
 * ================================================== */


/**
 * 出力結果フォルダ取得
 */
function getOutputFolder_() {

  let folder =
    DriveApp.getRootFolder();


  CONFIG
    .OUTPUT_FOLDER_PATH
    .forEach(
      function(folderName) {

        const folders =
          folder.getFoldersByName(
            folderName
          );


        if (
          !folders.hasNext()
        ) {

          throw new Error(
            'フォルダが見つかりません。\n\n' +
            '対象フォルダ: ' +
            folderName +
            '\n\nパス:\n' +
            CONFIG
              .OUTPUT_FOLDER_PATH
              .join(' > ')
          );

        }


        folder =
          folders.next();

      }
    );


  return folder;
}

/**
 * マスタフォルダ取得
 *
 * マイドライブ
 *   └ 投資
 *      └ プログラミング
 *         └ GAS
 *            └ マスタ
 */
function getMasterFolder_() {

  let folder =
    DriveApp.getRootFolder();


  CONFIG
    .MASTER_FOLDER_PATH
    .forEach(
      function(folderName) {

        const folders =
          folder.getFoldersByName(
            folderName
          );


        if (
          !folders.hasNext()
        ) {

          throw new Error(
            'マスタフォルダが見つかりません。\n\n' +
            '対象フォルダ: ' +
            folderName +
            '\n\nパス:\n' +
            CONFIG
              .MASTER_FOLDER_PATH
              .join(' > ')
          );

        }


        folder =
          folders.next();

      }
    );


  return folder;
}

/**
 * 定点観測備考マスタ読み込み
 *
 * Googleスプレッドシート:
 *
 * ブロック名 | 備考
 *
 * を読み込み、
 *
 * {
 *   '■信用残・評価損益': '...',
 *   '■日経225バリュエーション': '...',
 *   ...
 * }
 *
 * の形式で返す。
 *
 * 備考が空欄の行は登録しない。
 */
function readObservationNoteMaster_() {

  const folder =
    getMasterFolder_();


  const file =
    findGoogleSpreadsheet_(
      folder,
      CONFIG.OBSERVATION_NOTE_MASTER_NAME
    );


  if (
    !file
  ) {

    throw new Error(
      'Googleスプレッドシートが見つかりません。\n\n' +
      CONFIG.OBSERVATION_NOTE_MASTER_NAME
    );

  }


  const ss =
    SpreadsheetApp.openById(
      file.getId()
    );


  const sheets =
    ss.getSheets();


  if (
    sheets.length === 0
  ) {

    return {};

  }


  const sheet =
    sheets[0];


  const lastRow =
    sheet.getLastRow();


  const lastColumn =
    sheet.getLastColumn();


  if (
    lastRow < 2 ||
    lastColumn === 0
  ) {

    return {};

  }


  const values =
    sheet
      .getRange(
        1,
        1,
        lastRow,
        lastColumn
      )
      .getDisplayValues();


  const headers =
    values[0];


  const blockNameIndex =
    findHeaderIndex_(
      headers,
      'ブロック名'
    );


  const noteIndex =
    findHeaderIndex_(
      headers,
      '備考'
    );


  if (
    blockNameIndex === -1
  ) {

    throw new Error(
      '「定点観測備考マスタ」に「ブロック名」列がありません。'
    );

  }


  if (
    noteIndex === -1
  ) {

    throw new Error(
      '「定点観測備考マスタ」に「備考」列がありません。'
    );

  }


  const notes = {};


  values
    .slice(1)
    .forEach(
      function(row) {

        const blockName =
          normalizeCellValue_(
            row[blockNameIndex]
          );


        const note =
          String(
            row[noteIndex] || ''
          )
            .replace(/\r\n/g, '\n')
            .replace(/\r/g, '\n');


        /*
         * ブロック名なし / 備考なし
         * は登録しない。
         */
        if (
          blockName === '' ||
          note.trim() === ''
        ) {

          return;

        }


        notes[blockName] =
          note;

      }
    );


  return notes;
}

/* ==================================================
 * TXTファイル読み込み
 * ================================================== */


/**
 * 必須TXT
 */
function readRequiredTextFileByBaseName_(
  folder,
  baseName
) {

  const file =
    findFileByNames_(
      folder,
      [
        baseName,
        baseName + '.txt'
      ]
    );


  if (
    !file
  ) {

    throw new Error(
      '必要なTXTファイルが見つかりません。\n\n' +
      baseName +
      '.txt'
    );

  }


  return file
    .getBlob()
    .getDataAsString(
      'UTF-8'
    );
}


/**
 * 任意TXT
 *
 * なければ null
 */
function readOptionalTextFileByBaseName_(
  folder,
  baseName
) {

  const file =
    findFileByNames_(
      folder,
      [
        baseName,
        baseName + '.txt'
      ]
    );


  if (
    !file
  ) {

    return null;

  }


  return file
    .getBlob()
    .getDataAsString(
      'UTF-8'
    );
}


/* ==================================================
 * Google Spreadsheet
 * ================================================== */


/**
 * Googleスプレッドシート読み込み
 *
 * ・ファイル名完全一致
 * ・MIMEがGoogle Spreadsheet
 * ・最初のシートを使用
 * ・1行目をヘッダー
 * ・2行目以降をデータ
 * ・getDisplayValues()を使用
 */
function readGoogleSpreadsheet_(
  folder,
  baseName
) {

  const file =
    findGoogleSpreadsheet_(
      folder,
      baseName
    );


  if (
    !file
  ) {

    throw new Error(
      'Googleスプレッドシートが見つかりません。\n\n' +
      baseName
    );

  }


  const ss =
    SpreadsheetApp.openById(
      file.getId()
    );


  const sheets =
    ss.getSheets();


  if (
    sheets.length === 0
  ) {

    return {
      headers: [],
      rows: []
    };

  }


  const sheet =
    sheets[0];


  const lastRow =
    sheet.getLastRow();


  const lastColumn =
    sheet.getLastColumn();


  if (
    lastRow === 0 ||
    lastColumn === 0
  ) {

    return {
      headers: [],
      rows: []
    };

  }


  /*
   * 表示値を取得することで
   *
   * 2026-08-19
   * 16:30
   * 3,006.28
   * +12.3%
   *
   * 等を画面表示どおり取得する。
   */
  const values =
    sheet
      .getRange(
        1,
        1,
        lastRow,
        lastColumn
      )
      .getDisplayValues();


  return matrixToTable_(
    values
  );
}


/**
 * Google Spreadsheetを
 * ファイル名から検索
 */
function findGoogleSpreadsheet_(
  folder,
  baseName
) {

  const files =
    folder.getFilesByName(
      baseName
    );


  const found = [];


  while (
    files.hasNext()
  ) {

    const file =
      files.next();


    if (
      file.getMimeType() ===
      MimeType.GOOGLE_SHEETS
    ) {

      found.push(
        file
      );

    }

  }


  if (
    found.length === 0
  ) {

    return null;

  }


  /*
   * 同名が複数ある場合は
   * 更新日時が新しいもの
   */
  found.sort(
    function(a, b) {

      return (
        b
          .getLastUpdated()
          .getTime() -
        a
          .getLastUpdated()
          .getTime()
      );

    }
  );


  return found[0];
}


/* ==================================================
 * Driveファイル検索
 * ================================================== */


/**
 * 複数候補名からファイル検索
 *
 * 同名が複数ある場合は更新日時が最新。
 */
function findFileByNames_(
  folder,
  names
) {

  const found = [];


  names.forEach(
    function(name) {

      const files =
        folder.getFilesByName(
          name
        );


      while (
        files.hasNext()
      ) {

        found.push(
          files.next()
        );

      }

    }
  );


  if (
    found.length === 0
  ) {

    return null;

  }


  found.sort(
    function(a, b) {

      return (
        b
          .getLastUpdated()
          .getTime() -
        a
          .getLastUpdated()
          .getTime()
      );

    }
  );


  return found[0];
}


/* ==================================================
 * 表データ
 * ================================================== */


/**
 * 2次元配列を
 *
 * {
 *   headers: [],
 *   rows: []
 * }
 *
 * に変換。
 */
function matrixToTable_(values) {

  if (
    !values ||
    values.length === 0
  ) {

    return {
      headers: [],
      rows: []
    };

  }


  const headers =
    values[0].map(
      function(value) {

        return normalizeCellValue_(
          value
        );

      }
    );


  /*
   * 完全空行を除外
   */
  const rows =
    values
      .slice(1)
      .filter(
        function(row) {

          return row.some(
            function(value) {

              return (
                normalizeCellValue_(
                  value
                ) !== ''
              );

            }
          );

        }
      );


  return {
    headers: headers,
    rows: rows
  };
}


/* ==================================================
 * ヘッダー・セル
 * ================================================== */


/**
 * ヘッダー名から値取得
 */
function getValueByHeader_(
  headers,
  row,
  headerName
) {

  const index =
    findHeaderIndex_(
      headers,
      headerName
    );


  if (
    index === -1
  ) {

    return '';

  }


  return normalizeCellValue_(
    row[index]
  );
}


/**
 * ヘッダー位置取得
 */
function findHeaderIndex_(
  headers,
  headerName
) {

  const target =
    normalizeHeader_(
      headerName
    );


  for (
    let i = 0;
    i < headers.length;
    i++
  ) {

    if (
      normalizeHeader_(
        headers[i]
      ) ===
      target
    ) {

      return i;

    }

  }


  return -1;
}


/**
 * ヘッダー比較用正規化
 */
function normalizeHeader_(value) {

  return String(
    value || ''
  )
    .trim()
    .replace(
      /\s+/g,
      ''
    )
    .replace(
      /％/g,
      '%'
    )
    .replace(
      /（/g,
      '('
    )
    .replace(
      /）/g,
      ')'
    );
}


/**
 * セル値正規化
 */
function normalizeCellValue_(value) {

  if (
    value === null ||
    typeof value ===
      'undefined'
  ) {

    return '';

  }


  return String(
    value
  ).trim();
}


/* ==================================================
 * 日付
 * ================================================== */


/**
 * YYYY-MM-DD検証
 */
function validateDate_(dateString) {

  const value =
    String(
      dateString || ''
    );


  if (
    !/^\d{4}-\d{2}-\d{2}$/.test(
      value
    )
  ) {

    throw new Error(
      '日付は YYYY-MM-DD 形式で入力してください。'
    );

  }


  const parts =
    value
      .split('-')
      .map(Number);


  const date =
    new Date(
      parts[0],
      parts[1] - 1,
      parts[2],
      12,
      0,
      0
    );


  if (
    date.getFullYear() !==
      parts[0] ||
    date.getMonth() !==
      parts[1] - 1 ||
    date.getDate() !==
      parts[2]
  ) {

    throw new Error(
      '存在しない日付です。'
    );

  }
}


/**
 * YYYY-MM-DDへ日数加算
 */
function addDays_(
  dateString,
  days
) {

  const parts =
    dateString
      .split('-')
      .map(Number);


  const date =
    new Date(
      parts[0],
      parts[1] - 1,
      parts[2],
      12,
      0,
      0
    );


  date.setDate(
    date.getDate() +
    days
  );


  return Utilities.formatDate(
    date,
    CONFIG.TIME_ZONE,
    'yyyy-MM-dd'
  );
}


/* ==================================================
 * テキスト共通
 * ================================================== */


/**
 * 改行コード統一
 */
function normalizeNewlines_(text) {

  return String(
    text || ''
  )
    .replace(
      /\r\n/g,
      '\n'
    )
    .replace(
      /\r/g,
      '\n'
    );
}


/**
 * ブロック連結
 *
 * ブロック間は空行1つ。
 */
function joinTextBlocks_(blocks) {

  return blocks
    .filter(
      function(block) {

        return (
          block !== null &&
          typeof block !==
            'undefined' &&
          String(block).trim() !== ''
        );

      }
    )
    .map(
      function(block) {

        return String(
          block
        ).trim();

      }
    )
    .join('\n\n');
}
/**
 * レポート内の「■」ブロックを
 * CONFIG.REPORT_BLOCK_ORDER の順番に並べ替える。
 *
 * ・指定されたブロックが存在しない場合はスキップ
 * ・指定されていないブロックは元の順番のまま末尾へ配置
 * ・「■」より前にテキストが存在する場合も末尾へ残す
 */
function reorderReportBlocks_(text) {

  const lines =
    normalizeNewlines_(
      text
    ).split('\n');


  const blocks = [];

  let currentName = null;

  let currentLines = [];


  function flush() {

    if (
      currentLines.length === 0
    ) {

      return;

    }


    const blockText =
      currentLines
        .join('\n')
        .trim();


    if (
      blockText === ''
    ) {

      currentLines = [];

      return;

    }


    blocks.push({
      name: currentName,
      text: blockText
    });


    currentLines = [];

  }


  lines.forEach(
    function(line) {

      if (
        /^■/.test(
          line.trim()
        )
      ) {

        flush();

        currentName =
          line.trim();

        currentLines = [
          line
        ];

      } else {

        currentLines.push(
          line
        );

      }

    }
  );


  flush();


  const orderedBlocks = [];

  const usedIndexes = {};


  /*
   * 指定された順番でブロックを追加
   */
  CONFIG
    .REPORT_BLOCK_ORDER
    .forEach(
      function(blockName) {

        blocks.forEach(
          function(block, index) {

            if (
              block.name === blockName
            ) {

              orderedBlocks.push(
                block.text
              );

              usedIndexes[index] = true;

            }

          }
        );

      }
    );


  /*
   * 並び順に指定されていないブロックは
   * 消さずに元の順番のまま末尾へ追加
   */
  blocks.forEach(
    function(block, index) {

      if (
        !usedIndexes[index]
      ) {

        orderedBlocks.push(
          block.text
        );

      }

    }
  );


  return orderedBlocks
    .join('\n\n');
}

/* ==================================================
 * WordPress貼り付け用HTML
 * ================================================== */


/**
 * 通常レポートをWordPress貼り付け用HTMLへ変換する。
 *
 * ・■見出し       → h2
 * ・【見出し】     → h3
 * ・タブ区切り表   → table
 * ・通常テキスト   → report-text
 * ・空行           → ブロック区切り
 *
 * WBレポート固有の表示調整もここで行う。
 */
function convertReportToWordPressHtml_(
  text,
  blockNotes
) {

  blockNotes =
    blockNotes || {};

  const lines =
    normalizeNewlines_(
      text
    ).split('\n');


  const output = [];

  let tableLines = [];

  /*
   * 現在処理中の■ブロック名
   */
  let currentBlockName = '';

  /*
   * 現在処理中の【小見出し】
   */
  let currentSubheading = '';

  /*
   * 直前に出力したh2の位置。
   * 見出し直後に更新日時が来た場合、
   * h2内へ取り込むために使用。
   */
  let lastH2OutputIndex = -1;

  /*
   * h2直後で、まだ日時を取り込める状態か。
   */
  let canAttachDatetimeToH2 = false;

  /*
   * 現在のh2表示名
   */
  let currentH2DisplayName = '';

  /*
   * タイトル右側へ表示する補足情報
   *
   * 例:
   * 単位: 億円、％
   * 更新日時: 2026-08-20
   */
  let titleMetaTexts = [];

  /*
   * WB表示上だけ変更するブロック名
   *
   * 元のWEBレポートやREPORT_BLOCK_ORDERは変更しない。
   */
  const blockDisplayNames = {

    '世界の株価リアルタイム':
      '世界の株価',

    'JPXホーム':
      '東証 売買高・売買代金',

    'JPXプログラム売買の状況（週間）：裁定取引に係る現物ポジション':
      'JPXプログラム売買の状況（週間）',

    'JPX裁定取引の状況（日別）：裁定取引に係る現物ポジション':
      'JPX裁定取引の状況（日別）',

    '株探テーマアクセスランキング':
      '人気テーマ',

    'FRB総資産：Assets: Total Assets: Total Assets (Less Eliminations from Consolidation): Wednesday Level (WALCL)':
      'FRB総資産',

    '米国ハイイールドスプレッド：ICE BofA US High Yield Index Option-Adjusted Spread (BAMLH0A0HYM2)':
      '米国ハイイールドスプレッド',

    '米国社債スプレッド：ICE BofA US Corporate Index Option-Adjusted Spread (BAMLC0A0CM)':
      '米国社債スプレッド',

    'GDPNow：Federal Reserve Bank of Atlanta':
      'GDPNow'

  };

  /*
   * WB表示上だけ変更する小見出し名
   * 以下のように、追加する。
   * '変換対象の文字列':'変換後の文字列'
   */
  const subheadingDisplayNames = {};

  /**
   * WB表示用文字列変換
   */
  function convertDisplayText(value) {

    return String(
      value || ''
    )
      .replace(
        /日本225/g,
        '日経225'
      );
  }
  
    /**
   * WB表示用の表ヘッダー変換
   */
  function convertTableHeaderText(
    value
  ) {

    const header =
      String(
        value || ''
      ).trim();

    /*
     * ■日経225バリュエーション
     *
     * 【指数ベース】【加重平均】とも
     * 同じヘッダー名へ変換する。
     */
    if (
      currentBlockName ===
        '日経225バリュエーション'
    ) {

      const map = {

        '日経225PER':
          'PER',

        '日経225PBR':
          'PBR',

        '日経225EPS':
          'EPS',

        '日経225BPS':
          'BPS',

        '日経225益回り':
          '益回り',

        '日経225配当利回り':
          '配当利回り',

        '日本国債利回り':
          '日本国債利回り',

        '日本株225':
          '日経平均',

        '日本株225(変化)':
          '前日比',

        '日経平均':
          '日経平均',

        '前日比':
          '前日比',

        'プライム出来高(百万株)':
          'プライム出来高(百万株)'

      };


      return (
        map[header] ||
        header
      );

    }
    
    
    /*
     * ■米国株バリュエーション
     *
     * 【予想PER】【実績PER】とも
     * 同じヘッダー名へ変換する。
     */
    if (
      currentBlockName ===
        '米国株バリュエーション'
    ) {

      const map = {

        '日経225株価':
          '日経225',

        '日経225PER':
          'PER',

        '日経225配当利回り':
          'DY',

        'DOW30株価':
          'DOW30',

        'DOW30PER':
          'PER',

        'DOW30配当利回り':
          'DY',

        'S&P500株価':
          'S&P500',

        'S&P500PER':
          'PER',

        'S&P500配当利回り':
          'DY',

        'NASDAQ100株価':
          'NASDAQ100',

        'NASDAQ100PER':
          'PER',

        'NASDAQ100配当利回り':
          'DY',

        'Russell2000株価':
          'Russell2000',

        'Russell2000PER':
          'PER',

        'Russell2000配当利回り':
          'DY'

      };


      return (
        map[header] ||
        header
      );

    }
    
    /*
     * ■騰落レシオ
     */
    if (
      currentBlockName ===
        '騰落レシオ'
    ) {

      const map = {

        '騰落レシオ(25日)':
          '25日',

        '騰落レシオ(15日)':
          '15日',

        '騰落レシオ(10日)':
          '10日',

        '騰落レシオ(6日)':
          '6日'

      };


      return (
        map[header] ||
        header
      );

    }


    return header;
  }

  /**
   * 通常テキスト出力
   */
  function pushNormalText(value) {

    output.push(
      '<div class="report-text">' +
      escapeHtml_(
        convertDisplayText(
          value
        )
      ) +
      '</div>'
    );
  }

  /**
   * 現在の■ブロックに対応する備考を出力する。
   *
   * 備考マスタにブロック名がない、
   * または備考が空なら何もしない。
   *
   * マスタ側は「■」付きブロック名。
   */
  function pushCurrentBlockNote() {

    if (
      currentBlockName === ''
    ) {

      return;

    }


    const masterBlockName =
      '■' +
      currentBlockName;


    const note =
      blockNotes[
        masterBlockName
      ];


    if (
      typeof note === 'undefined' ||
      String(note).trim() === ''
    ) {

      return;

    }


    output.push(
      '<div class="report-block-note">' +
      escapeHtml_(
        note
      ) +
      '</div>'
    );
  }

  /*
   * 左揃えにする列
   *
   * このタイトルを持つ列は、
   * タイトル・値とも左揃え。
   */
  const tableLeftAlignHeaders =
    new Set([
      '日付',
      '日時',
      '名称',
      '更新日',
      '市場区分',
      '銘柄名',
      '業種',
      'テーマ',
      '国・地域',
      '項目',
      '種類',
      'スタート日(注2)',
      'エンド日(注2)',
      '推定期間',
      '国',
      '期間',
      '指標名'
    ]);


  /*
   * 中央揃えにする列
   *
   * タイトル・値とも中央揃え。
   */
  const tableCenterAlignHeaders =
    new Set([
      '順位',
      '方向',
      '連続日数',
      '参考値',
      '評価',
      '重要度'
    ]);


  /**
   * 表ヘッダーの揃え方
   *
   * 原則中央。
   * 左揃え指定列だけ左。
   */
  function getTableHeaderAlignClass(
    header
  ) {

    const value =
      String(
        header || ''
      ).trim();


    if (
      tableLeftAlignHeaders.has(
        value
      )
    ) {

      return 'report-align-left';

    }


    return 'report-align-center';
  }


  /**
   * 表データの揃え方
   *
   * 原則右。
   *
   * 左揃え指定列 → 左
   * 中央揃え指定列 → 中央
   */
  function getTableValueAlignClass(
    header
  ) {

    const value =
      String(
        header || ''
      ).trim();


    if (
      tableLeftAlignHeaders.has(
        value
      )
    ) {

      return 'report-align-left';

    }


    if (
      tableCenterAlignHeaders.has(
        value
      )
    ) {

      return 'report-align-center';

    }


    return 'report-align-right';
  }


  /**
   * 通常のHTMLテーブル出力
   */
  function pushTable(
    rows,
    options
  ) {

    options =
      options || {};


    const noHeader =
      !!options.noHeader;


    const compact =
      !!options.compact;

    const allLeft =
      !!options.allLeft;

    output.push(
      '<div class="report-table-wrap' +
      (
        compact
          ? ' report-table-wrap-compact'
          : ''
      ) +
      '">'
    );


    output.push(
      '<table class="report-table">'
    );


    /*
     * 通常表は1行目をヘッダーとする。
     *
     * 日経平均想定値だけは
     * すべてデータ行として出力する。
     */
    if (
      !noHeader &&
      rows.length > 0
    ) {

      output.push(
        '<thead>'
      );

      output.push(
        '<tr>'
      );


      rows[0].forEach(
        function(cell) {

          const alignClass =
            allLeft
              ? 'report-align-left'
              : getTableHeaderAlignClass(
                  cell
                );


          output.push(
            '<th class="' +
            alignClass +
            '">' +
            escapeHtml_(
              convertTableHeaderText(
                convertDisplayText(
                  cell
                )
              )
            ) +
            '</th>'
          );

        }
      );


      output.push(
        '</tr>'
      );

      output.push(
        '</thead>'
      );

    }


    const bodyRows =
      noHeader
        ? rows
        : rows.slice(1);


    if (
      bodyRows.length > 0
    ) {

      output.push(
        '<tbody>'
      );


      bodyRows.forEach(
        function(row) {

          output.push(
            '<tr>'
          );


          row.forEach(
            function(cell, columnIndex) {

              /*
               * noHeader表にはヘッダーがないので、
               * allLeft指定以外は右揃え。
               */
              let alignClass =
                'report-align-right';


              if (
                allLeft
              ) {

                alignClass =
                  'report-align-left';

              } else if (
                !noHeader &&
                rows.length > 0 &&
                columnIndex <
                  rows[0].length
              ) {

                alignClass =
                  getTableValueAlignClass(
                    rows[0][columnIndex]
                  );

              }


              output.push(
                '<td class="' +
                alignClass +
                '">' +
                escapeHtml_(
                  convertDisplayText(
                    cell.trim()
                  )
                ) +
                '</td>'
              );

            }
          );


          output.push(
            '</tr>'
          );

        }
      );


      output.push(
        '</tbody>'
      );

    }


    output.push(
      '</table>'
    );

    output.push(
      '</div>'
    );
  }


  /**
   * 株探テーマアクセスランキング
   *
   * WB表示では、
   *
   * 順位 / テーマ
   *
   * のみ表示し、1～3位だけ残す。
   */
  function pushPopularThemeTable(
    rows
  ) {

    if (
      rows.length === 0
    ) {

      return;

    }


    const header =
      rows[0].map(
        function(cell) {

          return cell.trim();

        }
      );


    const rankIndex =
      header.indexOf(
        '順位'
      );


    const themeIndex =
      header.indexOf(
        'テーマ'
      );


    /*
     * 想定した列がなければ、
     * データ欠落を避けるため通常表として出力。
     */
    if (
      rankIndex === -1 ||
      themeIndex === -1
    ) {

      pushTable(
        rows
      );

      return;

    }


    const filteredRows = [
      [
        '順位',
        'テーマ'
      ]
    ];


    rows
      .slice(1)
      .forEach(
        function(row) {

          const rank =
            parseInt(
              String(
                row[rankIndex] || ''
              ).trim(),
              10
            );


          if (
            rank >= 1 &&
            rank <= 3
          ) {

            filteredRows.push(
              [
                row[rankIndex] || '',
                row[themeIndex] || ''
              ]
            );

          }

        }
      );


    pushTable(
      filteredRows
    );
  }


  /**
   * 貯めたTSV行をHTMLへ変換
   */
  function flushTable() {

    if (
      tableLines.length === 0
    ) {

      return;

    }


    const rows =
      tableLines.map(
        function(line) {

          return line.split('\t');

        }
      );


    /*
     * ------------------------------------------------
     * 大量保有速報 区分サマリ
     *
     * 表にしない。
     * ------------------------------------------------
     */
    if (
      currentBlockName ===
      '大量保有速報 区分サマリ'
    ) {

      tableLines.forEach(
        function(line) {

          pushNormalText(
            line
              .split('\t')
              .join(' ')
          );

        }
      );


      tableLines = [];

      return;

    }


    /*
     * ------------------------------------------------
     * 人気テーマ
     *
     * ・順位
     * ・テーマ
     *
     * のみ。
     * 1～3位まで。
     * ------------------------------------------------
     */
    if (
      currentBlockName ===
      '株探テーマアクセスランキング'
    ) {

      pushPopularThemeTable(
        rows
      );


      tableLines = [];

      return;

    }


    /*
     * ------------------------------------------------
     * 日経平均想定値
     *
     * 1行目もタイトルではなくデータとして扱う。
     * 表間の余白も小さくする。
     * ------------------------------------------------
     */
    if (
      currentSubheading ===
      '日経平均想定値'
    ) {

      pushTable(
        rows,
        {
          noHeader: true,
          compact: true,
          allLeft: true
        }
      );


      tableLines = [];

      return;

    }

    /*
     * ------------------------------------------------
     * 世界バフェット指数
     *
     * ・1行目もタイトルではない
     * ・世界時価総額
     * ・世界予想名目GDP
     * ・世界バフェット指数
     *
     * をすべて通常データとして扱う。
     * ・全セル左揃え
     * ------------------------------------------------
     */
    if (
      currentBlockName ===
      '世界バフェット指数'
    ) {

      pushTable(
        rows,
        {
          noHeader: true,
          allLeft: true
        }
      );


      tableLines = [];

      return;

    }


    /*
     * ------------------------------------------------
     * タブ行が1行しかない場合は
     * 通常テキストとして扱う。
     * ------------------------------------------------
     */
    if (
      tableLines.length === 1
    ) {

      pushNormalText(
        tableLines[0]
          .split('\t')
          .join(' ')
      );


      tableLines = [];

      return;

    }


    /*
     * 通常表
     */
    pushTable(
      rows
    );


    tableLines = [];

  }


  lines.forEach(
    function(line) {

      const trimmed =
        line.trim();


      /*
       * タブ区切り行
       */
      if (
        line.indexOf('\t') !== -1
      ) {

        canAttachDatetimeToH2 = false;

        tableLines.push(
          line
        );

        return;

      }


      /*
       * 表終了
       */
      flushTable();


      /*
       * 空行
       */
      if (
        trimmed === ''
      ) {

        return;

      }


      /*
       * ------------------------------------------------
       * ■大見出し
       * ------------------------------------------------
       */
      if (
        /^■/.test(trimmed)
      ) {

        /*
         * 前のブロックが終了するので、
         * 前ブロックの備考を末尾へ追加。
         */
        pushCurrentBlockNote();


        currentBlockName =
          trimmed.substring(1);

        currentSubheading = '';

        titleMetaTexts = [];

        currentH2DisplayName =
          blockDisplayNames[
            currentBlockName
          ] ||
          currentBlockName;


        currentH2DisplayName =
          convertDisplayText(
            currentH2DisplayName
          );


        output.push(
          '<div class="report-title-row">' +
            '<h2 class="report-title">' +
              escapeHtml_(
                currentH2DisplayName
              ) +
            '</h2>' +
          '</div>'
        );


        lastH2OutputIndex =
          output.length - 1;

        canAttachDatetimeToH2 =
          true;


        return;

      }


      /*
       * ------------------------------------------------
       * 【小見出し】
       * ------------------------------------------------
       */
      if (
        /^【.*】$/.test(trimmed)
      ) {

        currentSubheading =
          trimmed
            .replace(
              /^【/,
              ''
            )
            .replace(
              /】$/,
              ''
            );

        canAttachDatetimeToH2 =
          false;

        const displaySubheading =
          subheadingDisplayNames[
            currentSubheading
          ] ||
          currentSubheading;


        output.push(
          '<h3>' +
          escapeHtml_(
            convertDisplayText(
              displaySubheading
            )
          ) +
          '</h3>'
        );



        return;

      }

      /*
       * ------------------------------------------------
       * 大見出し直後の補足情報
       *
       * 例:
       * データ処理日時: 2026-08-21 07:01
       * 更新日時: 2026-08-20
       * 単位: 億円、％
       *
       * 通常はタイトル右側へ1行表示。
       *
       * 「日銀オペレーション・オファー／落札結果」
       * については、
       *
       * 更新日時: 2026-08-20
       * 単位: 億円、％
       *
       * の2行をタイトル右側へ表示する。
       * ------------------------------------------------
       */
      if (
        canAttachDatetimeToH2 &&
        /^(データ処理日時|更新日時|単位)\s*[:：]/.test(
          trimmed
        )
      ) {

        titleMetaTexts.push(
          convertDisplayText(
            trimmed
          )
        );


        /*
         * 日銀オペレーションだけは、
         * 表示順を
         *
         * 更新日時
         * 単位
         *
         * に固定する。
         */
        if (
          currentBlockName ===
          '日銀オペレーション・オファー／落札結果'
        ) {

          titleMetaTexts.sort(
            function(a, b) {

              function priority(value) {

                if (
                  /^更新日時\s*[:：]/.test(value)
                ) {
                  return 1;
                }

                if (
                  /^単位\s*[:：]/.test(value)
                ) {
                  return 2;
                }

                return 3;
              }


              return (
                priority(a) -
                priority(b)
              );
            }
          );

        }


        /*
         * HTML生成
         *
         * 日銀オペレーションでは
         * <br>で2行表示。
         *
         * その他は従来どおり1行。
         */
        const titleMetaHtml =
          currentBlockName ===
          '日銀オペレーション・オファー／落札結果'
            ?
              titleMetaTexts
                .map(
                  function(value) {

                    return escapeHtml_(
                      value
                    );

                  }
                )
                .join('<br>')
            :
              escapeHtml_(
                titleMetaTexts.join('　')
              );


        output[
          lastH2OutputIndex
        ] =
          '<div class="report-title-row">' +
            '<h2 class="report-title">' +
              escapeHtml_(
                currentH2DisplayName
              ) +
            '</h2>' +
            '<div class="report-title-datetime">' +
              titleMetaHtml +
            '</div>' +
          '</div>';


        /*
         * 通常ブロック:
         * 更新日時・データ処理日時を取得したら終了。
         *
         * 日銀オペレーション:
         * 更新日時を取得しても終了せず、
         * 続く「単位」も取得できるようにする。
         */
        if (
          currentBlockName !==
            '日銀オペレーション・オファー／落札結果' &&
          /^(データ処理日時|更新日時)\s*[:：]/.test(
            trimmed
          )
        ) {

          canAttachDatetimeToH2 =
            false;

        }


        /*
         * 日銀オペレーションは
         * 「更新日時」と「単位」の両方を取得したら終了。
         */
        if (
          currentBlockName ===
            '日銀オペレーション・オファー／落札結果'
        ) {

          const hasUpdated =
            titleMetaTexts.some(
              function(value) {

                return (
                  /^更新日時\s*[:：]/.test(
                    value
                  )
                );

              }
            );


          const hasUnit =
            titleMetaTexts.some(
              function(value) {

                return (
                  /^単位\s*[:：]/.test(
                    value
                  )
                );

              }
            );


          if (
            hasUpdated &&
            hasUnit
          ) {

            canAttachDatetimeToH2 =
              false;

          }

        }


        return;
      }

      /*
       * ------------------------------------------------
       * 通常行
       *
       * ・データ処理日時
       * ・注記
       * ・※
       * ・決算分類サマリ
       * などすべて同じreport-textサイズ。
       * ------------------------------------------------
       */
      canAttachDatetimeToH2 =
        false;
      
      pushNormalText(
        line
      );

    }
  );


  /*
   * 最終行が表だった場合
   */
  flushTable();

  /*
   * 最後の■ブロックの備考
   */
  pushCurrentBlockNote();

  return [
    '<div class="market-report">',
    '',
    output.join('\n'),
    '',
    '</div>'
  ].join('\n');
}


/**
 * HTML特殊文字エスケープ
 */
function escapeHtml_(value) {

  return String(
    value || ''
  )
    .replace(
      /&/g,
      '&amp;'
    )
    .replace(
      /</g,
      '&lt;'
    )
    .replace(
      />/g,
      '&gt;'
    )
    .replace(
      /"/g,
      '&quot;'
    )
    .replace(
      /'/g,
      '&#39;'
    );
}