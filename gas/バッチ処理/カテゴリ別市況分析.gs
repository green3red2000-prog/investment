/**
 * カテゴリ別市況分析_最新 生成（全銘柄日足分析_yyyy-MM-dd から集計）
 */

const TZ = 'Asia/Tokyo';

// フォルダパス（マイドライブ配下）
const FOLDER_MASTER = ['投資','プログラミング','GAS','マスタ'];
const FOLDER_OUTPUT = ['投資','プログラミング','GAS','スクレイピング','出力結果'];

// ファイル名
const OUT_SHEET_NAME = 'カテゴリ別市況分析_最新';
const CALENDAR_MASTER_NAME = 'カレンダーマスタ';
const THEME_MASTER_NAME = '私選テーマ別銘柄マスタ';
const BASIC_INFO_MASTER_NAME = '全銘柄基本情報マスタ';
const SRC_PREFIX = '全銘柄日足分析_';

// 通常実行で最後に処理した集計元ファイル名を保存する
const PROP_LAST_PROCESSED_SOURCE = 'CATEGORY_MARKET_SUMMARY_LAST_SOURCE';

// 出力対象シート
const SHEET_ZENMEIGARA = '全銘柄';
const SHEET_THEME = '私選テーマ別';

// 私選テーマ別の分類（テンプレに存在する分類 + 割合2種）
const THEME_CLASS_BLOCKS = [
  '前日比上昇銘柄数',
  '売買代金',
  '(11)終値の直近10日間の回帰係数',
  '前日比上昇銘柄割合',
  '(11)終値の直近10日間の回帰係数割合',
];

/** エントリポイント */
function run_category_market_summary() {
  console.log('[START] run_category_market_summary');

  // 5分トリガーの多重実行防止
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(1000)) {
    console.log('[SKIP] another execution is already running');
    return;
  }

  try {
    const today = new Date();
    const todayStr = fmtDate_(today);

    console.log(`[INFO] today=${todayStr}`);

    // (1) 営業日カレンダー取得・営業日判定
    const businessDaySet = loadBusinessDaySet_();

    if (!businessDaySet.has(todayStr)) {
      console.log(`[SKIP] not a business day (${todayStr})`);
      return;
    }

    // (2) 集計元「全銘柄日足分析_yyyy-MM-dd」の最新ファイルを取得
    const srcPick = findLatestDatedSpreadsheet_(
      FOLDER_OUTPUT,
      SRC_PREFIX
    );

    if (!srcPick) {
      throw new Error(
        `集計元が見つかりません: ${SRC_PREFIX}yyyy-MM-dd`
      );
    }

    // スクリプトプロパティから前回処理した集計元ファイル名を取得
    const props = PropertiesService.getScriptProperties();
    const lastProcessedSource =
      String(
        props.getProperty(PROP_LAST_PROCESSED_SOURCE) || ''
      ).trim();

    if (lastProcessedSource) {
      const m = lastProcessedSource.match(
        new RegExp(
          '^' +
          escapeRegExp_(SRC_PREFIX) +
          '(\\d{4}-\\d{2}-\\d{2})$'
        )
      );

      if (m) {
        const lastProcessedDate = m[1];

        // 最新ファイルが前回処理ファイルより新しくなければ終了
        if (srcPick.dateStr <= lastProcessedDate) {
          console.log(
            `[SKIP] no newer source ` +
            `(latest=${srcPick.title}, last=${lastProcessedSource})`
          );
          return;
        }
      } else {
        // プロパティ値が不正でも、同一ファイルなら再処理しない
        if (lastProcessedSource === srcPick.title) {
          console.log(
            `[SKIP] source already processed (${lastProcessedSource})`
          );
          return;
        }

        console.log(
          `[WARN] invalid property value; continue processing ` +
          `(${PROP_LAST_PROCESSED_SOURCE}=${lastProcessedSource})`
        );
      }
    }

    console.log(`[OK] source picked: ${srcPick.title}`);

    // (3) 出力先「カテゴリ別市況分析_最新」
    const outSs = openSpreadsheetInFolder_(
      FOLDER_OUTPUT,
      OUT_SHEET_NAME
    );

    if (!outSs) {
      throw new Error(`出力先が見つかりません: ${OUT_SHEET_NAME}`);
    }

    console.log(`[OK] output sheet opened: ${OUT_SHEET_NAME}`);

    const outFile = DriveApp.getFileById(outSs.getId());
    const srcSs = SpreadsheetApp.openById(srcPick.id);

    // 集計元データ読み込み
    const src = loadSourceTable_(srcSs);

    /*
     * 集計元ファイル名の日付を、集計結果の対象日として使用する。
     */
    const targetDate = parseDateLoose_(srcPick.dateStr);
    if (!targetDate) {
      throw new Error(
        `集計元の日付解釈に失敗しました: ${srcPick.dateStr}`
      );
    }

    // ===== 全銘柄シート集計 =====
    aggregateZenmeigara_(
      outSs.getSheetByName(SHEET_ZENMEIGARA),
      src,
      targetDate
    );
    console.log('[DONE] aggregate zenmeigara');

    // ===== 私選テーマ別シート集計 =====
    const themeMaster = loadThemeMaster_();

    console.log('[RUN] aggregate theme');

    aggregateTheme_(
      outSs.getSheetByName(SHEET_THEME),
      src,
      targetDate,
      themeMaster
    );

    console.log('[DONE] aggregate theme');

    SpreadsheetApp.flush();

    // (4) スプレッドシートのコピー
    const copiedName = `カテゴリ別市況分析_${todayStr}`;
    const dstFolder = getFolderByPath_(FOLDER_OUTPUT);

    if (!dstFolder) {
      throw new Error(
        `コピー先フォルダが見つかりません: ${FOLDER_OUTPUT.join(' > ')}`
      );
    }

    const copiedFile = outFile.makeCopy(copiedName, dstFolder);
    console.log('[DONE] sheet copy');

    // (5) メール送信
    const summary = buildMailSummary_(
      outSs,
      copiedFile.getUrl()
    );

    MailApp.sendEmail({
      to: 'green3red2000@gmail.com',
      subject: copiedName,
      body: summary,
    });

    console.log('[DONE] main send');

    // (6) レポートファイルの出力
    const reportBody = buildReportSummary_(summary, new Date());
    const reportName = `カテゴリ別市況分析_レポート_${todayStr}.txt`;

    writeTextReport_(
      dstFolder,
      reportName,
      reportBody
    );

    console.log(`[DONE] report output: ${reportName}`);

    /*
     * 集計・コピー・メール送信・レポート出力がすべて正常終了した後に記録する。
     *
     * 途中でエラーになった場合は記録されないため、
     * 次回の5分トリガーで再実行できる。
     */
    props.setProperty(
      PROP_LAST_PROCESSED_SOURCE,
      srcPick.title
    );

    console.log(
      `[DONE] script property updated: ` +
      `${PROP_LAST_PROCESSED_SOURCE}=${srcPick.title}`
    );

    console.log('[END] run_category_market_summary');

  } finally {
    lock.releaseLock();
  }
}

/**
 * ★リカバリ実行（起動判定を無視）
 * - 集計元「全銘柄日足分析_yyyy-MM-dd」を dateStr で指定して実行する
 * - 休日/週末/日付一致チェックを行わない（欠落日の埋め戻し用）
 *
 * 使い方例:
 *   const dateStr = '202X-XX-XX'; を指定日に書き換えて
 *   run_category_market_summary_recovery();を実行。
 */
function run_category_market_summary_recovery() {

  const dateStr = '2026-07-29';

  console.log('[START] run_category_market_summary_recovery');

  const ds = String(dateStr || '').trim();
  if (!ds.match(/^\d{4}-\d{2}-\d{2}$/)) {
    throw new Error(`dateStr が不正です: ${dateStr}（例: 2026-01-29）`);
  }

  // ★対象日（通常の targetDate 相当）を dateStr から作る
  const targetDate = parseDateLoose_(ds);
  if (!targetDate) throw new Error(`dateStr の日付解釈に失敗しました: ${ds}`);

  console.log(`[INFO] recovery targetDate=${fmtDate_(targetDate)} (起動判定は無視)`);

  // 出力先「カテゴリ別市況分析_最新」
  const outSs = openSpreadsheetInFolder_(FOLDER_OUTPUT, OUT_SHEET_NAME);
  if (!outSs) throw new Error(`出力先が見つかりません: ${OUT_SHEET_NAME}`);
  console.log(`[OK] output sheet opened: ${OUT_SHEET_NAME}`);
  const outFile = DriveApp.getFileById(outSs.getId());

  // ★集計元を dateStr で厳密指定
  const srcPick = findDatedSpreadsheet_(FOLDER_OUTPUT, SRC_PREFIX, ds);
  if (!srcPick) throw new Error(`集計元が見つかりません: ${SRC_PREFIX}${ds}`);
  console.log(`[OK] source picked (recovery): ${SRC_PREFIX}${srcPick.dateStr}`);

  const srcSs = SpreadsheetApp.openById(srcPick.id);
  const src = loadSourceTable_(srcSs);

  // 集計（targetDate を使って 0列の日付/ローリング判定を行う）
  aggregateZenmeigara_(outSs.getSheetByName(SHEET_ZENMEIGARA), src, targetDate);
  console.log('[DONE] aggregate zenmeigara (recovery)');

  const themeMaster = loadThemeMaster_();
  aggregateTheme_(outSs.getSheetByName(SHEET_THEME), src, targetDate, themeMaster);
  console.log('[DONE] aggregate theme (recovery)');
  SpreadsheetApp.flush();

  // ★コピー名は「欠落日を埋めた日付」に合わせて作る（上書き事故防止）
  const copiedName = `カテゴリ別市況分析_${ds}`;
  const dstFolder = getFolderByPath_(FOLDER_OUTPUT);
  const copiedFile = outFile.makeCopy(copiedName, dstFolder);
  console.log('[DONE] sheet copy (recovery)');

  // メール（通常と同じ形式で送る）
  const summary = buildMailSummary_(outSs, copiedFile.getUrl());
  MailApp.sendEmail({
    to: 'green3red2000@gmail.com',
    subject: copiedName,
    body: summary,
  });
  console.log('[DONE] main send (recovery)');

  // (6) レポートファイルの出力
  const reportBody = buildReportSummary_(summary, new Date());
  const reportName = `カテゴリ別市況分析_レポート_${ds}.txt`;

  writeTextReport_(
    dstFolder,
    reportName,
    reportBody
  );

  console.log(`[DONE] report output (recovery): ${reportName}`);

  console.log('[END] run_category_market_summary_recovery');
}



