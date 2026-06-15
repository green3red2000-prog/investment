/**
 * スプレッドシート + 株探スクレイピング + レンタルサーバーDB(API) 連携スクリプト（完全版）
 * ---------------------------------------------------------------------------
 * 追加仕様（今回反映）：
 *  - 当日シート「全銘柄日足取得_yyyy-MM-dd」の列を4列にする：
 *      A:証券コード / B:更新日 / C:実行結果 / D:DB最新日付
 *  - 新規作成時に、DB最新日付を「一括取得API(1回)」で取得し D列にセット
 *  - getLatestAsOfDateFromDB_(code) での code毎SELECTは廃止し、D列で判定する
 * ---------------------------------------------------------------------------
 * UrlFetch回数：
 *  - DB最新日付一覧取得：最大1回（新規作成時 or D列欠落補修時）
 *  - DB一括upsert：完走時に1回
 */

// ====== 設定値 ======
const ROOT_FOLDER_NAME = '投資';

// 出力先フォルダ：投資/プログラミング/GAS/スクレイピング/出力結果
const OUTPUT_FOLDER_PATH = ['プログラミング', 'GAS', 'スクレイピング', '出力結果'];

// マスタフォルダ：投資/プログラミング/GAS/マスタ
const MASTER_FOLDER_PATH = ['プログラミング', 'GAS', 'マスタ'];
const MASTER_SPREADSHEET_NAME = '証券コードマスタ';
const MASTER_CODE_HEADER = '証券コード';

const DAILY_SHEET_PREFIX = '全銘柄日足取得_';
const API_DATA_PREFIX    = '全銘柄日足取得APIデータ_';

// ★ DB API（レンタルサーバー）設定
const DB_API_BASE = 'http://133.18.243.68/api';
// ★ Script Properties から取得するトークンのキー名
const DB_API_TOKEN_PROP_KEY = 'DB_API_TOKEN';

// ★ 一括最新日付API（今回追加）
const DB_LATEST_API_PATH = '/prices_eod_latest_by_code.php';

const SLEEP_MS_PER_ROW  = 1000; // “APIデータ書き出しあり” 行のみ 1 秒
const SLEEP_MS_PER_PAGE = 1000; // ページ切替は常に 1 秒

// ====== 持ち時間（秒）と消費テーブル ======
const TIME_BUDGET_SEC = 240; // 総持ち時間
const COST_FULL_SEC   = 20;  // 全件取得＆書き込み（Mode2）
const COST_DIFF_SEC   = 3;   // 差分取得＆書き込み（Mode1)
const COST_SKIP_SEC   = 0;   // シート更新なしスキップ（shouldProcess=false）
const COST_OTHER_SEC  = 20;  // それ以外

// ====== 実行許可ウィンドウ判定（JST基準） ======
function isWithinExecutionWindow_(now, tz) {
  const ymd  = Utilities.formatDate(now, tz, 'yyyy-MM-dd');
  const hour = parseInt(Utilities.formatDate(now, tz, 'H'), 10);
  const [y, m, d] = ymd.split('-').map(Number);
  const dow = new Date(y, m - 1, d).getDay(); // 0=日,...,6=土
  if (dow === 0 || dow === 6) return true; // 土日OK
  return (hour >= 17); // 月〜金 17時以降のみ
}

// ====== Script Properties（当日署名の保存 / DBトークン） ======
function getScriptProps_() {
  return PropertiesService.getScriptProperties();
}
function getDailyState_() {
  const p = getScriptProps_();
  return {
    date: p.getProperty('DAILY_STATE_DATE') || '',
    hash: p.getProperty('DAILY_STATE_HASH') || ''
  };
}
function setDailyState_(todaySlash, hash) {
  const p = getScriptProps_();
  p.setProperty('DAILY_STATE_DATE', todaySlash);
  p.setProperty('DAILY_STATE_HASH', hash);
}
function getDbApiToken_() {
  const p = getScriptProps_();
  const token = (p.getProperty(DB_API_TOKEN_PROP_KEY) || '').trim();
  if (!token) throw new Error(`DB_API_TOKEN が Script Properties に設定されていません。キー=${DB_API_TOKEN_PROP_KEY}`);
  return token;
}

// ====== シート状態の署名（A/B/C列を空コード行までハッシュ） ======
function computeSheetSignature_(sheet, tz) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return 'EMPTY';

  // A/B/Cのみで署名（従来通り）
  const values = sheet.getRange(2, 1, lastRow - 1, 3).getValues(); // [code, 更新日, 実行結果]
  const lines = [];

  for (const row of values) {
    const code = String(row[0] || '').trim();
    if (!code) break;
    const upd = normalizeSheetDateToSlash_(row[1], tz) || '';
    const res = String(row[2] || '').trim();
    lines.push([code, upd, res].join('|'));
  }

  const text = lines.join('\n');
  const bytes = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, text);
  return bytes.map(b => ('0' + (b & 0xFF).toString(16)).slice(-2)).join('');
}

