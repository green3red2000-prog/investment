/**
 * BigQuery + スプレッドシート + 株探スクレイピング 連携スクリプト（完全版）
 * ---------------------------------------------------------------------------
 * 特徴：
 *  - 5分トリガー運用 / 場中抑制（JST）：月 17:00?23:59、火?金 00:00?07:59 & 17:00?23:59、土日 終日OK
 *  - 時間予算制御：持ち時間 240秒
 *      全件取得＆書き込み=20秒 / 差分取得＆書き込み=3秒 / スキップ=0秒 / その他=20秒
 *  - 処理対象判定：更新日が本日より前の行のみ（本日と同日/未来はスキップ・シート未更新）
 *  - スクレイピング：
 *      ・最新差分（1ページのみ）: latest < 取得日 <= today
 *      ・初回全件（最大200件、複数ページ）
 *      ・日付重複チェック
 *  - BQ挿入：ダッシュ（－など）を null に変換して挿入（他の不正値は従来どおりエラー）
 *  - ページ切替/挿入あり行のみ 1 秒スリープ
 *  - メールは「全行完了時のみ」送信。本文はシート文字列ベースで集計。
 *  - 重複送信防止＋“シート変更があれば再送”：当日完走時のシート状態を署名し Script Properties に保存。
 *      以後、署名が同じならスキップ。シートが更新/追加され署名が変われば、次の完走時に再送。
 */

// ====== 設定値 ======
const PROJECT_ID   = 'stocks-471015';
const DATASET_ID   = 'stocks';
const TABLE_ID     = 'prices_eod';

const FOLDER_NAME  = '投資';
const FILE_NAME    = '全銘柄日足取得：証券コードと実行結果';

const SLEEP_MS_PER_ROW  = 1000; // Kabutan取得→BQ挿入があった行のみ 1 秒
const SLEEP_MS_PER_PAGE = 1000; // ページ切替は常に 1 秒

// ====== 持ち時間（秒）と消費テーブル ======
const TIME_BUDGET_SEC = 240; // 総持ち時間
const COST_FULL_SEC   = 20;  // 全件取得＆書き込み（Mode2）
const COST_DIFF_SEC   = 3;   // 差分取得＆書き込み（Mode1)
const COST_SKIP_SEC   = 0;   // シート更新なしスキップ（shouldProcess=false）
const COST_OTHER_SEC  = 20;  // それ以外（同日「処理なし」記録、未来日エラー記録、例外記録など）

// ====== 実行許可ウィンドウ判定（JST基準） ======
function isWithinExecutionWindow_(now, tz) {
  const ymd  = Utilities.formatDate(now, tz, 'yyyy-MM-dd');
  const hour = parseInt(Utilities.formatDate(now, tz, 'H'), 10); // 0..23
  const [y, m, d] = ymd.split('-').map(Number);
  const dow = new Date(y, m - 1, d).getDay(); // 0=日,1=月,...,6=土

  // 土(6)・日(0)は終日OK
  if (dow === 0 || dow === 6) return true;

  // 月?金は 17:00?23:59 のみ実行
  return (hour >= 17);
}


// ====== Script Properties（当日署名の保存） ======
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

// ====== シート状態の署名（A/B/C列を空コード行までハッシュ） ======
function computeSheetSignature_(sheet, tz) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return 'EMPTY';

  const values = sheet.getRange(2, 1, lastRow - 1, 3).getValues(); // [code, 更新日, 実行結果]
  const lines = [];

  for (const row of values) {
    const code = String(row[0] || '').trim();
    if (!code) break; // 空コード行で終端
    const upd = normalizeSheetDateToSlash_(row[1], tz) || '';
    const res = String(row[2] || '').trim();
    lines.push([code, upd, res].join('|'));
  }

  const text = lines.join('\n');
  const bytes = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, text);
  return bytes.map(b => ('0' + (b & 0xFF).toString(16)).slice(-2)).join('');
}

