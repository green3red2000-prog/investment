/**
 * 出力結果フォルダ内の「*_メッセージ_yyyy-MM-dd.txt」を検出し、
 * 対応するスプレッドシート整形 → メール送信 → コピー → メッセージファイル削除 を行う。
 *
 * 5分ごとのトリガー起動想定。
 */
function postProcess_messages_5minTrigger() {
  const CONFIG = {
    // フォルダ階層
    srcFolderPath: ['投資', 'プログラミング', 'GAS', 'スクレイピング', '出力結果'],
    dstFolderPath: ['投資', 'プログラミング', 'GAS', 'スクレイピング', '出力結果', '基本情報付加'],

    // ★追加：マスタ更新に使うフォルダ/ファイル
    masterFolderPath: ['投資', 'プログラミング', 'GAS', 'マスタ'],
    baseInfoMasterName: '全銘柄基本情報マスタ',
    analysisMasterName: '全銘柄日足分析マスタ',
    securityCodeMasterName: '証券コードマスタ',
    calendarMasterName: 'カレンダーマスタ',
    economicScheduleMasterName: '経済スケジュールマスタ',

    // ★追加：本日のニュース（最新）
    todaysNewsLatestName: '本日のニュース_最新',
    todaysNewsPropKey: 'todays_news_last_run_date', // ScriptProperties
    todaysNewsKeepDays: 8, // 8日前より古い行を削除（= 今日-8日より前を削除）
    todaysNewsTimeHeader: '時刻',
    todaysNewsDefaultSheetName: 'シート1',

    // ★追加：完了ファイル出力先
    completeFolderPath: ['投資', 'プログラミング', 'GAS', 'バッチ処理', '状態管理'],

    // メール
    mailTo: 'green3red2000@gmail.com',

    // 対象メッセージファイル（複数ヒットは全件処理）
    messagePrefixes: [
      'PTS＆朝刊ニュース_メッセージ_',
      '決算速報_メッセージ_',
      '適時開示_メッセージ_',
      '本日の株価動向_メッセージ_',
      '全銘柄基本情報取得_メッセージ_',
      '全銘柄四季報情報取得_メッセージ_',
      '証券コード取得_メッセージ_',
      'カレンダー取得_メッセージ_',
      '四季報情報更新監視_メッセージ_',
      '指数日足取得_メッセージ_',
      '全銘柄日足取得_メッセージ_',
      '全銘柄日足分析_メッセージ_',
      '大量保有速報_メッセージ_',
      'ブロックIP集計_メッセージ_',
      '市況関連データ抽出_ALL_メッセージ_',
      '市況関連データ抽出_GROUP1_メッセージ_',
      '市況関連データ抽出＃経済スケジュール_メッセージ_',      
    ],

    // ★追加：コピーしないプレフィックス
    noCopyPrefixes: new Set(['全銘柄基本情報取得', '全銘柄四季報情報取得', '証券コード取得', 'カレンダー取得', '四季報情報更新監視', '指数日足取得', '全銘柄日足取得', '全銘柄日足分析', '市況関連データ抽出_ALL', '市況関連データ抽出_GROUP1', '市況関連データ抽出＃経済スケジュール']),
    
    // ★追加：マスタ更新するプレフィックス
    needsBaseInfoMasterUpdate: new Set(['全銘柄基本情報取得', '全銘柄四季報情報取得']),

    // 全銘柄日足分析マスタを更新するプレフィックス
    needsAnalysisMasterUpdate: new Set(['全銘柄日足分析']),

    // ★追加：証券コードマスタ更新するプレフィックス
    needsSecurityCodeMasterUpdate: new Set(['証券コード取得']),

    // ★追加：カレンダーマスタ更新するプレフィックス
    needsCalendarMasterUpdate: new Set(['カレンダー取得']),

    // 共通：ヘッダ名 → 揃え
    alignByHeader: {
      '証券コード': 'right',
      '証券コード5桁': 'right',
      '17業種コード': 'right',
      '33業種コード': 'right',
      '市場区分コード': 'right',
      '商品区分コード': 'right',

      '銘柄名': 'left',
      '銘柄名(英語)': 'left',
      '17業種コード名': 'left',
      '33業種コード名': 'left',
      '規模コード': 'left',
      '市場区分名': 'left',
      '貸借信用区分': 'left',
      '貸借信用区分名': 'left',

      'コード': 'right',
      '市場': 'center',
      '値幅制限': 'center',
      '終値比': 'right',
      '終値比(%)': 'right',
      '出来高': 'right',
      '詳細リンク': 'center',
      '前日比': 'right',
      '前日比(%)': 'right',
    },

    // ★追加：日付列の表示形式
    dateFormatHeaders: new Set(['日付', '情報適用年月日']),
  };

  const lock = LockService.getScriptLock();
  if (!lock.tryLock(60 * 1000)) return; // 同時実行回避（5分トリガー想定）
  try {
    const srcFolder = getFolderByPath_(CONFIG.srcFolderPath);
    const dstFolder = getFolderByPath_(CONFIG.dstFolderPath);

    // ===== 本日のニュース（最新）処理 =====
    try {
      processTodaysNewsLatest_(srcFolder, CONFIG);
    } catch (e) {
      console.error(`本日のニュース処理失敗: ${e && e.stack ? e.stack : e}`);
      // 失敗してもメッセージ処理は続ける
    }

    // ===== メッセージ(txt)処理 =====
    const targets = findMessageFiles_(srcFolder, CONFIG.messagePrefixes);

    if (targets.length === 0) {
      console.log('対象メッセージファイルなし。');
      return;
    }

    console.log(`検出メッセージファイル数: ${targets.length}`);
    console.log('検出メッセージ一覧: ' + targets.map(f => f.getName()).join(' , '));
    for (const file of targets) {
      try {
        console.log(`処理中: ${file.getName()}`);
        processOneMessageFile_(file, srcFolder, dstFolder, CONFIG);
      } catch (e) {
        console.error(`処理失敗: ${file.getName()} / ${e && e.stack ? e.stack : e}`);
        // 1件失敗しても他は処理継続
      }
    }
  } finally {
    lock.releaseLock();
  }
}

/**
 * ★追加：本日のニュース_最新 を条件付きで処理
 * - 6:00〜7:00 の間 AND ScriptPropertiesの日付が「今日より前日」なら起動
 * - 「本日のニュース_最新」の「時刻」列が“前日”の行を、新規「本日のニュース_yyyy-MM-dd（前日）」にコピー
 * - 「本日のニュース_最新」の「時刻」列が“今日の8日前より前”の行を削除
 * - ScriptProperties に今日の日付を格納
 * - メール送信（件名=本日のニュース_yyyy-MM-dd（前日）、本文=件数とURL）
 *
 * フォーマット整形はしない（仕様）
 */