// ============================================================================
// エントリーポイント
// ============================================================================
function runAll() {
  const tz  = 'Asia/Tokyo';
  const now = new Date();
  const todaySlash = Utilities.formatDate(now, tz, 'yyyy/MM/dd');
  const todayYMD   = Utilities.formatDate(now, tz, 'yyyy-MM-dd');

  if (!isWithinExecutionWindow_(now, tz)) {
    const nowStr = Utilities.formatDate(now, tz, 'yyyy/MM/dd HH:mm');
    Logger.log(`現在の時刻帯では実行しません（場中抑制）：${nowStr} JST`);
    return;
  }

  // 1) 当日分の「全銘柄日足取得_yyyy-MM-dd」を用意（無ければ新規作成してD列もセット）
  const { ss, sheet } = openOrCreateDailySpreadsheet_(todayYMD, tz);

  // 2) 当日APIデータファイルを用意（無ければ新規作成）
  const apiDataSheet = openOrCreateApiDataSpreadsheet_(todayYMD);

  const lastRow = sheet.getLastRow();
  if (lastRow < 2) {
    Logger.log('シートに処理対象の行がありません（見出しのみ）。処理を終了します。');
    return;
  }

  // 当日「変更なし」なら起動直後にスキップ
  const prev = getDailyState_();
  const preSig = computeSheetSignature_(sheet, tz);
  if (prev.date === todaySlash && prev.hash === preSig) {
    Logger.log(`本日は既に全行処理済み＆シート変更なしのため、この起動はスキップします。（${todaySlash}）`);
    return;
  }

  // ★ 4列読む：[証券コード, 更新日, 実行結果, DB最新日付]
  const values = sheet.getRange(2, 1, lastRow - 1, 4).getValues();

  let finishedAll    = false;
  let reachedTimeOut = false;
  let budgetLeft     = TIME_BUDGET_SEC;

  Logger.log(`処理開始：本日=${todaySlash}、持ち時間=${budgetLeft}秒`);

  for (let i = 0; i < values.length; i++) {
    const rowIndex    = i + 2;
    const code        = String(values[i][0] || '').trim();
    const updatedCell = values[i][1];
    const updatedLog  = formatUpdatedForLog_(updatedCell, tz);

    // ★ DB最新日付（D列）
    const dbLatestCell = values[i][3];
    const dbLatest = normalizeYMD_(String(dbLatestCell || '').trim()) || null; // 'YYYY-MM-DD' or null

    if (!code) {
      Logger.log(`終端に到達：${rowIndex} 行目の「証券コード」が空。全行処理完了と判断します。`);
      finishedAll = true;
      break;
    }

    const shouldProcess = isProcessTarget_(updatedCell, now, tz);
    Logger.log(`処理判定：行=${rowIndex}、コード=${code}、更新日='${updatedLog}'、処理対象=${shouldProcess ? 'はい' : 'いいえ'}`);

    let consumedSec = 0;
    let throttleAfterRow = false;
    let resultMsg = '';

    try {
      if (shouldProcess) {

        // ★ ここでDB API SELECT(1件)は呼ばない。D列で判定する
        const latest = dbLatest; // nullなら未登録扱い
        Logger.log(`DB最新日付（D列）：コード=${code}、asof_date=${latest || '（空）'}`);

        if (!latest) {
          // 全件取得（最大200件）
          Logger.log(`全件取得：コード=${code}、株探から最大200件取得開始`);
          const rows = scrapeKabutan_Mode2_Full_(code);
          Logger.log(`全件取得：コード=${code}、取得件数=${rows.length}件`);
          assertNoDuplicateDates_(rows);

          const wrote = appendApiDataRows_(apiDataSheet, rows);
          resultMsg   = `全件取得＆書き込み：${rows.length}件`;
          consumedSec = COST_FULL_SEC;
          Logger.log(`全件取得：コード=${code}、APIデータ書き出し=${wrote}件（消費=${consumedSec}秒）`);
          if (wrote > 0) throttleAfterRow = true;

          // ★ D列を更新（取得データの最大日付）
          const maxYmd = maxAsofDate_(rows);
          if (maxYmd) sheet.getRange(rowIndex, 4).setValue(maxYmd);

        } else {
          const cmp = compareYMD_(todayYMD, latest);
          if (cmp > 0) {
            // 差分取得（page=1）
            Logger.log(`差分取得：コード=${code}、today=${todayYMD} > latest=${latest}、page=1のみ取得`);
            const rowsAll = scrapeKabutan_Mode1_Page1_(code);
            const rows    = rowsAll.filter(r => r.asof_date > latest && r.asof_date <= todayYMD);
            Logger.log(`差分取得：コード=${code}、抽出件数（latest〜today）=${rows.length}件`);
            assertNoDuplicateDates_(rows);

            const wrote = rows.length ? appendApiDataRows_(apiDataSheet, rows) : 0;
            resultMsg   = `差分取得＆書き込み：${rows.length}件`;
            consumedSec = COST_DIFF_SEC;
            Logger.log(`差分取得：コード=${code}、APIデータ書き出し=${wrote}件（消費=${consumedSec}秒）`);
            if (wrote > 0) throttleAfterRow = true;

            // ★ D列を更新（差分の最大日付）
            const maxYmd = maxAsofDate_(rows);
            if (maxYmd) sheet.getRange(rowIndex, 4).setValue(maxYmd);

          } else if (cmp === 0) {
            resultMsg   = '処理なし';
            consumedSec = COST_OTHER_SEC;
            Logger.log(`同日判定：コード=${code} は最新が本日分のため「処理なし」（消費=${consumedSec}秒）`);

          } else {
            resultMsg   = 'エラー：DBに未来日が保存されている';
            consumedSec = COST_OTHER_SEC;
            Logger.log(`異常判定：latest=${latest} > today=${todayYMD}（消費=${consumedSec}秒）`);
          }
        }

        // shouldProcess=true の場合だけシート更新（B/C）
        sheet.getRange(rowIndex, 2).setValue(todaySlash);
        sheet.getRange(rowIndex, 3).setValue(resultMsg);
        Logger.log(`シート更新：行=${rowIndex}、結果='${resultMsg}'`);

        if (throttleAfterRow) {
          Logger.log(`スロットリング：コード=${code}、1秒待機（APIデータ書き出しあり）`);
          Utilities.sleep(SLEEP_MS_PER_ROW);
        }

      } else {
        consumedSec = COST_SKIP_SEC;
        Logger.log(`スキップ：コード=${code} は本日分が既に処理済みのためシート更新なし（消費=${consumedSec}秒）`);
      }

    } catch (err) {
      const msg = String(err && err.message || err);
      const isScrapeErr = /SCRAPE_ERROR/.test(msg) || /KABUTAN/.test(msg);
      const emsg = isScrapeErr ? 'エラー：株探読み込みに失敗' : 'エラー：処理が中断した';
      consumedSec = COST_OTHER_SEC;
      Logger.log(`エラー：行=${rowIndex}、コード=${code}、詳細=${msg}（消費=${consumedSec}秒）`);

      sheet.getRange(rowIndex, 2).setValue(todaySlash);
      sheet.getRange(rowIndex, 3).setValue(emsg);
    }

    budgetLeft -= consumedSec;
    Logger.log(`持ち時間：この行の消費=${consumedSec}秒、残り=${budgetLeft}秒`);
    if (budgetLeft <= 0) {
      reachedTimeOut = true;
      Logger.log('最大処理件数に達したため、この実行を終了します（メール送信は行いません）。');
      break;
    }
  }

  if (!reachedTimeOut && !finishedAll) {
    finishedAll = true;
    Logger.log('最終行まで処理を実行しました。全行処理完了と判断します。');
  }

  if (finishedAll && !reachedTimeOut) {
    Logger.log('全行処理完了：本日の集計を実施します。');

    const postSig = computeSheetSignature_(sheet, tz);

    // 署名が未登録 or 日付が違う or 署名が変わっていれば送信（=DB更新もここで実行）
    if (prev.date !== todaySlash || prev.hash !== postSig) {

      // DB一括upsert（UrlFetch 1回）
      try {
        const upserted = bulkUpsertFromApiDataSheet_(apiDataSheet);
        Logger.log(`DB一括upsert完了：${upserted}件（UrlFetch 1回）`);
      } catch (e) {
        Logger.log('DB一括upsertに失敗：' + e);
        Logger.log('DB更新失敗のため、メール送信および署名更新はスキップします。');
        Logger.log('処理終了。');
        return;
      }

      // メール送信
      const { targetCount, processedCount, errorCount } = countTodaySummary_(sheet, todaySlash);
      const subject = `全銘柄日足取得：${todaySlash}`;
      const body =
        '本日の全銘柄日足取得処理を終了しました。\n\n' +
        `処理対象件数は${targetCount}件でした。\n\n` +
        `処理件数は${processedCount}件でした。\n\n` +
        `エラーの件数は${errorCount}件でした。\n\n\n` +
        'スプレッドシート：\n' + ss.getUrl();

      try {
        MailApp.sendEmail({ to: 'green3red2000@gmail.com', subject, body });
        Logger.log('メール送信完了。今回のシート状態を署名として記録します。');
        setDailyState_(todaySlash, postSig);
      } catch (e) {
        Logger.log('メール送信に失敗：' + e);
      }

    } else {
      Logger.log('全行完了だがシート状態に変更がないため、DB更新/API実行およびメール送信は省略します。');
    }

  } else if (reachedTimeOut) {
    Logger.log('今回の実行は持ち時間に到達したため、メール送信はスキップしました。');
  } else {
    Logger.log('今回の実行は途中終了しました（全行未完了／持ち時間未到達）。メール送信はありません。');
  }

  Logger.log('処理終了。');
}

