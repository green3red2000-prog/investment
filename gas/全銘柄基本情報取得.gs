/***********************
 * 全銘柄基本情報取得（株探のみ）
 * - カレント管理: 出力結果/全銘柄基本情報取得_yyyy-MM-dd
 * - データ保持  : マスタ/全銘柄基本情報マスタ
 ***********************/

const CONFIG = {
  // ====== Driveパス（MyDrive配下）======
  PATH_OUT_FOLDER: ["投資","プログラミング","GAS","スクレイピング","出力結果"],
  PATH_MASTER_FOLDER: ["投資","プログラミング","GAS","マスタ"],

  // ====== ファイル名 ======
  CURRENT_FILE_PREFIX: "全銘柄基本情報取得_",           // + yyyy-MM-dd
  CODE_MASTER_FILE_NAME: "証券コードマスタ",
  MASTER_FILE_NAME: "全銘柄基本情報マスタ",

  // ====== シート/列名 ======
  CURRENT_HEADERS: ["証券コード","更新日","実行結果"],

  // 既存の保持データ（全銘柄基本情報マスタ）の見出し（以前のHEADERSを踏襲）
  DATA_HEADERS: [
    "証券コード","更新日","実行結果","会社名略称","会社名","業種","概要","特色","連結事業",
    "時価総額","上場区分","売上高","経常益","最終益","PER","PBR","利回り","終値","前日比","騰落率",
    "出来高","信用日付","信用売り残","信用買い残","信用倍率"
  ],

  MAX_ROWS: 85,
  TZ: "Asia/Tokyo",
  MAIL_TO: "green3red2000@gmail.com",
  MAIL_SUBJECT_PREFIX: "全銘柄基本情報取得：",
  SLEEP_MS: 1000
};