function processTodaysNewsLatest_(srcFolder, CONFIG) {
  const now = new Date();
  const tz = Session.getScriptTimeZone();

  const hour = Number(Utilities.formatDate(now, tz, 'H')); // 0-23
  const withinWindow = (hour >= 6 && hour < 7);

  const todayStr = Utilities.formatDate(now, tz, 'yyyy-MM-dd');

  // 前日
  const y = new Date(now.getTime());
  y.setDate(y.getDate() - 1);
  const ydayStr = Utilities.formatDate(y, tz, 'yyyy-MM-dd');

  // 起動判定（ScriptProperties）
  const props = PropertiesService.getScriptProperties();
  const lastRun = String(props.getProperty(CONFIG.todaysNewsPropKey) || '').trim();

  // ログ（要点のみ）
  console.log(`[本日のニュース] 判定: now=${todayStr} ${Utilities.formatDate(now, tz, 'HH:mm')} in6to7=${withinWindow} lastRun=${lastRun || '(none)'}`);

  if (!withinWindow) return;
  if (lastRun === todayStr) return; // すでに今日実行済み

  // 「本日のニュース_最新」を出力結果フォルダ内で探す（スプレッドシート）
  const latestFile = findSpreadsheetByExactNameInFolder_(srcFolder, CONFIG.todaysNewsLatestName);
  if (!latestFile) {
    console.log(`[本日のニュース] 最新が見つからないためスキップ: ${CONFIG.todaysNewsLatestName}`);
    // 実行済み日付は更新しない（見つからないのに実行済み扱いしない）
    return;
  }
  console.log(`[本日のニュース] 処理対象: ${latestFile.getName()}`);

  const latestSs = SpreadsheetApp.openById(latestFile.getId());
  let latestSheet = latestSs.getSheetByName(CONFIG.todaysNewsDefaultSheetName) || latestSs.getSheets()[0];
  if (!latestSheet) {
    console.log('[本日のニュース] シートが見つからないためスキップ');
    return;
  }

  // ヘッダから「時刻」列を探す
  const lastCol = latestSheet.getLastColumn();
  const lastRow = latestSheet.getLastRow();
  if (lastRow < 2 || lastCol < 1) {
    console.log('[本日のニュース] データなし（latestが空）');
    // 実行済み日付だけは更新するか悩むが、仕様にないので更新しない
    return;
  }

  const headers = latestSheet.getRange(1, 1, 1, lastCol).getValues()[0].map(v => String(v ?? '').trim());
  const timeIdx0 = headers.indexOf(CONFIG.todaysNewsTimeHeader);
  if (timeIdx0 < 0) {
    console.log(`[本日のニュース] 見出し「${CONFIG.todaysNewsTimeHeader}」が無いためスキップ`);
    return;
  }
  const timeCol = timeIdx0 + 1;

  // 2行目以降の全データ
  const values = latestSheet.getRange(2, 1, lastRow - 1, lastCol).getValues();
  const copyRows = [];
  const keepRows = []; // 最新シートに残す行

  // 削除閾値（今日-8日より前は削除）
  const cutoff = new Date(now.getTime());
  cutoff.setDate(cutoff.getDate() - CONFIG.todaysNewsKeepDays);
  cutoff.setHours(0, 0, 0, 0);

  let deletedCount = 0;

  for (const row of values) {
    const cell = row[timeCol - 1];
    const d = parseAsDateMaybe_(cell, tz);

    // 時刻が解釈できない行は「残す」（誤削除防止）
    if (!d) {
      keepRows.push(row);
      continue;
    }

    const dStr = Utilities.formatDate(d, tz, 'yyyy-MM-dd');

    // 前日ならコピー対象
    if (dStr === ydayStr) copyRows.push(row);

    // 8日前より前なら削除（= keep しない）
    const dd = new Date(d.getTime());
    dd.setHours(0, 0, 0, 0);
    if (dd < cutoff) {
      deletedCount++;
      continue;
    }

    keepRows.push(row);
  }

  // 前日分の新規スプレッドシート作成（出力結果フォルダ配下）
  const outName = `本日のニュース_${ydayStr}`;
  const outSs = getOrCreateSpreadsheetInFolderByFolder_(outName, srcFolder);
  let outSheet = outSs.getSheetByName(CONFIG.todaysNewsDefaultSheetName) || outSs.getSheets()[0] || outSs.insertSheet(CONFIG.todaysNewsDefaultSheetName);
  if (outSheet.getName() !== CONFIG.todaysNewsDefaultSheetName) outSheet.setName(CONFIG.todaysNewsDefaultSheetName);

  // 既存をクリアして、ヘッダ＋前日行をセット（コピー）
  outSheet.clearContents();
  outSheet.getRange(1, 1, 1, lastCol).setValues([headers]);
  if (copyRows.length > 0) {
    outSheet.getRange(2, 1, copyRows.length, lastCol).setValues(copyRows);
  }
  // ★「時刻」列の表示形式を yyyy-MM-dd HH:mm:ss に設定（新規作成シートのみ）
  const timeFormat = 'yyyy-MM-dd HH:mm:ss';
  outSheet.getRange(2, timeCol, Math.max(copyRows.length, 1), 1).setNumberFormat(timeFormat);

  // 最新シートから8日前より前を削除 → 行を作り直す（安全に一括更新）
  // ※フォーマット類は保持したい場合があるが、仕様は列整形なし・削除のみなので contents のみ置換
  latestSheet.getRange(2, 1, lastRow - 1, lastCol).clearContent();

  if (keepRows.length > 0) {
    latestSheet.getRange(2, 1, keepRows.length, lastCol).setValues(keepRows);
  }

  // プロパティ更新（今日）
  props.setProperty(CONFIG.todaysNewsPropKey, todayStr);

  // メール送信
  const createdRows = 1 + copyRows.length; // ヘッダ含む
  const newsCount = Math.max(createdRows - 1, 0);
  const subject = outName;
  const body =
    `本日のニュース数は、${newsCount}銘柄でした。\n\n` +
    `${outSs.getUrl()}\n\n` +
    `本日のニュース_最新：\n` +
    `https://docs.google.com/spreadsheets/d/1AEegW2usuYpw2QwqRaH42PnuVwljkCX_uwnsi0VsHCs/edit?gid=0#gid=0\n\n` +
    `未読記事の一括取得：\n` +
    `https://script.google.com/macros/s/AKfycbxoMcqwlo8oCRBrCwDqHms7EIJ51H_JYtPao8OXYx_OZ8fDX-GjtNFUWoWsT7lYqMr7Ew/exec`;

  GmailApp.sendEmail(CONFIG.mailTo, subject, body);

  // 要点ログ
  console.log(`[本日のニュース] 作成=${outName} copyRows=${copyRows.length} deletedFromLatest=${deletedCount} mailSent=OK propSet=${todayStr}`);
}

/**
 * Date/文字列を Date に寄せる（ダメなら null）
 * - シートの日時セルは Date 型で来ることが多い
 * - 文字列の場合も Date.parse を試す（JST前提の厳密性は捨て、日付判定だけに使う）
 */
function parseAsDateMaybe_(v, tz) {
  if (v instanceof Date) return v;
  const s = String(v ?? '').trim();
  if (!s) return null;
  const t = Date.parse(s);
  if (!isNaN(t)) return new Date(t);

  // "2026-01-16 7:21:07" などもここで拾えるはずだが、念のため yyyy-MM-dd だけも試す
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) {
    const y = Number(m[1]), mo = Number(m[2]) - 1, d = Number(m[3]);
    return new Date(y, mo, d);
  }
  return null;
}

/**
 * 指定フォルダ直下にスプレッドシートを作成（既存なら開く）
 * ※既存 getOrCreateSpreadsheetInFolder_ は「フォルダ名」指定だったので、フォルダオブジェクト版を追加
 */
function getOrCreateSpreadsheetInFolderByFolder_(name, folder) {
  const files = folder.getFilesByName(name);
  if (files.hasNext()) return SpreadsheetApp.open(files.next());

  const file = SpreadsheetApp.create(name);
  const newFile = DriveApp.getFileById(file.getId());
  folder.addFile(newFile);
  DriveApp.getRootFolder().removeFile(newFile);
  return file;
}

/**
 * 1件のメッセージファイルを処理
 */