// ============================================================================
// Drive / Spreadsheet 作成・取得
// ============================================================================

function getOrCreateFolderPathUnderRoot_(rootFolderName, pathParts) {
  const roots = DriveApp.getFoldersByName(rootFolderName);
  if (!roots.hasNext()) throw new Error(`ルートフォルダが見つかりません：${rootFolderName}`);
  let folder = roots.next();

  for (const name of pathParts) {
    const it = folder.getFoldersByName(name);
    folder = it.hasNext() ? it.next() : folder.createFolder(name);
  }
  return folder;
}
function findFileByNameInFolder_(folder, fileName) {
  const files = folder.getFilesByName(fileName);
  return files.hasNext() ? files.next() : null;
}

// ★ 当日シートを開く（無ければ新規作成し、D列を一括API(1回)で埋める）
function openOrCreateDailySpreadsheet_(todayYMD, tz) {
  const folder = getOrCreateFolderPathUnderRoot_(ROOT_FOLDER_NAME, OUTPUT_FOLDER_PATH);
  const fileName = `${DAILY_SHEET_PREFIX}${todayYMD}`;

  let file = findFileByNameInFolder_(folder, fileName);
  let ss, sheet;

  if (!file) {
    Logger.log(`当日ファイルが存在しないため新規作成します：${fileName}`);

    // ★ DB最新日付を一括取得（UrlFetch 1回）
    const latestMap = fetchLatestMapFromDB_();

    ss = SpreadsheetApp.create(fileName);
    file = DriveApp.getFileById(ss.getId());
    folder.addFile(file);
    DriveApp.getRootFolder().removeFile(file);

    sheet = ss.getSheets()[0];
    initDailySheetFromMaster_(sheet, latestMap); // ★ D列セット含む
    Logger.log(`新規作成完了：${fileName}`);

    return { ss, sheet, fileUrl: ss.getUrl() };
  }

  ss = SpreadsheetApp.open(file);
  sheet = ss.getSheets()[0];

  // ★ 既存ファイルでも、D列が無い/見出しが古い場合は補修（必要時だけ一括API 1回）
  const needsFix = ensureDailySheetHeader4_(sheet);
  if (needsFix) {
    Logger.log('既存当日シートの見出し/列が旧仕様だったため補修します（D列追加＆一括取得）');
    const latestMap = fetchLatestMapFromDB_(); // UrlFetch 1回
    fillDbLatestColumn_(sheet, latestMap);
  }

  return { ss, sheet, fileUrl: ss.getUrl() };
}