/** 全銘柄シートの集計 */
function aggregateZenmeigara_(sheet, src, targetDate) {
  if (!sheet) throw new Error('出力先シートが見つかりません: 全銘柄');

  // 3行ヘッダ: 1=テーブル種別,2=日付,3=offset
  const zeroColAgg = ensureZeroColumnReady_(sheet, targetDate, '集計値', null);
  const zeroColPct = ensureZeroColumnReady_(sheet, targetDate, '割合', 2);

  const values = sheet.getDataRange().getValues();
  const lastRow = values.length;
  if (lastRow < 4) return;

  const totalN = src.rows.length;
  const rules = buildCountRules_();

  const outAgg = [];
  const outPct = [];

  let currentClass = '';

  for (let r = 4; r <= lastRow; r++) {
    const classCell = String(values[r - 1][0] || '').trim();
    const itemCell = String(values[r - 1][1] || '').trim();

    if (classCell) currentClass = classCell;

    const itemKey = stripPrefixColon_(itemCell); // 例: 「順：(73)相関」→「(73)相関」

    let v = null;

    if (currentClass === '母数') {
      // 母数: 銘柄数
      if (itemKey === '銘柄数') v = totalN;
      else v = null;

    } else if (currentClass === '合計値') {
      v = calcSum_(itemKey, src);

    } else if (currentClass === '中央値') {
      v = calcMedian_(itemKey, src);

    } else if (currentClass === '平均値') {
      v = calcMean_(itemKey, src);

    } else if (currentClass === '標準偏差') {
      v = calcStd_(itemKey, src);

    } else if (currentClass === 'カウント数') {
      // 前日比は削除された想定（シートに無ければ呼ばれない）
      v = calcCountByRule_(itemCell, itemKey, src, rules);

    } else {
      v = null;
    }

    const fv = formatZenmeigaraOutput_(currentClass, itemKey, v);
    outAgg.push([fv]);

    // 割合テーブル：カウント数だけ (count / totalN)。それ以外は "ー"
    if (currentClass === 'カウント数' && typeof v === 'number' && totalN > 0) {
      const pct = truncNumber_((v / totalN) * 100, 2); // %表示想定
      outPct.push([pct / 100]); // セル表示はパーセント書式なので 0.1234 形式
    } else {
      outPct.push(['ー']);
    }
  }

  // 書き込み（4行目〜）
  sheet.getRange(4, zeroColAgg, outAgg.length, 1).setValues(outAgg);
  sheet.getRange(4, zeroColPct, outPct.length, 1).setValues(outPct);

  // 割合列の表示形式（%）: 4行目以降で数値だけ
  const pctRange = sheet.getRange(4, zeroColPct, outPct.length, 3);
  pctRange.setNumberFormat('0.00%');

  applyDashRightAlign_(pctRange);

  // 傾き更新（★集計値ブロックの 0..-29 のみを参照）
  fillSlopeColumn_(sheet, zeroColAgg, 29);

  // 差分列（★集計値 0 - (-1) を基本。1があれば 0-1）
  fillDiffColumn_(sheet, zeroColAgg, 29);
}


/** 私選テーマ別の集計 */
function aggregateTheme_(sheet, src, targetDate, themeMaster) {
  if (!sheet) throw new Error('出力先シートが見つかりません: 私選テーマ別');

  const zeroColAgg = ensureZeroColumnReady_(sheet, targetDate, '集計値', null);
  const zeroColPct = ensureZeroColumnReady_(sheet, targetDate, '割合', 2);

  // カテゴリ行をマスタに同期
  syncThemeCategories_(sheet, themeMaster.categories);

  const values = sheet.getDataRange().getValues();
  const lastRow = values.length;
  if (lastRow < 4) return;

  let r = 4;
  while (r <= lastRow) {
    const className = String(values[r - 1][0] || '').trim();
    if (!className) { r++; continue; }

    // 次の分類ブロックまで
    let end = r;
    while (end + 1 <= lastRow && !String(values[end][0] || '').trim()) end++;

    // ブロック内のカテゴリ行（B列=カテゴリ）
    const blockRows = [];
    for (let i = r; i <= end; i++) {
      const cat = values[i - 1][1];
      if (cat) blockRows.push({ row: i, category: String(cat).trim() });
    }

    for (const br of blockRows) {
      const set = themeMaster.codesByCategory.get(br.category) || new Set();
      const denom = set.size;

      let v = null;

      if (className === '母数') {
        v = denom;

      } else if (className === '前日比上昇銘柄数') {
        v = countInSet_(src, set, '(6)直近の終値の前日比', (x) => toNumber_(x) > 0);

      } else if (className === '売買代金') {
        v = sumInSetTurnover_(src, set);

      } else if (className === '(11)終値の直近10日間の回帰係数') {
        v = countInSet_(src, set, '(11)終値の直近10日間の回帰係数', (x) => toNumber_(x) > 0);

      } else {
        v = null;
      }

      // 集計値
      let outV = v;
      if (className === '売買代金') {
        // 売買代金：基準=壱、表示=兆、小数=2、単位なし（仕様★）
        outV = formatJPNumber_(v, '壱', '兆', 2, 'なし');
      }
      sheet.getRange(br.row, zeroColAgg).setValue(outV);

      // 割合：前日比上昇銘柄数 / denom, (11)回帰係数 / denom。それ以外は "ー"
      if ((className === '前日比上昇銘柄数' || className === '(11)終値の直近10日間の回帰係数')
          && typeof v === 'number' && denom > 0) {
        const pct = truncNumber_((v / denom) * 100, 2);
        sheet.getRange(br.row, zeroColPct).setValue(pct / 100);
      } else {
        sheet.getRange(br.row, zeroColPct).setValue('ー');
      }
    }

    r = end + 1;
  }

  // 割合列表示形式（%）
  const pctRows = lastRow - 3;
  const pctRange = sheet.getRange(4, zeroColPct, pctRows, 3);
  pctRange.setNumberFormat('0.00%');
  applyDashRightAlign_(pctRange);

  // 傾き更新（★集計値ブロックの 0..-29 のみを参照）
  fillSlopeColumn_(sheet, zeroColAgg, 29);

  // 差分列（★集計値 0 - (-1) を基本。1があれば 0-1）
  fillDiffColumn_(sheet, zeroColAgg, 29);
}


/** ====== ★傾き列：0〜-29 の値が10個以上なら回帰係数（傾き）を計算 ======
 *  - x: 0,-1,...,-29
 *  - y: 各セルの数値（%は 0〜1 の実数として扱う）
 *  - 出力：小数1桁 0埋め、2位以降切り捨て（数値として出す）
 */
function fillSlopeColumn_(sheet, aggZeroCol, maxOffset) {
  const slopeCol = findColumnByHeader_(sheet, '傾き');
  if (!slopeCol) {
    console.log('[INFO] 傾き列が見つからないためスキップ');
    return;
  }

  const offsetCols = findOffsetColumnsInBlock_(sheet, aggZeroCol, maxOffset);
  if (offsetCols.length === 0) {
    console.log('[INFO] 0..-29 の列が見つからないためスキップ');
    return;
  }

  const lastRow = sheet.getLastRow();
  if (lastRow < 4) return;

  const numRows = lastRow - 3;

  // 対象範囲をまとめて読む
  const lastCol = sheet.getLastColumn();
  const data = sheet.getRange(4, 1, numRows, lastCol).getValues();

  // ★傾き列の表示形式：各行の「集計値0列」の表示形式をベースにする
  //   （「合計値」「売買代金」などで見た目が崩れる問題の対策）
  const baseFormats = sheet.getRange(4, aggZeroCol, numRows, 1).getNumberFormats(); // 2D

  const slopes = [];
  const slopeFormats = [];
  const slopeAligns = [];   // ★ 右揃え制御
  for (let i = 0; i < numRows; i++) {
    // 0..-29 から数値を拾う
    const xs = [];
    const ys = [];

    for (const c of offsetCols) {
      const offset = c.offset;      // 0,-1,...-29
      const colIdx0 = c.col - 1;    // 0-based
      const raw = data[i][colIdx0];

      const y = toNumber_(raw);
      if (isFinite(y)) {
        xs.push(offset);
        ys.push(y);
      }
    }

    if (ys.length >= 10) {
      const slope = linearRegressionSlope_(xs, ys);
      const t = truncNumber_(slope, 1);
      slopes.push([t]);
      // ★小数1桁に揃える（表示形式は「集計値0列」をベースにしつつ 1桁へ寄せる）
      slopeFormats.push([coerceOneDecimalFormat_(baseFormats[i][0])]);
      slopeAligns.push(['RIGHT']);   // 数値も右揃え（既存見た目と一致）
    } else {
      // ★9個以下は「ー」
      slopes.push(['ー']);
      // 文字なので表示形式は空でOK（Sheets側が保持してても影響しない）
      slopeFormats.push([baseFormats[i][0]]);
      slopeAligns.push(['RIGHT']);   // ★「ー」は必ず右揃え
    }
  }

  // 書き込み＆表示形式（★行ごと）
  const outRange = sheet.getRange(4, slopeCol, numRows, 1);
  outRange.setValues(slopes);
  outRange.setNumberFormats(slopeFormats);
  outRange.setHorizontalAlignments(slopeAligns); // ★追加
}
/**
 * ★集計値ブロック内（aggZeroCol〜aggZeroCol+maxOffset）のみから
 * row3 の offset (0,-1,...,-maxOffset) 列を抽出
 */
function findOffsetColumnsInBlock_(sheet, aggZeroCol, maxOffset) {
  const lastCol = sheet.getLastColumn();
  const row3 = sheet.getRange(3, 1, 1, lastCol).getValues()[0];

  const start = aggZeroCol;
  const end = Math.min(lastCol, aggZeroCol + maxOffset);

  const want = new Set();
  for (let v = 0; v >= -maxOffset; v--) want.add(String(v));

  const cols = [];
  for (let c = start; c <= end; c++) {
    const s = String(row3[c - 1]).trim();
    if (want.has(s)) cols.push({ col: c, offset: Number(s) });
  }

  // 0,-1,-2,... の順にしたいので offset降順
  cols.sort((a, b) => b.offset - a.offset);
  return cols;
}

function fillDiffColumn_(sheet, aggZeroCol, maxOffset) {
  // ★「差分」= 集計値(0) - 集計値(-1)（-1が無ければ 1）
  const lastRow = sheet.getLastRow();
  if (lastRow < 4) return;

  const diffCol = findColumnByHeader_(sheet, '差分'); // ★1行目/3行目両方で探す
  if (!diffCol) return;

  // ★集計値ブロック内で「-1」を優先して探す（無ければ「1」）
  const lastCol = sheet.getLastColumn();
  const header3 = sheet.getRange(3, 1, 1, lastCol).getValues()[0].map(v => String(v ?? '').trim());
  const start = aggZeroCol;
  const end = Math.min(lastCol, aggZeroCol + maxOffset);

  let oneCol = 0;
  for (let c = start; c <= end; c++) {
    if (header3[c - 1] === '-1') { oneCol = c; break; }
  }
  if (!oneCol) {
    for (let c = start; c <= end; c++) {
      if (header3[c - 1] === '1') { oneCol = c; break; }
    }
  }
  if (!oneCol) return;

  const numRows = lastRow - 3;
  const r0 = sheet.getRange(4, aggZeroCol, numRows, 1);
  const r1 = sheet.getRange(4, oneCol, numRows, 1);

  const v0 = r0.getValues();
  const v1 = r1.getValues();
  const nf0 = r0.getNumberFormats();

  const out = new Array(numRows);
  for (let i = 0; i < numRows; i++) {
    const a = toNumber_(v0[i][0]);
    const b = toNumber_(v1[i][0]);
    if (!isFinite(a) || !isFinite(b)) out[i] = [''];
    else out[i] = [a - b];
  }

  const outRange = sheet.getRange(4, diffCol, numRows, 1);
  outRange.setValues(out);
  outRange.setNumberFormats(nf0);
}