function processOneMessageFile_(msgFile, srcFolder, dstFolder, CONFIG) {
  const msgName = msgFile.getName(); // 例: PTS＆朝刊ニュース_メッセージ_2026-01-16.txt
  const parsed = parseMessageFilename_(msgName);
  if (!parsed) {
    console.log(`スキップ（命名不一致）: ${msgName}`);
    return;
  }

  const { basePrefix, dateStr, sheetName } = parsed;

  // ブロックIP集計はスプレッドシートを使用せず、
  // メッセージTXTの内容だけをメール送信する
  if (basePrefix === 'ブロックIP集計') {
    const bodyText = msgFile.getBlob().getDataAsString('UTF-8');
    const subject = `${basePrefix}_${dateStr}`;

    GmailApp.sendEmail(CONFIG.mailTo, subject, bodyText);

    // 対象のメッセージファイル削除
    msgFile.setTrashed(true);

    console.log(`完了: ${msgName}`);
    return;
  }

  // 市況関連データ抽出_ALL / GROUP1 はスプレッドシートを使用せず、
  // 対応するレポートTXTのURLをメール本文に付加して送信する
  if (
    basePrefix === '市況関連データ抽出_ALL' ||
    basePrefix === '市況関連データ抽出_GROUP1'
  ) {
    const reportName = `${basePrefix}_レポート_${dateStr}.txt`;
    const reportFiles = srcFolder.getFilesByName(reportName);

    if (!reportFiles.hasNext()) {
      console.log(`スキップ（対応レポート未発見）: msg=${msgName} / report=${reportName}`);
      return;
    }

    const reportFile = reportFiles.next();

    const bodyText = msgFile.getBlob().getDataAsString('UTF-8');
    const subject = `${basePrefix}_${dateStr}`;
    const mailBody = bodyText + '\n\n' + reportFile.getUrl();

    GmailApp.sendEmail(CONFIG.mailTo, subject, mailBody);

    // 対象のメッセージファイル削除
    msgFile.setTrashed(true);

    console.log(`完了: ${msgName}`);
    return;
  }

  // 市況関連データ抽出＃経済スケジュール
  // レポートTXT（タブ区切り）から経済スケジュールマスタを更新する
  if (basePrefix === '市況関連データ抽出＃経済スケジュール') {
    const reportName =
      `市況関連データ抽出＃経済スケジュール_レポート_${dateStr}.txt`;
    const reportFiles = srcFolder.getFilesByName(reportName);

    if (!reportFiles.hasNext()) {
      console.log(
        `スキップ（対応レポート未発見）: ` +
        `msg=${msgName} / report=${reportName}`
      );
      return;
    }

    const reportFile = reportFiles.next();

    const result =
      syncAndUpdateEconomicScheduleMaster_(reportFile, CONFIG);

    const bodyText = msgFile.getBlob().getDataAsString('UTF-8');
    const subject = `${basePrefix}_${dateStr}`;

    const mailBody =
      bodyText + '\n\n' +
      reportFile.getUrl() + '\n\n' +
      `経済スケジュールマスタ更新状況\n` +
      `更新: ${result.updatedRows}件\n` +
      `追加: ${result.insertedRows}件\n\n` +
      `経済スケジュールマスタ\n${result.masterUrl}`;

    GmailApp.sendEmail(CONFIG.mailTo, subject, mailBody);

    // 対象のメッセージファイル削除
    msgFile.setTrashed(true);

    console.log(`完了: ${msgName}`);
    return;
  }

  // 対応するスプレッドシート名（_メッセージ を除去、拡張子は無い前提）
  const ssName = `${basePrefix}_${dateStr}`; // 例: PTS＆朝刊ニュース_2026-01-16
  const ssFile = findSpreadsheetByExactNameInFolder_(srcFolder, ssName);
  if (!ssFile) {
    console.log(`スキップ（対応スプレッドシート未発見）: msg=${msgName} / ss=${ssName}`);
    return;
  }

  const ss = SpreadsheetApp.openById(ssFile.getId());

  // 対象スプレッドシートの先頭シート名を必ず「シート1」にする
  let sheet = ss.getSheets()[0];
  if (!sheet) {
    console.log(`スキップ（対象シートなし）: ${ssName}`);
    return;
  }
  if (sheet.getName() !== 'シート1') {
    sheet.setName('シート1');
  }

  let extraMailBody = '';

  // 全銘柄基本情報取得 / 全銘柄四季報情報取得 → マスタ更新
  if (CONFIG.needsBaseInfoMasterUpdate.has(basePrefix)) {
    const result = syncAndUpdateBaseInfoMaster_(
      sheet,
      CONFIG,
      basePrefix
    );

    // 全銘柄基本情報取得のみ、更新状況とマスタURLをメール本文へ追加
    if (basePrefix === '全銘柄基本情報取得') {
      extraMailBody =
        '\n全銘柄基本情報マスタ更新状況\n' +
        `更新: ${result.updatedRows}件\n` +
        `追加: ${result.insertedRows}件\n` +
        `追加列: ${result.addedColumns}件\n` +
        `削除列: ${result.deletedColumns}件\n\n` +
        `全銘柄基本情報マスタ\n${result.masterUrl}`;
    }
  }

  // 全銘柄日足分析 → 全銘柄日足分析マスタ更新
  if (CONFIG.needsAnalysisMasterUpdate.has(basePrefix)) {
    const result = syncAndUpdateAnalysisMaster_(sheet, CONFIG);

    extraMailBody =
      '\n全銘柄日足分析マスタ更新状況\n' +
      `更新: ${result.updatedRows}件\n` +
      `追加: ${result.insertedRows}件\n` +
      `追加列: ${result.addedColumns}件\n` +
      `削除列: ${result.deletedColumns}件\n\n` +
      `全銘柄日足分析マスタ\n${result.masterUrl}`;
  }

  // 証券コード取得 → 証券コードマスタ更新
  if (CONFIG.needsSecurityCodeMasterUpdate.has(basePrefix)) {
    const result = syncAndUpdateSecurityCodeMaster_(sheet, CONFIG);
    extraMailBody =
      '\n証券コードマスタ更新状況\n' +
      `指数保持: ${result.keptIndexRows}件\n` +
      `更新: ${result.updatedRows}件\n` +
      `追加: ${result.insertedRows}件\n` +
      `削除: ${result.deletedRows}件\n\n` +
      `証券コードマスタ\n${result.masterUrl}`;
  }

  // カレンダー取得 → カレンダーマスタ更新
  if (CONFIG.needsCalendarMasterUpdate.has(basePrefix)) {
    const result = syncAndUpdateCalendarMaster_(sheet, CONFIG);
    extraMailBody =
      '\nカレンダーマスタ更新状況\n' +
      `更新: ${result.updatedRows}件\n` +
      `追加: ${result.insertedRows}件\n` +
      `削除: ${result.deletedRows}件\n\n` +
      `カレンダーマスタ\n${result.masterUrl}`;
  }

  // 2) 共通：フォーマット整形（ヘッダ名で列検出）
  applyHeaderAlignment_(sheet, CONFIG.alignByHeader, { logPrefix: basePrefix });
  applyDateFormatByHeader_(sheet, CONFIG.dateFormatHeaders, { logPrefix: basePrefix });

  // ★重要：DriveのmakeCopy前に変更を確定（コピー先に反映されない問題の対策）
  SpreadsheetApp.flush();

  // 3) 共通：メール送信（件名は _メッセージ を消した名前 = ssName）
  const bodyText = msgFile.getBlob().getDataAsString('UTF-8');
  const ssUrl = ss.getUrl();
  const subject = ssName;
  const mailBody = bodyText + '\n\n' + ssUrl + '\n' + extraMailBody;

  GmailApp.sendEmail(CONFIG.mailTo, subject, mailBody);

  // 4) 共通：ファイルコピー（スプレッドシートをコピー）
  // コピー対象外の処理はスプレッドシートをコピーしない
  if (!CONFIG.noCopyPrefixes.has(basePrefix)) {
    ssFile.makeCopy(ssFile.getName(), dstFolder);
  }

  // 5) 共通：対象のメッセージファイル削除
  msgFile.setTrashed(true);

  // ★追加：完了ファイルの書き出し
  writeCompleteFileIfNeeded_(basePrefix, dateStr, CONFIG);

  console.log(`完了: ${msgName}`);
}

/**
 * 出力結果フォルダ直下で、指定プレフィックスに一致する txt を全件拾う
 */
function findMessageFiles_(folder, prefixes) {
  const out = [];
  const files = folder.getFiles();
  while (files.hasNext()) {
    const f = files.next();
    const name = f.getName();
    if (!name.endsWith('.txt')) continue;
    if (prefixes.some(p => name.startsWith(p))) {
      out.push(f);
    }
  }
  return out;
}

/**
 * "PTS＆朝刊ニュース_メッセージ_2026-01-16.txt" → { basePrefix:"PTS＆朝刊ニュース", dateStr:"2026-01-16" }
 */
function parseMessageFilename_(filename) {
  const base = filename.endsWith('.txt') ? filename.slice(0, -4) : filename;
  const m = base.match(/^(.+?)_メッセージ_(\d{4}-\d{2}-\d{2})$/);
  if (!m) return null;

  return {
    basePrefix: m[1],
    dateStr: m[2],
    sheetName: '',
  };
}

/**
 * 全銘柄日足分析マスタを更新する。
 *
 * 処理内容：
 * - 分析元にあって分析マスタにない列を追加
 *   ただし、全銘柄基本情報マスタに存在する列は追加しない
 * - 分析マスタにあって分析元にない列を削除
 *   ただし、全銘柄基本情報マスタに存在する列は削除しない
 * - 証券コードが一致する銘柄を更新
 * - 分析マスタに存在しない銘柄を末尾へ追加
 */