/* ========== メイン ========== */
function main() {
  if (!isExecutionAllowedJST_(new Date())) return;

  const todayDateOnly = getTodayDateOnlyJST_();
  const todayStrFile  = Utilities.formatDate(todayDateOnly, CONFIG.TZ, "yyyy-MM-dd");
  const todayStrMail  = Utilities.formatDate(todayDateOnly, CONFIG.TZ, "yyyy/MM/dd");

  // 1) カレント管理ファイル（全銘柄基本情報取得_yyyy-MM-dd）を開く（なければ作る）
  const { ss: curSS, sheet: curSheet } = openOrCreateCurrentSheet_(todayStrFile);

  // 2) 保持マスタ（全銘柄基本情報マスタ）を開く
  const { ss: masterSS, sheet: masterSheet } = openMasterSheet_();

  const curHeaderMap = buildHeaderMapFromRow_(curSheet, CONFIG.CURRENT_HEADERS);
  const masterHeaderMap = buildHeaderMapFromRow_(masterSheet, CONFIG.DATA_HEADERS);

  // マスタ側の書式を先に適用（従来の整形ルール）
  applyNumberFormats_(masterSheet, masterHeaderMap);

  // 3) マスタの証券コード→行番号の索引を作る（upsert高速化）
  const masterIndex = buildMasterIndexByCode_(masterSheet, masterHeaderMap);

  // 4) カレント（当日ファイル）から処理対象を読む
  const curLastRow = curSheet.getLastRow();
  if (curLastRow < 2) return;

  const height = curLastRow - 1;
  const colCode = curHeaderMap["証券コード"];
  const colUpd  = curHeaderMap["更新日"];
  const colRes  = curHeaderMap["実行結果"];

  const codeVals = curSheet.getRange(2, colCode, height, 1).getValues().map(r => String(r[0] || "").trim());
  const updVals  = curSheet.getRange(2, colUpd,  height, 1).getValues().map(r => r[0]);

  let processed = 0;
  let endedBecauseEmptyCode = false;
  let endedByMaxRows = false;

  for (let i = 0; i < height; i++) {
    const row  = 2 + i;
    const code = codeVals[i];

    if (!code) {
      console.log("End at row " + row + " (empty 証券コード) -> will send summary mail");
      endedBecauseEmptyCode = true;
      break;
    }

    console.log("Processing current row " + row + " / code=" + code);

    if (processed >= CONFIG.MAX_ROWS) {
      console.log("Reached MAX_ROWS at row " + row + " -> no mail");
      endedByMaxRows = true;
      break;
    }

    const updVal = updVals[i];
    if (!shouldProcessRow_(updVal, todayDateOnly)) continue;

    // --- スクレイピング ---
    let kabutanOk = false;
    let kabutanData = {};
    try {
      kabutanData = fetchFromKabutan_(code);
      kabutanOk = true;
    } catch (e) {
      kabutanOk = false;
      console.log("Kabutan error at current row " + row + ": " + e);
    }

    // --- マスタに書く行データを作る（DATA_HEADERS分） ---
    const rowValsMap = {};
    // まずは空で初期化
    CONFIG.DATA_HEADERS.forEach(h => rowValsMap[h] = "");

    // 必須
    rowValsMap["証券コード"] = code;

    if (kabutanOk) {
      assignIfPresent_(rowValsMap, "会社名略称", kabutanData.companyShort);
      assignIfPresent_(rowValsMap, "上場区分",   kabutanData.marketType);
      assignIfPresent_(rowValsMap, "終値",       kabutanData.close);
      assignIfPresent_(rowValsMap, "前日比",     kabutanData.prevDiff);
      assignIfPresent_(rowValsMap, "騰落率",     kabutanData.changeRate);
      assignIfPresent_(rowValsMap, "PER",        kabutanData.per);
      assignIfPresent_(rowValsMap, "PBR",        kabutanData.pbr);
      assignIfPresent_(rowValsMap, "利回り",     kabutanData.yield);
      assignIfPresent_(rowValsMap, "時価総額",   kabutanData.marketCap);

      assignIfPresent_(rowValsMap, "会社名",     kabutanData.companyName);
      assignIfPresent_(rowValsMap, "業種",       kabutanData.industry);
      assignIfPresent_(rowValsMap, "概要",       kabutanData.summary);
      assignIfPresent_(rowValsMap, "売上高",     kabutanData.sales);
      assignIfPresent_(rowValsMap, "経常益",     kabutanData.keijo);
      assignIfPresent_(rowValsMap, "最終益",     kabutanData.netIncome);

      assignIfPresent_(rowValsMap, "出来高",     kabutanData.volume);
      assignIfPresent_(rowValsMap, "信用日付",   kabutanData.marginDate);
      assignIfPresent_(rowValsMap, "信用売り残", kabutanData.marginShort);
      assignIfPresent_(rowValsMap, "信用買い残", kabutanData.marginLong);
      assignIfPresent_(rowValsMap, "信用倍率",   kabutanData.marginRatio);
    }

    // 書き込み前整形
    enforceOutputFormatsAsNumber_(rowValsMap);

    // 更新日/実行結果（マスタ側）
    rowValsMap["更新日"] = todayDateOnly;

    // upsert（マスタ側）
    if (kabutanOk) {
      const hitRow = masterIndex[code];
      if (hitRow) {
        rowValsMap["実行結果"] = "正常";
        writeMasterRow_(masterSheet, masterHeaderMap, hitRow, rowValsMap);
      } else {
        // 新規は「実行結果=新規登録」
        rowValsMap["実行結果"] = "新規登録";
        const newRow = appendMasterRow_(masterSheet, masterHeaderMap, rowValsMap);
        masterIndex[code] = newRow; // 索引更新
      }
    }

    // カレント管理（当日ファイル）側の更新
    // ※仕様通り「カレント管理」は当日ファイルで行う
    // 取得OKなら「正常」、NGなら「エラー：株探読み込みに失敗」
    curSheet.getRange(row, colUpd).setValue(todayDateOnly);
    curSheet.getRange(row, colRes).setValue(kabutanOk ? "正常" : "エラー：株探読み込みに失敗");

    console.log("Updated current row " + row + " result=" + (kabutanOk ? "正常" : "エラー"));

    processed++;
    Utilities.sleep(CONFIG.SLEEP_MS);
  }

  const reachedSheetEnd = (!endedBecauseEmptyCode && !endedByMaxRows);

  // メール送信条件（従来ロジック踏襲）
  if ((endedBecauseEmptyCode || reachedSheetEnd) && processed > 0) {
    applyNumberFormats_(masterSheet, masterHeaderMap); // 念のため最終整形
    console.log("Send mail: endedBecauseEmptyCode=" + endedBecauseEmptyCode + ", reachedSheetEnd=" + reachedSheetEnd + ", processed=" + processed);
    sendSummaryMailForCurrent_(curSheet, curHeaderMap, todayStrMail, curSS.getUrl());
  } else {
    console.log("Skip mail: endedBecauseEmptyCode=" + endedBecauseEmptyCode + ", reachedSheetEnd=" + reachedSheetEnd + ", processed=" + processed);
  }
}


