/**  BaseInfo Library（シート参照版・パターン可変｜全シート合算 writeLimit｜上限到達=戻り0）
 *  更新点（今回）：
 *    - config.startCol が 0 の場合、各シートの「右端の次列」を出力開始列として採用
 *
 *  既存の更新点：
 *    - fetchIntervalMs 廃止済み
 *    - 出力見出しを config.headerPattern で切替（0/未指定=従来36列, 1, 2, 3）
 *    - config.sheetName が ''（空）の場合、スプレッドシート内の全シートを対象（1枚でもその1枚を処理）
 *    - writeLimit は全シート合算の上限（既定: 2000 / 0=無制限）。上限到達時は **戻り値0** ＆ ログ「上限到達で途中終了 (0)」
 *
 *  公開関数: BaseInfo_run(config)
 *
 *  config（任意）:
 *    spreadsheetName   : string   … 書き出し先スプレッドシート名
 *    folderName        : string   … フォルダ名
 *    startCol          : number   … 出力開始列（既定: 7=G列、**0=シート右端の次列**）
 *    companyNameCol    : number   … 会社名列（既定: 4 = D列）
 *    codeCol           : number   … 銘柄コードの入力列（既定: 3 = C列）
 *    sheetName         : string   … シート名（既定: 'シート1'。''なら全シートを対象）
 *    kabutanLinkType   : number   … 株探リンク種別（1〜6, 既定=1）
 *    writeLimit        : number   … 書き出し合算上限（既定: 2000。0=無制限）
 *    bottomLineCol     : number   … 罫線・重複値圧縮の対象列（既定: 0 = 実行しない）
 *    headerPattern     : number   … 出力見出しパターン（0/未指定=従来36列, 1, 2, 3）
 */

// ====== デフォルト設定 ======
const __DFLT__ = {
  startCol: 7,
  companyNameCol: 4,
  codeCol: 3,
  sheetName: 'シート1',
  folderName: '出力結果',
  kabutanLinkType: 1,
  writeLimit: 2000,        // 既定2000（0なら無制限）
  bottomLineCol: 0,
  headerPattern: 0
};

// ====== 出力見出しパターン ======
const OUTPUT_HEADERS_BASE = [
  '業種','概要','株探','四季','銘偵',
  '時価総額','上場区分','PER','PBR','利回り',
  '終値','前日比','騰落率','出来高',
  '売上高','経常益','最終益',
  '信用日付','信用売り残','信用買い残','信用倍率',
  '(96) AI基準判定',
  '(10)終値の直近5日間の回帰係数',
  '(11)終値の直近10日間の回帰係数',
  '(12)終値の直近22日間の回帰係数',
  '(13)終値の直近45日間の回帰係数',
  '(14)終値の直近90日間の回帰係数',
  '(15)出来高の直近5日間の回帰係数',
  '(16)出来高の直近10日間の回帰係数',
  '(17)出来高の直近22日間の回帰係数',
  '(18)出来高の直近45日間の回帰係数',
  '(19)出来高の直近90日間の回帰係数',
  '(30)終値5日移動平均の直近5日の回帰係数',
  '(31)終値10日移動平均の直近10日の回帰係数',
  '(32)終値22日移動平均の直近10日の回帰係数',
  '(33)終値45日移動平均の直近10日の回帰係数',
  '(34)終値90日移動平均の直近10日の回帰係数',
  '(35)出来高5日移動平均の直近5日の回帰係数',
  '(36)出来高10日移動平均の直近10日の回帰係数',
  '(37)出来高22日移動平均の直近10日の回帰係数',
  '(38)出来高45日移動平均の直近10日の回帰係数',
  '(39)出来高90日移動平均の直近10日の回帰係数',
  '(84) 連続日数',
  '(86) 10日間上昇率',
  '(90) 10日間下落率',
  '(72) β',
  '(73) 相関',
  '(74) 相対ボラ',
  '(75) 残差ボラ',
  '(76) アップサイドβ',
  '(77) ダウンサイドβ',
  '(78) Up Capture',
  '(79) Down Capture',
  '(92) 低ボラ出来高増'
];

const OUTPUT_HEADERS_PTN1 = [
  '業種','概要','株探','四季','銘偵',
  '時価総額','上場区分','PER','PBR','利回り',
  '終値','前日比','騰落率','出来高',
  '売上高','経常益','最終益',
  '信用日付','信用売り残','信用買い残','信用倍率'
];