function syncAndUpdateAnalysisMaster_(srcSheet, CONFIG) {
  const masterFolder = getFolderByPath_(CONFIG.masterFolderPath);

  const baseMasterFile = findSpreadsheetByExactNameInFolder_(
    masterFolder,
    CONFIG.baseInfoMasterName
  );
  if (!baseMasterFile) {
    throw new Error(
      `マスタ未発見: ${CONFIG.masterFolderPath.join(' / ')} / ` +
      CONFIG.baseInfoMasterName
    );
  }

  const analysisMasterFile = findSpreadsheetByExactNameInFolder_(
    masterFolder,
    CONFIG.analysisMasterName
  );
  if (!analysisMasterFile) {
    throw new Error(
      `マスタ未発見: ${CONFIG.masterFolderPath.join(' / ')} / ` +
      CONFIG.analysisMasterName
    );
  }

  const baseMasterSs = SpreadsheetApp.openById(baseMasterFile.getId());
  const baseMasterSheet =
    baseMasterSs.getSheetByName('シート1') ||
    baseMasterSs.getSheets()[0];

  const analysisMasterSs =
    SpreadsheetApp.openById(analysisMasterFile.getId());
  const analysisMasterSheet =
    analysisMasterSs.getSheetByName('シート1') ||
    analysisMasterSs.getSheets()[0];

  if (!baseMasterSheet) {
    throw new Error(
      '全銘柄基本情報マスタ: 対象シートが見つかりません。'
    );
  }

  if (!analysisMasterSheet) {
    throw new Error(
      '全銘柄日足分析マスタ: 対象シートが見つかりません。'
    );
  }

  const srcInfo = getAnalysisHeaderMap_(srcSheet);
  const baseInfo = getAnalysisHeaderMap_(baseMasterSheet);
  const masterInfo = getAnalysisHeaderMap_(analysisMasterSheet);

  if (!srcInfo.map['証券コード']) {
    throw new Error(
      '全銘柄日足分析: 見出し「証券コード」が見つかりません。'
    );
  }

  if (!masterInfo.map['証券コード']) {
    throw new Error(
      '全銘柄日足分析マスタ: 見出し「証券コード」が見つかりません。'
    );
  }

  const baseHeaderSet = new Set(baseInfo.headers);
  const masterHeaderSet = new Set(masterInfo.headers);
  const srcHeaderSet = new Set(srcInfo.headers);

  /*
   * 新規列：
   * 分析元にはあるが分析マスタにはなく、
   * さらに基本情報マスタにもない列
   */
  const headersToAdd = srcInfo.headers.filter(header =>
    header &&
    !masterHeaderSet.has(header) &&
    !baseHeaderSet.has(header)
  );

  if (headersToAdd.length > 0) {
    const lastColumn = analysisMasterSheet.getLastColumn();

    analysisMasterSheet.insertColumnsAfter(
      lastColumn,
      headersToAdd.length
    );

    analysisMasterSheet
      .getRange(
        1,
        lastColumn + 1,
        1,
        headersToAdd.length
      )
      .setValues([headersToAdd]);

    console.log(
      `[日足分析マスタ更新] 新規列追加: ` +
      `${headersToAdd.length}件 / ${headersToAdd.join(', ')}`
    );
  }

  /*
   * 古い列：
   * 分析マスタにはあるが分析元にはなく、
   * さらに基本情報マスタにもない列
   */
  const refreshedMasterInfo =
    getAnalysisHeaderMap_(analysisMasterSheet);

  const columnsToDelete = [];

  refreshedMasterInfo.headers.forEach((header, index) => {
    if (
      header &&
      !srcHeaderSet.has(header) &&
      !baseHeaderSet.has(header)
    ) {
      columnsToDelete.push({
        column: index + 1,
        header: header,
      });
    }
  });

  // 列番号がずれないよう、右側から削除
  columnsToDelete
    .sort((a, b) => b.column - a.column)
    .forEach(item => {
      analysisMasterSheet.deleteColumn(item.column);
    });

  if (columnsToDelete.length > 0) {
    console.log(
      `[日足分析マスタ更新] 古い列削除: ` +
      `${columnsToDelete.length}件 / ` +
      columnsToDelete.map(item => item.header).join(', ')
    );
  }

  // 列追加・削除後の最新見出しを再取得
  const finalMasterInfo =
    getAnalysisHeaderMap_(analysisMasterSheet);

  const srcLastRow = srcSheet.getLastRow();
  const srcLastColumn = srcSheet.getLastColumn();

  if (srcLastRow < 2 || srcLastColumn < 1) {
    console.log(
      '[日足分析マスタ更新] スキップ: 分析元にデータなし'
    );

    return {
      updatedRows: 0,
      insertedRows: 0,
      addedColumns: headersToAdd.length,
      deletedColumns: columnsToDelete.length,
      masterUrl: analysisMasterSs.getUrl(),
    };
  }

  const masterLastRow = analysisMasterSheet.getLastRow();
  const masterLastColumn = analysisMasterSheet.getLastColumn();

  const masterValues =
    masterLastRow >= 1
      ? analysisMasterSheet
          .getRange(
            1,
            1,
            masterLastRow,
            masterLastColumn
          )
          .getValues()
      : [finalMasterInfo.headers];

  const srcValues = srcSheet
    .getRange(
      1,
      1,
      srcLastRow,
      srcLastColumn
    )
    .getValues();

  const masterCodeIndex =
    finalMasterInfo.map['証券コード'] - 1;
  const srcCodeIndex =
    srcInfo.map['証券コード'] - 1;

  const codeToMasterRow = new Map();

  for (let row = 1; row < masterValues.length; row++) {
    const code = String(
      masterValues[row][masterCodeIndex] ?? ''
    ).trim();

    if (code && !codeToMasterRow.has(code)) {
      codeToMasterRow.set(code, row);
    }
  }

  const commonHeaders = finalMasterInfo.headers.filter(
    header => header && srcInfo.map[header]
  );

  let updatedRows = 0;
  let insertedRows = 0;
  let skippedRows = 0;

  for (let row = 1; row < srcValues.length; row++) {
    const code = String(
      srcValues[row][srcCodeIndex] ?? ''
    ).trim();

    if (!code) {
      skippedRows++;
      continue;
    }

    let masterRow = codeToMasterRow.get(code);

    if (masterRow === undefined) {
      const newRow =
        new Array(masterLastColumn).fill('');

      newRow[masterCodeIndex] = code;

      masterValues.push(newRow);
      masterRow = masterValues.length - 1;

      codeToMasterRow.set(code, masterRow);
      insertedRows++;
    } else {
      updatedRows++;
    }

    for (const header of commonHeaders) {
      const masterColumn =
        finalMasterInfo.map[header] - 1;
      const srcColumn =
        srcInfo.map[header] - 1;

      masterValues[masterRow][masterColumn] =
        srcValues[row][srcColumn];
    }
  }

  analysisMasterSheet
    .getRange(
      1,
      1,
      masterValues.length,
      masterLastColumn
    )
    .setValues(masterValues);

  console.log(
    `[日足分析マスタ更新] 完了: ` +
    `更新=${updatedRows}, ` +
    `追加=${insertedRows}, ` +
    `スキップ=${skippedRows}, ` +
    `追加列=${headersToAdd.length}, ` +
    `削除列=${columnsToDelete.length}` 
  );

  return {
    updatedRows: updatedRows,
    insertedRows: insertedRows,
    addedColumns: headersToAdd.length,
    deletedColumns: columnsToDelete.length,
    masterUrl: analysisMasterSs.getUrl(),
  };
}

/**
 * ★全銘柄基本情報取得シートの内容を「全銘柄基本情報マスタ」に反映（高速版）
 * - 見出し差分を見て、マスタに新規列追加 / 古い列削除
 * - 「証券コード」一致行について、見出し一致セルを更新
 * - 更新は「配列上で更新 → setValues一括」で高速化
 * - 進捗ログは出し過ぎない（200行ごと＋先頭＋末尾）
 *
 * 注意：
 * - 「証券コード」ヘッダは必須
 * - 同一コードがマスタに無い場合は、新規銘柄として末尾に追加する
 */