/* ========== Drive/Spreadsheet ユーティリティ ========== */

// MyDrive配下のフォルダをパス配列で辿って取得
function getFolderByPath_(pathArr) {
  let folder = DriveApp.getRootFolder(); // My Drive
  for (const name of pathArr) {
    const it = folder.getFoldersByName(name);
    if (!it.hasNext()) throw new Error("フォルダが見つかりません: " + pathArr.join(" > "));
    folder = it.next();
  }
  return folder;
}

function openOrCreateCurrentSheet_(todayStrFile) {
  const outFolder = getFolderByPath_(CONFIG.PATH_OUT_FOLDER);
  const fileName = CONFIG.CURRENT_FILE_PREFIX + todayStrFile;

  const files = outFolder.getFilesByName(fileName);
  if (files.hasNext()) {
    const f = files.next();
    const ss = SpreadsheetApp.openById(f.getId());
    const sheet = ss.getSheets()[0];
    // 見出しチェック（無いなら作る、ではなくエラーで良い）
    ensureHeadersRow_(sheet, CONFIG.CURRENT_HEADERS);
    return { ss, sheet };
  }

  // 無ければ新規作成
  const ss = SpreadsheetApp.create(fileName);
  const sheet = ss.getSheets()[0];
  sheet.setName("Sheet1");

  // 見出し
  sheet.getRange(1, 1, 1, CONFIG.CURRENT_HEADERS.length).setValues([CONFIG.CURRENT_HEADERS]);

  // 証券コードマスタから証券コード列を全コピー
  const codes = loadAllCodesFromCodeMaster_();
  if (codes.length > 0) {
    const values = codes.map(c => [c, "", ""]); // 更新日/実行結果は空
    sheet.getRange(2, 1, values.length, 3).setValues(values);
  }

  // 出力結果フォルダへ移動
  const file = DriveApp.getFileById(ss.getId());
  outFolder.addFile(file);
  DriveApp.getRootFolder().removeFile(file);

  return { ss, sheet };
}

function loadAllCodesFromCodeMaster_() {
  const masterFolder = getFolderByPath_(CONFIG.PATH_MASTER_FOLDER);
  const files = masterFolder.getFilesByName(CONFIG.CODE_MASTER_FILE_NAME);
  if (!files.hasNext()) throw new Error("指定ファイルが見つかりません: " + CONFIG.CODE_MASTER_FILE_NAME);

  const f = files.next();
  const ss = SpreadsheetApp.openById(f.getId());
  const sheet = ss.getSheets()[0];

  // 「証券コード」列を探して全コピー
  const header = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0].map(v => String(v || "").trim());
  const idx = header.indexOf("証券コード");
  if (idx === -1) throw new Error("証券コードマスタに「証券コード」列が見つかりません");

  const col = idx + 1;
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return [];

  const vals = sheet.getRange(2, col, lastRow - 1, 1).getValues()
    .map(r => String(r[0] || "").trim())
    .filter(v => v !== "");

  // 重複排除（念のため）
  const seen = {};
  const out = [];
  vals.forEach(v => { if (!seen[v]) { seen[v] = true; out.push(v); } });
  return out;
}

function openMasterSheet_() {
  const masterFolder = getFolderByPath_(CONFIG.PATH_MASTER_FOLDER);
  const files = masterFolder.getFilesByName(CONFIG.MASTER_FILE_NAME);
  if (!files.hasNext()) throw new Error("指定ファイルが見つかりません: " + CONFIG.MASTER_FILE_NAME);
  const file = files.next();
  const ss = SpreadsheetApp.openById(file.getId());
  const sheet = ss.getSheets()[0];
  return { ss, sheet };
}

function ensureHeadersRow_(sheet, headers) {
  const lastCol = Math.max(sheet.getLastColumn(), headers.length);
  const firstRow = sheet.getRange(1, 1, 1, lastCol).getValues()[0].map(v => String(v || "").trim());
  for (let i = 0; i < headers.length; i++) {
    if (firstRow[i] !== headers[i]) {
      throw new Error("見出しが一致しません。期待=" + headers.join(",") + " / 実際=" + firstRow.slice(0, headers.length).join(","));
    }
  }
}