// ====== エントリーポイント ======
function runAll() {
  const { ss, sheet } = openTargetSpreadsheet_(FOLDER_NAME, FILE_NAME);

  const tz  = 'Asia/Tokyo';
  const now = new Date();
  const todaySlash = Utilities.formatDate(now, tz, 'yyyy/MM/dd');

  // 場中抑制
  if (!isWithinExecutionWindow_(now, tz)) {
    const nowStr = Utilities.formatDate(now, tz, 'yyyy/MM/dd HH:mm');
    Logger.log(`現在の時刻帯では実行しません（場中抑制）：${nowStr} JST`);
    return;
  }

  const lastRow = sheet.getLastRow();
  if (lastRow < 2) {
    Logger.log('シートに処理対象の行がありません（見出しのみ）。処理を終了します。');
    return;
  }

  // 当日「変更なし」なら起動直後にスキップ（重複送信抑制）
  const prev = getDailyState_();
  const preSig = computeSheetSignature_(sheet, tz);
  if (prev.date === todaySlash && prev.hash === preSig) {
    Logger.log(`本日は既に全行処理済み＆シート変更なしのため、この起動はスキップします。（${todaySlash}）`);
    return;
  }

  const todayYMD   = Utilities.formatDate(now, tz, 'yyyy-MM-dd'); // BQ/比較用

  // 1 行目は見出し：[証券コード, 更新日, 実行結果]
  const values = sheet.getRange(2, 1, lastRow - 1, 3).getValues();

  let finishedAll    = false;     // 空コード or 末尾まで到達
  let reachedTimeOut = false;     // 持ち時間を使い切った
  let budgetLeft     = TIME_BUDGET_SEC;

  Logger.log(`処理開始：本日=${todaySlash}、持ち時間=${budgetLeft}秒`);

  for (let i = 0; i < values.length; i++) {
    const rowIndex    = i + 2;
    const code        = String(values[i][0] || '').trim();
    const updatedCell = values[i][1]; // Date型/文字列 どちらもあり
    const updatedLog  = formatUpdatedForLog_(updatedCell, tz);

    if (!code) {
      Logger.log(`終端に到達：${rowIndex} 行目の「証券コード」が空。全行処理完了と判断します。`);
      finishedAll = true;
      break;
    }

    // 本行が処理対象か（本日 > 更新日 → true。等しい/未来 → false。未設定 → true）
    const shouldProcess = isProcessTarget_(updatedCell, now, tz);
    Logger.log(`処理判定：行=${rowIndex}、コード=${code}、更新日='${updatedLog}'、処理対象=${shouldProcess ? 'はい' : 'いいえ'}`);

    let consumedSec = 0;       // この行の時間消費
    let throttleAfterRow = false; // 挿入ありなら 1 秒スリープ
    let resultMsg = '';

    try {
      if (shouldProcess) {
        // BQ 最新日付
        const latest = getLatestAsOfDateFromBQ_(code);
        Logger.log(`BQ最新日付：コード=${code}、asof_date=${latest || '（データなし）'}`);

        if (!latest) {
          // 全件取得（最大200件）
          Logger.log(`全件取得：コード=${code}、株探から最大200件取得開始`);
          const rows = scrapeKabutan_Mode2_Full_(code);
          Logger.log(`全件取得：コード=${code}、取得件数=${rows.length}件`);
          assertNoDuplicateDates_(rows);
          const inserted = insertRowsToBQ_(rows);
          resultMsg  = `全件取得＆書き込み：${inserted}件`;
          consumedSec = COST_FULL_SEC;
          Logger.log(`全件取得：コード=${code}、BQ挿入=${inserted}件（消費=${consumedSec}秒）`);
          if (inserted > 0) throttleAfterRow = true;

        } else {
          const cmp = compareYMD_(todayYMD, latest);
          if (cmp > 0) {
            // 差分取得（page=1：latest < 日付 <= today）
            Logger.log(`差分取得：コード=${code}、today=${todayYMD} > latest=${latest}、page=1のみ取得し差分挿入`);
            const rowsAll = scrapeKabutan_Mode1_Page1_(code);
            const rows    = rowsAll.filter(r => r.asof_date > latest && r.asof_date <= todayYMD);
            Logger.log(`差分取得：コード=${code}、抽出件数（latest?today・本日含む）=${rows.length}件`);
            assertNoDuplicateDates_(rows);
            const inserted = rows.length ? insertRowsToBQ_(rows) : 0;
            resultMsg  = `差分取得＆書き込み：${inserted}件`;
            consumedSec = COST_DIFF_SEC;
            Logger.log(`差分取得：コード=${code}、BQ挿入=${inserted}件（消費=${consumedSec}秒）`);
            if (inserted > 0) throttleAfterRow = true;

          } else if (cmp === 0) {
            // 本日分は既に BQ に存在 → 「処理なし」を記録（その他扱い）
            resultMsg  = '処理なし';
            consumedSec = COST_OTHER_SEC;
            Logger.log(`同日判定：コード=${code} は最新が本日分のため「処理なし」を記録（消費=${consumedSec}秒）`);

          } else {
            // 未来日 → エラー記録（その他扱い）
            resultMsg  = 'エラー：BigQueryに未来日が保存されている';
            consumedSec = COST_OTHER_SEC;
            Logger.log(`異常判定：コード=${code} 最新が未来日（latest=${latest} > today=${todayYMD}）。エラー記録（消費=${consumedSec}秒）`);
          }
        }

        // shouldProcess=true の場合だけシート更新
        sheet.getRange(rowIndex, 2).setValue(todaySlash);
        sheet.getRange(rowIndex, 3).setValue(resultMsg);
        Logger.log(`シート更新：行=${rowIndex}、結果='${resultMsg}'`);

        if (throttleAfterRow) {
          Logger.log(`スロットリング：コード=${code}、1秒待機（挿入あり）`);
          Utilities.sleep(SLEEP_MS_PER_ROW);
        }

      } else {
        // 本日分が既に処理済み → シートは書き換えずスキップ
        consumedSec = COST_SKIP_SEC; // 0 秒
        Logger.log(`スキップ：コード=${code} は本日分が既に処理済みのためシート更新なしでスキップ（消費=${consumedSec}秒）`);
      }

    } catch (err) {
      // 例外は「その他」として20秒消費し、エラーをシートに記録
      const msg = String(err && err.message || err);
      const isScrapeErr = /SCRAPE_ERROR/.test(msg) || /KABUTAN/.test(msg);
      const emsg = isScrapeErr ? 'エラー：株探読み込みに失敗' : 'エラー：処理が中断した';
      consumedSec = COST_OTHER_SEC;
      Logger.log(`エラー：行=${rowIndex}、コード=${code}、詳細=${msg}（消費=${consumedSec}秒）`);

      // エラーは必ずシートに記録
      sheet.getRange(rowIndex, 2).setValue(todaySlash);
      sheet.getRange(rowIndex, 3).setValue(emsg);
    }

    // ---- 持ち時間を減算して判定 ----
    budgetLeft -= consumedSec;
    Logger.log(`持ち時間：この行の消費=${consumedSec}秒、残り=${budgetLeft}秒`);
    if (budgetLeft <= 0) {
      reachedTimeOut = true;
      Logger.log('最大処理件数に達したため、この実行を終了します（メール送信は行いません）。');
      break;
    }
  }

  // ループを最後まで回り切っていれば全行完了扱い
  if (!reachedTimeOut && !finishedAll) {
    finishedAll = true;
    Logger.log('最終行まで処理を実行しました。全行処理完了と判断します。');
  }

  // 全行完了かつ タイムアウト未到達 のときのみ（かつ差分署名がある場合のみ）メール送信
  if (finishedAll && !reachedTimeOut) {
    Logger.log('全行処理完了：本日の集計を実施します。');

    // 完走後の「最新シート署名」を算出
    const postSig = computeSheetSignature_(sheet, tz);

    // 署名が未登録 or 日付が違う or 署名が変わっていれば送信
    if (prev.date !== todaySlash || prev.hash !== postSig) {
      const { targetCount, processedCount, errorCount } = countTodaySummary_(sheet, todaySlash);
      const subject = `全銘柄日足取得：${todaySlash}`;
      const body =
        '本日の全銘柄日足取得処理を終了しました。\n\n' +
        `処理対象件数は${targetCount}件でした。\n\n` +
        `処理件数は${processedCount}件でした。\n\n` +
        `エラーの件数は${errorCount}件でした。\n\n\n` +
        'スプレッドシート：\n' +
        ss.getUrl();

      try {
        MailApp.sendEmail({ to: 'green3red2000@gmail.com', subject, body });
        Logger.log('メール送信完了。今回のシート状態を署名として記録します。');
        setDailyState_(todaySlash, postSig); // 署名を保存
      } catch (e) {
        Logger.log('メール送信に失敗：' + e);
        // 送れなかった場合は署名を更新しない → 次回起動時に再送チャンスを残す
      }
    } else {
      Logger.log('全行完了だがシート状態に変更がないため、メール送信は省略します。');
    }

  } else if (reachedTimeOut) {
    Logger.log('今回の実行は持ち時間に到達したため、メール送信はスキップしました。');
  } else {
    Logger.log('今回の実行は途中終了しました（全行未完了／持ち時間未到達）。メール送信はありません。');
  }

  Logger.log('処理終了。');
}