function syncAndUpdateBaseInfoMaster_(srcSheet, CONFIG, basePrefixArg = '') {
  const masterFolder = getFolderByPath_(CONFIG.masterFolderPath);
  const masterFile = findSpreadsheetByExactNameInFolder_(masterFolder, CONFIG.baseInfoMasterName);
  if (!masterFile) {
    throw new Error(`マスタ未発見: ${CONFIG.masterFolderPath.join(' / ')} / ${CONFIG.baseInfoMasterName}`);
  }

  const masterSs = SpreadsheetApp.openById(masterFile.getId());
  const masterSheet = masterSs.getSheets()[0];
  const mgmtSheet = masterSs.getSheetByName('見出し管理');
  if (!mgmtSheet) throw new Error('全銘柄基本情報マスタ: シート「見出し管理」が見つかりません。');

  const srcLastCol = srcSheet.getLastColumn();
  const srcLastRow = srcSheet.getLastRow();
  if (srcLastCol <= 0 || srcLastRow < 2) {
    console.log('[マスタ更新] スキップ: 取得シートにデータなし');

    return {
      updatedRows: 0,
      insertedRows: 0,
      addedColumns: 0,
      deletedColumns: 0,
      masterUrl: masterSs.getUrl(),
    };
  }

  const mstLastCol = masterSheet.getLastColumn();
  const mstLastRow = masterSheet.getLastRow();

  // 見出し取得
  const srcHeaders = srcSheet.getRange(1, 1, 1, srcLastCol).getValues()[0].map(v => String(v ?? '').trim());
  const srcSet = new Set(srcHeaders.filter(h => h));

  // 必須ヘッダ
  if (!srcSet.has('証券コード')) throw new Error('全銘柄基本情報取得: 見出し「証券コード」が見つかりません。');
  if (!srcSet.has('実行結果')) {
    throw new Error(`${basePrefixArg}: 見出し「実行結果」が見つかりません。`);
  }
  // マスタ側の「証券コード」存在は、後段で mstHeaderToIdx を作って検証する

  // ===== 見出し管理シート基準で追加/削除判定 =====
  // basePrefix により突合行を切り替える
  // - 全銘柄基本情報取得: 見出し管理「2行目」を正
  // - 全銘柄四季報情報取得: 見出し管理「1行目」を正
  const basePrefix = String(basePrefixArg || '');
  // 見出し管理: 1行目=基本情報, 2行目=四季報
  const mgmtRow = (basePrefix === '全銘柄四季報情報取得') ? 2 : 1;
  const mgmtLastCol = mgmtSheet.getLastColumn();
  const mgmtHeaders = (mgmtLastCol > 0)
    ? mgmtSheet.getRange(mgmtRow, 1, 1, mgmtLastCol).getValues()[0].map(v => String(v ?? '').trim())
    : [];
  const mgmtSet = new Set(mgmtHeaders.filter(h => h));

  // 反対側の管理行（基本<->四季報）も取得して「削除ガード」に使う
  const otherRow = (mgmtRow === 1) ? 2 : 1;
  const otherHeaders = (mgmtLastCol > 0)
    ? mgmtSheet.getRange(otherRow, 1, 1, mgmtLastCol).getValues()[0].map(v => String(v ?? '').trim())
    : [];
  const otherSet = new Set(otherHeaders.filter(h => h));

  // ① 新しい項目：srcにあるが、見出し管理（該当行）に無い
  const newHeaders = srcHeaders.filter(h => h && h !== '証券コード' && !mgmtSet.has(h));

  // 古い項目：管理行にはあるが src に無い。ただし「もう一方の管理行に存在する列」は消さない（事故防止）
  const oldHeaders = mgmtHeaders.filter(h =>
    h && h !== '証券コード' && !srcSet.has(h) && !otherSet.has(h)
  );


  // ログ（要点だけ）
  console.log(`[マスタ更新] 開始: srcRows=${srcLastRow - 1}, srcCols=${srcLastCol}, masterRows=${Math.max(mstLastRow - 1, 0)}, masterCols=${mstLastCol}`);
  if (newHeaders.length > 0) console.log(`[マスタ更新] 新規ヘッダ追加: ${newHeaders.length}件`);
  if (oldHeaders.length > 0) console.log(`[マスタ更新] 古いヘッダ削除: ${oldHeaders.length}件`);

  // 新規列追加（末尾）＋ 見出し管理にも末尾追加
  if (newHeaders.length > 0) {
    const beforeLastCol = Math.max(masterSheet.getLastColumn(), 1);
    masterSheet.insertColumnsAfter(beforeLastCol, newHeaders.length);
    const newStartCol = beforeLastCol + 1;
    masterSheet.getRange(1, newStartCol, 1, newHeaders.length).setValues([newHeaders]);

    const mgBeforeLastCol = Math.max(mgmtSheet.getLastColumn(), 1);
    mgmtSheet.insertColumnsAfter(mgBeforeLastCol, newHeaders.length);
    const mgNewStartCol = mgBeforeLastCol + 1;
    mgmtSheet.getRange(mgmtRow, mgNewStartCol, 1, newHeaders.length).setValues([newHeaders]);
  }

  // 古い列削除（右から）＋ 見出し管理からも同じ列削除
  if (oldHeaders.length > 0) {
    const currentMstLastCol = masterSheet.getLastColumn();
    const currentHeaders = masterSheet.getRange(1, 1, 1, currentMstLastCol)
      .getValues()[0].map(v => String(v ?? '').trim());
    const headerToColM = {};
    for (let c = 0; c < currentHeaders.length; c++) {
      const h = currentHeaders[c];
      if (h) headerToColM[h] = c + 1;
    }

    const currentMgLastCol = mgmtSheet.getLastColumn();
    const currentMgHeaders = mgmtSheet.getRange(mgmtRow, 1, 1, currentMgLastCol)
      .getValues()[0].map(v => String(v ?? '').trim());
    const headerToColG = {};
    for (let c = 0; c < currentMgHeaders.length; c++) {
      const h = currentMgHeaders[c];
      if (h) headerToColG[h] = c + 1;
    }

    const colsToDeleteM = oldHeaders
      .map(h => headerToColM[h])
      .filter(c => !!c)
      .sort((a, b) => b - a);
    const colsToDeleteG = oldHeaders
      .map(h => headerToColG[h])
      .filter(c => !!c)
      .sort((a, b) => b - a);

    colsToDeleteM.forEach(col => masterSheet.deleteColumn(col));
    colsToDeleteG.forEach(col => mgmtSheet.deleteColumn(col));
  }

  // ここから「高速更新」：master全体を配列で読み込み、配列上で更新して一括setValues
  const finalMstLastRow = masterSheet.getLastRow();
  const finalMstLastCol = masterSheet.getLastColumn();
  if (finalMstLastRow < 2 || finalMstLastCol < 1) {
    console.log('[マスタ更新] スキップ: マスタ側にデータなし');
    return {
      updatedRows: 0,
      insertedRows: 0,
      addedColumns: newHeaders.length,
      deletedColumns: oldHeaders.length,
      masterUrl: masterSs.getUrl(),
    };
  }

  const mstAll = masterSheet.getRange(1, 1, finalMstLastRow, finalMstLastCol).getValues();
  const mstHeadersRow = mstAll[0].map(v => String(v ?? '').trim());

  // master: header -> idx(0-based)
  const mstHeaderToIdx = {};
  for (let i = 0; i < mstHeadersRow.length; i++) {
    const h = mstHeadersRow[i];
    if (h) mstHeaderToIdx[h] = i;
  }
  if (mstHeaderToIdx['証券コード'] == null) {
    throw new Error('全銘柄基本情報マスタ: 見出し「証券コード」が見つかりません。（更新後）');
  }

  // src: header -> idx(0-based)
  const srcHeaderToIdx = {};
  for (let i = 0; i < srcHeaders.length; i++) {
    const h = srcHeaders[i];
    if (h) srcHeaderToIdx[h] = i;
  }
  if (srcHeaderToIdx['証券コード'] == null) {
    throw new Error('全銘柄基本情報取得: 見出し「証券コード」が見つかりません。');
  }

  // 共通ヘッダ（証券コード以外）: srcにあって masterにもあるものだけ
  const commonHeaders = srcHeaders.filter(h => h && h !== '証券コード' && (mstHeaderToIdx[h] != null));
  console.log(`[マスタ更新] 更新対象列数: ${commonHeaders.length}`);

  // master: code -> rowIdx(0-based, ヘッダ行含む配列mstAll上の行番号)
  const codeIdxM = mstHeaderToIdx['証券コード'];
  const codeToRowIdx = new Map();
  for (let r = 1; r < mstAll.length; r++) {
    const code = String(mstAll[r][codeIdxM] ?? '').trim();
    if (!code) continue;
    if (!codeToRowIdx.has(code)) codeToRowIdx.set(code, r);
  }

  // src データ（ヘッダ除く）
  const srcData = srcSheet.getRange(2, 1, srcLastRow - 1, srcLastCol).getValues();
  const codeIdxS = srcHeaderToIdx['証券コード'];
  const resultIdxS = srcHeaderToIdx['実行結果'];

  const totalRows = srcData.length;
  let updatedRows = 0;
  let skippedRows = 0;
  let insertedRows = 0;

  for (let i = 0; i < totalRows; i++) {
    // 進捗（出し過ぎ防止）
    if (i === 0 || (i % 200) === 0 || i === totalRows - 1) {
      console.log(`[マスタ更新] 進捗: ${i + 1}/${totalRows} 行（更新=${updatedRows}, 追加=${insertedRows}, スキップ=${skippedRows}）`);
    }

    const code = String(srcData[i][codeIdxS] ?? '').trim();
    if (!code) { skippedRows++; continue; }

    const executionResult = String(srcData[i][resultIdxS] ?? '').trim();

    // 全銘柄基本情報取得：
    // 「実行結果」の先頭が「エラー」の行は更新しない
    if (
      basePrefix === '全銘柄基本情報取得' &&
      executionResult.startsWith('エラー')
    ) {
      skippedRows++;
      continue;
    }

    // 全銘柄四季報情報取得：
    // 「実行結果」が「正常」の行だけ更新する
    if (
      basePrefix === '全銘柄四季報情報取得' &&
      executionResult !== '正常'
    ) {
      skippedRows++;
      continue;
    }

    const mstRow = codeToRowIdx.get(code);
    if (mstRow == null) {
      // ★追加仕様：srcに存在し master に存在しないコードは、新規銘柄として末尾に行追加
      const newRow = new Array(mstAll[0].length).fill('');
      newRow[codeIdxM] = code;
      for (const h of commonHeaders) {
        const sIdx = srcHeaderToIdx[h];
        const mIdx = mstHeaderToIdx[h];
        newRow[mIdx] = srcData[i][sIdx];
      }
      mstAll.push(newRow);
      const newRowIdx = mstAll.length - 1;
      codeToRowIdx.set(code, newRowIdx);
      insertedRows++;
      continue;
    }

    // 配列上で更新
    for (const h of commonHeaders) {
      const sIdx = srcHeaderToIdx[h];
      const mIdx = mstHeaderToIdx[h];
      const value = srcData[i][sIdx];

      // 完全な空欄の場合は、マスタの既存値を残す
      // 「－」は空欄ではないため、通常どおり更新する
      if (value === '' || value == null) continue;

      mstAll[mstRow][mIdx] = value;
    }
    updatedRows++;
  }

  // 一括反映（これが速い）
  masterSheet.getRange(1, 1, mstAll.length, mstAll[0].length).setValues(mstAll);

  console.log(`[マスタ更新] 完了: 更新=${updatedRows}, ` + `追加=${insertedRows}, スキップ=${skippedRows}`);

  return {
    updatedRows: updatedRows,
    insertedRows: insertedRows,
    addedColumns: newHeaders.length,
    deletedColumns: oldHeaders.length,
    masterUrl: masterSs.getUrl(),
  };
}