const OUTPUT_HEADERS_PTN2 = [
  '業種','概要','株探','四季','銘偵',
  '時価総額','上場区分','PER','PBR','利回り',
  '終値','前日比','騰落率','出来高',
  '売上高','経常益','最終益',
  '信用日付','信用売り残','信用買い残','信用倍率',
  '(96) AI基準判定'
];

const OUTPUT_HEADERS_PTN3 = [
  '(96) AI基準判定',
  '(10)終値の直近5日間の回帰係数',
  '(11)終値の直近10日間の回帰係数',
  '(12)終値の直近22日間の回帰係数',
  '(13)終値の直近45日間の回帰係数',
  '(14)終値の直近90日間の回帰係数',
  '(15)出来高の直近5日間の回帰係数',
  '(16)出来高の直近10日間の回帰係数',
  '(17)出来高の直近22日間の回帰係数',
  '(18)出来高の直近45日間の回帰係数',
  '(19)出来高の直近90日間の回帰係数',
  '(30)終値5日移動平均の直近5日の回帰係数',
  '(31)終値10日移動平均の直近10日の回帰係数',
  '(32)終値22日移動平均の直近10日の回帰係数',
  '(33)終値45日移動平均の直近10日の回帰係数',
  '(34)終値90日移動平均の直近10日の回帰係数',
  '(35)出来高5日移動平均の直近5日の回帰係数',
  '(36)出来高10日移動平均の直近10日の回帰係数',
  '(37)出来高22日移動平均の直近10日の回帰係数',
  '(38)出来高45日移動平均の直近10日の回帰係数',
  '(39)出来高90日移動平均の直近10日の回帰係数',
  '(84) 連続日数',
  '(86) 10日間上昇率',
  '(90) 10日間下落率',
  '(72) β',
  '(73) 相関',
  '(74) 相対ボラ',
  '(75) 残差ボラ',
  '(76) アップサイドβ',
  '(77) ダウンサイドβ',
  '(78) Up Capture',
  '(79) Down Capture',
  '(80) RSI',
  '(81) RSIの直近22日間の回帰係数',
  '(82) MACD',
  '(83) MACDの直近22日間の回帰係数',
  '(92) 低ボラ出来高増',
  '(93) 水平ライン上突破',
  '(94) 水平ライン下突破',
  '(95) GUPから全モ'
];

// ====== 公開関数 ======
function BaseInfo_run(config) {
  const cfg = normalizeConfig_(config);
  let ss;
  try {
    ss = getOrCreateSpreadsheetInFolder_(cfg.spreadsheetName, cfg.folderName);
  } catch (e) {
    Logger.log("書き出した件数: エラーで処理中断 (-1)");
    return -1;
  }

  // 対象シートの決定（''なら全シート。0枚なら生成）
  let targetSheets = [];
  try {
    if (cfg.sheetName === '') {
      targetSheets = ss.getSheets();
      if (!targetSheets || targetSheets.length === 0) {
        targetSheets = [ss.insertSheet('シート1')];
      }
    } else {
      const sh = ss.getSheetByName(cfg.sheetName) || ss.insertSheet(cfg.sheetName);
      targetSheets = [sh];
    }
  } catch (e) {
    Logger.log("書き出した件数: エラーで処理中断 (-1)");
    return -1;
  }

  // 参照インデックスのロード（一度だけ）
  let idx1, idx2;
  try {
    idx1 = loadSheetIndexByCode_(['投資','プログラミング','GAS','マスタ'], '全銘柄基本情報マスタ');
    idx2 = loadSheetIndexByCode_(['投資','プログラミング','GAS','マスタ'], '全銘柄日足分析マスタ');
  } catch (e) {
    Logger.log("書き出した件数: エラーで処理中断 (-1)");
    return -1;
  }

  // 全シート合算 writeLimit の管理
  let totalWrite = 0;
  const globalLimit = Number(cfg.writeLimit) || 0; // 0=無制限
  let limitReached = false;

  try {
    for (const sh of targetSheets) {
      const remaining = (globalLimit > 0) ? Math.max(globalLimit - totalWrite, 0) : 0; // 0=無制限
      if (globalLimit > 0 && remaining === 0) { limitReached = true; break; }

      const perSheetCfg = Object.assign({}, cfg, { __runtimeWriteLimit: remaining });

      const res = writeFromSheetsCore_(sh, perSheetCfg, idx1, idx2); // {written, hitLimit, error}
      if (res.error) {
        Logger.log("書き出した件数: エラーで処理中断 (-1)");
        return -1;
      }
      totalWrite += res.written;

      if (res.written > 0 && cfg.bottomLineCol > 0) {
        applyBottomLinesAndDedup_(sh, cfg.bottomLineCol);
      }

      if (res.hitLimit) {
        if (globalLimit > 0 && totalWrite >= globalLimit) {
          limitReached = true;
          break;
        }
      }
    }

    if (globalLimit > 0 && totalWrite >= globalLimit) limitReached = true;

    if (limitReached) {
      Logger.log("書き出した件数: 上限到達で途中終了 (0)");
      return 0;
    }

    if (totalWrite > 0) {
      Logger.log(`書き出した件数: ${totalWrite}行`);
      return totalWrite;
    } else {
      Logger.log("書き出した件数: 上限到達で途中終了 (0)");
      return 0;
    }
  } catch (e) {
    Logger.log("書き出した件数: エラーで処理中断 (-1)");
    return -1;
  }
}