// ★ 見出しが 4列になっているか確認。補修が必要なら true
function ensureDailySheetHeader4_(sheet) {
  const header = sheet.getRange(1, 1, 1, Math.max(4, sheet.getLastColumn())).getValues()[0];
  const a = String(header[0] || '').trim();
  const b = String(header[1] || '').trim();
  const c = String(header[2] || '').trim();
  const d = String(header[3] || '').trim();

  if (a === '証券コード' && b === '更新日' && c === '実行結果' && d === 'DB最新日付') return false;

  // 旧仕様（3列）などを想定して補修
  sheet.getRange(1, 1, 1, 4).setValues([['証券コード', '更新日', '実行結果', 'DB最新日付']]);
  sheet.getRange('A:A').setNumberFormat('@'); // 先頭ゼロ維持
  sheet.getRange('B:B').setNumberFormat('@');
  sheet.getRange('C:C').setNumberFormat('@');
  sheet.getRange('D:D').setNumberFormat('@');
  return true;
}

// ★ 新規作成：見出し＋マスタコード全コピー＋D列（最新日付）セット
function initDailySheetFromMaster_(sheet, latestMap) {
  sheet.clear();

  sheet.getRange(1, 1, 1, 4).setValues([['証券コード', '更新日', '実行結果', 'DB最新日付']]);

  sheet.getRange('A:A').setNumberFormat('@');
  sheet.getRange('B:B').setNumberFormat('@');
  sheet.getRange('C:C').setNumberFormat('@');
  sheet.getRange('D:D').setNumberFormat('@');

  const codes = loadCodesFromMaster_();
  if (!codes.length) {
    Logger.log('マスタから証券コードが取得できませんでした（0件）。');
    return;
  }

  const values = codes.map(code => {
    const c = String(code).trim();
    const latest = latestMap[c] || ''; // 'YYYY-MM-DD' or ''
    return [c, '', '', latest];
  });

  sheet.getRange(2, 1, values.length, 4).setValues(values);
}