/**
 * ★追加：証券コード取得シートの内容を「証券コードマスタ」に反映
 * - 「市場区分コード」が「-」の行は指数情報として更新対象外。そのまま残す
 * - 「証券コード」で突合
 *   - masterにありsrcにある → masterをsrcの値で更新
 *   - masterにありsrcにない → masterから削除
 *   - masterになくsrcにある → master末尾に追加
 */
function syncAndUpdateSecurityCodeMaster_(srcSheet, CONFIG) {
  const masterFolder = getFolderByPath_(CONFIG.masterFolderPath);
  const masterFile = findSpreadsheetByExactNameInFolder_(masterFolder, CONFIG.securityCodeMasterName);
  if (!masterFile) {
    throw new Error(`証券コードマスタ未発見: ${CONFIG.masterFolderPath.join(' / ')} / ${CONFIG.securityCodeMasterName}`);
  }

  const masterSs = SpreadsheetApp.openById(masterFile.getId());
  const masterSheet = masterSs.getSheets()[0];

  const srcLastCol = srcSheet.getLastColumn();
  const srcLastRow = srcSheet.getLastRow();
  if (srcLastCol <= 0 || srcLastRow < 2) {
    console.log('[証券コードマスタ更新] スキップ: 取得シートにデータなし');

    return {
      keptIndexRows: 0,
      updatedRows: 0,
      insertedRows: 0,
      deletedRows: 0,
      masterUrl: masterSs.getUrl(),
    };
  }

  const mstLastCol = masterSheet.getLastColumn();
  const mstLastRow = masterSheet.getLastRow();
  if (mstLastCol <= 0 || mstLastRow < 1) {
    throw new Error('証券コードマスタ: ヘッダ行が見つかりません。');
  }

  const srcHeaders = srcSheet.getRange(1, 1, 1, srcLastCol).getValues()[0].map(v => String(v ?? '').trim());
  const mstHeaders = masterSheet.getRange(1, 1, 1, mstLastCol).getValues()[0].map(v => String(v ?? '').trim());

  const srcHeaderToIdx = {};
  srcHeaders.forEach((h, i) => { if (h) srcHeaderToIdx[h] = i; });

  const mstHeaderToIdx = {};
  mstHeaders.forEach((h, i) => { if (h) mstHeaderToIdx[h] = i; });

  if (srcHeaderToIdx['証券コード'] == null) throw new Error('証券コード取得: 見出し「証券コード」が見つかりません。');
  if (mstHeaderToIdx['証券コード'] == null) throw new Error('証券コードマスタ: 見出し「証券コード」が見つかりません。');
  if (mstHeaderToIdx['市場区分コード'] == null) throw new Error('証券コードマスタ: 見出し「市場区分コード」が見つかりません。');

  const srcData = srcSheet.getRange(2, 1, srcLastRow - 1, srcLastCol).getValues();
  const mstAll = masterSheet.getRange(1, 1, mstLastRow, mstLastCol).getValues();

  const codeIdxS = srcHeaderToIdx['証券コード'];
  const codeIdxM = mstHeaderToIdx['証券コード'];
  const marketCodeIdxM = mstHeaderToIdx['市場区分コード'];

  const commonHeaders = srcHeaders.filter(h => h && mstHeaderToIdx[h] != null);

  // src: code -> row
  const srcByCode = new Map();
  for (const row of srcData) {
    const code = String(row[codeIdxS] ?? '').trim();
    if (!code) continue;
    if (!srcByCode.has(code)) srcByCode.set(code, row);
  }

  const finalRows = [mstAll[0]];
  const processedCodes = new Set();

  let keptIndexRows = 0;
  let updatedRows = 0;
  let deletedRows = 0;
  let insertedRows = 0;

  // master既存行を走査
  for (let r = 1; r < mstAll.length; r++) {
    const mstRow = mstAll[r];
    const code = String(mstRow[codeIdxM] ?? '').trim();
    const marketCode = String(mstRow[marketCodeIdxM] ?? '').trim();

    // 指数情報はそのまま残す
    if (marketCode === '-') {
      finalRows.push(mstRow);
      keptIndexRows++;
      continue;
    }

    if (!code) {
      deletedRows++;
      continue;
    }

    const srcRow = srcByCode.get(code);
    if (!srcRow) {
      deletedRows++;
      continue;
    }

    // 既存行をsrcの値で上書き更新
    const newRow = mstRow.slice();
    for (const h of commonHeaders) {
      newRow[mstHeaderToIdx[h]] = srcRow[srcHeaderToIdx[h]];
    }

    finalRows.push(newRow);
    processedCodes.add(code);
    updatedRows++;
  }

  // srcにあってmasterにないコードを追加
  for (const [code, srcRow] of srcByCode.entries()) {
    if (processedCodes.has(code)) continue;

    const newRow = new Array(mstHeaders.length).fill('');
    for (const h of commonHeaders) {
      newRow[mstHeaderToIdx[h]] = srcRow[srcHeaderToIdx[h]];
    }

    finalRows.push(newRow);
    insertedRows++;
  }

  // 既存内容をクリアして再配置
  masterSheet.getRange(1, 1, Math.max(mstLastRow, finalRows.length), mstLastCol).clearContent();
  masterSheet.getRange(1, 1, finalRows.length, mstLastCol).setValues(finalRows);

  
  // ★追加：証券コードマスタ側も該当項目があればフォーマット整形
  applyHeaderAlignment_(masterSheet, CONFIG.alignByHeader, { logPrefix: '証券コードマスタ' });
  applyDateFormatByHeader_(masterSheet, CONFIG.dateFormatHeaders, { logPrefix: '証券コードマスタ' });

  console.log(`[証券コードマスタ更新] 完了: 指数保持=${keptIndexRows}, 更新=${updatedRows}, 追加=${insertedRows}, 削除=${deletedRows}`);
  
  return {
    keptIndexRows,
    updatedRows,
    insertedRows,
    deletedRows,
    masterUrl: masterSs.getUrl(),
  };
}

/**
 * ★追加：カレンダー取得シートの内容を「カレンダーマスタ」に反映
 * - 「日付」で突合
 *   - masterにありsrcにある → masterをsrcの値で更新
 *   - masterにありsrcにない → masterから削除
 *   - masterになくsrcにある → master末尾に追加
 */