/** 1次回帰の傾き（最小二乗） */
function linearRegressionSlope_(xs, ys) {
  const n = xs.length;
  if (n <= 1) return 0;

  let sx=0, sy=0, sxx=0, sxy=0;
  for (let i=0; i<n; i++) {
    const x = xs[i];
    const y = ys[i];
    sx += x; sy += y;
    sxx += x*x;
    sxy += x*y;
  }
  const denom = (n*sxx - sx*sx);
  if (denom === 0) return 0;
  return (n*sxy - sx*sy) / denom;
}



/** ヘッダから列番号を探す（1行目で探す→なければ3行目も探す） */
function findColumnByHeader_(sheet, headerName) {
  const lastCol = sheet.getLastColumn();
  const r1 = sheet.getRange(1, 1, 1, lastCol).getValues()[0].map(x=>String(x||'').trim());
  let idx = r1.indexOf(headerName);
  if (idx >= 0) return idx + 1;

  const r3 = sheet.getRange(3, 1, 1, lastCol).getValues()[0].map(x=>String(x||'').trim());
  idx = r3.indexOf(headerName);
  if (idx >= 0) return idx + 1;

  // 最後の保険：C列が「傾き」っぽい想定
  return null;
}

/** ====== ★全銘柄：出力フォーマット整形（分類×項目） ====== */
function formatZenmeigaraOutput_(className, itemKey, v) {
  if (v == null) return null;

  // 合計値：指定項目は数値表示関数
  if (className === '合計値') {
    if (itemKey === '売買代金') return formatJPNumber_(v, '壱', '兆', 2, 'なし');
    if (itemKey === '時価総額') return formatJPNumber_(v, '億', '兆', 1, 'なし');
    if (itemKey === '売上高')   return formatJPNumber_(v, '億', '兆', 1, 'なし');
    if (itemKey === '経常益')   return formatJPNumber_(v, '億', '兆', 1, 'なし');
    if (itemKey === '最終益')   return formatJPNumber_(v, '億', '兆', 1, 'なし');
    if (itemKey === '出来高')   return formatJPNumber_(v, '壱', '億', 2, 'なし');
    if (itemKey === '信用売り残') return formatJPNumber_(v, '壱', '億', 1, 'なし');
    if (itemKey === '信用買い残') return formatJPNumber_(v, '壱', '億', 1, 'なし');
    return v;
  }

  // 中央値：信用買い残日数は小数2桁0埋め、3位以降切り捨て
  if (className === '中央値') {
    if (itemKey === '(98)信用買い残日数') return formatFixedTrunc_(v, 2);
    return v;
  }

  // 平均値：指定項目は固定小数 or 数値表示関数
  if (className === '平均値') {
    if (itemKey === 'PER') return formatFixedTrunc_(v, 1);
    if (itemKey === 'PBR') return formatFixedTrunc_(v, 1);
    if (itemKey === 'MIX係数') return formatFixedTrunc_(v, 1);
    if (itemKey === '利回り') return formatFixedTrunc_(v, 2);
    if (itemKey === '信用倍率') return formatFixedTrunc_(v, 1);
    if (itemKey === '(98)信用買い残日数') return formatFixedTrunc_(v, 2);
    if (itemKey === '時価総額') return formatJPNumber_(v, '億', '億', 1, 'なし');
    if (itemKey === '売上高')   return formatJPNumber_(v, '億', '億', 1, 'なし');
    if (itemKey === '経常益')   return formatJPNumber_(v, '億', '億', 1, 'なし');
    if (itemKey === '最終益')   return formatJPNumber_(v, '億', '億', 1, 'なし');
    if (itemKey === '騰落率')   return formatFixedTrunc_(v, 2);
    if (itemKey === '出来高')   return formatJPNumber_(v, '壱', '壱', 0, 'なし');
    return v;
  }

  // 標準偏差：指定項目は固定小数 or 数値表示関数
  if (className === '標準偏差') {
    if (itemKey === 'PER') return formatFixedTrunc_(v, 1);
    if (itemKey === 'PBR') return formatFixedTrunc_(v, 1);
    if (itemKey === 'MIX係数') return formatFixedTrunc_(v, 1);
    if (itemKey === '利回り') return formatFixedTrunc_(v, 2);
    if (itemKey === '信用倍率') return formatFixedTrunc_(v, 1);
    if (itemKey === '(98)信用買い残日数') return formatFixedTrunc_(v, 2);
    if (itemKey === '時価総額') return formatJPNumber_(v, '億', '億', 1, 'なし');
    if (itemKey === '売上高')   return formatJPNumber_(v, '億', '億', 1, 'なし');
    if (itemKey === '経常益')   return formatJPNumber_(v, '億', '億', 1, 'なし');
    if (itemKey === '最終益')   return formatJPNumber_(v, '億', '億', 1, 'なし');
    if (itemKey === '騰落率')   return formatFixedTrunc_(v, 2);
    if (itemKey === '出来高')   return formatJPNumber_(v, '壱', '壱', 0, 'なし');
    return v;
  }

  return v;
}

/** ====== ★カウント数ルール（判定式修正込み） ====== */
function buildCountRules_() {
  // (100)-(103) と (52)(53)(55)(56) は 0.03 判定（値が 0.0045 などの小数のため）
  const ge0_03 = (v) => toNumber_(v) >= 0.03;

  const ge3 = (v) => toNumber_(v) >= 3; // 3（%が “3” で入る系）
  const leMinus3 = (v) => toNumber_(v) <= -3;

  return {
    '(6)直近の終値の前日比': (v)=> toNumber_(v) > 0,

    '前日比': (v)=> toNumber_(v) > 0,

    '(11)終値の直近10日間の回帰係数': (v)=> toNumber_(v) > 0,
    '(12)終値の直近22日間の回帰係数': (v)=> toNumber_(v) > 0,
    '(14)終値の直近132日間の回帰係数': (v)=> toNumber_(v) > 0,

    '(16)出来高の直近10日間の回帰係数': (v)=> toNumber_(v) > 0,
    '(17)出来高の直近22日間の回帰係数': (v)=> toNumber_(v) > 0,
    '(19)出来高の直近132日間の回帰係数': (v)=> toNumber_(v) > 0,

    '(31)終値10日移動平均の直近10日の回帰係数': (v)=> toNumber_(v) > 0,
    '(32)終値22日移動平均の直近10日の回帰係数': (v)=> toNumber_(v) > 0,
    '(33)終値66日移動平均の直近10日の回帰係数': (v)=> toNumber_(v) > 0,
    '(34)終値132日移動平均の直近10日の回帰係数': (v)=> toNumber_(v) > 0,

    '(36)出来高10日移動平均の直近10日の回帰係数': (v)=> toNumber_(v) > 0,
    '(37)出来高22日移動平均の直近10日の回帰係数': (v)=> toNumber_(v) > 0,
    '(38)出来高66日移動平均の直近10日の回帰係数': (v)=> toNumber_(v) > 0,
    '(39)出来高132日移動平均の直近10日の回帰係数': (v)=> toNumber_(v) > 0,

    // 0.03 判定
    '(100)直近10日間の値幅不安定率': ge0_03,
    '(101)直近22日間の値幅不安定率': ge0_03,
    '(102)直近66日間の値幅不安定率': ge0_03,
    '(103)直近132日間の値幅不安定率': ge0_03,


    '(47)直近10日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数': (v)=> toNumber_(v) > 0,
    '(48)直近22日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数': (v)=> toNumber_(v) > 0,
    '(49)直近66日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数': (v)=> toNumber_(v) > 0,
    '(50)直近132日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数': (v)=> toNumber_(v) > 0,


    // 0.03 判定
    '(52)終値10日移動平均と終値の移動平均乖離率': ge0_03,
    '(53)終値22日移動平均と終値の移動平均乖離率': ge0_03,
    '(55)終値66日移動平均と終値の移動平均乖離率': ge0_03,
    '(56)終値132日移動平均と終値の移動平均乖離率': ge0_03,
    // (58)-(61)は指定通り 3%（値が小数なら同様に0.03へ変更可）
    '(58)出来高10日移動平均と出来高の移動平均乖離率': ge3,
    '(59)出来高22日移動平均と出来高の移動平均乖離率': ge3,
    '(60)出来高66日移動平均と出来高の移動平均乖離率': ge3,
    '(61)出来高132日移動平均と出来高の移動平均乖離率': ge3,

    '(72)β': (v)=> toNumber_(v) >= 1,

    '(73)相関': (v)=> toNumber_(v) >= 0.5,

    // ★逆相関：-0.1以下（変更）
    '__NEG__(73)相関': (v)=> toNumber_(v) <= -0.1,

    '(74)相対ボラ': (v)=> toNumber_(v) >= 1.2,
    '(75)残差ボラ': (v)=> toNumber_(v) >= 0.8,
    '(76)アップサイドβ': (v)=> toNumber_(v) >= 1.2,
    '(77)ダウンサイドβ': (v)=> toNumber_(v) >= 1.2,

    // Captureは 1.2 以上（値が 0.62 等の小数のため）
    '(78)Up Capture': (v)=> toNumber_(v) >= 1.2,
    '(79)Down Capture': (v)=> toNumber_(v) >= 1.2,

    '(80)RSI__HIGH': (v)=> toNumber_(v) > 80,
    '(80)RSI__MID': (v)=> {
      const n = toNumber_(v);
      return n >= 20 && n <= 80;
    },
    '(80)RSI__LOW': (v)=> toNumber_(v) < 20,

    '(81)RSIの直近22日間の回帰係数': (v)=> toNumber_(v) > 0,
    '(82)MACD': (v)=> toNumber_(v) > 0,
    '(83)MACDの直近22日間の回帰係数': (v)=> toNumber_(v) > 0,

    '(84)連続日数__UP': (v)=> toNumber_(v) >= 5,
    '(84)連続日数__DOWN': (v)=> toNumber_(v) <= -5,

    '(85)5日間上昇率': ge3,
    '(86)10日間上昇率': ge3,
    '(88)22日間上昇率': ge3,

    // 下落率：-3以下
    '(89)5日間下落率': leMinus3,
    '(90)10日間下落率': leMinus3,
    '(91)22日間下落率': leMinus3,

    '(92)低ボラ出来高増': (v)=> toNumber_(v) >= 1,
    '(93)水平ライン上突破': (v)=> toNumber_(v) >= 1,
    '(94)水平ライン下突破': (v)=> toNumber_(v) >= 1,
    '(95)GUPから全モ': (v)=> toNumber_(v) >= 1,

    '(96)AI基準判定__HIGH': (v)=> toNumber_(v) >= 8,
    '(96)AI基準判定__MID': (v)=> {
      const n = toNumber_(v);
      return n >= 4 && n <= 7;
    },
    '(96)AI基準判定__LOW': (v)=> toNumber_(v) <= 3,

    // PER/PBR/利回り/時価総額のレンジ別は calcCountByRule_ で動的に処理（★）
    'PER': null,
    'PBR': null,
    '利回り': null,
    '時価総額': null,

    '信用倍率': (v)=> toNumber_(v) >= 1,
    '(98)信用買い残日数': (v)=> toNumber_(v) >= 5,

    '(104)パーフェクトオーダー判定__UP': (v)=> toNumber_(v) === 1,
    '(104)パーフェクトオーダー判定__DOWN': (v)=> toNumber_(v) === -1,

    // ★(138)ボリンジャーバンドのσ値：レンジ別カウント（prefixで分岐）
    '__BB__(138)__GE3': (v)=> toNumber_(v) >= 3,
    '__BB__(138)__2_3': (v)=> {
      const n = toNumber_(v);
      return n >= 2 && n < 3;
    },
    '__BB__(138)__1_2': (v)=> {
      const n = toNumber_(v);
      return n >= 1 && n < 2;
    },
    '__BB__(138)__M1_1': (v)=> {
      const n = toNumber_(v);
      return n >= -1 && n < 1;
    },
    '__BB__(138)__M2_M1': (v)=> {
      const n = toNumber_(v);
      return n >= -2 && n < -1;
    },
    '__BB__(138)__M3_M2': (v)=> {
      const n = toNumber_(v);
      return n >= -3 && n < -2;
    },
    '__BB__(138)__LT_M3': (v)=> toNumber_(v) < -3,
  };
}