function buildHeaderMapFromRow_(sheet, headers) {
  const headerValues = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  const map = {};
  headerValues.forEach((name, idx) => {
    const key = String(name || "").trim();
    if (headers.indexOf(key) !== -1) map[key] = idx + 1;
  });
  headers.forEach(h => { if (!map[h]) throw new Error("見出しが見つかりません: " + h); });
  return map;
}

// マスタの証券コード→行番号 index
function buildMasterIndexByCode_(sheet, headerMap) {
  const colCode = headerMap["証券コード"];
  const lastRow = sheet.getLastRow();
  const index = {};
  if (lastRow < 2) return index;

  const codes = sheet.getRange(2, colCode, lastRow - 1, 1).getValues().map(r => String(r[0] || "").trim());
  for (let i = 0; i < codes.length; i++) {
    const c = codes[i];
    if (!c) continue;
    index[c] = 2 + i; // シート行番号
  }
  return index;
}

// マスタ既存行を更新（DATA_HEADERS分を一括setValues）
function writeMasterRow_(sheet, headerMap, row, rowValsMap) {
  const outputRow = CONFIG.DATA_HEADERS.map(h => rowValsMap[h] ?? "");
  sheet.getRange(row, 1, 1, outputRow.length).setValues([outputRow]);
}

// マスタ末尾に追加し、追加した行番号を返す
function appendMasterRow_(sheet, headerMap, rowValsMap) {
  const outputRow = CONFIG.DATA_HEADERS.map(h => rowValsMap[h] ?? "");
  const newRow = Math.max(sheet.getLastRow() + 1, 2);
  sheet.getRange(newRow, 1, 1, outputRow.length).setValues([outputRow]);
  return newRow;
}


/* ========== 日付・実行時間ユーティリティ ========== */

function getTodayDateOnlyJST_() {
  const now = new Date();
  const y = parseInt(Utilities.formatDate(now, CONFIG.TZ, "yyyy"), 10);
  const m = parseInt(Utilities.formatDate(now, CONFIG.TZ, "MM"), 10);
  const d = parseInt(Utilities.formatDate(now, CONFIG.TZ, "dd"), 10);
  return new Date(y, m - 1, d);
}

function shouldProcessRow_(updVal, todayDateOnly) {
  if (!updVal) return true;
  const valDate = new Date(updVal);
  const v = new Date(valDate.getFullYear(), valDate.getMonth(), valDate.getDate());
  return v.getTime() < todayDateOnly.getTime();
}

function isExecutionAllowedJST_(now) {
  const wd = parseInt(Utilities.formatDate(now, CONFIG.TZ, "u"), 10); // 1=Mon ... 7=Sun
  const hh = parseInt(Utilities.formatDate(now, CONFIG.TZ, "HH"), 10);
  const mi = parseInt(Utilities.formatDate(now, CONFIG.TZ, "mm"), 10);
  const minutes = hh * 60 + mi;

  // 月〜金：17:00〜23:59のみ実行
  if (wd >= 1 && wd <= 5) {
    return minutes >= 17 * 60 && minutes <= (23 * 60 + 59);
  }
  if (wd === 6 || wd === 7) return true; // 土日
  return false;
}

function assignIfPresent_(obj, key, val) {
  if (val !== undefined && val !== null && val !== "") obj[key] = val;
}


/* ========== 書式（マスタ側に適用） ========== */

function applyNumberFormats_(sheet, headerMap) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return;
  const height = lastRow - 1;

  const fixed1Cols = ["時価総額","売上高","経常益","最終益","PER","PBR","信用倍率"];
  fixed1Cols.forEach(name => {
    const c = headerMap[name];
    if (!c) return;
    sheet.getRange(2, c, height, 1).setNumberFormat("0.0");
  });

  const rightCols = [
    "時価総額","売上高","経常益","最終益","PER","PBR","利回り","終値","前日比","騰落率",
    "出来高","信用売り残","信用買い残","信用倍率"
  ];
  rightCols.forEach(name => {
    const c = headerMap[name];
    if (!c) return;
    sheet.getRange(2, c, height, 1).setHorizontalAlignment("right");
  });

  const dateCol = headerMap["信用日付"];
  if (dateCol) sheet.getRange(2, dateCol, height, 1).setHorizontalAlignment("left");
}


/* ========== メール送信（当日ファイルを集計） ========== */