// ====== 設定正規化 ======
function normalizeConfig_(config) {
  const now = new Date();
  const defaultSpreadsheetName =
    `決算速報_${Utilities.formatDate(now, Session.getScriptTimeZone(), 'yyyy-MM-dd')}`;
  const c = config || {};
  return {
    spreadsheetName: (c.spreadsheetName && String(c.spreadsheetName).trim()) || defaultSpreadsheetName,
    folderName     : (c.folderName && String(c.folderName).trim())           || __DFLT__.folderName,
    startCol       : isFinite(c.startCol) ? Number(c.startCol)               : __DFLT__.startCol, // 0 を許容
    companyNameCol : isFinite(c.companyNameCol) ? Number(c.companyNameCol)   : __DFLT__.companyNameCol,
    codeCol        : isFinite(c.codeCol) ? Number(c.codeCol)                 : __DFLT__.codeCol,
    sheetName      : (c.sheetName != null) ? String(c.sheetName)             : __DFLT__.sheetName, // 空文字許容
    kabutanLinkType: isFinite(c.kabutanLinkType) ? Number(c.kabutanLinkType) : __DFLT__.kabutanLinkType,
    writeLimit     : (isFinite(c.writeLimit) ? Number(c.writeLimit) : __DFLT__.writeLimit), // 既定2000
    bottomLineCol  : isFinite(c.bottomLineCol) ? Number(c.bottomLineCol)     : __DFLT__.bottomLineCol,
    headerPattern  : isFinite(c.headerPattern) ? Number(c.headerPattern)     : __DFLT__.headerPattern
  };
}

/* =========================
 *  出力見出しの取得
 * ========================= */
function getOutputHeaders_(pattern) {
  switch (pattern) {
    case 1: return OUTPUT_HEADERS_PTN1.slice();
    case 2: return OUTPUT_HEADERS_PTN2.slice();
    case 3: return OUTPUT_HEADERS_PTN3.slice();
    default: return OUTPUT_HEADERS_BASE.slice(); // 0/未定義/その他
  }
}

/* =========================
 *  startCol 解決（0 → 右端の次列、1以上 → そのまま）
 * ========================= */
function resolveStartCol_(sh, cfgStartCol) {
  const n = Number(cfgStartCol) || 0;
  if (n === 0) {
    const last = Math.max(sh.getLastColumn(), 0);
    return Math.max(1, last + 1);
  }
  return n;
}

/* =========================
 *  メイン（シート単位処理）
 *  戻り値: { written:number, hitLimit:boolean, error:boolean }
 * ========================= */