function syncAndUpdateCalendarMaster_(srcSheet, CONFIG) {
  const masterFolder = getFolderByPath_(CONFIG.masterFolderPath);
  const masterFile = findSpreadsheetByExactNameInFolder_(masterFolder, CONFIG.calendarMasterName);
  if (!masterFile) {
    throw new Error(`カレンダーマスタ未発見: ${CONFIG.masterFolderPath.join(' / ')} / ${CONFIG.calendarMasterName}`);
  }

  const masterSs = SpreadsheetApp.openById(masterFile.getId());
  const masterSheet = masterSs.getSheets()[0];

  const srcLastCol = srcSheet.getLastColumn();
  const srcLastRow = srcSheet.getLastRow();
  if (srcLastCol <= 0 || srcLastRow < 2) {
    console.log('[カレンダーマスタ更新] スキップ: 取得シートにデータなし');

    return {
      updatedRows: 0,
      insertedRows: 0,
      deletedRows: 0,
      masterUrl: masterSs.getUrl(),
    };
  }

  const mstLastCol = masterSheet.getLastColumn();
  const mstLastRow = masterSheet.getLastRow();
  if (mstLastCol <= 0 || mstLastRow < 1) {
    throw new Error('カレンダーマスタ: ヘッダ行が見つかりません。');
  }

  const srcHeaders = srcSheet.getRange(1, 1, 1, srcLastCol).getValues()[0].map(v => String(v ?? '').trim());
  const mstHeaders = masterSheet.getRange(1, 1, 1, mstLastCol).getValues()[0].map(v => String(v ?? '').trim());

  const srcHeaderToIdx = {};
  srcHeaders.forEach((h, i) => { if (h) srcHeaderToIdx[h] = i; });

  const mstHeaderToIdx = {};
  mstHeaders.forEach((h, i) => { if (h) mstHeaderToIdx[h] = i; });

  if (srcHeaderToIdx['日付'] == null) throw new Error('カレンダー取得: 見出し「日付」が見つかりません。');
  if (mstHeaderToIdx['日付'] == null) throw new Error('カレンダーマスタ: 見出し「日付」が見つかりません。');

  const srcData = srcSheet.getRange(2, 1, srcLastRow - 1, srcLastCol).getValues();
  const mstAll = masterSheet.getRange(1, 1, mstLastRow, mstLastCol).getValues();

  const dateIdxS = srcHeaderToIdx['日付'];
  const dateIdxM = mstHeaderToIdx['日付'];

  const commonHeaders = srcHeaders.filter(h => h && mstHeaderToIdx[h] != null);

  const dateKey = (v) => {
    if (v instanceof Date) {
      return Utilities.formatDate(v, Session.getScriptTimeZone(), 'yyyy-MM-dd');
    }
    const s = String(v ?? '').trim();
    if (!s) return '';

    const m = s.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/);
    if (m) {
      return [
        m[1],
        String(m[2]).padStart(2, '0'),
        String(m[3]).padStart(2, '0'),
      ].join('-');
    }

    return s;
  };

  // src: date -> row
  const srcByDate = new Map();
  for (const row of srcData) {
    const date = dateKey(row[dateIdxS]);
    if (!date) continue;
    if (!srcByDate.has(date)) srcByDate.set(date, row);
  }

  const finalRows = [mstAll[0]];
  const processedDates = new Set();

  let updatedRows = 0;
  let deletedRows = 0;
  let insertedRows = 0;

  // master既存行を走査
  for (let r = 1; r < mstAll.length; r++) {
    const mstRow = mstAll[r];
    const date = dateKey(mstRow[dateIdxM]);

    if (!date) {
      deletedRows++;
      continue;
    }

    const srcRow = srcByDate.get(date);
    if (!srcRow) {
      deletedRows++;
      continue;
    }

    const newRow = mstRow.slice();
    for (const h of commonHeaders) {
      newRow[mstHeaderToIdx[h]] = srcRow[srcHeaderToIdx[h]];
    }

    finalRows.push(newRow);
    processedDates.add(date);
    updatedRows++;
  }

  // srcにあってmasterにない日付を追加
  for (const [date, srcRow] of srcByDate.entries()) {
    if (processedDates.has(date)) continue;

    const newRow = new Array(mstHeaders.length).fill('');
    for (const h of commonHeaders) {
      newRow[mstHeaderToIdx[h]] = srcRow[srcHeaderToIdx[h]];
    }

    finalRows.push(newRow);
    insertedRows++;
  }

  // ★追加：「日付」列をキーに昇順ソート
  // 1行目のヘッダは固定し、2行目以降だけを並べ替える
  const headerRow = finalRows[0];
  const dataRows = finalRows.slice(1);

  dataRows.sort((a, b) => {
    const dateA = dateKey(a[dateIdxM]);
    const dateB = dateKey(b[dateIdxM]);
    return dateA.localeCompare(dateB);
  });

  finalRows.splice(0, finalRows.length, headerRow, ...dataRows);

  masterSheet.getRange(1, 1, Math.max(mstLastRow, finalRows.length), mstLastCol).clearContent();
  masterSheet.getRange(1, 1, finalRows.length, mstLastCol).setValues(finalRows);

  // ★追加：カレンダーマスタ側も該当項目があればフォーマット整形
  applyHeaderAlignment_(masterSheet, CONFIG.alignByHeader, { logPrefix: 'カレンダーマスタ' });
  applyDateFormatByHeader_(masterSheet, CONFIG.dateFormatHeaders, { logPrefix: 'カレンダーマスタ' });

  console.log(`[カレンダーマスタ更新] 完了: 更新=${updatedRows}, 追加=${insertedRows}, 削除=${deletedRows}`);

  return {
    updatedRows,
    insertedRows,
    deletedRows,
    masterUrl: masterSs.getUrl(),
  };
}

/**
 * ★追加：必要な処理だけ完了ファイルを書き出す
 */
function writeCompleteFileIfNeeded_(basePrefix, dateStr, CONFIG) {
  const completePrefixMap = {
    '証券コード取得': 'complete_security_master_',
    'カレンダー取得': 'complete_calendar_',
  };

  const filePrefix = completePrefixMap[basePrefix];
  if (!filePrefix) return;

  const ymd = String(dateStr).replace(/-/g, '');
  const fileName = `${filePrefix}${ymd}.txt`;

  const folder = getFolderByPath_(CONFIG.completeFolderPath);

  // 既存があれば上書き相当で削除してから作成
  const files = folder.getFilesByName(fileName);
  while (files.hasNext()) {
    files.next().setTrashed(true);
  }

  folder.createFile(fileName, dateStr, MimeType.PLAIN_TEXT);

  console.log(`[完了ファイル] 作成: ${fileName}`);
}

/**
 * ★修正：ヘッダ名の揺れ（全角/半角/%/空白）を吸収して揃え設定を適用
 * - ログは「適用できなかった重要見出し」だけ（出し過ぎ防止）
 */
function applyHeaderAlignment_(sheet, alignByHeader, opt = {}) {
  const logPrefix = opt.logPrefix ? String(opt.logPrefix) : '';

  const lastCol = sheet.getLastColumn();
  const lastRow = sheet.getLastRow();
  if (lastCol <= 0 || lastRow <= 1) return;

  // ヘッダ取得（表示文字で）
  const rawHeaders = sheet.getRange(1, 1, 1, lastCol).getDisplayValues()[0].map(v => String(v ?? '').trim());

  // 正規化関数：空白除去、全角括弧→半角、全角％→半角%、全角スペース除去など
  const norm = (s) => {
    return String(s ?? '')
      .trim()
      .replace(/[ 　\t\r\n]+/g, '')      // あらゆる空白除去
      .replace(/（/g, '(').replace(/）/g, ')')
      .replace(/％/g, '%');
  };

  // シート側: 正規化ヘッダ -> 列番号
  const headerToCol = {};
  for (let c = 0; c < rawHeaders.length; c++) {
    const h = rawHeaders[c];
    if (!h) continue;
    const k = norm(h);
    if (!headerToCol[k]) headerToCol[k] = c + 1; // 1-indexed（最初を優先）
  }

  // 設定側: 正規化して適用
  const missing = [];
  Object.keys(alignByHeader).forEach(header => {
    const key = norm(header);
    const col = headerToCol[key];
    if (!col) {
      // 重要なものだけ missing 収集（ログ過多防止）
      if (header === '証券コード' || header === '値幅制限' || header === '終値比' || header === '終値比(%)' ||
          header === '前日比' || header === '前日比(%)' || header === '詳細リンク') {
        missing.push(header);
      }
      return;
    }

    const align = alignByHeader[header];
    const rng = sheet.getRange(2, col, Math.max(0, lastRow - 1), 1);
    if (align === 'right') rng.setHorizontalAlignment('right');
    if (align === 'center') rng.setHorizontalAlignment('center');
    if (align === 'left') rng.setHorizontalAlignment('left');
  });

  if (missing.length > 0) {
    console.log(`[整形] 未適用ヘッダ（見出し不一致）: ${logPrefix ? logPrefix + ' ' : ''}${missing.join(', ')} / headers=${rawHeaders.join('|')}`);
  }
}

/**
 * ★追加：ヘッダ名が一致する日付列を yyyy-MM-dd 形式にする
 * - Date型セルは表示形式を yyyy-MM-dd に設定
 * - "yyyy/MM/dd" 文字列は "yyyy-MM-dd" 文字列へ変換
 */
function applyDateFormatByHeader_(sheet, dateFormatHeaders, opt = {}) {
  const logPrefix = opt.logPrefix ? String(opt.logPrefix) : '';

  const lastCol = sheet.getLastColumn();
  const lastRow = sheet.getLastRow();
  if (lastCol <= 0 || lastRow <= 1) return;

  const headers = sheet.getRange(1, 1, 1, lastCol).getDisplayValues()[0].map(v => String(v ?? '').trim());

  const headerToCol = {};
  for (let c = 0; c < headers.length; c++) {
    const h = headers[c];
    if (h) headerToCol[h] = c + 1;
  }

  for (const header of dateFormatHeaders) {
    const col = headerToCol[header];
    if (!col) continue;

    const rng = sheet.getRange(2, col, lastRow - 1, 1);
    const values = rng.getValues();

    let changed = false;
    for (let r = 0; r < values.length; r++) {
      const v = values[r][0];

      if (v instanceof Date) {
        continue;
      }

      const s = String(v ?? '').trim();
      const m = s.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
      if (m) {
        values[r][0] =
          `${m[1]}-${String(m[2]).padStart(2, '0')}-${String(m[3]).padStart(2, '0')}`;
        changed = true;
      }
    }

    if (changed) {
      rng.setValues(values);
    }

    rng.setNumberFormat('yyyy-MM-dd');

    console.log(`[日付整形] ${logPrefix ? logPrefix + ' ' : ''}${header}列を yyyy-MM-dd 形式に設定`);
  }
}