function sendSummaryMailForCurrent_(curSheet, curHeaderMap, todayStrMail, sheetUrl) {
  const lastRow = curSheet.getLastRow();
  if (lastRow < 2) return;

  const colUpd = curHeaderMap["更新日"];
  const colRes = curHeaderMap["実行結果"];

  const updVals = curSheet.getRange(2, colUpd, lastRow - 1, 1).getValues().map(r => r[0]);
  const resVals = curSheet.getRange(2, colRes, lastRow - 1, 1).getValues().map(r => String(r[0] || ""));

  let targetCount = 0, okCount = 0, errCount = 0;

  for (let i = 0; i < updVals.length; i++) {
    const v = updVals[i];
    if (!v) continue;
    const d = new Date(v);
    const ds = Utilities.formatDate(new Date(d.getFullYear(), d.getMonth(), d.getDate()), CONFIG.TZ, "yyyy/MM/dd");
    if (ds === todayStrMail) {
      targetCount++;
      const r = resVals[i];
      if (r === "正常") okCount++;
      if (r.indexOf("エラー") !== -1) errCount++;
    }
  }

  const subject = CONFIG.MAIL_SUBJECT_PREFIX + todayStrMail;
  const body =
    "本日の全銘柄基本情報取得処理を終了しました。\n\n" +
    `処理対象件数は ${targetCount} 件でした。\n` +
    `処理件数は ${okCount} 件でした。\n\n` +
    `エラーの件数は ${errCount} 件でした。\n\n` +
    "カレント管理スプレッドシート：\n" + sheetUrl + "\n";

  GmailApp.sendEmail(CONFIG.MAIL_TO, subject, body);
}


/* ========== 株探取得（アンカー付き正規表現） ========== */