// ★ 既存シート：D列に最新日付を埋める（A列コードと突合）
function fillDbLatestColumn_(sheet, latestMap) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return;

  const codes = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
  const out = [];

  for (const r of codes) {
    const code = String(r[0] || '').trim();
    if (!code) break;
    out.push([latestMap[code] || '']);
  }

  if (out.length) {
    sheet.getRange(2, 4, out.length, 1).setValues(out);
  }
}

function loadCodesFromMaster_() {
  const folder = getOrCreateFolderPathUnderRoot_(ROOT_FOLDER_NAME, MASTER_FOLDER_PATH);
  const file = findFileByNameInFolder_(folder, MASTER_SPREADSHEET_NAME);
  if (!file) throw new Error(`マスタスプレッドシートが見つかりません：${MASTER_SPREADSHEET_NAME}`);

  const ss = SpreadsheetApp.open(file);
  const sheet = ss.getSheets()[0];

  const lastRow = sheet.getLastRow();
  const lastCol = sheet.getLastColumn();
  if (lastRow < 2 || lastCol < 1) return [];

  const header = sheet.getRange(1, 1, 1, lastCol).getValues()[0].map(v => String(v || '').trim());
  const idx = header.indexOf(MASTER_CODE_HEADER);
  if (idx < 0) throw new Error(`マスタに「${MASTER_CODE_HEADER}」列が見つかりません`);

  const col = idx + 1;
  const vals = sheet.getRange(2, col, lastRow - 1, 1).getValues();

  const out = [];
  for (const r of vals) {
    const code = String(r[0] || '').trim();
    if (!code) continue;
    out.push(code);
  }
  return out;
}

// ============================================================================
// APIデータファイル
// ============================================================================
function openOrCreateApiDataSpreadsheet_(todayYMD) {
  const folder = getOrCreateFolderPathUnderRoot_(ROOT_FOLDER_NAME, OUTPUT_FOLDER_PATH);
  const fileName = `${API_DATA_PREFIX}${todayYMD}`;

  let file = findFileByNameInFolder_(folder, fileName);
  let ss, sheet;

  if (!file) {
    Logger.log(`APIデータファイルが存在しないため新規作成します：${fileName}`);
    ss = SpreadsheetApp.create(fileName);
    file = DriveApp.getFileById(ss.getId());
    folder.addFile(file);
    DriveApp.getRootFolder().removeFile(file);

    sheet = ss.getSheets()[0];
    initApiDataSheet_(sheet);
    Logger.log(`APIデータファイル新規作成完了：${fileName}`);
  } else {
    ss = SpreadsheetApp.open(file);
    sheet = ss.getSheets()[0];
    ensureApiDataHeader_(sheet);
  }

  return sheet;
}
function initApiDataSheet_(sheet) {
  sheet.clear();
  sheet.getRange(1, 1, 1, 7).setValues([['asof_date', 'code', 'open', 'high', 'low', 'close', 'volume']]);
  sheet.getRange('B:B').setNumberFormat('@');
  sheet.getRange('A:A').setNumberFormat('@');
}
function ensureApiDataHeader_(sheet) {
  const v = sheet.getRange(1, 1, 1, 7).getValues()[0];
  const joined = v.map(x => String(x || '').trim()).join('|');
  if (joined !== 'asof_date|code|open|high|low|close|volume') initApiDataSheet_(sheet);
}

// ============================================================================
// DB(API) 関連：最新日付一覧（UrlFetch 1回） / 一括upsert（完走時1回）
// ============================================================================
function callDbApi_(url, options) {
  const opt = Object.assign(
    {
      muteHttpExceptions: true,
      followRedirects: true,
      headers: {
        'User-Agent': 'Mozilla/5.0 (compatible; GAS-DB-StockFetcher/1.1; +https://script.google.com/)',
        'Accept': 'application/json'
      }
    },
    options || {}
  );

  const res = UrlFetchApp.fetch(url, opt);
  const httpCode = res.getResponseCode();
  const text = res.getContentText('UTF-8');

  if (httpCode < 200 || httpCode >= 300) {
    throw new Error(`DB_API_ERROR: HTTP ${httpCode} / ${text}`);
  }
  return text;
}

