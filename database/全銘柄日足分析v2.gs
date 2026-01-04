/**
 * 全銘柄日足分析：証券コードと実行結果v2（指定仕様対応版）
 * - Drive: 投資/プログラミング/GAS/データーベース/全銘柄日足分析：証券コードと実行結果v2
 * - 1行目=見出し（証券コード/更新日/実行結果 + 計算結果列）
 * - 2行目以降を上から順次処理（証券コードが空で終了）
 * - 平日19:00-23:59のみ、土日終日
 * - 1回の最大処理件数=80
 * - 全行読み切り時のみ（かつ重複抑止）抽出シート作成→メール送信
 *
 * ==========================
 * 分析ロジック
 * ==========================
 * BigQueryから取得したデータ(A)～(E)を用いて各指標を算出し、
 * 「実行結果」列の後ろに順に出力する。
 */

const SETTINGS = {
  PROJECT_ID: 'stocks-471015',
  DATASET_TABLE: 'stocks.prices_eod',
  TODAY_TZ: 'Asia/Tokyo',
  MAX_ROWS: 80,

  SPREADSHEET_PATH: ['投資','プログラミング','GAS','データーベース','全銘柄日足分析：証券コードと実行結果v2'],
  SHEET_NAME: null,

  EMAIL_TO: 'green3red2000@gmail.com',

  // オシレーター標準パラメータ
  RSI_PERIOD: 14,
  MACD_FAST: 12,
  MACD_SLOW: 26,
  MACD_SIGNAL: 9,
};
function runAll() {
  const tz = SETTINGS.TODAY_TZ || 'Asia/Tokyo';
  const now = new Date();
  const ts = Utilities.formatDate(now, tz, 'yyyy-MM-dd HH:mm:ss');
  console.log(`[開始] 実行開始 ${ts}`);

  if (!isWithinExecutionWindow_()) {
    console.log('[スキップ] 実行ウィンドウ外（平日19:00-23:59、土日終日）');
    return;
  }

  const ss = openSpreadsheetByPathSimple_(SETTINGS.SPREADSHEET_PATH);
  const sh = SETTINGS.SHEET_NAME ? ss.getSheetByName(SETTINGS.SHEET_NAME) : ss.getSheets()[0];
  if (!sh) throw new Error('対象シートが見つかりません');

  const url = ss.getUrl();
  const todayStr = Utilities.formatDate(now, tz, 'yyyy/MM/dd');
  const todayKey = Utilities.formatDate(now, tz, 'yyyyMMdd');

  // 1行目見出し
  const headerRange = sh.getRange(1, 1, 1, sh.getLastColumn());
  let header = headerRange.getValues()[0].map(v => String(v || '').trim());

  // 必須列
  const baseHeaders = ['証券コード','更新日','実行結果'];

  // 出力列（指定文言のまま）
  const calcHeaders = buildCalcHeaders_();

  // 見出し拡張（存在しないものは末尾に追加）
  header = ensureHeaders_(sh, header, baseHeaders.concat(calcHeaders));

  // 列インデックス（0-based）
  const col = {};
  header.forEach((h, i) => { if (h) col[h] = i; });

  const lastRow = sh.getLastRow();
  if (lastRow < 2) {
    console.log('[情報] データ行がありません');
    return;
  }

  // 対象領域を一括取得（読み）
  const values = sh.getRange(2, 1, lastRow - 1, sh.getLastColumn()).getValues();

  // TOPIXキャッシュ（close昇順）
  let topixCloseAsc = null;
  try {
    const topixRows = fetchEodFromBq_OHLCV_('0010');
    topixCloseAsc = topixRows.map(r => r.close).reverse();
  } catch (e) {
    console.log('[警告] TOPIX取得に失敗: ' + e);
  }

  let processed = 0;
  let i = 0;

  for (i = 0; i < values.length && processed < SETTINGS.MAX_ROWS; i++) {
    const r = values[i];
    const rowIdx = i + 2; // sheet row

    const code = String(r[col['証券コード']] || '').trim();
    if (!code) {
      console.log('[情報] 証券コードが空行のため処理終了');
      break;
    }

    const upd = r[col['更新日']];
    const updKey = dateKey_(upd);
    const needProcess = (!updKey || todayKey > updKey);
    if (!needProcess) continue;

    try {
      const rows = fetchEodFromBq_OHLCV_(code);

      if (!rows || rows.length <= 100) {
        // 更新日・実行結果だけ書き込み
        writeRowByHeader_(sh, rowIdx, col, {
          '更新日': todayStr,
          '実行結果': 'エラー：データ不足(100件以下)',
        });
        processed++;
        continue;
      }

      // (A)～(E)を昇順で作る（古→新）
      const openAsc  = rows.map(x => x.open).reverse();
      const highAsc  = rows.map(x => x.high).reverse();
      const lowAsc   = rows.map(x => x.low ).reverse();
      const closeAsc = rows.map(x => x.close).reverse();
      const volAsc   = rows.map(x => x.volume).reverse();

      // ==========================
      // 分析ロジック
      // ==========================

      const out = computeAllMetrics_({
        openAsc, highAsc, lowAsc, closeAsc, volAsc,
        topixCloseAsc
      });

      // 行書き込み（更新日/実行結果 + 全計算列）
      const payload = Object.assign(
        { '更新日': todayStr, '実行結果': '正常終了' },
        out
      );
      writeRowByHeader_(sh, rowIdx, col, payload);

      processed++;
      console.log(`[情報] code=${code} 正常終了`);

    } catch (err) {
      console.error(`code=${code} でエラー発生: ${err && (err.stack || err)}`);
      writeRowByHeader_(sh, rowIdx, col, {
        '更新日': todayStr,
        '実行結果': 'エラー：その他',
      });
      processed++;
    }
  }

  console.log(`[情報] 本日処理数=${processed}（最大処理件数=${SETTINGS.MAX_ROWS}）`);

  // 全行読み切り条件：forが「証券コード空」または配列末尾で止まっている
  const readAll = (i >= values.length) || (i < values.length && String(values[i][col['証券コード']] || '').trim() === '');
  if (!readAll) {
    console.log('[情報] 全行読み切りではありません（MAX_ROWS到達または未処理行あり）');
    console.log('[終了] 実行終了');
    return;
  }

  // 全行読み切り時の集計 + 重複抑止 + 抽出 + メール
  const allNow = sh.getRange(2, 1, sh.getLastRow() - 1, sh.getLastColumn()).getValues();
  const idxUpd = col['更新日'];
  const idxRes = col['実行結果'];

  let cntUpd = 0, cntProc = 0, cntErr = 0;
  for (const rr of allNow) {
    const updStr = toDateStr_(rr[idxUpd]);
    if (updStr === todayStr) {
      cntUpd++;
      const res = String(rr[idxRes] || '');
      if (res !== '処理なし') cntProc++;
      if (res.indexOf('エラー') >= 0) cntErr++;
    }
  }

  const fingerprint = computeTodayFingerprint_v2_(allNow, header, col, todayStr);
  const props = PropertiesService.getScriptProperties();
  const KEY_DATE = 'MAIL_DATE_V2';
  const KEY_HASH = 'MAIL_HASH_V2';

  const lastDate = props.getProperty(KEY_DATE);
  const lastHash = props.getProperty(KEY_HASH);
  const shouldSend = (lastDate !== todayStr) || (lastHash !== fingerprint);

  console.log(`[情報] 重複送信判定: 前回日付=${lastDate || '-'} 前回ハッシュ=${(lastHash || '').slice(0,8)} 今回ハッシュ=${fingerprint.slice(0,8)} -> 送信=${shouldSend}`);

  if (!shouldSend) {
    console.log('[スキップ] 抽出・メール送信ともにスキップ（同一スナップショットのため）');
    console.log('[終了] 実行終了');
    return;
  }

  // 抽出
  console.log('[情報] 抽出シートの作成を開始');
  const extract = extractAndWriteResults_v2_({
    srcSheet: sh,
    header,
    col,
    todayStr
  });
  console.log(`[情報] 抽出完了: 抽出件数=${extract.writtenRows} URL=${extract.spreadsheetUrl}`);

  // メール
  const subject = `全銘柄日足分析v2：${todayStr}`;
  const body =
`本日の全銘柄日足分析処理v2を終了しました。
処理対象件数は${cntUpd}件でした。
処理件数は${cntProc}件でした。
エラーの件数は${cntErr}件でした。
抽出件数は${extract.writtenRows}件でした。

全銘柄分析の実行結果：
${url}
抽出結果：
${extract.spreadsheetUrl}
`;
  MailApp.sendEmail(SETTINGS.EMAIL_TO, subject, body);
  console.log('[情報] メール送信完了');

  props.setProperty(KEY_DATE, todayStr);
  props.setProperty(KEY_HASH, fingerprint);

  console.log('[終了] 実行終了');
}