function fetchFromKabutan_(code) {
  const url = "https://kabutan.jp/stock/?code=" + encodeURIComponent(code);
  const html = UrlFetchApp.fetch(url, {
    muteHttpExceptions: true,
    followRedirects: true,
    headers: {
      "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36",
      "Accept-Language": "ja,en-US;q=0.7,en;q=0.3"
    }
  }).getContentText("UTF-8");

  if (!html || html.indexOf('id="stockinfo_i1"') === -1) {
    throw new Error("Kabutan HTML not found or layout changed");
  }

  let companyShort = extractText_(html, /<div id="stockinfo_i1"[\s\S]*?<h2>([\s\S]*?)<\/h2>/, true);
  companyShort = stripSpanAndContent_(companyShort)
                  .replace(/^\d{4}\s*/,"")
                  .replace(/^[\s　]+/,"")
                  .trim();

  const marketType = extractText_(html, /<span class="market">([\s\S]*?)<\/span>/).trim();

  let close = extractText_(html, /<div class="si_i1_2">[\s\S]*?<span class="kabuka">([\s\S]*?)<\/span>/).trim();
  close = normalizeNumber_(close);

  const ddBlock = matchFirst_(html, /<dl class="si_i1_dl1">([\s\S]*?)<\/dl>/);
  let prevDiff = "", changeRate = "";
  if (ddBlock) {
    const dds = ddBlock.match(/<dd>[\s\S]*?<\/dd>/g) || [];
    if (dds[0]) prevDiff = stripTags_(dds[0]);
    if (dds[1]) changeRate = normalizeNumber_(stripTags_(dds[1]));
  }

  const i3 = matchFirst_(html, /<div id="stockinfo_i3">([\s\S]*?)<\/div><!--stockinfo_i3-->/) ||
             matchFirst_(html, /<div id="stockinfo_i3">([\s\S]*?)<\/div>/);

  let per = "", pbr = "", yieldPct = "", marketCap = "", marginRatio = "";
  if (i3) {
    per = stripSpanAndContent_(extractText_(i3, /<tbody>[\s\S]*?<tr>\s*<td>([\s\S]*?)<\/td>/, true)).replace(/倍/g,"").trim();
    pbr = stripSpanAndContent_(extractText_(i3, /<tbody>[\s\S]*?<tr>[\s\S]*?<td>[\s\S]*?<\/td>\s*<td>([\s\S]*?)<\/td>/, true)).replace(/倍/g,"").trim();
    yieldPct = stripSpanAndContent_(extractText_(i3, /<tbody>[\s\S]*?<tr>[\s\S]*?<td>[\s\S]*?<\/td>\s*<td>[\s\S]*?<\/td>\s*<td>([\s\S]*?)<\/td>/, true));
    yieldPct = normalizeNumber_(yieldPct);

    const mr = extractText_(i3, /<tbody>[\s\S]*?<tr>[\s\S]*?<td>[\s\S]*?<\/td>\s*<td>[\s\S]*?<\/td>\s*<td>[\s\S]*?<\/td>\s*<td>([\s\S]*?)<\/td>/, true);
    if (mr) marginRatio = normalizeNumber_(stripSpanAndContent_(mr));

    const zika = extractText_(i3, /class="v_zika2">([\s\S]*?)<\/td>/, true);
    marketCap = stripSpanOnly_(zika).replace(/億円/g,"").trim();
  }

  let companyName = extractText_(html,
    /<div id="kobetsu_right"[\s\S]*?<div class="company_block">[\s\S]*?<h3>([\s\S]*?)<\/h3>/).trim();

  const companyTable = extractMatch1_(
    html,
    /<div id="kobetsu_right"[\s\S]*?<div class="company_block">[\s\S]*?<table[^>]*>([\s\S]*?)<\/table>/
  );
  let industry = "", summary = "";
  if (companyTable) {
    industry = extractFollowingCellFromBlock_(companyTable, /<th[^>]*>業種<\/th>/);
    summary  = extractFollowingCellFromBlock_(companyTable, /<th[^>]*>概要<\/th>/);
  }

  const gyousekiTbody = extractMatch1_(
    html,
    /<div id="kobetsu_right"[\s\S]*?<div class="gyouseki_block">[\s\S]*?<tbody>([\s\S]*?)<\/tbody>/
  );
  let sales = "", keijo = "", netIncome = "";
  if (gyousekiTbody) {
    const rows = gyousekiTbody.match(/<tr[\s\S]*?<\/tr>/g) || [];
    if (rows[1]) {
      const tds = rows[1].match(/<td[\s\S]*?<\/td>/g) || [];
      if (tds[0]) sales     = stripTags_(tds[0]);
      if (tds[1]) keijo     = stripTags_(tds[1]);
      if (tds[2]) netIncome = stripTags_(tds[2]);
    }
  }

  const leftBlock = extractMatch0_(html, /<div id="kobetsu_left">[\s\S]*?<\/div>/) || "";
  let volume = "";
  if (leftBlock) {
    const tables = leftBlock.match(/<table[\s\S]*?<\/table>/g) || [];
    if (tables && tables[1]) {
      const firstTd = extractMatch1_(tables[1], /<tbody>[\s\S]*?<tr>[\s\S]*?<td>([\s\S]*?)<\/td>/) || "";
      volume = normalizeNumber_(stripTags_(firstTd));
    }
  }

  let marginDate = "", marginShort = "", marginLong = "", marginRatio2 = "";
  const creditTable = extractMatch1_(
    html,
    /<div id="kobetsu_left"[\s\S]*?<h2[^>]*>\s*信用取引[\s\S]*?<\/h2>[\s\S]*?<table[^>]*>([\s\S]*?)<\/table>/
  );
  if (creditTable) {
    const firstRow = extractMatch1_(creditTable, /<tbody>[\s\S]*?<tr>([\s\S]*?)<\/tr>/) || "";
    const thDate   = extractMatch1_(firstRow, /<th[^>]*>([\s\S]*?)<\/th>/) || "";
    const tds      = firstRow.match(/<td>[\s\S]*?<\/td>/g) || [];

    marginDate  = stripTags_(thDate).replace(/[^\d/./-]/g, "").trim();
    if (tds[0]) marginShort = normalizeNumber_(stripTags_(tds[0]));
    if (tds[1]) marginLong  = normalizeNumber_(stripTags_(tds[1]));
    if (tds[2]) marginRatio2 = normalizeNumber_(stripTags_(tds[2]));
  }

  const marginRatioFinal = marginRatio || marginRatio2;

  return {
    companyShort,
    marketType,
    close,
    prevDiff,
    changeRate,
    per,
    pbr,
    yield: yieldPct,
    marketCap,

    companyName,
    industry,
    summary,
    sales, keijo, netIncome,

    volume,
    marginDate,
    marginShort,
    marginLong,
    marginRatio: marginRatioFinal
  };
}


/* ========== フォーマット（数値型＋1桁） ========== */