/** ====== ★MIX係数：PER*PBR を対象値として扱う（中央値/平均/標準偏差用） ====== */
function buildMixValues_(src) {
  const perIdx = src.hmap.get(normalizeHeader_('PER'));
  const pbrIdx = src.hmap.get(normalizeHeader_('PBR'));
  if (perIdx == null || pbrIdx == null) return [];

  const arr = [];
  for (const r of src.rows) {
    const per = toNumber_(r.row[perIdx]);
    const pbr = toNumber_(r.row[pbrIdx]);
    if (isFinite(per) && isFinite(pbr)) arr.push(per * pbr);
  }
  return arr;
}

/** ====== 全銘柄：合計/中央値/平均/標準偏差 ====== */
function calcSum_(itemKey, src) {
  if (itemKey === '売買代金') return sumTradeValue_(src);

  const idx = src.hmap.get(normalizeHeader_(itemKey));
  if (idx == null) return null;

  let s = 0;
  for (const r of src.rows) {
    const v = toNumber_(r.row[idx]);
    if (isFinite(v)) s += v;
  }
  return s;
}

function calcMedian_(itemKey, src) {
  if (itemKey === 'MIX係数') {
    const arr = buildMixValues_(src);
    return median_(arr);
  }

  const idx = src.hmap.get(normalizeHeader_(itemKey));
  if (idx == null) return null;

  const arr = [];
  for (const r of src.rows) {
    const v = toNumber_(r.row[idx]);
    if (isFinite(v)) arr.push(v);
  }
  return median_(arr);
}

function calcMean_(itemKey, src) {
  if (itemKey === 'MIX係数') {
    const arr = buildMixValues_(src);
    if (!arr.length) return null;
    let s=0;
    for (const v of arr) s += v;
    return s / arr.length;
  }

  const idx = src.hmap.get(normalizeHeader_(itemKey));
  if (idx == null) return null;

  let s=0, n=0;
  for (const r of src.rows) {
    const v = toNumber_(r.row[idx]);
    if (isFinite(v)) { s+=v; n++; }
  }
  return n>0 ? s/n : null;
}

function calcStd_(itemKey, src) {
  if (itemKey === 'MIX係数') {
    const arr = buildMixValues_(src);
    return stddev_(arr);
  }

  const idx = src.hmap.get(normalizeHeader_(itemKey));
  if (idx == null) return null;

  const arr = [];
  for (const r of src.rows) {
    const v = toNumber_(r.row[idx]);
    if (isFinite(v)) arr.push(v);
  }
  return stddev_(arr);
}

/** カウント数：項目名に応じて判定 */
function calcCountByRule_(itemRaw, itemKey, src, rules) {
  const raw = String(itemRaw).trim();
  let key = itemKey;

  // 逆：相関（-0.1以下）
  if (raw.indexOf('逆：') === 0 && itemKey === '(73)相関') key = '__NEG__(73)相関';

  // RSI
  if (raw.indexOf('高：') === 0 && itemKey === '(80)RSI') key = '(80)RSI__HIGH';
  if (raw.indexOf('中：') === 0 && itemKey === '(80)RSI') key = '(80)RSI__MID';
  if (raw.indexOf('低：') === 0 && itemKey === '(80)RSI') key = '(80)RSI__LOW';

  // 連続日数
  if (raw.indexOf('上昇：') === 0 && itemKey === '(84)連続日数') key = '(84)連続日数__UP';
  if (raw.indexOf('下落：') === 0 && itemKey === '(84)連続日数') key = '(84)連続日数__DOWN';

  // AI判定
  if (raw.indexOf('高：') === 0 && itemKey === '(96)AI基準判定') key = '(96)AI基準判定__HIGH';
  if (raw.indexOf('中：') === 0 && itemKey === '(96)AI基準判定') key = '(96)AI基準判定__MID';
  if (raw.indexOf('低：') === 0 && itemKey === '(96)AI基準判定') key = '(96)AI基準判定__LOW';

  // パーフェクトオーダー
  if (raw.indexOf('順：') === 0 && itemKey === '(104)パーフェクトオーダー判定') key = '(104)パーフェクトオーダー判定__UP';
  if (raw.indexOf('逆：') === 0 && itemKey === '(104)パーフェクトオーダー判定') key = '(104)パーフェクトオーダー判定__DOWN';

  // ★ボリンジャーバンドσ値（(138)）：prefixでレンジ分岐
  // 例) 「３：(138)ボリンジャーバンドのσ値」など
  if (itemKey === '(138)ボリンジャーバンドのσ値') {
    if (raw.indexOf('３：') === 0) key = '__BB__(138)__GE3';
    else if (raw.indexOf('２：') === 0) key = '__BB__(138)__2_3';
    else if (raw.indexOf('１：') === 0) key = '__BB__(138)__1_2';
    else if (raw.indexOf('０：') === 0) key = '__BB__(138)__M1_1';
    else if (raw.indexOf('-１：') === 0) key = '__BB__(138)__M2_M1';
    else if (raw.indexOf('-２：') === 0) key = '__BB__(138)__M3_M2';
    else if (raw.indexOf('-３：') === 0) key = '__BB__(138)__LT_M3';
    else return null;
  }

  // ★MIX係数：PER*PBR を算出して 22.5以上をカウント
  if (itemKey === 'MIX係数') {
    const perIdx = src.hmap.get(normalizeHeader_('PER'));
    const pbrIdx = src.hmap.get(normalizeHeader_('PBR'));
    if (perIdx == null || pbrIdx == null) return null;

    let cnt = 0;
    for (const r of src.rows) {
      const per = toNumber_(r.row[perIdx]);
      const pbr = toNumber_(r.row[pbrIdx]);
      if (isFinite(per) && isFinite(pbr) && (per * pbr) >= 22.5) cnt++;
    }
    return cnt;
  }

  // ★PERレンジ（B列のプレフィックスで判定。C列は参照しない）
  if (itemKey === 'PER') {
    const idx = src.hmap.get(normalizeHeader_('PER'));
    if (idx == null) return null;

    // 超：30以上 / 高：20以上30未満 / 中：10以上20未満 / 低：10未満
    let min = null, max = null;
    if (raw.indexOf('超：') === 0) { min = 30; max = null; }
    else if (raw.indexOf('高：') === 0) { min = 20; max = 30; }
    else if (raw.indexOf('中：') === 0) { min = 10; max = 20; }
    else if (raw.indexOf('低：') === 0) { min = null; max = 10; }
    else { return null; }

    let cnt = 0;
    for (const r of src.rows) {
      const v = toNumber_(r.row[idx]);
      if (!isFinite(v)) continue;
      if (rangeCheck_(v, min, max, true, false)) cnt++;
    }
    return cnt;
  }

  // ★PBRレンジ（B列のプレフィックスで判定。C列は参照しない）
  if (itemKey === 'PBR') {
    const idx = src.hmap.get(normalizeHeader_('PBR'));
    if (idx == null) return null;

    // 超：3以上 / 高：2以上3未満 / 中：1以上2未満 / 低上：0.5以上1未満 / 低下：0.5未満
    let min = null, max = null;
    if (raw.indexOf('超：') === 0) { min = 3; max = null; }
    else if (raw.indexOf('高：') === 0) { min = 2; max = 3; }
    else if (raw.indexOf('中：') === 0) { min = 1; max = 2; }
    else if (raw.indexOf('低上：') === 0) { min = 0.5; max = 1; }
    else if (raw.indexOf('低下：') === 0) { min = null; max = 0.5; }
    else { return null; }

    let cnt = 0;
    for (const r of src.rows) {
      const v = toNumber_(r.row[idx]);
      if (!isFinite(v)) continue;
      if (rangeCheck_(v, min, max, true, false)) cnt++;
    }
    return cnt;
  }

  // ★利回りレンジ（B列のプレフィックスで判定。C列は参照しない）
  if (itemKey === '利回り') {
    const idx = src.hmap.get(normalizeHeader_('利回り'));
    if (idx == null) return null;

    // 仕様どおりハードコード：
    // 超：3以上 / 高：3以上 / 中：2以上3未満 / 低上：1以上2未満 / 低下：1未満
    let min = null, max = null;
    if (raw.indexOf('超：') === 0) { min = 3; max = null; }
    else if (raw.indexOf('高：') === 0) { min = 3; max = null; }
    else if (raw.indexOf('中：') === 0) { min = 2; max = 3; }
    else if (raw.indexOf('低上：') === 0) { min = 1; max = 2; }
    else if (raw.indexOf('低下：') === 0) { min = null; max = 1; }
    else { return null; }

    let cnt = 0;
    for (const r of src.rows) {
      const v = toNumber_(r.row[idx]);
      if (!isFinite(v)) continue;
      if (rangeCheck_(v, min, max, true, false)) cnt++;
    }
    return cnt;
  }

  // ★時価総額レンジ（B列のプレフィックスで判定。C列は参照しない）
  // ※集計元の「時価総額」列の単位はそのまま。仕様の閾値(10000等)に合わせて比較
  if (itemKey === '時価総額') {
    const idx = src.hmap.get(normalizeHeader_('時価総額'));
    if (idx == null) return null;

    // 超：10000以上 / 高：5000以上10000未満 / 中上：1000以上5000未満 / 中下：300以上1000未満 / 低上：100以上300未満 / 低下：100未満
    let min = null, max = null;
    if (raw.indexOf('超：') === 0) { min = 10000; max = null; }
    else if (raw.indexOf('高：') === 0) { min = 5000; max = 10000; }
    else if (raw.indexOf('中上：') === 0) { min = 1000; max = 5000; }
    else if (raw.indexOf('中下：') === 0) { min = 300; max = 1000; }
    else if (raw.indexOf('低上：') === 0) { min = 100; max = 300; }
    else if (raw.indexOf('低下：') === 0) { min = null; max = 100; }
    else { return null; }

    let cnt = 0;
    for (const r of src.rows) {
      const v = toNumber_(r.row[idx]);
      if (!isFinite(v)) continue;
      if (rangeCheck_(v, min, max, true, false)) cnt++;
    }
    return cnt;
  }


  const headerForLookup =
    (key === '__NEG__(73)相関') ? '(73)相関'
    : (key.indexOf('(80)RSI')===0) ? '(80)RSI'
    : (key.indexOf('(84)連続日数')===0) ? '(84)連続日数'
    : (key.indexOf('(96)AI基準判定')===0) ? '(96)AI基準判定'
    : (key.indexOf('(104)パーフェクトオーダー判定')===0) ? '(104)パーフェクトオーダー判定'
    : (key.indexOf('__BB__(138)')===0) ? '(138)ボリンジャーバンドのσ値'
    : (itemKey === '前日比') ? '(6)直近の終値の前日比'
    : itemKey;

  const rule = rules[key];
  if (typeof rule !== 'function') return null;

  const idx = src.hmap.get(normalizeHeader_(headerForLookup));
  if (idx == null) return null;

  let cnt = 0;
  for (const r of src.rows) {
    if (rule(r.row[idx])) cnt++;
  }
  return cnt;
}