// ====== スプレッドシート関連 ======
function openTargetSpreadsheet_(folderName, fileName) {
  const folders = DriveApp.getFoldersByName(folderName);
  if (!folders.hasNext()) throw new Error(`フォルダが見つかりません：${folderName}`);
  const folder = folders.next();

  const files = folder.getFilesByName(fileName);
  if (!files.hasNext()) throw new Error(`スプレッドシートが見つかりません：${fileName}`);
  const file = files.next();

  const ss = SpreadsheetApp.open(file);
  const sheet = ss.getSheets()[0]; // 先頭シートを使用
  return { ss, sheet, fileUrl: file.getUrl() };
}


// ====== BigQuery 関連 ======
function getLatestAsOfDateFromBQ_(code) {
  const sql = `
    SELECT asof_date
    FROM \`${PROJECT_ID}.${DATASET_ID}.${TABLE_ID}\`
    WHERE code = @code
    ORDER BY asof_date DESC
    LIMIT 1
  `;
  const req = {
    query: sql,
    useLegacySql: false,
    parameterMode: 'NAMED',
    queryParameters: [
      { name: 'code', parameterType: { type: 'STRING' }, parameterValue: { value: code } }
    ]
  };
  const res = BigQuery.Jobs.query(req, PROJECT_ID);
  if (res.status && res.status.errorResult) throw new Error(JSON.stringify(res.status.errors || res.status.errorResult));
  if (!res.rows || !res.rows.length) return null;
  return String(res.rows[0].f[0].v); // 'YYYY-MM-DD'
}