function getFolderByPath_(pathParts) {
  let folder = DriveApp.getRootFolder();
  for (const name of pathParts) {
    const it = folder.getFoldersByName(name);
    if (!it.hasNext()) {
      throw new Error(`フォルダが見つかりません: ${pathParts.join(' / ')}`);
    }
    folder = it.next();
  }
  return folder;
}

function findSpreadsheetByExactNameInFolder_(folder, exactName) {
  const q = [
    `title = "${escapeQueryLiteral_(exactName)}"`,
    'mimeType = "application/vnd.google-apps.spreadsheet"',
    'trashed = false',
  ].join(' and ');

  const files = folder.searchFiles(q);
  return files.hasNext() ? files.next() : null;
}

function escapeQueryLiteral_(s) {
  return String(s).replace(/"/g, '\\"');
}

/**
 * 全銘柄日足分析マスタ更新用の見出し情報を取得する。
 *
 * 戻り値：
 * {
 *   headers: ['証券コード', '会社名', ...],
 *   map: {
 *     '証券コード': 1,
 *     '会社名': 2,
 *     ...
 *   }
 * }
 */
function getAnalysisHeaderMap_(sheet) {
  const lastColumn = sheet.getLastColumn();

  if (lastColumn < 1) {
    return {
      headers: [],
      map: {},
    };
  }

  const headers = sheet
    .getRange(1, 1, 1, lastColumn)
    .getValues()[0]
    .map(value => String(value ?? '').trim());

  const map = {};

  headers.forEach((header, index) => {
    if (header) {
      map[header] = index + 1;
    }
  });

  return {
    headers: headers,
    map: map,
  };
}

/**
 * 市況関連データ抽出＃経済スケジュールの
 * レポートTXT（タブ区切り）から「経済スケジュールマスタ」を更新する。
 *
 * 突合キー：
 *   日付 / 国 / 期間 / 指標名
 *
 * - masterにありsrcにもある → srcの値で上書き
 * - masterにありsrcにない   → 何もしない
 * - masterになくsrcにある   → master末尾へ追加
 * - 最後に「日付」の降順でソート
 */
function syncAndUpdateEconomicScheduleMaster_(reportFile, CONFIG) {
  const masterFolder = getFolderByPath_(CONFIG.masterFolderPath);
  const masterFile = findSpreadsheetByExactNameInFolder_(
    masterFolder,
    CONFIG.economicScheduleMasterName
  );

  if (!masterFile) {
    throw new Error(
      `経済スケジュールマスタ未発見: ` +
      `${CONFIG.masterFolderPath.join(' / ')} / ${CONFIG.economicScheduleMasterName}`
    );
  }

  const masterSs = SpreadsheetApp.openById(masterFile.getId());
  const masterSheet = masterSs.getSheets()[0];

  if (!masterSheet) {
    throw new Error('経済スケジュールマスタ: 対象シートが見つかりません。');
  }

  // UTF-8のタブ区切りTXTを読み込む
  const text = reportFile.getBlob().getDataAsString('UTF-8').replace(/^\uFEFF/, '');
  const lines = text.split(/\r?\n/);

  // 「日付」を含む行をヘッダ行として特定
  const headerLineIndex = lines.findIndex(line => {
    const cols = line.split('\t').map(v => String(v ?? '').trim());
    return cols.includes('日付');
  });

  if (headerLineIndex < 0) {
    throw new Error(
      '市況関連データ抽出＃経済スケジュール: 見出し行「日付」が見つかりません。'
    );
  }

  const tsvText = lines.slice(headerLineIndex).join('\n');
  const srcAll = Utilities.parseCsv(tsvText, '\t');

  if (srcAll.length < 2) {
    console.log('[経済スケジュールマスタ更新] スキップ: 取得データなし');
    return {
      updatedRows: 0,
      insertedRows: 0,
      masterUrl: masterSs.getUrl(),
    };
  }

  const srcHeaders = srcAll[0].map(v => String(v ?? '').trim());
  const srcData = srcAll.slice(1).filter(row =>
    row.some(v => String(v ?? '').trim() !== '')
  );

  const mstLastRow = masterSheet.getLastRow();
  const mstLastCol = masterSheet.getLastColumn();

  if (mstLastRow < 1 || mstLastCol < 1) {
    throw new Error('経済スケジュールマスタ: ヘッダ行が見つかりません。');
  }

  const mstAll = masterSheet
    .getRange(1, 1, mstLastRow, mstLastCol)
    .getValues();

  const mstHeaders = mstAll[0].map(v => String(v ?? '').trim());

  const srcHeaderToIdx = {};
  srcHeaders.forEach((h, i) => {
    if (h) srcHeaderToIdx[h] = i;
  });

  const mstHeaderToIdx = {};
  mstHeaders.forEach((h, i) => {
    if (h) mstHeaderToIdx[h] = i;
  });

  const keyHeaders = ['日付', '国', '期間', '指標名'];

  for (const h of keyHeaders) {
    if (srcHeaderToIdx[h] == null) {
      throw new Error(
        `市況関連データ抽出＃経済スケジュール: 見出し「${h}」が見つかりません。`
      );
    }

    if (mstHeaderToIdx[h] == null) {
      throw new Error(
        `経済スケジュールマスタ: 見出し「${h}」が見つかりません。`
      );
    }
  }

  const commonHeaders = srcHeaders.filter(
    h => h && mstHeaderToIdx[h] != null
  );

  // 日付を突合・ソート用に正規化
  const dateKey = (v) => {
    if (v instanceof Date) {
      return Utilities.formatDate(
        v,
        Session.getScriptTimeZone(),
        'yyyy-MM-dd'
      );
    }

    const s = String(v ?? '').trim();
    if (!s) return '';

    const m = s.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/);
    if (m) {
      return [
        m[1],
        String(m[2]).padStart(2, '0'),
        String(m[3]).padStart(2, '0'),
      ].join('-');
    }

    return s;
  };

  const makeKey = (row, headerToIdx) => {
    return keyHeaders.map(h => {
      const v = row[headerToIdx[h]];
      return h === '日付'
        ? dateKey(v)
        : String(v ?? '').trim();
    }).join('\u0001');
  };

  // masterの複合キー → 行番号
  const keyToMasterRow = new Map();

  for (let r = 1; r < mstAll.length; r++) {
    const key = makeKey(mstAll[r], mstHeaderToIdx);
    if (!keyToMasterRow.has(key)) {
      keyToMasterRow.set(key, r);
    }
  }

  let updatedRows = 0;
  let insertedRows = 0;

  for (const srcRow of srcData) {
    const key = makeKey(srcRow, srcHeaderToIdx);
    const mstRow = keyToMasterRow.get(key);

    if (mstRow != null) {
      // 既存行を上書き更新
      for (const h of commonHeaders) {
        mstAll[mstRow][mstHeaderToIdx[h]] =
          srcRow[srcHeaderToIdx[h]];
      }

      updatedRows++;
      continue;
    }

    // 新規行追加
    const newRow = new Array(mstHeaders.length).fill('');

    for (const h of commonHeaders) {
      newRow[mstHeaderToIdx[h]] =
        srcRow[srcHeaderToIdx[h]];
    }

    mstAll.push(newRow);
    keyToMasterRow.set(key, mstAll.length - 1);
    insertedRows++;
  }

  // ヘッダを除き「日付」降順
  const headerRow = mstAll[0];
  const dataRows = mstAll.slice(1);
  const dateIdxM = mstHeaderToIdx['日付'];

  dataRows.sort((a, b) => {
    const dateA = dateKey(a[dateIdxM]);
    const dateB = dateKey(b[dateIdxM]);
    return dateB.localeCompare(dateA);
  });

  const finalRows = [headerRow, ...dataRows];

  // 既存内容をクリアして再配置
  masterSheet
    .getRange(
      1,
      1,
      Math.max(mstLastRow, finalRows.length),
      mstLastCol
    )
    .clearContent();

  masterSheet
    .getRange(1, 1, finalRows.length, mstLastCol)
    .setValues(finalRows);

  console.log(
    `[経済スケジュールマスタ更新] 完了: ` +
    `更新=${updatedRows}, 追加=${insertedRows}`
  );

  return {
    updatedRows,
    insertedRows,
    masterUrl: masterSs.getUrl(),
  };
}