/* ===== 実行ウィンドウ判定 ===== */
function isWithinExecutionWindow_() {
  const tz = SETTINGS.TODAY_TZ || Session.getScriptTimeZone() || 'Asia/Tokyo';
  const now = new Date();
  const dow = parseInt(Utilities.formatDate(now, tz, 'u'), 10); // 1=月..7=日
  const hhmm = parseInt(Utilities.formatDate(now, tz, 'HHmm'), 10);

  if (dow === 6 || dow === 7) return true; // 土日: 終日
  if (dow >= 1 && dow <= 5) return (hhmm >= 1900 && hhmm <= 2359); // 平日: 19:00-23:59
  return false;
}

/* ===== BigQuery 取得 (OHLCV) ===== */
function fetchEodFromBq_OHLCV_(code) {
  const sql =
    'SELECT asof_date, open, high, low, close, volume ' +
    'FROM `'+SETTINGS.PROJECT_ID+'.'+SETTINGS.DATASET_TABLE+'` ' +
    'WHERE code = @code ' +
    'ORDER BY asof_date DESC ' +
    'LIMIT 200';

  const request = {
    query: sql,
    useLegacySql: false,
    parameterMode: 'NAMED',
    queryParameters: [{
      name: 'code',
      parameterType: { type: 'STRING' },
      parameterValue: { value: String(code) }
    }]
  };

  let qr = BigQuery.Jobs.query(request, SETTINGS.PROJECT_ID);
  const jobId = qr.jobReference.jobId;

  let wait = 400;
  while (!qr.jobComplete) {
    Utilities.sleep(wait);
    wait = Math.min(wait * 2, 4000);
    qr = BigQuery.Jobs.getQueryResults(SETTINGS.PROJECT_ID, jobId);
  }

  const rows = [];
  const schema = qr.schema.fields.map(f => f.name);
  let page = qr;

  while (true) {
    if (page.rows) {
      for (const row of page.rows) {
        const o = {};
        row.f.forEach((c, i) => o[schema[i]] = c.v);

        const close = parseFloat(o.close);
        let open  = parseFloat(o.open);
        let high  = parseFloat(o.high);
        let low   = parseFloat(o.low);
        const vol = parseFloat(o.volume);

        // null/NaN対策：open/high/low が無効なら close で補完
        if (!isFinite(open)) open = close;
        if (!isFinite(high)) high = close;
        if (!isFinite(low))  low  = close;

        rows.push({ asof_date: o.asof_date, open, high, low, close, volume: vol });
      }
    }
    if (!page.pageToken) break;
    page = BigQuery.Jobs.getQueryResults(SETTINGS.PROJECT_ID, jobId, { pageToken: page.pageToken });
  }
  return rows;
}

/* ==========================
 * 分析計算（全指標）
 * ========================== */