function rangeCheck_(v, min, max, minInc, maxInc) {
  if (min != null) {
    if (minInc) { if (v < min) return false; }
    else { if (v <= min) return false; }
  }
  if (max != null) {
    if (maxInc) { if (v > max) return false; }
    else { if (v >= max) return false; }
  }
  return true;
}

/** 売買代金 = sum(close*volume) */
function sumTradeValue_(src) {
  const cIdx = src.hmap.get(normalizeHeader_('(4)直近の終値'));
  const vIdx = src.hmap.get(normalizeHeader_('(5)直近の出来高'));
  if (cIdx == null || vIdx == null) return null;

  let s = 0;
  for (const r of src.rows) {
    const close = toNumber_(r.row[cIdx]);
    const vol = toNumber_(r.row[vIdx]);
    if (isFinite(close) && isFinite(vol)) s += close * vol;
  }
  return s;
}

/** ====== 私選テーマ別：カテゴリ別集計 ====== */
function countInSet_(src, codeSet, header, predicate) {
  const idx = src.hmap.get(normalizeHeader_(header));
  if (idx == null) return null;
  let cnt=0;
  for (const r of src.rows) {
    if (!codeSet.has(r.code)) continue;
    if (predicate(r.row[idx])) cnt++;
  }
  return cnt;
}

function sumTradeValueInSet_(src, codeSet) {
  const cIdx = src.hmap.get(normalizeHeader_('(4)直近の終値'));
  const vIdx = src.hmap.get(normalizeHeader_('(5)直近の出来高'));
  if (cIdx == null || vIdx == null) return null;

  let s = 0;
  for (const r of src.rows) {
    if (!codeSet.has(r.code)) continue;
    const close = toNumber_(r.row[cIdx]);
    const vol = toNumber_(r.row[vIdx]);
    if (isFinite(close) && isFinite(vol)) s += close * vol;
  }
  return s;
}


// 互換: 旧名（テーマ別で使用）
function sumInSetTurnover_(src, codeSet) {
  return sumTradeValueInSet_(src, codeSet);
}

/** ====== 出力列（0列）準備：日付比較→必要なら右シフト ====== */
function ensureZeroColumnReady_(sheet, targetDate, tableLabel, maxOffset) {
  const lastCol = sheet.getLastColumn();

  // ★ maxOffset が null/undefined の場合は「無限拡張モード」（集計値用）
  const unlimited = (maxOffset === null || maxOffset === undefined);

  // --- label行は「1行目」固定 ---
  const row1 = sheet.getRange(1, 1, 1, lastCol).getValues()[0]
    .map(v => String(v ?? '').trim());

  const labelCol = row1.findIndex(v => v === tableLabel) + 1; // 1-based
  if (labelCol <= 0) throw new Error(`「${tableLabel}」ラベルが見つかりません（1行目）`);

  // --- 次のテーブルラベル列（境界）を取得（あるなら） ---
  let nextLabelCol = 0;
  for (let c = labelCol + 1; c <= lastCol; c++) {
    if (row1[c - 1] !== '') { nextLabelCol = c; break; }
  }
  const endCol = nextLabelCol ? (nextLabelCol - 1) : lastCol;

  // --- offset行（0,-1,... がある行）を 1〜5行目で自動検出 ---
  const probeRows = Math.min(5, sheet.getLastRow());
  const head = sheet.getRange(1, 1, probeRows, lastCol).getValues()
    .map(r => r.map(v => String(v ?? '').trim()));

  let offsetRow = 0; // 1-based
  for (let r = 1; r <= probeRows; r++) {
    const rr = head[r - 1];
    const hasZero = rr.some(v => v === '0');
    const hasMinus1 = rr.some(v => v === '-1');
    const hasSlope = rr.some(v => v === '傾き');
    // 0 があり、かつ -1 か 傾き がある行を優先
    if (hasZero && (hasMinus1 || hasSlope)) { offsetRow = r; break; }
  }
  // 見つからなければ「0 があるだけ」の行を探す
  if (!offsetRow) {
    for (let r = 1; r <= probeRows; r++) {
      const rr = head[r - 1];
      if (rr.some(v => v === '0')) { offsetRow = r; break; }
    }
  }
  if (!offsetRow) {
    throw new Error(`「${tableLabel}」の offset行(0) が見つかりません（1〜${probeRows}行目）`);
  }

  // --- date行は offset行の1つ上（仕様どおりなら2行目になる） ---
  const dateRow = Math.max(1, offsetRow - 1);

  // --- offset行から「0列」を探す（tableLabel領域内だけ） ---
  const offsetRowVals = head[offsetRow - 1];
  let zeroCol = 0;
  for (let c = labelCol; c <= endCol; c++) {
    if (offsetRowVals[c - 1] === '0') { zeroCol = c; break; }
  }
  if (!zeroCol) {
    // デバッグ用：最小限の状況表示（ここだけ）
    console.log(`[WARN] zero not found: label=${tableLabel}, labelCol=${labelCol}, endCol=${endCol}, offsetRow=${offsetRow}, dateRow=${dateRow}`);
    throw new Error(`「${tableLabel}」テーブルの 0 列が見つかりません（label=1行目, offset=${offsetRow}行目=0）`);
  }

  // --- 既存日付（dateRow）判定 ---
  const existing = sheet.getRange(dateRow, zeroCol).getValue();
  if (existing instanceof Date) {
    if (existing.getTime() >= targetDate.getTime()) return zeroCol;
  } else if (existing) {
    const d = new Date(existing);
    if (!isNaN(d.getTime()) && d.getTime() >= targetDate.getTime()) return zeroCol;
  }

  // --- 右に1列シフト（表示形式も維持） ---
  if (!unlimited) {
    // 従来：固定幅でローリング（0..-maxOffset）
    const width = maxOffset + 1;
    shiftColumnsRightWithinWidth_(sheet, zeroCol, width);

    // --- dateRow に targetDate ---
    sheet.getRange(dateRow, zeroCol).setValue(targetDate);

    // --- offsetRow を作り直す（0,-1,...） ---
    rebuildOffsetsRowWidth_(sheet, zeroCol, width, offsetRow);

    return zeroCol;
  }

  // ★ 無限拡張：今ある幅(0,-1,-2,...)を数えて「右に1列追加→全体を右へ1シフト」
  // 末尾列の右に1列追加（＝古いデータの退避先を作る）し、フォーマットを末尾列から継承

  // 現在の幅を offsetRow の並びから数える（0, -1, -2, ... が連続するところまで）
  const currentOffsetRow = sheet.getRange(offsetRow, zeroCol, 1, Math.max(1, endCol - zeroCol + 1)).getValues()[0]
    .map(v => String(v ?? '').trim());

  let width = 0;
  for (let i = 0; i < currentOffsetRow.length; i++) {
    const expected = (i === 0) ? '0' : `-${i}`;
    if (currentOffsetRow[i] !== expected) break;
    width++;
  }
  if (width < 1) width = 1;

  const lastHistCol = zeroCol + width - 1;

  // 境界（次ラベル）より左に「列追加」を差し込む：lastHistCol の右に1列を挿入
  sheet.insertColumnAfter(lastHistCol);

  // 追加列にフォーマットを継承（末尾列の見た目をコピー）
  const lastRow = sheet.getLastRow();
  sheet.getRange(1, lastHistCol, lastRow, 1)
    .copyTo(sheet.getRange(1, lastHistCol + 1, lastRow, 1), SpreadsheetApp.CopyPasteType.PASTE_FORMAT, false);

  const newWidth = width + 1;

  // dateRow：右へ1シフトし、0列へ targetDate
  const oldDates = sheet.getRange(dateRow, zeroCol, 1, width).getValues()[0];
  const newDates = [targetDate].concat(oldDates);
  sheet.getRange(dateRow, zeroCol, 1, newWidth).setValues([newDates]);

  // データ行（offsetRow+1 以降）：右へ1シフト（0列は空に）
  const dataStartRow = offsetRow + 1;
  if (lastRow >= dataStartRow) {
    const oldData = sheet.getRange(dataStartRow, zeroCol, lastRow - dataStartRow + 1, width).getValues();
    const newData = oldData.map(row => [''].concat(row));
    sheet.getRange(dataStartRow, zeroCol, lastRow - dataStartRow + 1, newWidth).setValues(newData);
  }

  // offsetRow：0,-1,-2,... を newWidth で作り直す
  rebuildOffsetsRowWidth_(sheet, zeroCol, newWidth, offsetRow);

  return zeroCol;
}


function shiftColumnsRightWithinWidth_(sheet, startCol, width) {
  // 右に1列シフト（値だけでなく表示形式も維持する）
  // startCol: 1-based
  // width: 対象列数（例: 30なら 0,-1,...,-29 / 3なら 0,-1,-2）
  const lastRow = sheet.getLastRow();
  if (lastRow < 2 || width < 2) return;

  // --- 2行目: 日付行（1行だけ） ---
  {
    const src = sheet.getRange(2, startCol, 1, width - 1);
    const dst = sheet.getRange(2, startCol + 1, 1, width - 1);
    dst.setValues(src.getValues());
    dst.setNumberFormats(src.getNumberFormats());
    sheet.getRange(2, startCol, 1, 1).clearContent(); // 形式は残す
  }

  // --- 4行目以降: データ行（3行目は見出し=0,-1,...） ---
  if (lastRow >= 4) {
    const numRows = lastRow - 3;
    const src = sheet.getRange(4, startCol, numRows, width - 1);
    const dst = sheet.getRange(4, startCol + 1, numRows, width - 1);
    dst.setValues(src.getValues());
    dst.setNumberFormats(src.getNumberFormats());
    sheet.getRange(4, startCol, numRows, 1).clearContent(); // 形式は残す
  }

  console.log(`[INFO] shifted right: startCol=${startCol}, width=${width}`);
}


function rebuildOffsetsRowWidth_(sheet, startCol, width) {
  const arr = [];
  for (let i = 0; i < width; i++) arr.push(-i);
  arr[0] = 0;
  sheet.getRange(3, startCol, 1, width).setValues([arr]);
}



/** ====== Drive検索ユーティリティ ====== */
function openSpreadsheetInFolder_(pathParts, fileName) {
  const folder = getFolderByPath_(pathParts);
  if (!folder) return null;

  const q = `title = "${fileName}" and mimeType = "application/vnd.google-apps.spreadsheet" and trashed = false`;
  const it = folder.searchFiles(q);
  if (!it.hasNext()) return null;

  const file = it.next();
  return SpreadsheetApp.openById(file.getId());
}