function writeFromSheetsCore_(sh, cfg, idx1, idx2) {
  const OUTPUT_HEADERS = getOutputHeaders_(cfg.headerPattern);

  // ★ startCol を確定（0指定時は右端の次列）
  const startCol = resolveStartCol_(sh, cfg.startCol);

  ensureHeader_(sh, startCol, OUTPUT_HEADERS);

  const lastRow = sh.getLastRow();
  if (lastRow < 2) return { written: 0, hitLimit: false, error: false };

  const numRows = lastRow - 1;
  const codeColVals    = sh.getRange(2, cfg.codeCol, numRows, 1).getValues();
  const outHeadColVals = sh.getRange(2, startCol, numRows, 1).getValues();

  let writeCount = 0;
  let hitLimit = false;
  const localLimit = (cfg.__runtimeWriteLimit != null ? Number(cfg.__runtimeWriteLimit) : Number(cfg.writeLimit)) || 0; // 0=無制限

  try {
    for (let i = 0; i < numRows; i++) {
      const rowIndex = i + 2;
      const code     = String(codeColVals[i][0] || '').trim();
      const already  = String(outHeadColVals[i][0] || '').trim();

      if (!code) continue;
      if (already) continue;

      if (localLimit > 0 && writeCount >= localLimit) {
        hitLimit = true;
        break;
      }

      const rec1 = idx1.map.get(code) || null;
      const rec2 = idx2.map.get(code) || null;

      const kabutanUrls = {
        1: `https://kabutan.jp/stock/?code=${code}`,
        2: `https://kabutan.jp/stock/chart?code=${code}`,
        3: `https://kabutan.jp/stock/kabuka?code=${code}`,
        4: `https://kabutan.jp/stock/news?code=${code}`,
        5: `https://kabutan.jp/stock/finance?code=${code}`,
        6: `https://kabutan.jp/stock/holder?code=${code}`
      };
      const kabutanUrl = kabutanUrls[cfg.kabutanLinkType] || kabutanUrls[1];
      const kabutanLink = `=HYPERLINK("${kabutanUrl}","株")`;
      const shikihoLink = `=HYPERLINK("https://shikiho.toyokeizai.net/stocks/${code}","季")`;
      const meiteiLink  = `=HYPERLINK("https://monex.ifis.co.jp/index.php?sa=find&ta=e&wd=${code}&x=0&y=0","銘")`;

      const rowData = OUTPUT_HEADERS.map((label) => {
        if (label === '株探') return kabutanLink;
        if (label === '四季') return shikihoLink;
        if (label === '銘偵') return meiteiLink;
        const v1 = rec1 ? pickByHeader_(rec1, label) : '';
        const v2 = rec2 ? pickByHeader_(rec2, label) : '';
        return valuePrefer_(v1, v2);
      });

      sh.getRange(rowIndex, startCol, 1, OUTPUT_HEADERS.length).setValues([rowData]);

      const name1v = rec1 ? pickByHeader_(rec1, '会社名') : '';
      const name2v = rec2 ? pickByHeader_(rec2, '会社名') : '';
      const companyName = valuePrefer_(name1v, name2v);
      if (companyName) {
        sh.getRange(rowIndex, cfg.companyNameCol).setValue(companyName);
      }

      writeCount++;
    }

    // 書式（列位置は startCol 基準）
    const rightAlignIdx = ['終値','前日比','騰落率','出来高','時価総額','PER','PBR','利回り']
      .map(lbl => OUTPUT_HEADERS.indexOf(lbl)).filter(i => i >= 0);
    rightAlignIdx.forEach(idx => {
      sh.getRange(2, startCol + idx, numRows, 1).setHorizontalAlignment('right');
    });

    ['株探','四季','銘偵']
      .map(lbl => OUTPUT_HEADERS.indexOf(lbl)).filter(i => i >= 0)
      .forEach(idx => {
        sh.getRange(2, startCol + idx, numRows, 1).setHorizontalAlignment('center');
      });

    const perIdx = OUTPUT_HEADERS.indexOf('PER');
    if (perIdx >= 0) sh.getRange(2, startCol + perIdx, numRows, 1).setNumberFormat('0.0');
    const pbrIdx = OUTPUT_HEADERS.indexOf('PBR');
    if (pbrIdx >= 0) sh.getRange(2, startCol + pbrIdx, numRows, 1).setNumberFormat('0.00');

    return { written: writeCount, hitLimit, error: false };
  } catch (e) {
    return { written: 0, hitLimit: false, error: true };
  }
}

/* ========================= ヘルパ群 ========================= */
function valuePrefer_(v1, v2) {
  const s1 = String(v1 ?? '').trim();
  if (s1 !== '') return v1;
  return v2 ?? '';
}