function computeAllMetrics_({ openAsc, highAsc, lowAsc, closeAsc, volAsc, topixCloseAsc }) {
  const out = {};
  const n = closeAsc.length;

  // ★追加 (1)?(9)
  const lastOpen  = openAsc[n - 1];
  const lastHigh  = highAsc[n - 1];
  const lastLow   = lowAsc[n - 1];
  const lastClose = closeAsc[n - 1];
  const lastVol   = volAsc[n - 1];

  const prevClose = n >= 2 ? closeAsc[n - 2] : null;
  const prevVol   = n >= 2 ? volAsc[n - 2] : null;

  out['(1)直近の始値'] = lastOpen;
  out['(2)直近の高値'] = lastHigh;
  out['(3)直近の安値'] = lastLow;
  out['(4)直近の終値'] = lastClose;
  out['(5)直近の出来高'] = lastVol;

  out['(6)直近の終値の前日比'] =
    (isFinite(prevClose) ? (lastClose - prevClose) : '');

  out['(7)直近の終値の前日比率'] =
    (isFinite(prevClose) && prevClose !== 0 ? ((lastClose / prevClose) - 1) * 100 : '');

  out['(8)直近の出来高の前日比'] =
    (isFinite(prevVol) ? (lastVol - prevVol) : '');

  out['(9)直近の出来高の前日比率'] =
    (isFinite(prevVol) && prevVol !== 0 ? ((lastVol / prevVol) - 1) * 100 : '');

  // 回帰係数（終値） 10..14
  out['(10)終値の直近5日間の回帰係数']  = slopeLastN_(closeAsc, 5);
  out['(11)終値の直近10日間の回帰係数'] = slopeLastN_(closeAsc, 10);
  out['(12)終値の直近22日間の回帰係数'] = slopeLastN_(closeAsc, 22);
  out['(13)終値の直近45日間の回帰係数'] = slopeLastN_(closeAsc, 45);
  out['(14)終値の直近90日間の回帰係数'] = slopeLastN_(closeAsc, 90);

  // 回帰係数（出来高） 15..19
  out['(15)出来高の直近5日間の回帰係数']  = slopeLastN_(volAsc, 5);
  out['(16)出来高の直近10日間の回帰係数'] = slopeLastN_(volAsc, 10);
  out['(17)出来高の直近22日間の回帰係数'] = slopeLastN_(volAsc, 22);
  out['(18)出来高の直近45日間の回帰係数'] = slopeLastN_(volAsc, 45);
  out['(19)出来高の直近90日間の回帰係数'] = slopeLastN_(volAsc, 90);

  // 移動平均（終値・出来高） 20..29
  const W = [5,10,22,45,90];
  const maC = Object.fromEntries(W.map(w => [w, movingAverage_(closeAsc, w)]));
  const maV = Object.fromEntries(W.map(w => [w, movingAverage_(volAsc,   w)]));

  out['(20)終値5日移動平均']  = lastDefined_(maC[5]);
  out['(21)終値10日移動平均'] = lastDefined_(maC[10]);
  out['(22)終値22日移動平均'] = lastDefined_(maC[22]);
  out['(23)終値45日移動平均'] = lastDefined_(maC[45]);
  out['(24)終値90日移動平均'] = lastDefined_(maC[90]);

  out['(25)出来高5日移動平均']  = lastDefined_(maV[5]);
  out['(26)出来高10日移動平均'] = lastDefined_(maV[10]);
  out['(27)出来高22日移動平均'] = lastDefined_(maV[22]);
  out['(28)出来高45日移動平均'] = lastDefined_(maV[45]);
  out['(29)出来高90日移動平均'] = lastDefined_(maV[90]);

  // 移動平均の回帰係数 30..39
  out['(30)終値5日移動平均の直近5日の回帰係数']   = slopeLastN_Def_(maC[5], 5);
  out['(31)終値10日移動平均の直近10日の回帰係数'] = slopeLastN_Def_(maC[10], 10);
  out['(32)終値22日移動平均の直近10日の回帰係数'] = slopeLastN_Def_(maC[22], 10);
  out['(33)終値45日移動平均の直近10日の回帰係数'] = slopeLastN_Def_(maC[45], 10);
  out['(34)終値90日移動平均の直近10日の回帰係数'] = slopeLastN_Def_(maC[90], 10);

  out['(35)出来高5日移動平均の直近5日の回帰係数']   = slopeLastN_Def_(maV[5], 5);
  out['(36)出来高10日移動平均の直近10日の回帰係数'] = slopeLastN_Def_(maV[10], 10);
  out['(37)出来高22日移動平均の直近10日の回帰係数'] = slopeLastN_Def_(maV[22], 10);
  out['(38)出来高45日移動平均の直近10日の回帰係数'] = slopeLastN_Def_(maV[45], 10);
  out['(39)出来高90日移動平均の直近10日の回帰係数'] = slopeLastN_Def_(maV[90], 10);

  // 値幅系 40..50
  const rangeAsc = highAsc.map((h,i)=> (Number(h)||0) - (Number(lowAsc[i])||0));
  out['(40)200日分の高値-安値の一日の値幅平均'] = mean_(rangeAsc);

  const vol5  = rollingStdDev_(rangeAsc, 5);
  const vol10 = rollingStdDev_(rangeAsc, 10);
  const vol22 = rollingStdDev_(rangeAsc, 22);
  const vol45 = rollingStdDev_(rangeAsc, 45);
  const vol90 = rollingStdDev_(rangeAsc, 90);

  out['(41)直近5日間の高値-安値の値幅のボラティリティ']  = lastDefined_(vol5);
  out['(42)直近10日間の高値-安値の値幅のボラティリティ'] = lastDefined_(vol10);
  out['(43)直近22日間の高値-安値の値幅のボラティリティ'] = lastDefined_(vol22);
  out['(44)直近45日間の高値-安値の値幅のボラティリティ'] = lastDefined_(vol45);
  out['(45)直近90日間の高値-安値の値幅のボラティリティ'] = lastDefined_(vol90);

  out['(46)直近5日間の高値-安値の値幅のボラティリティの直近5日間の回帰係数']   = slopeLastN_Def_(vol5, 5);
  out['(47)直近10日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数'] = slopeLastN_Def_(vol10, 10);
  out['(48)直近22日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数'] = slopeLastN_Def_(vol22, 10);
  out['(49)直近45日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数'] = slopeLastN_Def_(vol45, 10);
  out['(50)直近90日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数'] = slopeLastN_Def_(vol90, 10);

  // 乖離率 51..61（番号飛びは仕様通り）
  const devC5  = deviationSeries_(closeAsc, maC[5]);
  const devC10 = deviationSeries_(closeAsc, maC[10]);
  const devC22 = deviationSeries_(closeAsc, maC[22]);
  const devC45 = deviationSeries_(closeAsc, maC[45]);
  const devC90 = deviationSeries_(closeAsc, maC[90]);

  const devV5  = deviationSeries_(volAsc, maV[5]);
  const devV10 = deviationSeries_(volAsc, maV[10]);
  const devV22 = deviationSeries_(volAsc, maV[22]);
  const devV45 = deviationSeries_(volAsc, maV[45]);
  const devV90 = deviationSeries_(volAsc, maV[90]);

  out['(51)終値5日移動平均と終値の移動平均乖離率']  = lastDefined_(devC5);
  out['(52)終値10日移動平均と終値の移動平均乖離率'] = lastDefined_(devC10);
  out['(53)終値22日移動平均と終値の移動平均乖離率'] = lastDefined_(devC22);
  out['(55)終値45日移動平均と終値の移動平均乖離率'] = lastDefined_(devC45);
  out['(56)終値90日移動平均と終値の移動平均乖離率'] = lastDefined_(devC90);

  out['(57)出来高5日移動平均と出来高の移動平均乖離率']  = lastDefined_(devV5);
  out['(58)出来高10日移動平均と出来高の移動平均乖離率'] = lastDefined_(devV10);
  out['(59)出来高22日移動平均と出来高の移動平均乖離率'] = lastDefined_(devV22);
  out['(60)出来高45日移動平均と出来高の移動平均乖離率'] = lastDefined_(devV45);
  out['(61)出来高90日移動平均と出来高の移動平均乖離率'] = lastDefined_(devV90);

  // 乖離率の回帰係数 62..71
  out['(62)終値5日移動平均の移動平均乖離率の直近5日間の回帰係数']   = slopeLastN_Def_(devC5, 5);
  out['(63)終値10日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastN_Def_(devC10, 10);
  out['(64)終値22日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastN_Def_(devC22, 10);
  out['(65)終値45日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastN_Def_(devC45, 10);
  out['(66)終値90日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastN_Def_(devC90, 10);

  out['(67)出来高5日移動平均の移動平均乖離率の直近5日間の回帰係数']   = slopeLastN_Def_(devV5, 5);
  out['(68)出来高10日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastN_Def_(devV10, 10);
  out['(69)出来高22日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastN_Def_(devV22, 10);
  out['(70)出来高45日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastN_Def_(devV45, 10);
  out['(71)出来高90日移動平均の移動平均乖離率の直近10日間の回帰係数'] = slopeLastN_Def_(devV90, 10);

  // 市場指標 72..79
  let marketStats = null;
  if (topixCloseAsc && topixCloseAsc.length) {
    marketStats = computeMarketStats_({ symCloseAsc: closeAsc, mktCloseAsc: topixCloseAsc });
  }
  out['(72) β']            = marketStats ? marketStats.beta : '';
  out['(73) 相関']          = marketStats ? marketStats.corr : '';
  out['(74) 相対ボラ']       = marketStats ? marketStats.relVol : '';
  out['(75) 残差ボラ']       = marketStats ? marketStats.residVol : '';
  out['(76) アップサイドβ']   = marketStats ? marketStats.betaUp : '';
  out['(77) ダウンサイドβ']   = marketStats ? marketStats.betaDown : '';
  out['(78) Up Capture']    = marketStats ? marketStats.upCapture : '';
  out['(79) Down Capture']  = marketStats ? marketStats.downCapture : '';

  // RSI / MACD 80..83
  const rsiSeries = computeRsiSeries_(closeAsc, SETTINGS.RSI_PERIOD);
  out['(80) RSI'] = lastDefined_(rsiSeries);
  out['(81) RSIの直近22日間の回帰係数'] = slopeLastN_Def_(rsiSeries, 22);

  const macd = computeMacd_(closeAsc, SETTINGS.MACD_FAST, SETTINGS.MACD_SLOW, SETTINGS.MACD_SIGNAL);
  out['(82) MACD'] = macd && macd.macdLine ? lastDefined_(macd.macdLine) : '';
  out['(83) MACDの直近22日間の回帰係数'] = macd && macd.macdLine ? slopeLastN_Def_(macd.macdLine, 22) : '';

  // 84..91（その他）※番号飛びは仕様通り
  out['(84) 連続日数'] = computeStreak_(closeAsc);

  out['(85) 5日間上昇率']  = trunc2_((lastClose / minLastN_(closeAsc, 5)  - 1) * 100);
  out['(86) 10日間上昇率'] = trunc2_((lastClose / minLastN_(closeAsc, 10) - 1) * 100);
  out['(88) 22日間上昇率'] = trunc2_((lastClose / minLastN_(closeAsc, 22) - 1) * 100);

  out['(89) 5日間下落率']  = trunc2_((lastClose / maxLastN_(closeAsc, 5)  - 1) * 100);
  out['(90) 10日間下落率'] = trunc2_((lastClose / maxLastN_(closeAsc, 10) - 1) * 100);
  out['(91) 22日間下落率'] = trunc2_((lastClose / maxLastN_(closeAsc, 22) - 1) * 100);

  // 92..96（評価）※参照キーも新番号に合わせる
  const score92 = scoreLowVolVolumeInc_({
    vol10RangeStd: out['(42)直近10日間の高値-安値の値幅のボラティリティ'],
    vol45RangeStd: out['(44)直近45日間の高値-安値の値幅のボラティリティ'],
    slopeCloseMA10: out['(31)終値10日移動平均の直近10日の回帰係数'],
    slopeVolMA10: out['(36)出来高10日移動平均の直近10日の回帰係数'],
    slopeVolMA45: out['(38)出来高45日移動平均の直近10日の回帰係数'],
    volMA10: out['(26)出来高10日移動平均'],
    volMA45: out['(28)出来高45日移動平均'],
  });
  out['(92) 低ボラ出来高増'] = score92;

  const nop = scoreNOP_likeSpec_({ closeAsc });
  out['(93) 水平ライン上突破'] = nop.N;
  out['(94) 水平ライン下突破'] = nop.O;
  out['(95) GUPから全モ']       = scoreGupFullRetrace_({ closeAsc });

  out['(96) AI基準判定'] = computeAiScore_({
    closeAsc,
    out,
    score92,
    score93: out['(93) 水平ライン上突破'],
    score94: out['(94) 水平ライン下突破'],
    score95: out['(95) GUPから全モ'],
  });

  return out;
}


/* ===== スコア(82) ===== */
function scoreLowVolVolumeInc_({ vol10RangeStd, vol45RangeStd, slopeCloseMA10, slopeVolMA10, slopeVolMA45, volMA10, volMA45 }) {
  const v10 = Number(vol10RangeStd) || 0;
  const v45 = Number(vol45RangeStd) || 0;
  const sC  = Number(slopeCloseMA10) || 0;
  const sV10= Number(slopeVolMA10) || 0;
  const sV45= Number(slopeVolMA45) || 0;
  const m10 = Number(volMA10) || 0;
  const m45 = Number(volMA45) || 0;

  const cond =
    (v10 < v45) &&
    (sC > 0) &&
    (sV10 > 0) &&
    (sV10 > sV45) &&
    (m10 > m45);

  if (!cond) return 0;

  const ratio = (m45 > 0) ? (m10 / m45) : 0;
  if (!isFinite(ratio) || ratio <= 1.0) return 0;

  // 1.2倍以下なら1、1.4倍以下なら2… 0.2刻み、2.8超で10
  let score = Math.floor((ratio - 1.0) / 0.2) + 1;
  score = Math.max(1, Math.min(10, score));
  return score;
}

/* ===== (83)(84) 0.3%刻み 10..1 / 非該当0 ===== */
function scoreNOP_likeSpec_({ closeAsc }) {
  const n = closeAsc.length;
  if (n < 61) return { N: 0, O: 0 };

  const todayClose = closeAsc[n - 1];

  // 直近60日以内、かつ本日から14日以上前：n-60 .. n-15 を対象
  const start = Math.max(0, n - 60);
  const end   = Math.max(0, n - 14); // 非含む
  const slice = closeAsc.slice(start, end);
  if (!slice.length) return { N: 0, O: 0 };

  const maxClose = Math.max.apply(null, slice);
  const minClose = Math.min.apply(null, slice);

  let Nscore = 0, Oscore = 0;

  // 上突破
  if (todayClose > maxClose && maxClose > 0) {
    const dPerc = Math.abs((todayClose - maxClose) / maxClose) * 100.0;
    Nscore = (dPerc < 0.3) ? 10 : Math.max(1, 10 - Math.floor(dPerc / 0.3));
  }

  // 下突破
  if (todayClose < minClose && minClose > 0) {
    const dPerc = Math.abs((minClose - todayClose) / minClose) * 100.0;
    Oscore = (dPerc < 0.3) ? 10 : Math.max(1, 10 - Math.floor(dPerc / 0.3));
  }

  return { N: Nscore, O: Oscore };
}

/* ===== (85) 0.5%刻み 1..10 / 非該当0 ===== */
function scoreGupFullRetrace_({ closeAsc }) {
  const n = closeAsc.length;
  if (n < 15) return 0;
  const todayClose = closeAsc[n - 1];

  // 直近14日以内に +8%以上の日
  const look = Math.min(14, n - 1);
  for (let i = n - look; i < n; i++) {
    const prev = closeAsc[i - 1];
    const cur  = closeAsc[i];
    if (prev && cur && (cur / prev - 1) >= 0.08) {
      // 上昇前日の終値より本日が下なら「全モ」判定
      if (todayClose < prev && prev > 0) {
        const dPerc = ((prev - todayClose) / prev) * 100.0;
        let score = Math.floor(dPerc / 0.5) + 1; // <0.5→1, <1.0→2 ...
        score = Math.max(1, Math.min(10, score));
        return score;
      }
      break;
    }
  }
  return 0;
}

/* ===== (86) AI基準判定（0-10） ===== */
function computeAiScore_({ closeAsc, out, score92, score93, score94, score95 }) {
  const n = closeAsc.length;
  if (n < 30) return '';

  const p10 = Number(out['(11)終値の直近10日間の回帰係数']) || 0;
  const p22 = Number(out['(12)終値の直近22日間の回帰係数']) || 0;
  const p45 = Number(out['(13)終値の直近45日間の回帰係数']) || 0;

  const v10 = Number(out['(16)出来高の直近10日間の回帰係数']) || 0;

  const rsi = Number(out['(80) RSI']);
  const macd = Number(out['(82) MACD']);

  const lastClose = closeAsc[n - 1] || 0;
  const macdPct = (isFinite(macd) && lastClose > 0) ? (macd / lastClose) : 0;

  const trendRaw = 0.5*sign_(p10) + 0.3*sign_(p22) + 0.2*sign_(p45);
  const volTrend = 0.5*sign_(v10);

  let osc = 0;
  if (isFinite(rsi)) osc += clamp_((rsi - 50) / 20, -1, 1) * 0.7;
  osc += Math.tanh(macdPct * 80) * 0.3;

  const support   = (Number(score92) || 0) / 10;
  const breakout  = (Number(score93) || 0) / 10;

  const downBreak = (Number(score94) || 0) / 10;
  const retrace   = (Number(score95) || 0) / 10;

  const raw =
    0.35*trendRaw +
    0.10*volTrend +
    0.20*support +
    0.15*breakout +
    0.10*osc
    -0.20*downBreak
    -0.15*retrace;

  let score = 5 + 5*raw;
  score = Math.max(0, Math.min(10, score));
  return Math.round(score);
}


/* ==========================
 * 抽出v2
 * ========================== */
function extractAndWriteResults_v2_({ srcSheet, header, col, todayStr }) {
  const outFolderPath = ['投資','プログラミング','GAS','スクレイピング','出力結果'];
  const outFolder = openOrCreateFolderByPath_(outFolderPath);

  const fileName = `全銘柄日足分析v2_${Utilities.formatDate(new Date(), SETTINGS.TODAY_TZ, 'yyyy-MM-dd')}`;
  const newSS = SpreadsheetApp.create(fileName);
  const newFileId = newSS.getId();
  const newFile = DriveApp.getFileById(newFileId);

  // 作成したファイルを出力結果フォルダへ移動
  outFolder.addFile(newFile);
  try { DriveApp.getRootFolder().removeFile(newFile); } catch(e) {}

  // 既存のデフォルトシートを(A)に使う
  const sheetSmall = newSS.getSheets()[0];
  sheetSmall.setName('中小型順張りスイング');

  // (B) 大型順張りスイング
  const sheetLarge = newSS.insertSheet('大型順張りスイング');

  // (C) 低ボラ異常増量スイング
  const sheetLV = newSS.insertSheet('低ボラ異常増量スイング');

  // (D) ロングショート
  const sheetLS = newSS.insertSheet('ロングショート');

  // ===== 見出し =====
  const SWING_HEADERS = [
    '証券コード',
    '会社名',
    'AI基準判定'
  ];
  sheetSmall.getRange(1,1,1,SWING_HEADERS.length).setValues([SWING_HEADERS]);
  sheetLarge.getRange(1,1,1,SWING_HEADERS.length).setValues([SWING_HEADERS]);

  const LV_HEADERS = [
    '証券コード',
    '会社名',
    '低ボラ出来高増',
    '直近5日間の高値-安値の値幅のボラティリティ',
    '直近10日間の高値-安値の値幅のボラティリティ',
    '相関係数',
    '相対ボラ',
    '残差ボラ',
    'アップサイドβ',
    'ダウンサイドβ',
    'Up Capture',
    'Down Capture'
  ];
  sheetLV.getRange(1,1,1,LV_HEADERS.length).setValues([LV_HEADERS]);

  const LS_HEADERS = [
    '証券コード',
    '会社名',
    '相関係数',
    '相対ボラ',
    '残差ボラ',
    'アップサイドβ',
    'ダウンサイドβ',
    'Up Capture',
    'Down Capture',
    'RSI',
    'RSIの直近22日間の回帰係数',
    'MACD',
    'MACDの直近22日間の回帰係数'
  ];
  sheetLS.getRange(1,1,1,LS_HEADERS.length).setValues([LS_HEADERS]);

  // ===== データ抽出 =====
  const lastRow = srcSheet.getLastRow();
  let writtenSmall = 0, writtenLarge = 0, writtenLV = 0, writtenLS = 0;

  if (lastRow >= 2) {
    const values = srcSheet.getRange(2, 1, lastRow - 1, srcSheet.getLastColumn()).getValues();

    const idx = (name)=> (name in col ? col[name] : -1);
    const idxCode = idx('証券コード');
    const idxName = idx('会社名');

    // (A)(B) 共通：AIスイング判定に使う列
    const idxAI   = idx('(96) AI基準判定');
    const idxM32  = idx('(32)終値22日移動平均の直近10日の回帰係数');
    const idxM33  = idx('(33)終値45日移動平均の直近10日の回帰係数');
    const idxM34  = idx('(34)終値90日移動平均の直近10日の回帰係数');

    // (C) 低ボラ増量
    const idxLV   = idx('(92) 低ボラ出来高増');
    const idxRangeVol5  = idx('(41)直近5日間の高値-安値の値幅のボラティリティ');
    const idxRangeVol10 = idx('(42)直近10日間の高値-安値の値幅のボラティリティ');

    // 市場統計
    const idxCorr     = idx('(73) 相関');
    const idxRelVol   = idx('(74) 相対ボラ');
    const idxResidVol = idx('(75) 残差ボラ');
    const idxBetaUp   = idx('(76) アップサイドβ');
    const idxBetaDown = idx('(77) ダウンサイドβ');
    const idxUpCap    = idx('(78) Up Capture');
    const idxDownCap  = idx('(79) Down Capture');

    // オシレーター
    const idxRSI     = idx('(80) RSI');
    const idxRSISlp  = idx('(81) RSIの直近22日間の回帰係数');
    const idxMACD    = idx('(82) MACD');
    const idxMACDSlp = idx('(83) MACDの直近22日間の回帰係数');

    const outSmall = [];
    const outLarge = [];
    const outLV = [];
    const outLS = [];

    for (let r=0; r<values.length; r++) {
      const row = values[r];
      const code = String(row[idxCode] || '').trim();
      if (!code) break;

      const name = (idxName>=0 ? String(row[idxName]||'').trim() : '');

      // ===== (A)(B) 同一条件・同一内容で両方に入れる（分割ロジックなし）=====
      const aiVal = (idxAI>=0 ? Number(row[idxAI]) : NaN);
      const m32 = (idxM32>=0 ? Number(row[idxM32]) : 0);
      const m33 = (idxM33>=0 ? Number(row[idxM33]) : 0);
      const m34 = (idxM34>=0 ? Number(row[idxM34]) : 0);

      if (isFinite(aiVal) && aiVal >= 8 && (m32 > 0) && (m33 > 0) && (m34 > 0)) {
        outSmall.push([code, name, aiVal]);
        outLarge.push([code, name, aiVal]);
      }

      // ===== (C) 低ボラ異常増量スイング：LV>=3 =====
      const lvVal = (idxLV>=0 ? Number(row[idxLV]) : NaN);
      if (isFinite(lvVal) && lvVal >= 3) {
        outLV.push([
          code,
          name,
          lvVal,
          idxRangeVol5>=0 ? row[idxRangeVol5] : '',
          idxRangeVol10>=0 ? row[idxRangeVol10] : '',
          idxCorr>=0 ? row[idxCorr] : '',
          idxRelVol>=0 ? row[idxRelVol] : '',
          idxResidVol>=0 ? row[idxResidVol] : '',
          idxBetaUp>=0 ? row[idxBetaUp] : '',
          idxBetaDown>=0 ? row[idxBetaDown] : '',
          idxUpCap>=0 ? row[idxUpCap] : '',
          idxDownCap>=0 ? row[idxDownCap] : '',
        ]);
      }

      // ===== (D) ロングショート：相関>=0.5 =====
      const corrVal = (idxCorr>=0 ? Number(row[idxCorr]) : NaN);
      if (isFinite(corrVal) && corrVal >= 0.5) {
        outLS.push([
          code,
          name,
          idxCorr>=0 ? row[idxCorr] : '',
          idxRelVol>=0 ? row[idxRelVol] : '',
          idxResidVol>=0 ? row[idxResidVol] : '',
          idxBetaUp>=0 ? row[idxBetaUp] : '',
          idxBetaDown>=0 ? row[idxBetaDown] : '',
          idxUpCap>=0 ? row[idxUpCap] : '',
          idxDownCap>=0 ? row[idxDownCap] : '',
          idxRSI>=0 ? row[idxRSI] : '',
          idxRSISlp>=0 ? row[idxRSISlp] : '',
          idxMACD>=0 ? row[idxMACD] : '',
          idxMACDSlp>=0 ? row[idxMACDSlp] : '',
        ]);
      }
    }

    if (outSmall.length) {
      sheetSmall.getRange(2,1,outSmall.length,SWING_HEADERS.length).setValues(outSmall);
      writtenSmall = outSmall.length;
    }
    if (outLarge.length) {
      sheetLarge.getRange(2,1,outLarge.length,SWING_HEADERS.length).setValues(outLarge);
      writtenLarge = outLarge.length;
    }
    if (outLV.length) {
      sheetLV.getRange(2,1,outLV.length,LV_HEADERS.length).setValues(outLV);
      writtenLV = outLV.length;
    }
    if (outLS.length) {
      sheetLS.getRange(2,1,outLS.length,LS_HEADERS.length).setValues(outLS);
      writtenLS = outLS.length;
    }
  }

  SpreadsheetApp.flush();

  // ===== コピー（2か所）=====
  const copyDestPath1 = ['投資','プログラミング','GAS','スクレイピング','出力結果'];
  const copyDestPath2 = ['投資','プログラミング','GAS','スクレイピング','出力結果','株探基本情報付加'];
  const dest1 = openOrCreateFolderByPath_(copyDestPath1);
  const dest2 = openOrCreateFolderByPath_(copyDestPath2);

  // ★重要：dest1 は outFolder と同じなので、makeCopy すると同名が2つ作られる
  // 既に outFolder に移動済み。よって dest1 へのコピーはスキップ。
  if (dest2.getId() !== outFolder.getId()) {
    DriveApp.getFileById(newFileId).makeCopy(fileName, dest2);
  }

  return {
    writtenRows: (writtenSmall + writtenLarge + writtenLV + writtenLS),
    spreadsheetUrl: newSS.getUrl()
  };
}



/* ==========================
 * 重複抑止フィンガープリント（v2）
 * ========================== */
function computeTodayFingerprint_v2_(allRows, header, col, todayStr) {
  const idxCode = col['証券コード'];
  const idxUpd  = col['更新日'];

  // 更新日が今日の行について、主要列を連結してMD5
  const keys = header
    .map(h => col[h])
    .filter(i => i != null);

  const lines = [];
  for (const r of allRows) {
    const updStr = toDateStr_(r[idxUpd]);
    if (updStr === todayStr) {
      const code = String(r[idxCode] || '').trim();
      const parts = [code, updStr];
      for (const idx of keys) {
        const v = r[idx];
        parts.push(v == null ? '' : String(v));
      }
      lines.push(parts.join('|'));
    }
  }
  lines.sort();
  const payload = lines.join('\n');

  const raw = Utilities.computeDigest(
    Utilities.DigestAlgorithm.MD5,
    payload,
    Utilities.Charset.UTF_8
  );
  return raw.map(b => ('0'+(b&0xff).toString(16)).slice(-2)).join('');
}

/* ==========================
 * 見出し構築
 * ========================== */
function buildCalcHeaders_() {
  const h = [];

  // ★追加 (1)?(9)
  h.push('(1)直近の始値');
  h.push('(2)直近の高値');
  h.push('(3)直近の安値');
  h.push('(4)直近の終値');
  h.push('(5)直近の出来高');
  h.push('(6)直近の終値の前日比');
  h.push('(7)直近の終値の前日比率');
  h.push('(8)直近の出来高の前日比');
  h.push('(9)直近の出来高の前日比率');

  // 10..14（終値回帰）
  h.push('(10)終値の直近5日間の回帰係数');
  h.push('(11)終値の直近10日間の回帰係数');
  h.push('(12)終値の直近22日間の回帰係数');
  h.push('(13)終値の直近45日間の回帰係数');
  h.push('(14)終値の直近90日間の回帰係数');

  // 15..19（出来高回帰）
  h.push('(15)出来高の直近5日間の回帰係数');
  h.push('(16)出来高の直近10日間の回帰係数');
  h.push('(17)出来高の直近22日間の回帰係数');
  h.push('(18)出来高の直近45日間の回帰係数');
  h.push('(19)出来高の直近90日間の回帰係数');

  // 20..24（終値MA）
  h.push('(20)終値5日移動平均');
  h.push('(21)終値10日移動平均');
  h.push('(22)終値22日移動平均');
  h.push('(23)終値45日移動平均');
  h.push('(24)終値90日移動平均');

  // 25..29（出来高MA）
  h.push('(25)出来高5日移動平均');
  h.push('(26)出来高10日移動平均');
  h.push('(27)出来高22日移動平均');
  h.push('(28)出来高45日移動平均');
  h.push('(29)出来高90日移動平均');

  // 30..34（終値MA 回帰）
  h.push('(30)終値5日移動平均の直近5日の回帰係数');
  h.push('(31)終値10日移動平均の直近10日の回帰係数');
  h.push('(32)終値22日移動平均の直近10日の回帰係数');
  h.push('(33)終値45日移動平均の直近10日の回帰係数');
  h.push('(34)終値90日移動平均の直近10日の回帰係数');

  // 35..39（出来高MA 回帰）
  h.push('(35)出来高5日移動平均の直近5日の回帰係数');
  h.push('(36)出来高10日移動平均の直近10日の回帰係数');
  h.push('(37)出来高22日移動平均の直近10日の回帰係数');
  h.push('(38)出来高45日移動平均の直近10日の回帰係数');
  h.push('(39)出来高90日移動平均の直近10日の回帰係数');

  // 40..50（値幅）
  h.push('(40)200日分の高値-安値の一日の値幅平均');
  h.push('(41)直近5日間の高値-安値の値幅のボラティリティ');
  h.push('(42)直近10日間の高値-安値の値幅のボラティリティ');
  h.push('(43)直近22日間の高値-安値の値幅のボラティリティ');
  h.push('(44)直近45日間の高値-安値の値幅のボラティリティ');
  h.push('(45)直近90日間の高値-安値の値幅のボラティリティ');
  h.push('(46)直近5日間の高値-安値の値幅のボラティリティの直近5日間の回帰係数');
  h.push('(47)直近10日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数');
  h.push('(48)直近22日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数');
  h.push('(49)直近45日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数');
  h.push('(50)直近90日間の高値-安値の値幅のボラティリティの直近10日間の回帰係数');

  // 51..61（乖離率）※番号飛びは仕様のまま
  h.push('(51)終値5日移動平均と終値の移動平均乖離率');
  h.push('(52)終値10日移動平均と終値の移動平均乖離率');
  h.push('(53)終値22日移動平均と終値の移動平均乖離率');
  h.push('(55)終値45日移動平均と終値の移動平均乖離率');
  h.push('(56)終値90日移動平均と終値の移動平均乖離率');

  h.push('(57)出来高5日移動平均と出来高の移動平均乖離率');
  h.push('(58)出来高10日移動平均と出来高の移動平均乖離率');
  h.push('(59)出来高22日移動平均と出来高の移動平均乖離率');
  h.push('(60)出来高45日移動平均と出来高の移動平均乖離率');
  h.push('(61)出来高90日移動平均と出来高の移動平均乖離率');

  // 62..71（乖離率回帰）
  h.push('(62)終値5日移動平均の移動平均乖離率の直近5日間の回帰係数');
  h.push('(63)終値10日移動平均の移動平均乖離率の直近10日間の回帰係数');
  h.push('(64)終値22日移動平均の移動平均乖離率の直近10日間の回帰係数');
  h.push('(65)終値45日移動平均の移動平均乖離率の直近10日間の回帰係数');
  h.push('(66)終値90日移動平均の移動平均乖離率の直近10日間の回帰係数');

  h.push('(67)出来高5日移動平均の移動平均乖離率の直近5日間の回帰係数');
  h.push('(68)出来高10日移動平均の移動平均乖離率の直近10日間の回帰係数');
  h.push('(69)出来高22日移動平均の移動平均乖離率の直近10日間の回帰係数');
  h.push('(70)出来高45日移動平均の移動平均乖離率の直近10日間の回帰係数');
  h.push('(71)出来高90日移動平均の移動平均乖離率の直近10日間の回帰係数');

  // 72..79（市場）
  h.push('(72) β');
  h.push('(73) 相関');
  h.push('(74) 相対ボラ');
  h.push('(75) 残差ボラ');
  h.push('(76) アップサイドβ');
  h.push('(77) ダウンサイドβ');
  h.push('(78) Up Capture');
  h.push('(79) Down Capture');

  // 80..83（オシレーター）
  h.push('(80) RSI');
  h.push('(81) RSIの直近22日間の回帰係数');
  h.push('(82) MACD');
  h.push('(83) MACDの直近22日間の回帰係数');

  // 84..91（その他）※番号飛びは仕様のまま
  h.push('(84) 連続日数');
  h.push('(85) 5日間上昇率');
  h.push('(86) 10日間上昇率');
  h.push('(88) 22日間上昇率');
  h.push('(89) 5日間下落率');
  h.push('(90) 10日間下落率');
  h.push('(91) 22日間下落率');

  // 92..96（評価）
  h.push('(92) 低ボラ出来高増');
  h.push('(93) 水平ライン上突破');
  h.push('(94) 水平ライン下突破');
  h.push('(95) GUPから全モ');
  h.push('(96) AI基準判定');

  return h;
}


/* ==========================
 * ユーティリティ（シート/フォルダ/書き込み）
 * ========================== */
function openSpreadsheetByPathSimple_(pathArr){
  if(!Array.isArray(pathArr) || pathArr.length===0) throw new Error('SPREADSHEET_PATH未設定');
  let folderIt = DriveApp.getFoldersByName(pathArr[0]);
  if(!folderIt.hasNext()) throw new Error('フォルダなし: '+pathArr[0]);
  let folder = folderIt.next();
  for(let i=1;i<pathArr.length-1;i++){
    const it = folder.getFoldersByName(pathArr[i]);
    if(!it.hasNext()) throw new Error('フォルダなし: '+pathArr[i]);
    folder = it.next();
  }
  const files = folder.getFilesByName(pathArr[pathArr.length-1]);
  if(!files.hasNext()) throw new Error('スプレッドシートなし: '+pathArr[pathArr.length-1]);
  return SpreadsheetApp.open(files.next());
}

function openOrCreateFolderByPath_(pathArr){
  if(!Array.isArray(pathArr) || pathArr.length===0) throw new Error('path未指定');
  let folder = DriveApp.getRootFolder();
  for (const name of pathArr) {
    let it = folder.getFoldersByName(name);
    folder = it.hasNext() ? it.next() : folder.createFolder(name);
  }
  return folder;
}

function ensureHeaders_(sh, header, needed) {
  let changed = false;
  const existing = new Set(header.filter(Boolean));
  const append = [];

  for (const h of needed) {
    if (!existing.has(h)) {
      append.push(h);
      existing.add(h);
      changed = true;
    }
  }
  if (!changed) return header;

  const newHeader = header.concat(append);
  sh.getRange(1, 1, 1, newHeader.length).setValues([newHeader]);
  return newHeader;
}

function writeRowByHeader_(sh, rowIdx, col, obj) {
  // 1行分まとめて書く（既存の値は維持、対象列だけ上書き）
  const lastCol = sh.getLastColumn();
  const row = sh.getRange(rowIdx, 1, 1, lastCol).getValues()[0];

  for (const k in obj) {
    if (!(k in col)) continue;
    row[col[k]] = obj[k];
  }
  sh.getRange(rowIdx, 1, 1, lastCol).setValues([row]);
}

function dateKey_(v){
  if (!v) return null;
  const d = (v instanceof Date) ? v : new Date(v);
  if (isNaN(d)) return null;
  return Utilities.formatDate(d, SETTINGS.TODAY_TZ, 'yyyyMMdd');
}
function toDateStr_(v){
  if (!v) return '';
  const d = (v instanceof Date) ? v : new Date(v);
  if (isNaN(d)) return '';
  return Utilities.formatDate(d, SETTINGS.TODAY_TZ, 'yyyy/MM/dd');
}

/* ==========================
 * 数学ユーティリティ
 * ========================== */
function slopeLastN_(arr, N) {
  if (!arr || arr.length < N) return '';
  const seg = arr.slice(-N).map(Number).filter(v => isFinite(v));
  if (seg.length < N) return '';
  return linregSlope_(seg);
}
function slopeLastN_Def_(arr, N) {
  const seg = lastNDefinedArray_(arr, N);
  if (!seg || seg.length < N) return '';
  return linregSlope_(seg);
}
function linregSlope_(y) {
  const n = y.length;
  // x = 0..n-1
  const xSum = (n - 1) * n / 2;
  const x2Sum = (n - 1) * n * (2 * n - 1) / 6;
  let ySum = 0, xySum = 0;
  for (let i = 0; i < n; i++) { ySum += y[i]; xySum += i * y[i]; }
  const denom = (n * x2Sum - xSum * xSum);
  if (Math.abs(denom) < 1e-12) return 0;
  return (n * xySum - xSum * ySum) / denom;
}
function movingAverage_(arr, W) {
  const n = arr.length;
  const out = new Array(n).fill(null);
  if (W <= 0 || n === 0) return out;
  let sum = 0;
  for (let i = 0; i < n; i++) {
    const v = Number(arr[i]) || 0;
    sum += v;
    if (i >= W) sum -= (Number(arr[i - W]) || 0);
    if (i >= W - 1) out[i] = sum / W;
  }
  return out;
}
function rollingStdDev_(arr, W) {
  const n = arr.length;
  const out = new Array(n).fill(null);
  if (W <= 1 || n === 0) return out;
  let sum=0, sum2=0;
  for (let i=0;i<n;i++){
    const v = Number(arr[i]) || 0;
    sum += v; sum2 += v*v;
    if (i >= W){
      const old = Number(arr[i-W]) || 0;
      sum -= old; sum2 -= old*old;
    }
    if (i >= W-1){
      const mean = sum/W;
      const varPop = Math.max(0, (sum2/W) - mean*mean);
      out[i] = Math.sqrt(varPop);
    }
  }
  return out;
}
function deviationSeries_(series, maArr) {
  const out = new Array(series.length).fill(null);
  for (let i=0;i<series.length;i++){
    const m = maArr[i];
    const s = Number(series[i]);
    if (m == null || !isFinite(m) || m === 0) continue;
    if (!isFinite(s)) continue;
    out[i] = (s / m) - 1.0;
  }
  return out;
}
function lastNDefinedArray_(arr, N) {
  const buf = [];
  for (let i = arr.length - 1; i >= 0 && buf.length < N; i--) {
    const v = Number(arr[i]);
    if (isFinite(v)) buf.push(v);
  }
  if (buf.length < N) return null;
  return buf.reverse();
}
function lastDefined_(arr) {
  if (!arr || !arr.length) return null;
  for (let i = arr.length - 1; i >= 0; i--) {
    const v = Number(arr[i]);
    if (isFinite(v)) return v;
  }
  return null;
}
function mean_(arr) {
  if (!arr || !arr.length) return '';
  let s=0, n=0;
  for (const v0 of arr) {
    const v = Number(v0);
    if (isFinite(v)) { s+=v; n++; }
  }
  return n ? (s/n) : '';
}
function sign_(x) {
  const v = Number(x);
  if (!isFinite(v) || v === 0) return 0;
  return v > 0 ? 1 : -1;
}
function clamp_(x, a, b){ return Math.max(a, Math.min(b, x)); }
function trunc2_(x){
  const v = Number(x);
  if (!isFinite(v)) return '';
  return Math.trunc(v * 100) / 100;
}
function minLastN_(arr, N){
  const seg = arr.slice(-N).map(Number).filter(v => isFinite(v));
  return seg.length ? Math.min.apply(null, seg) : '';
}
function maxLastN_(arr, N){
  const seg = arr.slice(-N).map(Number).filter(v => isFinite(v));
  return seg.length ? Math.max.apply(null, seg) : '';
}

/* ==========================
 * 市場指標（β等）
 * ========================== */
function computeMarketStats_({ symCloseAsc, mktCloseAsc }){
  const n = Math.min(symCloseAsc.length, mktCloseAsc.length);
  if (n < 91) return null;

  const sym = symCloseAsc.slice(n-91, n);
  const mkt = mktCloseAsc.slice(n-91, n);

  const symRet = [];
  const mktRet = [];
  for (let i=1;i<sym.length;i++){
    const rs = (sym[i]/sym[i-1]-1);
    const rm = (mkt[i]/mkt[i-1]-1);
    if (isFinite(rs) && isFinite(rm)){
      symRet.push(rs);
      mktRet.push(rm);
    }
  }
  if (symRet.length < 30) return null;

  function mean(a){ return a.reduce((s,v)=>s+v,0)/a.length; }
  function std(a){
    const mu=mean(a);
    let ss=0; for(const v of a){ const d=v-mu; ss+=d*d; }
    return Math.sqrt(ss/(a.length-1));
  }
  function linreg(x,y){
    const n=x.length;
    let sx=0,sy=0,sxx=0,sxy=0;
    for (let i=0;i<n;i++){ const xi=x[i], yi=y[i]; sx+=xi; sy+=yi; sxx+=xi*xi; sxy+=xi*yi; }
    const denom = n*sxx - sx*sx;
    const b = denom!==0 ? (n*sxy - sx*sy)/denom : 0;
    const a = (sy - b*sx)/n;
    let ss=0; for (let i=0;i<n;i++){ const e = y[i] - (a + b*x[i]); ss += e*e; }
    const residStd = Math.sqrt(ss/(n-2));
    return {a,b,residStd};
  }
  function corr(x,y){
    const n=x.length;
    const mux=mean(x), muy=mean(y);
    let num=0, dx=0, dy=0;
    for (let i=0;i<n;i++){
      const vx=x[i]-mux, vy=y[i]-muy;
      num += vx*vy; dx += vx*vx; dy += vy*vy;
    }
    return (dx>0 && dy>0) ? (num / Math.sqrt(dx*dy)) : 0;
  }

  const all     = linreg(mktRet, symRet);
  const r_all   = corr(mktRet, symRet);
  const relvol  = std(symRet) / Math.max(1e-12, std(mktRet));

  const upX=[], upY=[], dnX=[], dnY=[];
  for (let i=0;i<mktRet.length;i++){
    const rm = mktRet[i], rs = symRet[i];
    if (rm>0){ upX.push(rm); upY.push(rs); }
    if (rm<0){ dnX.push(rm); dnY.push(rs); }
  }
  const up = (upX.length>=5) ? linreg(upX, upY) : {b:null};
  const dn = (dnX.length>=5) ? linreg(dnX, dnY) : {b:null};

  function avg(a){ return a.length? mean(a) : null; }
  const upCap   = (avg(upY)!=null && avg(upX)!=null) ? (avg(upY)/avg(upX)) : null;
  const downCap = (avg(dnY)!=null && avg(dnX)!=null) ? (avg(dnY)/avg(dnX)) : null;

  return {
    beta: all.b, corr: r_all, relVol: relvol, residVol: all.residStd,
    betaUp: up.b, betaDown: dn.b,
    upCapture: upCap, downCapture: downCap
  };
}

/* ==========================
 * RSI / MACD
 * ========================== */
function computeRsiSeries_(closeAsc, period) {
  const n = closeAsc.length;
  const out = new Array(n).fill(null);
  if (n < period + 1) return out;

  let gain = 0, loss = 0;
  // 初期
  for (let i=1; i<=period; i++) {
    const d = closeAsc[i] - closeAsc[i-1];
    if (d > 0) gain += d;
    else loss += -d;
  }
  gain /= period;
  loss /= period;

  let rs = (loss === 0) ? Infinity : (gain / loss);
  out[period] = 100 - (100 / (1 + rs));

  // Wilder
  for (let i=period+1; i<n; i++) {
    const d = closeAsc[i] - closeAsc[i-1];
    const g = d > 0 ? d : 0;
    const l = d < 0 ? -d : 0;
    gain = (gain*(period-1) + g) / period;
    loss = (loss*(period-1) + l) / period;
    rs = (loss === 0) ? Infinity : (gain / loss);
    out[i] = 100 - (100 / (1 + rs));
  }
  return out;
}

function computeMacd_(closeAsc, fast, slow, signal) {
  if (!closeAsc || closeAsc.length < slow + signal + 2) return null;
  const emaFast = emaSeries_(closeAsc, fast);
  const emaSlow = emaSeries_(closeAsc, slow);
  const macdLine = closeAsc.map((_,i)=>{
    const a = emaFast[i], b = emaSlow[i];
    if (a==null || b==null) return null;
    return a - b;
  });
  const signalLine = emaSeries_(macdLine, signal);
  return { macdLine, signalLine };
}

function emaSeries_(arr, period) {
  const n = arr.length;
  const out = new Array(n).fill(null);
  if (period <= 0 || n < period) return out;

  const k = 2 / (period + 1);
  let sum = 0;
  for (let i=0; i<period; i++) sum += Number(arr[i]) || 0;
  let ema = sum / period;
  out[period - 1] = ema;

  for (let i=period; i<n; i++) {
    const v = Number(arr[i]);
    if (!isFinite(v)) { out[i] = out[i-1]; continue; }
    ema = v*k + ema*(1-k);
    out[i] = ema;
  }
  return out;
}

/* ==========================
 * 連続日数
 * ========================== */
function computeStreak_(closeAsc) {
  const n = closeAsc.length;
  if (n < 2) return '';
  const diffs = [];
  for (let i=1; i<n; i++) diffs.push(closeAsc[i] - closeAsc[i-1]);

  const last = diffs[diffs.length - 1];
  if (!isFinite(last) || last === 0) return 0;

  const dir = last > 0 ? 1 : -1;
  let cnt = 0;
  for (let i=diffs.length-1; i>=0; i--) {
    const d = diffs[i];
    if (!isFinite(d) || d === 0) break;
    if ((d > 0 && dir === 1) || (d < 0 && dir === -1)) cnt++;
    else break;
  }
  return dir * cnt;
}

/**
 * （任意）抽出＋メール送信だけを手動テストする（v2）
 * - runAll() の「全行読み切り」「重複抑止」「処理件数カウント」などは通さない
 * - 抽出結果URLと元シートURLを本文に入れる
 * - ※本番誤爆が怖い場合は、EMAIL_TO を一時的に自分以外へ変えないこと！
 */
function test_extractAndMail_v2() {
  const tz = SETTINGS.TODAY_TZ || 'Asia/Tokyo';
  const now = new Date();
  const todayStr = Utilities.formatDate(now, tz, 'yyyy/MM/dd');

  const ss = openSpreadsheetByPathSimple_(SETTINGS.SPREADSHEET_PATH);
  const sh = SETTINGS.SHEET_NAME ? ss.getSheetByName(SETTINGS.SHEET_NAME) : ss.getSheets()[0];
  if (!sh) throw new Error('対象シートが見つかりません');

  // 見出し準備（test_extractOnly_v2_ と同じ）
  const headerRange = sh.getRange(1, 1, 1, sh.getLastColumn());
  let header = headerRange.getValues()[0].map(v => String(v || '').trim());

  const baseHeaders = ['証券コード', '更新日', '実行結果'];
  const calcHeaders = buildCalcHeaders_();
  header = ensureHeaders_(sh, header, baseHeaders.concat(calcHeaders));

  const col = {};
  header.forEach((h, i) => { if (h) col[h] = i; });

  // 抽出
  const extract = extractAndWriteResults_v2_({ srcSheet: sh, header, col, todayStr });

  // メール（テスト用）
  const subject = `【TEST】全銘柄日足分析v2：${todayStr}`;
  const body =
`【TEST】抽出＋メール送信の手動テストです。

元シート：
${ss.getUrl()}

抽出結果：
${extract.spreadsheetUrl}

抽出件数（AI+低ボラの合算）：
${extract.writtenRows}
`;
  MailApp.sendEmail(SETTINGS.EMAIL_TO, subject, body);

  console.log(`[TEST] 抽出＋メール送信完了: url=${extract.spreadsheetUrl}`);
  return extract;
}