function findLatestDatedSpreadsheet_(pathParts, prefix) {
  const folder = getFolderByPath_(pathParts);
  if (!folder) return null;

  const q = `title contains "${prefix}" and mimeType = "application/vnd.google-apps.spreadsheet" and trashed = false`;
  const it = folder.searchFiles(q);

  let best = null; // {id, title, dateStr}
  while (it.hasNext()) {
    const f = it.next();
    const title = f.getName();
    const m = title.match(new RegExp('^' + escapeRegExp_(prefix) + '(\\d{4}-\\d{2}-\\d{2})$'));
    if (!m) continue;
    const dateStr = m[1];
    if (!best || dateStr > best.dateStr) best = { id: f.getId(), title, dateStr };
  }
  return best;
}

function getFolderByPath_(parts) {
  let cur = DriveApp.getRootFolder();
  for (const name of parts) {
    const it = cur.getFoldersByName(name);
    if (!it.hasNext()) return null;
    cur = it.next();
  }
  return cur;
}

/** ====== 起動判定用：営業日カレンダー読み込み ====== */
function loadBusinessDaySet_() {
  const ss = openSpreadsheetInFolder_(
    FOLDER_MASTER,
    CALENDAR_MASTER_NAME
  );

  if (!ss) {
    throw new Error(
      `カレンダーマスタが見つかりません: ${CALENDAR_MASTER_NAME}`
    );
  }

  const sh = ss.getSheets()[0];
  const vals = sh.getDataRange().getValues();

  if (vals.length === 0) {
    return new Set();
  }

  const headers = vals[0].map(v => String(v || '').trim());

  const dateIdx = headers.indexOf('日付');
  if (dateIdx < 0) {
    throw new Error('カレンダーマスタに「日付」列がありません');
  }

  const holidayDivisionIdx = headers.indexOf('日本市場休日区分');
  if (holidayDivisionIdx < 0) {
    throw new Error(
      'カレンダーマスタに「日本市場休日区分」列がありません'
    );
  }

  const businessDaySet = new Set();

  for (let r = 1; r < vals.length; r++) {
    const dateValue = vals[r][dateIdx];
    const division = String(
      vals[r][holidayDivisionIdx] ?? ''
    ).trim();

    // 日本市場休日区分が1または2の行だけを営業日とする
    if (division !== '1' && division !== '2') {
      continue;
    }

    if (!dateValue) {
      continue;
    }

    const date =
      dateValue instanceof Date
        ? dateValue
        : parseDateLoose_(String(dateValue));

    if (date) {
      businessDaySet.add(fmtDate_(date));
    }
  }

  return businessDaySet;
}

/** ====== 私選テーマ別銘柄マスタ読み込み ====== */
function loadThemeMaster_() {
  const ss = openSpreadsheetInFolder_(FOLDER_MASTER, THEME_MASTER_NAME);
  if (!ss) throw new Error(`私選テーマ別銘柄マスタが見つかりません: ${THEME_MASTER_NAME}`);
  const sh = ss.getSheets()[0];

  const vals = sh.getDataRange().getValues();
  if (vals.length < 1) return { categories: [], codesByCategory: new Map() };

  const categories = [];
  const codesByCategory = new Map();

  for (let c=1; c<=vals[0].length; c++) {
    const cat = vals[0][c-1];
    if (cat) {
      const name = String(cat).trim();
      categories.push(name);
      codesByCategory.set(name, new Set());
    }
  }

  for (let r=2; r<=vals.length; r++) {
    for (let c=1; c<=categories.length; c++) {
      const code = vals[r-1][c-1];
      if (!code) continue;
      const codeStr = normalizeCode_(code);
      codesByCategory.get(categories[c-1]).add(codeStr);
    }
  }

  return { categories, codesByCategory };
}

/** ====== メール用：指数情報読み込み ====== */
function loadIndexMarketRows_() {
  const ss = openSpreadsheetInFolder_(
    FOLDER_MASTER,
    BASIC_INFO_MASTER_NAME
  );

  if (!ss) {
    throw new Error(
      `全銘柄基本情報マスタが見つかりません: ${BASIC_INFO_MASTER_NAME}`
    );
  }

  const sh = ss.getSheets()[0];
  const values = sh.getDataRange().getValues();
  const displayValues = sh.getDataRange().getDisplayValues();

  if (values.length < 2) {
    return [];
  }

  const headers = values[0].map(v => String(v ?? '').trim());

  const requiredHeaders = [
    '証券コード',
    '会社名',
    '上場区分',
    '終値',
    '前日比',
    '騰落率',
    '出来高',
  ];

  const indexes = {};

  for (const header of requiredHeaders) {
    const index = headers.indexOf(header);

    if (index < 0) {
      throw new Error(
        `全銘柄基本情報マスタに「${header}」列がありません`
      );
    }

    indexes[header] = index;
  }

  const rows = [];

  for (let r = 1; r < values.length; r++) {
    const listingCategory = String(
      values[r][indexes['上場区分']] ?? ''
    ).trim();

    if (listingCategory !== '指数') {
      continue;
    }

    rows.push({
      code: formatIndexCode_(
        values[r][indexes['証券コード']]
      ),

      name: String(
        displayValues[r][indexes['会社名']] ?? ''
      ).trim(),

      close: String(
        displayValues[r][indexes['終値']] ?? ''
      ).trim(),

      previousChange: String(
        displayValues[r][indexes['前日比']] ?? ''
      ).trim(),

      changeRate: String(
        displayValues[r][indexes['騰落率']] ?? ''
      ).trim(),

      volume: values[r][indexes['出来高']],
    });
  }

  return rows;
}

/** 指数の証券コードを4桁の左ゼロ埋めにする */
function formatIndexCode_(value) {
  const code = String(value ?? '').trim();

  if (!code) {
    return 'ー';
  }

  return code.padStart(4, '0');
}

function syncThemeCategories_(sheet, categories) {
  if (!categories || categories.length === 0) return;

  let r = 3;
  while (true) {
    const lastRow = sheet.getLastRow();
    if (r > lastRow) break;

    const cls = sheet.getRange(r, 1).getValue();
    if (!cls) { r++; continue; }
    const className = String(cls).trim();
    if (THEME_CLASS_BLOCKS.indexOf(className) === -1) { r++; continue; }

    const start = r;

    let end = r;
    while (end+1 <= lastRow) {
      const nextCls = sheet.getRange(end+1, 1).getValue();
      if (nextCls) break;
      end++;
    }
    const blockSize = end - start + 1;

    if (blockSize < categories.length) {
      const need = categories.length - blockSize;
      for (let i=0; i<need; i++) {
        sheet.insertRowAfter(end+i);
        const template = sheet.getRange(start, 1, 1, sheet.getLastColumn());
        const dest = sheet.getRange(end+i+1, 1, 1, sheet.getLastColumn());
        template.copyTo(dest, { formatOnly: true });
      }
    } else if (blockSize > categories.length) {
      const del = blockSize - categories.length;
      sheet.deleteRows(start + categories.length, del);
    }

    for (let i=0; i<categories.length; i++) {
      const row = start + i;
      sheet.getRange(row, 1).setValue(i===0 ? className : '');
      sheet.getRange(row, 2).setValue(categories[i]);
    }

    r = start + categories.length;
  }
}

/** ====== 集計元「全銘柄日足分析」読み込み ====== */
function loadSourceTable_(srcSs) {
  const sh = srcSs.getSheets()[0];
  const vals = sh.getDataRange().getValues();
  if (vals.length === 0) throw new Error('集計元が空です');

  let headerRow = -1;
  for (let i=0; i<Math.min(10, vals.length); i++) {
    const row = vals[i].map(v => String(v||'').trim());
    if (row.indexOf('証券コード') >= 0) { headerRow = i; break; }
  }
  if (headerRow < 0) headerRow = 0;

  const headers = vals[headerRow].map(v => String(v||'').trim());
  const hmap = new Map();
  headers.forEach((h, idx)=> {
    if (!h) return;
    hmap.set(normalizeHeader_(h), idx);
  });

  const codeIdx = hmap.get(normalizeHeader_('証券コード'));
  if (codeIdx == null) throw new Error('集計元に「証券コード」列がありません');

  const rows = [];
  for (let r=headerRow+1; r<vals.length; r++) {
    const row = vals[r];
    const code = row[codeIdx];
    if (!code) continue;
    const codeStr = normalizeCode_(code);
    rows.push({ code: codeStr, row });
  }

  return { headers, hmap, rows };
}

/** 固定小数：0埋め表示、切り捨て（文字列） */
function formatFixedTrunc_(value, decimals) {
  const n = Number(value);
  if (!isFinite(n)) return null;

  const pow = Math.pow(10, decimals);
  const t = (n >= 0) ? Math.floor(n * pow) / pow : Math.ceil(n * pow) / pow;

  if (decimals === 0) return String(Math.trunc(t));
  const s = String(t);
  const [i, f=''] = s.split('.');
  return i + '.' + (f + '0'.repeat(decimals)).slice(0, decimals);
}

/** ★数値として切り捨て（傾き出力用） */
function truncNumber_(n, decimals) {
  const pow = Math.pow(10, decimals);
  const t = (n >= 0) ? Math.floor(n * pow) / pow : Math.ceil(n * pow) / pow;
  return t;
}

/** ★数値表示関数：京/兆/億/万/千/壱（単位：なし/円/株） */
function formatJPNumber_(value, baseDigit, displayDigit, decimals, unit) {
  const n = Number(value);
  if (!isFinite(n)) return null;

  const units = [
    { k: '京', p: 16 },
    { k: '兆', p: 12 },
    { k: '億', p: 8 },
    { k: '万', p: 4 },
    { k: '千', p: 3 },
    { k: '壱', p: 0 },
  ];

  const base = units.find(u => u.k === baseDigit);
  const disp = units.find(u => u.k === displayDigit);
  if (!base || !disp) throw new Error(`桁指定が不正です base=${baseDigit} display=${displayDigit}`);

  const baseFactor = Math.pow(10, base.p);
  const dispFactor = Math.pow(10, disp.p);

  // unit が「なし」：表示桁に換算した裸の数値だけ
  if (unit === 'なし') {
    const x = n * (baseFactor / dispFactor);
    return formatFixedTrunc_(x, decimals);
  }

  // unit が「円」or「株」：京/兆/億... を付けて組み立て（最小は displayDigit）
  const sign = (n < 0) ? '-' : '';
  let absOnes = Math.abs(n) * baseFactor; // 壱換算

  let started = false;
  let out = '';

  for (const u of units) {
    if (u.p < disp.p) continue;

    const f = Math.pow(10, u.p);

    if (u.k === displayDigit) {
      const x = absOnes / f;
      const s = formatFixedTrunc_(x, decimals);
      if (!started && (Number(s) === 0)) out += '0' + u.k;
      else out += s + (u.k === '壱' ? '' : u.k);
      started = true;
      break;
    }

    const q = Math.floor(absOnes / f);
    if (q > 0 || started) {
      out += String(q) + u.k;
      started = true;
    }
    absOnes = absOnes - q * f;
  }

  return sign + out + (unit === '円' ? '円' : (unit === '株' ? '株' : ''));
}

/** ====== 小物 ====== */
function fmtDate_(d) { return Utilities.formatDate(d, TZ, 'yyyy-MM-dd'); }
function parseDateLoose_(s) {
  const t = String(s||'').trim();
  if (!t) return null;
  const m = t.match(/^(\d{4})[-\/](\d{2})[-\/](\d{2})/);
  if (m) return new Date(`${m[1]}-${m[2]}-${m[3]}T00:00:00`);
  return null;
}