// ----- ダッシュ（未確定）表記を null にするだけの最小正規化 -----
function dashToNull_(v) {
  if (v == null) return null;
  const s = String(v).trim();
  // U+2212(?), U+FF0D(－), U+2010..2015(‐-???―), ASCII '-' を “ダッシュのみ” と判定
  return (/^[\u2212\uFF0D\u2010-\u2015\-]+$/.test(s)) ? null : s;
}

function insertRowsToBQ_(rows) {
  if (!rows || !rows.length) return 0;

  const chunkSize = 500;
  let inserted = 0;

  for (let i = 0; i < rows.length; i += chunkSize) {
    const chunk = rows.slice(i, i + chunkSize).map(r => ({
      json: {
        asof_date: r.asof_date,              // 'YYYY-MM-DD'
        code:      r.code,                   // STRING
        // NUMERIC/INT64には “文字列 or null” を渡す（ダッシュは null に）
        open:   dashToNull_(r.open),
        high:   dashToNull_(r.high),
        low:    dashToNull_(r.low),
        close:  dashToNull_(r.close),
        volume: dashToNull_(r.volume)
      }
    }));

    const resp = BigQuery.Tabledata.insertAll(
      {
        rows: chunk,
        ignoreUnknownValues: false,
        skipInvalidRows: false // ご要望：使わない（エラーは従来どおり例外）
      },
      PROJECT_ID, DATASET_ID, TABLE_ID
    );

    if (resp.insertErrors && resp.insertErrors.length) {
      Logger.log('挿入エラー詳細：' + JSON.stringify(resp.insertErrors));
      throw new Error('INSERT_FAILED: Tabledata.insertAll でエラーが発生しました');
    }

    inserted += chunk.length;
  }

  return inserted;
}