function enforceOutputFormatsAsNumber_(row) {
  row["時価総額"] = toFixed1MarketCapNumber_(row["時価総額"]);
  row["売上高"]   = toFixed1Number_(row["売上高"]);
  row["経常益"]   = toFixed1Number_(row["経常益"]);
  row["最終益"]   = toFixed1Number_(row["最終益"]);

  (function () {
    const raw = String(row["PER"] ?? "").replace(/倍/g, "").trim();
    if (/^(?:-|－|ー)$/.test(raw)) {
      row["PER"] = "ー";
    } else {
      row["PER"] = toFixed1Number_(raw);
    }
  })();

  row["PBR"]    = toFixed1Number_(String(row["PBR"] || "").replace(/倍/g,""));
  row["出来高"] = toNumber_(row["出来高"]);

  const shortNum = toNumber_(row["信用売り残"]);
  const longNum  = toNumber_(row["信用買い残"]);
  if (shortNum === 0 || longNum === 0) {
    row["信用倍率"] = "ー";
  } else {
    row["信用倍率"] = toFixed1Number_(String(row["信用倍率"] || "").replace(/倍/g,""));
  }
}


/* ========== HTMLユーティリティ ========== */

function extractMatch0_(str, regex) { const m = str.match(regex); return m ? m[0] : ""; }
function extractMatch1_(str, regex) { const m = str.match(regex); return m ? m[1] : ""; }

function extractText_(str, regex, keepSpanOnly) {
  const m = str.match(regex);
  if (!m) return "";
  let s = m[1] || "";
  if (keepSpanOnly) return stripSpanOnly_(s);
  return stripTags_(s);
}

function matchFirst_(str, regex) { const m = str.match(regex); return m ? m[0] : ""; }

function stripTags_(s) {
  return String(s)
    .replace(/<[^>]*>/g, "")
    .replace(/&nbsp;/gi, " ")
    .replace(/\u00A0/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}
function stripSpanOnly_(s) {
  return String(s)
    .replace(/<span[^>]*>|<\/span>/g, "")
    .replace(/&nbsp;/gi, " ")
    .replace(/\u00A0/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}
function stripSpanAndContent_(s) {
  return String(s)
    .replace(/<span[^>]*>[\s\S]*?<\/span>/g, "")
    .replace(/&nbsp;/gi, " ")
    .replace(/\u00A0/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}
function extractFollowingCellFromBlock_(block, thRegex) {
  const pos = block.search(thRegex);
  if (pos === -1) return "";
  const sub = block.slice(pos);
  const m = sub.match(/<\/th>\s*<td[^>]*>([\s\S]*?)<\/td>/);
  return m ? stripTags_(m[1]) : "";
}


/* ========== 数値ユーティリティ（数値型で返す） ========== */

function toFixed1Number_(raw) {
  if (raw === null || raw === undefined || raw === "") return "";
  const cleaned = String(raw)
    .replace(/&nbsp;/gi, "")
    .replace(/\u00A0/g, "")
    .replace(/[,\s　]/g, "");
  const n = parseFloat(cleaned);
  if (isNaN(n)) return "";
  return Number(n.toFixed(1));
}

function toNumber_(raw) {
  if (raw === null || raw === undefined || raw === "") return "";
  const cleaned = String(raw)
    .replace(/&nbsp;/gi, "")
    .replace(/\u00A0/g, "")
    .replace(/[,\s　]/g, "")
    .replace(/円|株|％|%/g, "");
  const n = parseFloat(cleaned);
  return isNaN(n) ? "" : n;
}

function toFixed1MarketCapNumber_(raw) {
  if (raw === null || raw === undefined || raw === "") return "";
  let str = String(raw)
    .replace(/&nbsp;/gi, "")
    .replace(/\u00A0/g, "")
    .replace(/,/g, "")
    .replace(/億円?/g, "")
    .trim();
  if (!str) return "";
  let totalOoku = 0;
  if (str.indexOf("兆") >= 0) {
    const parts = str.split("兆");
    const cho = parseFloat(parts[0]) || 0;
    const oku = parseFloat(parts[1] || "0") || 0;
    totalOoku = cho * 10000 + oku;
  } else {
    totalOoku = parseFloat(str) || 0;
  }
  return Number(totalOoku.toFixed(1));
}

function normalizeNumber_(s) {
  return String(s)
    .replace(/&nbsp;/gi, "")
    .replace(/\u00A0/g, "")
    .replace(/[,\s　]/g, "")
    .replace(/円|株|％|%/g, "")
    .trim();
}