// ★ 最新日付を一括取得して map 化：{ "1301": "2026-01-03", ... }
function fetchLatestMapFromDB_() {
  const token = getDbApiToken_();
  const url = `${DB_API_BASE}${DB_LATEST_API_PATH}?token=${encodeURIComponent(token)}`;

  const text = callDbApi_(url, { method: 'get' });

  let json;
  try {
    json = JSON.parse(text);
  } catch (e) {
    throw new Error('DB_API_ERROR: latest_by_code のJSONパースに失敗: ' + text);
  }

  const rows = (json && Array.isArray(json.rows)) ? json.rows : (Array.isArray(json) ? json : []);
  const map = Object.create(null);

  for (const r of rows) {
    const code = String((r && r.code) || '').trim();
    const latest = normalizeYMD_(String((r && r.latest_asof_date) || '').trim()) || '';
    if (code && latest) map[code] = latest;
  }

  Logger.log(`DB最新日付一覧取得：count=${rows.length}（map=${Object.keys(map).length}）`);
  return map;
}

// ----- ダッシュ（未確定）表記を null にするだけの最小正規化 -----
function dashToNull_(v) {
  if (v == null) return null;
  const s = String(v).trim();
  return (/^[\u2212\uFF0D\u2010-\u2015\-]+$/.test(s)) ? null : s;
}

// APIデータファイルへ追記
function appendApiDataRows_(apiDataSheet, rows) {
  if (!rows || !rows.length) return 0;
  ensureApiDataHeader_(apiDataSheet);

  const last = apiDataSheet.getLastRow();
  const startRow = last + 1;

  const values = rows.map(r => [
    String(r.asof_date || ''),
    String(r.code || ''),
    dashToNull_(r.open),
    dashToNull_(r.high),
    dashToNull_(r.low),
    dashToNull_(r.close),
    dashToNull_(r.volume)
  ]);

  apiDataSheet.getRange(startRow, 1, values.length, 7).setValues(values);
  return values.length;
}

// APIデータファイルに溜めた内容を、1回のUrlFetchでまとめてupsert
function bulkUpsertFromApiDataSheet_(apiDataSheet) {
  ensureApiDataHeader_(apiDataSheet);

  const lastRow = apiDataSheet.getLastRow();
  if (lastRow < 2) {
    Logger.log('APIデータファイルにデータがありません（0件）。upsertはスキップします。');
    return 0;
  }

  const values = apiDataSheet.getRange(2, 1, lastRow - 1, 7).getValues();
  const payload = [];
  const seen = Object.create(null);

  for (const r of values) {
    const asof_date = String(r[0] || '').trim();
    const code      = String(r[1] || '').trim();
    if (!asof_date || !code) continue;

    const key = code + '|' + asof_date;
    if (seen[key]) continue;
    seen[key] = true;

    payload.push({
      asof_date,
      code,
      open:   r[2] === '' ? null : r[2],
      high:   r[3] === '' ? null : r[3],
      low:    r[4] === '' ? null : r[4],
      close:  r[5] === '' ? null : r[5],
      volume: r[6] === '' ? null : r[6]
    });
  }

  if (!payload.length) return 0;

  const token = getDbApiToken_();
  const url = `${DB_API_BASE}/prices_eod_upsert.php?token=${encodeURIComponent(token)}`;

  const text = callDbApi_(url, {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify(payload)
  });

  let affected = payload.length;
  try {
    const json = JSON.parse(text);
    if (json && typeof json.upserted === 'number') affected = json.upserted;
    else if (json && typeof json.affected === 'number') affected = json.affected;
    else if (json && typeof json.count === 'number') affected = json.count;
    else if (json && typeof json.processed === 'number') affected = json.processed;
  } catch (_) {}

  return affected;
}