// ====== 日付/文字列ユーティリティ ======
function compareYMD_(aYmd, bYmd) {
  if (aYmd === bYmd) return 0;
  return aYmd > bYmd ? 1 : -1;
}

// updatedCell が空 or 解析不能なら「処理対象にする」
// それ以外は、「本日 > 更新日」のときだけ処理対象（同日/未来は対象外）
function isProcessTarget_(updatedCell, todayDateObj, tz) {
  const upd = normalizeSheetDateToSlash_(updatedCell, tz);   // 'YYYY/MM/DD' or null
  if (!upd) return true;                                     // 未設定 → 対象
  const today = Utilities.formatDate(todayDateObj, tz, 'yyyy/MM/dd');
  return upd < today;                                        // 厳密に「本日より前」のときだけ true
}

// ログ表示用（Date なら 'yyyy/MM/dd'、文字列は可能な限り正規化）
function formatUpdatedForLog_(updatedCell, tz) {
  if (updatedCell == null || updatedCell === '') return '（空）';
  if (Object.prototype.toString.call(updatedCell) === '[object Date]' && !isNaN(updatedCell)) {
    return Utilities.formatDate(updatedCell, tz, 'yyyy/MM/dd');
  }
  const n = normalizeSheetDateToSlash_(updatedCell, tz);
  return n || String(updatedCell);
}

// Date/文字列を 'yyyy/MM/dd' に正規化（失敗時 null）
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


// ====== 株探スクレイピング ======
// (1) page=1 だけ取得。stock_kabuka0 と stock_kabuka_dwm の両方から抽出
function scrapeKabutan_Mode1_Page1_(code) {
  const url = `https://kabutan.jp/stock/kabuka?code=${encodeURIComponent(code)}&ashi=day`;
  Logger.log(`HTTP取得（page=1）：${url}`);
  const html = fetchHtml_(url);

  const todayRow = parseTable_stock_kabuka0_(html, code);   // 1件または null
  const dwmRows  = parseTable_stock_kabuka_dwm_(html, code);// 複数

  const rows = [];
  if (todayRow) rows.push(todayRow);
  rows.push(...dwmRows);

  Logger.log(`page=1 抽出結果：todayRow=${todayRow ? 1 : 0}、dwmRows=${dwmRows.length}、合計=${rows.length}`);
  return rows;
}

// (2) 最大200件取得。1ページ目は (1) と同様、2ページ目以降は stock_kabuka_dwm のみ取得
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
        break; // データが無くなり次第終了
      }
    }

    if (out.length >= 200) break;
    page++;
    Logger.log(`ページ切替待機：1 秒スリープ（page=${page - 1} → ${page}）`);
    Utilities.sleep(SLEEP_MS_PER_PAGE); // 要件：ページ切替で 1 秒
    if (page > 50) {
      Logger.log('安全弁：ページ数が 50 を超えたためループを強制終了します。');
      break;
    }
  }

  const sliced = out.slice(0, 200);
  Logger.log(`全件取得最終件数：${sliced.length} 件（200 件に打ち止め）`);
  return sliced;
}