/** 例: 「順：(73)相関」→「(73)相関」(prefix: は全角/半角どちらも対応) */
function stripPrefixColon_(s) {
  const t = String(s || '').trim();
  const p1 = t.indexOf('：');
  const p2 = t.indexOf(':');
  const p = (p1 >= 0 && p2 >= 0) ? Math.min(p1, p2) : Math.max(p1, p2);
  if (p < 0) return t;
  return t.slice(p + 1).trim();
}
function normalizeHeader_(s) { return String(s||'').replace(/[\s　]+/g,'').trim(); }
function normalizeCode_(v) {
  const s = String(v).trim();
  const m = s.match(/^(\d{4})/);
  if (m) return m[1];
  const n = Number(v);
  if (isFinite(n)) return String(Math.trunc(n)).padStart(4,'0');
  return s;
}
function toNumber_(v) {
  if (v == null || v === '') return NaN;
  if (typeof v === 'number') return v;
  const s = String(v).trim();
  const pct = s.match(/^(-?\d+(?:\.\d+)?)\s*%$/);
  if (pct) return Number(pct[1]) / 100; // ★「%」文字列なら ratio として扱う（傾き計算で整合）
  const x = s.replace(/,/g,'');
  return Number(x);
}
function median_(arr) {
  if (!arr || arr.length === 0) return null;
  const a = arr.slice().sort((x,y)=>x-y);
  const n = a.length;
  const mid = Math.floor(n/2);
  return (n%2===1) ? a[mid] : (a[mid-1] + a[mid]) / 2;
}
function stddev_(arr) {
  if (!arr || arr.length === 0) return null;
  const n = arr.length;
  if (n === 1) return 0;
  let s=0;
  for (const v of arr) s += v;
  const mean = s / n;
  let ss=0;
  for (const v of arr) ss += (v-mean)*(v-mean);
  return Math.sqrt(ss / n); // 母標準偏差
}
function escapeRegExp_(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
/**
 * ★元の表示形式をベースに、傾き用に「小数1桁」へ寄せる
 * - % は傾きも % にしない（傾きは数値の傾きなので通常は 0.0）
 * - 通貨/桁区切りは維持しつつ小数1桁
 */
function coerceOneDecimalFormat_(fmt) {
  const f = String(fmt ?? '').trim();
  if (!f) return '0.0';
  if (f.indexOf('%') >= 0) return '0.0';              // ★%は傾きでは使わない
  // 小数部を1桁に統一（例: 0.00 -> 0.0, #,##0.000 -> #,##0.0）
  if (f.indexOf('.') >= 0) {
    return f.replace(/\.(0+|#+)/, '.0');
  }
  // 小数無しなら 1桁付与（例: #,##0 -> #,##0.0）
  return f + '.0';
}
/**
 * ★ 値が「ー」のセルだけ右揃えにする
 */
function applyDashRightAlign_(range) {
  const vals = range.getValues();
  const aligns = vals.map(row =>
    row.map(v => (v === 'ー' ? 'RIGHT' : 'RIGHT'))
  );
  range.setHorizontalAlignments(aligns);
}
/**
 * (5) メール本文を作る（概要＋コピーURL）
 * - 概要は仕様どおり「カテゴリ別市況分析_最新」から取得
 */
function buildMailSummary_(outSs, copiedUrl) {
  const zen = outSs.getSheetByName('全銘柄');
  const theme = outSs.getSheetByName('私選テーマ別');

  if (!zen || !theme) {
    throw new Error(
      'シートが見つかりません（全銘柄/私選テーマ別）'
    );
  }

  const indexRows = loadIndexMarketRows_();

  // 0列の実体（集計値/割合）を特定（既存の ensureZeroColumnReady_ の探索ロジックを流用せず、読取専用で探す）
  const zenCols = detectTableCols_(zen);     // { agg0Col, pct0Col, diffColByAgg0? }
  const themeCols = detectTableCols_(theme);

  // 全銘柄：分類→項目→行 の索引
  const zenIndex = indexByClassAndItem_(zen, 4, 1, 2); // startRow=4, classCol=1(A), itemCol=2(B)
  // 私選テーマ別：分類→カテゴリ→行
  const themeIndex = indexByClassAndItem_(theme, 4, 1, 2);

  // ---- 本文組み立て ----
  const lines = [];

  lines.push('本日のカテゴリ別市況分析を終了しました。');
  lines.push('');
  lines.push('概要は以下になります。');
  lines.push('');

  // ===== 指数部 =====
  lines.push('証券コード、指数名、終値(円)、前日比(円)、騰落率(％)、出来高');

  for (const row of indexRows) {
    lines.push([
    row.code,
    row.name,
    row.close || 'ー',
    row.previousChange || 'ー',
    row.changeRate || 'ー',
    formatJPNumber_(
      row.volume,
      '壱',
      '万',
      0,
      '株'
      ) || 'ー',
    ].join('、'));
  }

  lines.push('');
  lines.push('【全銘柄 / 合計値（集計値 0）】');

  // 合計値（集計値0）※フォーマット指定を修正
  lines.push(`売買代金: ${fmtYen_(getAgg0_(zen, zenIndex, zenCols, '合計値', '売買代金'), '兆', 2)}`);
  lines.push(`時価総額: ${fmtYen_(getAgg0_(zen, zenIndex, zenCols, '合計値', '時価総額'), '兆', 1)}`);
  lines.push(`売上高: ${fmtYen_(getAgg0_(zen, zenIndex, zenCols, '合計値', '売上高'), '兆', 1)}`);
  lines.push(`経常益: ${fmtYen_(getAgg0_(zen, zenIndex, zenCols, '合計値', '経常益'), '兆', 1)}`);
  lines.push(`最終益: ${fmtYen_(getAgg0_(zen, zenIndex, zenCols, '合計値', '最終益'), '兆', 1)}`);
  lines.push(`出来高: ${fmtShares_(getAgg0_(zen, zenIndex, zenCols, '合計値', '出来高'), '億', 2)}`);
  lines.push(`信用売り残: ${fmtShares_(getAgg0_(zen, zenIndex, zenCols, '合計値', '信用売り残'), '億', 1)}`);
  lines.push(`信用買い残: ${fmtShares_(getAgg0_(zen, zenIndex, zenCols, '合計値', '信用買い残'), '億', 1)}`);
  lines.push('');

  lines.push('【全銘柄 / 中央値（集計値 0）】');
  // 中央値（集計値0）
  lines.push(`PER: ${fmtFixed_(getAgg0_(zen, zenIndex, zenCols, '中央値', 'PER'), 1)}`);
  lines.push(`PBR: ${fmtFixed_(getAgg0_(zen, zenIndex, zenCols, '中央値', 'PBR'), 1)}`);
  lines.push(`MIX係数: ${fmtFixed_(getAgg0_(zen, zenIndex, zenCols, '中央値', 'MIX係数'), 1)}`);
  lines.push(`利回り: ${fmtFixed_(getAgg0_(zen, zenIndex, zenCols, '中央値', '利回り'), 2)}`);
  lines.push(`信用倍率: ${fmtFixed_(getAgg0_(zen, zenIndex, zenCols, '中央値', '信用倍率'), 1)}`);
  lines.push(`(98)信用買い残日数: ${fmtFixed_(getAgg0_(zen, zenIndex, zenCols, '中央値', '(98)信用買い残日数'), 2)}`);
  lines.push(`時価総額: ${fmtNumberJP_(getAgg0_(zen, zenIndex, zenCols, '中央値', '時価総額'))}`);
  lines.push(`売上高: ${fmtNumberJP_(getAgg0_(zen, zenIndex, zenCols, '中央値', '売上高'))}`);
  lines.push(`経常益: ${fmtNumberJP_(getAgg0_(zen, zenIndex, zenCols, '中央値', '経常益'))}`);
  lines.push(`最終益: ${fmtNumberJP_(getAgg0_(zen, zenIndex, zenCols, '中央値', '最終益'))}`);
  lines.push(`騰落率: ${fmtFixed_(getAgg0_(zen, zenIndex, zenCols, '中央値', '騰落率'), 2)}`);
  lines.push(`出来高: ${fmtNumberJP_(getAgg0_(zen, zenIndex, zenCols, '中央値', '出来高'))}`);
  lines.push('');

  lines.push('【全銘柄 / カウント数（集計値 0）】');
  const countSpec = [
   '順：(73)相関→→→0.5以上の数',
   '逆：(73)相関→→→-0.1以下の数',
   '---------------------------------------------------',
   '高：(80)RSI→→→80より大きい数',
   '中：(80)RSI→→→20-80の数',
   '低：(80)RSI→→→20未満の数',
   '(81)RSIの直近22日間の回帰係数→→→値が＋の数',
   '---------------------------------------------------',
   '３：(138)ボリンジャーバンドのσ値→→→3以上の数',
   '２：(138)ボリンジャーバンドのσ値→→→2以上3未満の数',
   '１：(138)ボリンジャーバンドのσ値→→→1以上2未満の数',
   '０：(138)ボリンジャーバンドのσ値→→→-1以上1未満の数の数',
   '-１：(138)ボリンジャーバンドのσ値→→→-2以上-1未満の数の数',
   '-２：(138)ボリンジャーバンドのσ値→→→-3以上-2未満の数の数',
   '-３：(138)ボリンジャーバンドのσ値→→→-3未満の数',
   '---------------------------------------------------',
   '高：(96)AI基準判定→→→8以上の数',
   '中：(96)AI基準判定→→→4-7の数',
   '低：(96)AI基準判定→→→3以下の数',
   '---------------------------------------------------',
   '超：PER→→→30以上の数',
   '高：PER→→→20以上、30未満の数',
   '中：PER→→→10以上、20未満の数',
   '低：PER→→→10未満の数',
   '---------------------------------------------------',
   '超：PBR→→→3以上の数',
   '高：PBR→→→2以上、3未満の数',
   '中：PBR→→→1以上、2未満の数',
   '低上：PBR→→→0.5以上、1未満の数',
   '低下：PBR→→→0.5未満の数',
   '---------------------------------------------------',
   'MIX係数→→→PER*PBRの値が22.5以上の数',
   '---------------------------------------------------',
   '高：利回り→→→3以上の数',
   '中：利回り→→→2以上、3未満の数',
   '低上：利回り→→→1以上、2未満の数',
   '低下：利回り→→→1未満の数',
   '---------------------------------------------------',
   '信用倍率→→→1以上の数',
   '---------------------------------------------------',
   '超：時価総額→→→10000以上',
   '高：時価総額→→→5000以上、10000未満',
   '中上：時価総額→→→1000以上、5000未満',
   '中下：時価総額→→→300以上、1000未満',
   '低上：時価総額→→→100以上、300未満',
   '低下：時価総額→→→100未満',
   '---------------------------------------------------',
   '(98)信用買い残日数→→→5以上の数',
   '---------------------------------------------------',
   '順：(104)パーフェクトオーダー判定→→→1の数',
   '逆：(104)パーフェクトオーダー判定→→→-1の数'
  ];

  for (const spec of countSpec) {
    if (spec === '---------------------------------------------------') {
      lines.push(spec);
      continue;
    }
    const parsed = splitItemAndCond_(spec);
    const itemKey = parsed.item; // シート側の項目名（→→→より左）
    const cond = parsed.cond;    // メール表示用（→→→より右）
    const v = getAgg0_(zen, zenIndex, zenCols, 'カウント数', itemKey);
    lines.push(`${itemKey}(${cond}): ${fmtIntOrDash_(v)}`);
  }
  lines.push('');

  lines.push('【私選テーマ別 / 前日比上昇銘柄数（割合 0）】');
  // 前日比上昇銘柄数：割合0列をカテゴリ行ごとに
  appendThemePctBlock_(lines, theme, themeIndex, themeCols, '前日比上昇銘柄数');

  lines.push('');
  lines.push('【私選テーマ別 / 売買代金（差分）】');
  appendThemeDiffBlock_(lines, theme, themeIndex, themeCols, '売買代金');

  lines.push('');
  lines.push('分析結果の詳細は以下になります。');
  lines.push(`${copiedUrl}：`);
  lines.push('');

  return lines.join('\n');
}
/**
 * (6) レポート本文を作る
 *
 * メール本文をベースに、
 * ・冒頭をレポート用タイトル＋処理日時へ変更
 * ・末尾の「分析結果の詳細は以下になります。」とURLを削除
 */
function buildReportSummary_(mailSummary, now) {
  const mailLines = String(mailSummary || '').split('\n');

  // メール本文冒頭
  //   本日のカテゴリ別市況分析を終了しました。
  //
  //   概要は以下になります。
  //
  // の4行を除外
  let bodyLines = mailLines.slice(4);

  // メール本文末尾
  //   分析結果の詳細は以下になります。
  //   URL：
  //
  // を削除
  const detailIndex = bodyLines.findIndex(
    line => line === '分析結果の詳細は以下になります。'
  );

  if (detailIndex >= 0) {
    bodyLines = bodyLines.slice(0, detailIndex);
  }

  // 末尾の空行を除去
  while (
    bodyLines.length > 0 &&
    String(bodyLines[bodyLines.length - 1]).trim() === ''
  ) {
    bodyLines.pop();
  }

  const processedAt = Utilities.formatDate(
    now,
    TZ,
    'yyyy-MM-dd HH:mm'
  );

  const lines = [];

  lines.push('■カテゴリ別市況分析');
  lines.push(`処理日時：${processedAt}`);
  lines.push('');
  lines.push(...bodyLines);
  lines.push('');

  return lines.join('\n');
}
/**
 * TXTレポートファイルを出力する。
 *
 * 同名ファイルが存在する場合は削除してから再作成する。
 */
function writeTextReport_(folder, fileName, body) {
  if (!folder) {
    throw new Error('レポート出力先フォルダが指定されていません。');
  }

  // 同名ファイルが存在する場合はゴミ箱へ移動
  const files = folder.getFilesByName(fileName);

  while (files.hasNext()) {
    files.next().setTrashed(true);
  }

  folder.createFile(
    fileName,
    body,
    MimeType.PLAIN_TEXT
  );
}
/** ===== ここから下は buildMailSummary_ 用の補助 ===== */

function detectTableCols_(sheet) {
  const lastCol = sheet.getLastColumn();
  const row1 = sheet.getRange(1, 1, 1, lastCol).getValues()[0].map(v => String(v ?? '').trim());

  const colOf = (label) => row1.findIndex(v => v === label) + 1;

  const pctLabelCol = colOf('割合');
  const aggLabelCol = colOf('集計値');
  if (!pctLabelCol || !aggLabelCol) throw new Error(`テーブルラベルが見つかりません（割合/集計値）: ${sheet.getName()}`);

  // offset 行（0 がいる行）を 1..5 から探す
  const probeRows = Math.min(5, sheet.getLastRow());
  const head = sheet.getRange(1, 1, probeRows, lastCol).getValues()
    .map(r => r.map(v => String(v ?? '').trim()));

  const findZeroColWithin = (labelCol) => {
    // label領域の終端（次のラベルまで）
    let endCol = lastCol;
    for (let c = labelCol + 1; c <= lastCol; c++) {
      if (row1[c - 1] !== '') { endCol = c - 1; break; }
    }
    // offsetRow探索
    let offsetRow = 0;
    for (let r = 1; r <= probeRows; r++) {
      if (head[r - 1].slice(labelCol - 1, endCol).includes('0')) { offsetRow = r; break; }
    }
    if (!offsetRow) throw new Error(`offset行(0)が見つかりません: ${sheet.getName()}`);
    const vals = head[offsetRow - 1];

    let zeroCol = 0;
    for (let c = labelCol; c <= endCol; c++) {
      if (vals[c - 1] === '0') { zeroCol = c; break; }
    }
    if (!zeroCol) throw new Error(`0列が見つかりません: ${sheet.getName()}`);
    return { zeroCol, offsetRow, endCol };
  };

  const pct = findZeroColWithin(pctLabelCol);
  const agg = findZeroColWithin(aggLabelCol);

  // 「差分」列は、見出し行のどこかに「差分」文字がある列
  // （あなたのシートでは割合と集計値の間にある）
  const diffCol = row1.findIndex(v => v === '差分') + 1;

  return {
    pct0Col: pct.zeroCol,
    agg0Col: agg.zeroCol,
    diffCol: diffCol || 0,
  };
}

/** startRow以降を走査して、分類(A)が変わったらブロック更新、項目(B)=キーで行番号を保持 */
function indexByClassAndItem_(sheet, startRow, classCol, itemCol) {
  const lastRow = sheet.getLastRow();
  const vals = sheet.getRange(startRow, 1, Math.max(0, lastRow - startRow + 1), Math.max(classCol, itemCol)).getValues();
  let cur = '';
  const map = new Map(); // class -> Map(item -> row)
  for (let i = 0; i < vals.length; i++) {
    const rowNo = startRow + i;
    const c = String(vals[i][classCol - 1] ?? '').trim();
    const it = String(vals[i][itemCol - 1] ?? '').trim();
    if (c) cur = c;
    if (!cur || !it) continue;
    if (!map.has(cur)) map.set(cur, new Map());
    map.get(cur).set(it, rowNo);
  }
  return map;
}

function getAgg0_(sheet, index, cols, className, itemName) {
  const m = index.get(className);
  if (!m) return null;
  const row = m.get(itemName);
  if (!row) return null;
  return sheet.getRange(row, cols.agg0Col).getValue();
}

function appendThemePctBlock_(lines, sheet, index, cols, className) {
  const m = index.get(className);
  if (!m) return;
  // m: category -> row
  const list = [];
  for (const [category, row] of m.entries()) {
    const v = sheet.getRange(row, cols.pct0Col).getValue(); // 0..1
    if (typeof v === 'number') {
      const disp = `${(Math.floor(v * 10000) / 100).toFixed(2)}%`;
      list.push({ category, sortVal: v, display: disp });
    } else {
      list.push({ category, sortVal: -Infinity, display: 'ー' });
    }
  }
  // 値の大きい順（同値はカテゴリ名で安定）
  list.sort((a, b) => {
    if (b.sortVal !== a.sortVal) return b.sortVal - a.sortVal;
    return String(a.category).localeCompare(String(b.category), 'ja');
  });
  for (const it of list) lines.push(`${it.category}: ${it.display}`);
}

function appendThemeDiffBlock_(lines, sheet, index, cols, className) {
  const m = index.get(className);
  if (!m) return;
  if (!cols.diffCol) {
    // 差分列が見つからない場合は空欄
    for (const [category] of m.entries()) lines.push(`${category}: ー`);
    return;
  }
  const list = [];
  for (const [category, row] of m.entries()) {
    const range = sheet.getRange(row, cols.diffCol);
    const v = range.getValue();
    const disp = range.getDisplayValue();
    const display = disp || (v ?? 'ー');

    // ソート用数値（数値ならそのまま、文字列なら「0.12兆円」等も解釈）
    let sortVal = -Infinity;
    if (typeof v === 'number') {
      sortVal = v;
    } else {
      sortVal = parseJPUnitNumber_(display);
    }
    list.push({ category, sortVal, display: String(display ?? 'ー') });
  }
  list.sort((a, b) => {
    if (b.sortVal !== a.sortVal) return b.sortVal - a.sortVal;
    return String(a.category).localeCompare(String(b.category), 'ja');
  });
  for (const it of list) lines.push(`${it.category}: ${it.display}`);
}

function fmtYen_(v, unit, decimals) {
  if (typeof v !== 'number') return 'ー';
  // 例: 基準=兆, 表示=兆
  return `${formatJPNumber_(v, unit, unit, decimals, '円')}`;
}
function fmtShares_(v, unit, decimals) {
  if (typeof v !== 'number') return 'ー';
  return `${formatJPNumber_(v, unit, unit, decimals, '株')}`;
}
function fmtFixed_(v, decimals) {
  if (typeof v !== 'number') return '';
  return truncNumber_(v, decimals).toFixed(decimals);
}
function fmtIntOrDash_(v) {
  if (typeof v !== 'number') return '';
  return String(Math.trunc(v));
}
function fmtNumberJP_(v) {
  if (typeof v !== 'number') return '';
  // 中央値はシート上が「億円」「億株」等の場合があるので、ここは素直に displayValue でもOK。
  // ただし数値だけ返したいならこの関数を調整してください。
  return String(v);
}
/** '項目→→→条件' を {item, cond} に分解（無ければ cond=''） */
function splitItemAndCond_(s) {
  const parts = String(s || '').split('→→→');
  const item = String(parts[0] || '').trim();
  const cond = String(parts[1] || '').trim();
  return { item, cond };
}
/**
 * "0.12兆円" / "3.4億" / "1200万" / "-0.5" などを数値化して返す（ソート用）
 * 解釈できない場合は -Infinity を返す
 */
function parseJPUnitNumber_(x) {
  const s0 = String(x ?? '').trim();
  if (!s0 || s0 === 'ー') return -Infinity;

  // 末尾の単位（円/株/% など）は落とす（%は基本来ない想定だが念のため）
  let s = s0.replace(/[,\s]/g, '').replace(/[円株%]/g, '');

  // 「兆」「億」「万」「千」を解釈して合算
  // 例: "1.2兆3.4億" のような表記にも対応
  const unitMap = { '兆': 1e12, '億': 1e8, '万': 1e4, '千': 1e3 };
  let total = 0;
  let matched = false;

  for (const [u, mul] of Object.entries(unitMap)) {
    const re = new RegExp(`([+\\-]?\\d+(?:\\.\\d+)?)${u}`);
    const m = s.match(re);
    if (m) {
      total += Number(m[1]) * mul;
      s = s.replace(m[0], '');
      matched = true;
    }
  }

  // 残りがただの数値なら加算（または単位無しの数値として採用）
  if (s) {
    const n = Number(s);
    if (isFinite(n)) {
      total += n;
      matched = true;
    }
  }

  return matched ? total : -Infinity;
}

/**
 * ★prefix + yyyy-MM-dd を厳密指定してスプレッドシートを探す（リカバリ用）
 */
function findDatedSpreadsheet_(pathParts, prefix, dateStr) {
  const folder = getFolderByPath_(pathParts);
  if (!folder) return null;

  const title = `${prefix}${dateStr}`;
  const q = `title = "${title}" and mimeType = "application/vnd.google-apps.spreadsheet" and trashed = false`;
  const it = folder.searchFiles(q);
  if (!it.hasNext()) return null;

  const f = it.next();
  return { id: f.getId(), title: f.getName(), dateStr };
}