function loadSheetIndexByCode_(pathArray, spreadsheetName) {
  const ss = openSpreadsheetInPath_(pathArray, spreadsheetName);
  const sheet = ss.getSheets()[0];
  const range = sheet.getDataRange();
  const values = range.getValues();

  if (values.length === 0) {
    return { headers: [], headerIndex: new Map(), map: new Map() };
  }

  const headers = (values[0] || []).map(h => String(h || '').trim());
  const headerIndex = new Map();
  headers.forEach((h, i) => headerIndex.set(h, i));

  const codeColIndex = 0; // 1列目が証券コード
  const map = new Map();

  for (let r = 1; r < values.length; r++) {
    const row = values[r] || [];
    const code = String(row[codeColIndex] || '').trim();
    if (!code) continue;
    if (!map.has(code)) {
      map.set(code, { row, headers, headerIndex });
    }
  }

  return { headers, headerIndex, map };
}

function pickByHeader_(rec, label) {
  const idx = rec.headerIndex.get(label);
  if (idx == null) return '';
  return rec.row[idx];
}

function openSpreadsheetInPath_(pathArray, spreadsheetName) {
  const folder = getFolderByPath_(pathArray);
  const files = folder.getFilesByName(spreadsheetName);
  if (!files.hasNext()) {
    throw new Error(`スプレッドシートが見つかりません: ${pathArray.join('/')} / ${spreadsheetName}`);
  }
  return SpreadsheetApp.open(files.next());
}

function getFolderByPath_(pathArray) {
  let current = DriveApp.getRootFolder();
  for (const name of pathArray) {
    const it = current.getFoldersByName(name);
    if (!it.hasNext()) {
      throw new Error(`フォルダが見つかりません: ${name}（パス: ${pathArray.join('/')}）`);
    }
    current = it.next();
  }
  return current;
}

function applyBottomLinesAndDedup_(sheet, bottomLineCol) {
  if (!bottomLineCol || bottomLineCol <= 0) return;

  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return;
  const lastCol = sheet.getLastColumn();

  const numRows = lastRow - 1;
  const vals = sheet.getRange(2, bottomLineCol, numRows, 1)
                   .getValues()
                   .map(r => String(r[0] || ''));

  sheet.getRange(1, 1, 1, lastCol)
       .setBorder(false, false, true, false, false, false, '#000000', SpreadsheetApp.BorderStyle.SOLID);

  for (let i = 0; i < vals.length; i++) {
    const curr = vals[i];
    const next = (i + 1 < vals.length) ? vals[i + 1] : null;
    if (next === null || curr !== next) {
      const r = i + 2;
      sheet.getRange(r, 1, 1, lastCol)
           .setBorder(false, false, true, false, false, false, '#000000', SpreadsheetApp.BorderStyle.SOLID);
    }
  }

  const out = [];
  let prev = null;
  for (let i = 0; i < vals.length; i++) {
    const v = vals[i];
    if (i === 0) { out.push([v]); prev = v; }
    else { out.push([v === prev ? '' : v]); if (v !== prev) prev = v; }
  }
  sheet.getRange(2, bottomLineCol, numRows, 1).setValues(out);
}

function ensureHeader_(sh, startCol, labels) {
  const range   = sh.getRange(1, startCol, 1, labels.length);
  const current = range.getValues()[0];
  const out     = current.slice();
  let changed   = false;
  for (let i = 0; i < labels.length; i++) {
    if (!current[i]) { out[i] = labels[i]; changed = true; }
  }
  if (changed) range.setValues([out]);
}

function getOrCreateSpreadsheetInFolder_(name, folderName) {
  const folder = getOrCreateFolderByName_(folderName);
  const files  = folder.getFilesByName(name);
  if (files.hasNext()) return SpreadsheetApp.open(files.next());

  const file = SpreadsheetApp.create(name);
  const newFile = DriveApp.getFileById(file.getId());
  folder.addFile(newFile);
  DriveApp.getRootFolder().removeFile(newFile);
  return file;
}

function getOrCreateFolderByName_(folderName) {
  const it = DriveApp.getFoldersByName(folderName);
  return it.hasNext() ? it.next() : DriveApp.createFolder(folderName);
}