// HTML取得（User-Agent付与）
function fetchHtml_(url) {
  try {
    const res = UrlFetchApp.fetch(url, {
      method: 'get',
      followRedirects: true,
      muteHttpExceptions: false,
      headers: {
        'User-Agent': 'Mozilla/5.0 (compatible; GAS-BQ-StockFetcher/1.4; +https://script.google.com/)',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
      }
    });
    return res.getContentText('UTF-8');
  } catch (e) {
    throw new Error('SCRAPE_ERROR: KABUTAN の取得に失敗しました: ' + e);
  }
}

// <table class="stock_kabuka0"> の tbody > tr（本日行）から 1 件抽出
function parseTable_stock_kabuka0_(html, code) {
  const tableMatch = html.match(/<table[^>]*class="stock_kabuka0[^"]*"[^>]*>[\s\S]*?<\/table>/i);
  if (!tableMatch) return null;

  const tbodyMatch = tableMatch[0].match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/i);
  if (!tbodyMatch) return null;

  const trMatch = tbodyMatch[1].match(/<tr[^>]*>([\s\S]*?)<\/tr>/i);
  if (!trMatch) return null;

  const tr = trMatch[1];

  // 日付は <th> 内の <time datetime="YYYY-MM-DD"> を優先
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

// <table class="stock_kabuka_dwm"> の tbody > 複数 tr から履歴抽出
function parseTable_stock_kabuka_dwm_(html, code) {
  const tableMatch = html.match(/<table[^>]*class="stock_kabuka_dwm[^"]*"[^>]*>[\s\S]*?<\/table>/i);
  if (!tableMatch) return [];

  const tbodyMatch = tableMatch[0].match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/i);
  if (!tbodyMatch) return [];

  const out = [];
  const trs = Array.from(tbodyMatch[1].matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/gi));
  for (const m of trs) {
    const tr = m[1];
    // <th> に <time datetime="YYYY-MM-DD"> または 'YYYY/MM/DD'
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

// 文字列ユーティリティ
function stripHtml_(s) {
  return String(s || '')
    .replace(/<[^>]+>/g, '')
    .replace(/\u00A0/g, ' ')
    .trim();
}
function decomma_(s) {
  return String(s || '')
    .replace(/[０-９]/g, c => String.fromCharCode(c.charCodeAt(0) - 0xFF10 + 0x30)) // 全角→半角
    .replace(/,/g, '')
    .trim();
}
function normalizeYMD_(s) {
  const t = String(s || '').trim();
  let m = t.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if (m) return [m[1], pad2_(m[2]), pad2_(m[3])].join('-');
  m = t.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
  if (m) return [m[1], pad2_(m[2]), pad2_(m[3])].join('-');
  m = t.match(/^(\d{2})\/(\d{1,2})\/(\d{1,2})$/); // 25/08/29 → 2025-08-29
  if (m) return ['20' + m[1], pad2_(m[2]), pad2_(m[3])].join('-');
  return null;
}
function pad2_(n) { return ('0' + String(n)).slice(-2); }

// 日付重複チェック（スクレイピング結果内）
function assertNoDuplicateDates_(rows) {
  const seen = Object.create(null);
  for (const r of rows) {
    if (!r.asof_date) continue;
    if (seen[r.asof_date]) throw new Error('SCRAPE_ERROR: 同一日付のレコードが重複しています: ' + r.asof_date);
    seen[r.asof_date] = true;
  }
}


// ====== メール集計（シートの“文字列のみ”で集計） ======
function countTodaySummary_(sheet, todaySlash) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return { targetCount: 0, processedCount: 0, errorCount: 0 };

  const tz = 'Asia/Tokyo';
  const values = sheet.getRange(2, 1, lastRow - 1, 3).getValues(); // [code, 更新日, 実行結果]

  let targetCount = 0;    // 更新日＝今日 の行数
  let processedCount = 0; // 更新日＝今日 且つ 実行結果が「処理なし」を含まない
  let errorCount = 0;     // 更新日＝今日 且つ 実行結果に「エラー」を含む

  for (const row of values) {
    const code = String(row[0] || '').trim();
    if (!code) break; // 終端
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