// ============================================================================
// 日付/文字列ユーティリティ
// ============================================================================
function compareYMD_(aYmd, bYmd) {
  if (aYmd === bYmd) return 0;
  return aYmd > bYmd ? 1 : -1;
}
function isProcessTarget_(updatedCell, todayDateObj, tz) {
  const upd = normalizeSheetDateToSlash_(updatedCell, tz);
  if (!upd) return true;
  const today = Utilities.formatDate(todayDateObj, tz, 'yyyy/MM/dd');
  return upd < today;
}
function formatUpdatedForLog_(updatedCell, tz) {
  if (updatedCell == null || updatedCell === '') return '（空）';
  if (Object.prototype.toString.call(updatedCell) === '[object Date]' && !isNaN(updatedCell)) {
    return Utilities.formatDate(updatedCell, tz, 'yyyy/MM/dd');
  }
  const n = normalizeSheetDateToSlash_(updatedCell, tz);
  return n || String(updatedCell);
}
function normalizeSheetDateToSlash_(cell, tz) {
  if (cell == null || cell === '') return null;
  if (Object.prototype.toString.call(cell) === '[object Date]' && !isNaN(cell)) {
    return Utilities.formatDate(cell, tz, 'yyyy/MM/dd');
  }
  const s = String(cell).trim();
  let m = s.replace(/\./g, '/').replace(/-/g, '/').match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
  if (m) return `${m[1]}/${('0'+m[2]).slice(-2)}/${('0'+m[3]).slice(-2)}`;
  const d = new Date(s);
  if (!isNaN(d)) return Utilities.formatDate(d, tz, 'yyyy/MM/dd');
  return null;
}
function normalizeYMD_(s) {
  const t = String(s || '').trim();
  let m = t.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if (m) return [m[1], pad2_(m[2]), pad2_(m[3])].join('-');
  m = t.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
  if (m) return [m[1], pad2_(m[2]), pad2_(m[3])].join('-');
  m = t.match(/^(\d{2})\/(\d{1,2})\/(\d{1,2})$/);
  if (m) return ['20' + m[1], pad2_(m[2]), pad2_(m[3])].join('-');
  return null;
}
function pad2_(n) { return ('0' + String(n)).slice(-2); }

// ★ rows[] の最大 asof_date を返す
function maxAsofDate_(rows) {
  if (!rows || !rows.length) return null;
  let max = null;
  for (const r of rows) {
    const ymd = r && r.asof_date ? String(r.asof_date) : '';
    if (!ymd) continue;
    if (!max || ymd > max) max = ymd;
  }
  return max;
}

// ============================================================================
// 株探スクレイピング（変更なし）
// ============================================================================
function scrapeKabutan_Mode1_Page1_(code) {
  const url = `https://kabutan.jp/stock/kabuka?code=${encodeURIComponent(code)}&ashi=day`;
  Logger.log(`HTTP取得（page=1）：${url}`);
  const html = fetchHtml_(url);

  const todayRow = parseTable_stock_kabuka0_(html, code);
  const dwmRows  = parseTable_stock_kabuka_dwm_(html, code);

  const rows = [];
  if (todayRow) rows.push(todayRow);
  rows.push(...dwmRows);

  Logger.log(`page=1 抽出結果：todayRow=${todayRow ? 1 : 0}、dwmRows=${dwmRows.length}、合計=${rows.length}`);
  return rows;
}

function scrapeKabutan_Mode2_Full_(code) {
  const out = [];
  let page = 1;

  while (out.length < 200) {
    const url = page === 1
      ? `https://kabutan.jp/stock/kabuka?code=${encodeURIComponent(code)}&ashi=day`
      : `https://kabutan.jp/stock/kabuka?code=${encodeURIComponent(code)}&ashi=day&page=${page}`;

    Logger.log(`HTTP取得（page=${page}）：${url}`);
    const html = fetchHtml_(url);

    if (page === 1) {
      const todayRow = parseTable_stock_kabuka0_(html, code);
      const dwmRows  = parseTable_stock_kabuka_dwm_(html, code);
      if (todayRow) out.push(todayRow);
      out.push(...dwmRows);
      Logger.log(`page=1 解析結果：todayRow=${todayRow ? 1 : 0}、dwmRows=${dwmRows.length}、合計=${out.length}`);
    } else {
      const dwmRows = parseTable_stock_kabuka_dwm_(html, code);
      out.push(...dwmRows);
      Logger.log(`page=${page} 解析結果：dwmRows=${dwmRows.length}、合計=${out.length}`);
      if (!dwmRows.length) {
        Logger.log(`ページ終了検知：page=${page} にデータなし。ループを終了します。`);
        break;
      }
    }

    if (out.length >= 200) break;
    page++;
    Logger.log(`ページ切替待機：1 秒スリープ（page=${page - 1} → ${page}）`);
    Utilities.sleep(SLEEP_MS_PER_PAGE);
    if (page > 50) {
      Logger.log('安全弁：ページ数が 50 を超えたためループを強制終了します。');
      break;
    }
  }

  const sliced = out.slice(0, 200);
  Logger.log(`全件取得最終件数：${sliced.length} 件（200 件に打ち止め）`);
  return sliced;
}

function fetchHtml_(url) {
  try {
    const res = UrlFetchApp.fetch(url, {
      method: 'get',
      followRedirects: true,
      muteHttpExceptions: false,
      headers: {
        'User-Agent': 'Mozilla/5.0 (compatible; GAS-DB-StockFetcher/1.4; +https://script.google.com/)',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
      }
    });
    return res.getContentText('UTF-8');
  } catch (e) {
    throw new Error('SCRAPE_ERROR: KABUTAN の取得に失敗しました: ' + e);
  }
}

function parseTable_stock_kabuka0_(html, code) {
  const tableMatch = html.match(/<table[^>]*class="stock_kabuka0[^"]*"[^>]*>[\s\S]*?<\/table>/i);
  if (!tableMatch) return null;

  const tbodyMatch = tableMatch[0].match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/i);
  if (!tbodyMatch) return null;

  const trMatch = tbodyMatch[1].match(/<tr[^>]*>([\s\S]*?)<\/tr>/i);
  if (!trMatch) return null;

  const tr = trMatch[1];

  let ymd = null;
  const timeAttr = tr.match(/<time[^>]*datetime="([^"]+)"/i);
  if (timeAttr && timeAttr[1]) {
    ymd = normalizeYMD_(timeAttr[1]);
  } else {
    const thText = stripHtml_(tr.match(/<th[^>]*>([\s\S]*?)<\/th>/i)?.[1] || '');
    ymd = normalizeYMD_(thText);
  }
  if (!ymd) return null;

  const tds = Array.from(tr.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/gi)).map(m => stripHtml_(m[1]));
  if (tds.length < 7) return null;

  const open   = decomma_(tds[0]);
  const high   = decomma_(tds[1]);
  const low    = decomma_(tds[2]);
  const close  = decomma_(tds[3]);
  const volume = decomma_(tds[6]);

  return { asof_date: ymd, code, open, high, low, close, volume };
}

function parseTable_stock_kabuka_dwm_(html, code) {
  const tableMatch = html.match(/<table[^>]*class="stock_kabuka_dwm[^"]*"[^>]*>[\s\S]*?<\/table>/i);
  if (!tableMatch) return [];

  const tbodyMatch = tableMatch[0].match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/i);
  if (!tbodyMatch) return [];

  const out = [];
  const trs = Array.from(tbodyMatch[1].matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/gi));
  for (const m of trs) {
    const tr = m[1];
    let ymd = null;
    const timeAttr = tr.match(/<time[^>]*datetime="([^"]+)"/i);
    if (timeAttr && timeAttr[1]) {
      ymd = normalizeYMD_(timeAttr[1]);
    } else {
      const thText = stripHtml_(tr.match(/<th[^>]*>([\s\S]*?)<\/th>/i)?.[1] || '');
      ymd = normalizeYMD_(thText);
    }
    if (!ymd) continue;

    const tds = Array.from(tr.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/gi)).map(m2 => stripHtml_(m2[1]));
    if (tds.length < 7) continue;

    const open   = decomma_(tds[0]);
    const high   = decomma_(tds[1]);
    const low    = decomma_(tds[2]);
    const close  = decomma_(tds[3]);
    const volume = decomma_(tds[6]);

    out.push({ asof_date: ymd, code, open, high, low, close, volume });
  }
  return out;
}

function stripHtml_(s) {
  return String(s || '')
    .replace(/<[^>]+>/g, '')
    .replace(/\u00A0/g, ' ')
    .trim();
}
function decomma_(s) {
  return String(s || '')
    .replace(/[０-９]/g, c => String.fromCharCode(c.charCodeAt(0) - 0xFF10 + 0x30))
    .replace(/,/g, '')
    .trim();
}

function assertNoDuplicateDates_(rows) {
  const seen = Object.create(null);
  for (const r of rows) {
    if (!r.asof_date) continue;
    if (seen[r.asof_date]) throw new Error('SCRAPE_ERROR: 同一日付のレコードが重複しています: ' + r.asof_date);
    seen[r.asof_date] = true;
  }
}

// ============================================================================
// メール集計（A/B/Cの“文字列のみ”で集計：従来通り）
// ============================================================================
function countTodaySummary_(sheet, todaySlash) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return { targetCount: 0, processedCount: 0, errorCount: 0 };

  const tz = 'Asia/Tokyo';
  const values = sheet.getRange(2, 1, lastRow - 1, 3).getValues(); // [code, 更新日, 実行結果]

  let targetCount = 0;
  let processedCount = 0;
  let errorCount = 0;

  for (const row of values) {
    const code = String(row[0] || '').trim();
    if (!code) break;
    const updSlash = normalizeSheetDateToSlash_(row[1], tz);
    const res  = String(row[2] || '').trim();
    if (updSlash === todaySlash) {
      targetCount++;
      if (res.indexOf('エラー') >= 0) errorCount++;
      if (res.indexOf('処理なし') === -1) processedCount++;
    }
  }
  return { targetCount, processedCount, errorCount };
}
