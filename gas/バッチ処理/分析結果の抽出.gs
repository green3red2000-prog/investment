/**
 * 分析結果抽出（5分おきトリガー想定）
 *
 * 仕様まとめ
 * (1) 出力結果フォルダから最新日付の
 *   - 全銘柄日足分析_yyyy-MM-dd
 *   - 決算速報_yyyy-MM-dd
 *   をそれぞれ1つずつ選ぶ。
 *   分析結果の抽出は、全銘柄日足分析と決算速報のファイル名が
 *   両方とも前回処理時から更新されている場合に実行する。
 *   決算速報に「業種」列が存在しない場合は、
 *   分析結果の抽出を実行しない。
 * (2) 分析結果の抽出_yyyy-MM-dd を出力結果フォルダに作成し、
 *     指定シートを作成して抽出結果を書き出す。
 * (3) メール送信
 */

const CONFIG = {
  // タイムゾーン
  timeZone: 'Asia/Tokyo',

  // フォルダパス
  outputFolderPath: ['投資', 'プログラミング', 'GAS', 'スクレイピング', '出力結果'],
  masterFolderPath: ['投資', 'プログラミング', 'GAS', 'マスタ'],

  // ファイルプレフィックス
  prefixAnalysis: '全銘柄日足分析_',
  prefixKessan: '決算速報_',
  prefixOutput: '分析結果の抽出_',

  // マスタファイル名
  masterBaseInfoName: '全銘柄基本情報マスタ',
  masterAnalysisName: '全銘柄日足分析マスタ',

  // (2) 抽出 起動判定（ファイル名）
  propLastZenFileNameForExtract: 'LAST_ZEN_FILENAME_FOR_EXTRACT',
  propLastKessanFileNameForExtract: 'LAST_KESSAN_FILENAME_FOR_EXTRACT',

  // Email
  mailTo: 'green3red2000@gmail.com',
};

// ★ 見出し略称（作成完了後に表示だけ置換）
const HEADER_ALIASES = {
  '(96)AI基準判定': '(96)AI判定',
  '(101)直近22日間の値幅不安定率': '(101)不安定率22',
  '(103)直近132日間の値幅不安定率': '(103)不安定率132',
  '(104)パーフェクトオーダー判定': '(104)PO判定',
  '(98)信用買い残日数': '(98)買い残日数',
  '(51)終値5日移動平均と終値の移動平均乖離率': '(51)乖離率5',
  '(53)終値22日移動平均と終値の移動平均乖離率': '(53)乖離率22',
  '(55)終値66日移動平均と終値の移動平均乖離率': '(55)乖離率66',
  '(56)終値132日移動平均と終値の移動平均乖離率': '(56)乖離率132',
  '(86)10日間上昇率': '(86)上昇率10',
  '(90)10日間下落率': '(90)下落率10',
  '(41)直近5日間の高値-安値の値幅のボラティリティ': '(41)値幅ボラ5',
  '(42)直近10日間の高値-安値の値幅のボラティリティ': '(42)値幅ボラ10',
  '(81)RSIの直近22日間の回帰係数': '(81)RSI回帰22',
  '(76)アップサイドβ': '(76)US β',
  '(77)ダウンサイドβ': '(77)DS β',
  '(78)Up Capture': '(78)U Cap',
  '(79)Down Capture': '(79)D Cap',
  '(83)MACDの直近22日間の回帰係数': '(83)MACD回帰22',
  // ★ 追加
  '(128)週足RSI': '(128)週RSI',
  '(129)週足RSIの直近5週間の回帰係数': '(129)週RSI回帰5',
  '(130)ストキャスティクス%K': '(130)ST%K',
  '(131)ストキャスティクス%D': '(131)ST%D',
  '(132)週足ストキャスティクス%K': '(132)週ST%K',
  '(133)週足ストキャスティクス%D': '(133)週ST%D',
  '(134)ストキャスティクス%Kの直近5日間の回帰係数': '(134)ST%K回帰5',
  '(135)ストキャスティクス%Dの直近5日間の回帰係数': '(135)ST%D回帰5',
  '(136)週足ストキャスティクス%Kの直近5週間の回帰係数': '(136)週ST%K回帰5',
  '(137)週足ストキャスティクス%Dの直近5週間の回帰係数': '(137)週ST%D回帰5',
  '(138)ボリンジャーバンドのσ値': '(138)σ値',
};


/**
 * 通常運用（トリガー実行想定）：抽出の重複チェックあり
 */
function run_extract_and_mail() {
  return run_extract_and_mail_impl_({ skipDupCheck: false });
}

/**
 * 手動テスト用：（手動実行想定）：抽出の重複チェックなし
 */
function run_extract_and_mail_test() {
  return run_extract_and_mail_impl_({ skipDupCheck: true });
}

function run_extract_and_mail_impl_(opt) {
  const { skipDupCheck } = opt || { skipDupCheck: false };

  const tz = CONFIG.timeZone;
  const todayStr = Utilities.formatDate(new Date(), tz, 'yyyy-MM-dd');
  const todayMailStr = Utilities.formatDate(new Date(), tz, 'yyyy/MM/dd');

  // (1) 処理対象ファイルの検出
  const outputFolder = getFolderByPath_(CONFIG.outputFolderPath);
  const latestAnalysis = findLatestDatedFile_(outputFolder, CONFIG.prefixAnalysis); // {file, name, date}
  const latestKessan = findLatestDatedFile_(outputFolder, CONFIG.prefixKessan);

  if (!latestAnalysis || !latestKessan) {
    console.log('処理対象ファイルが見つかりません。' +
      ` analysis=${!!latestAnalysis}, kessan=${!!latestKessan}`);
    return;
  }

  const props = PropertiesService.getScriptProperties();

  // =========================================================
  // (2) 分析結果の抽出 起動判定
  // テスト実行時は重複チェックをスキップ
  // =========================================================
  let shouldRunExtract = true;
  let shKessanSource = null;

  if (!skipDupCheck) {
    const lastZenNameForExtract =
      props.getProperty(CONFIG.propLastZenFileNameForExtract) || '';

    const lastKessanNameForExtract =
      props.getProperty(CONFIG.propLastKessanFileNameForExtract) || '';

    const currZenName = latestAnalysis.name || '';
    const currKessanName = latestKessan.name || '';

    const zenUpdated =
      currZenName !== lastZenNameForExtract;

    const kessanUpdated =
      currKessanName !== lastKessanNameForExtract;

    // 両方更新されたときのみ抽出する
    shouldRunExtract = zenUpdated && kessanUpdated;

    if (!shouldRunExtract) {
      console.log(
        '[SKIP][EXTRACT] 両方更新されていないため抽出処理なし'
      );
      console.log(
        `  zen: curr=${currZenName} / ` +
        `last=${lastZenNameForExtract} / updated=${zenUpdated}`
      );
      console.log(
        `  kessan: curr=${currKessanName} / ` +
        `last=${lastKessanNameForExtract} / updated=${kessanUpdated}`
      );
    }
  } else {
    console.log(
      '[TEST] skipDupCheck=true のため、起動判定をスキップします'
    );
  }

  // ★ 追加条件：両方更新されている場合でも、決算速報に「業種」列が無ければ抽出しない（＆シートを使い回す）
  if (shouldRunExtract) {
    const ssK = SpreadsheetApp.openById(latestKessan.file.getId());
    shKessanSource = ssK.getSheets()[0];
    const kInfo = getHeaderMap_(shKessanSource);
    if (!kInfo.map['業種']) {
      console.log('[SKIP][EXTRACT] 決算速報ファイルに株探基本情報付加されてないため抽出処理なし（業種列なし）');
      console.log(`  kessan=${latestKessan.name}`);
      shouldRunExtract = false;
      shKessanSource = null;
    }
  }

  // 抽出を行わない場合は終了
  if (!shouldRunExtract) {
    return;
  }

  // 起動判定OKログ
  console.log('[START] 起動判定OK');
  console.log(`対象(zen): ${latestAnalysis.name}`);
  console.log(`作成日時(zen): ${Utilities.formatDate(latestAnalysis.file.getDateCreated(), tz, 'yyyy-MM-dd HH:mm:ss')}`);
  console.log(`対象(kessan): ${latestKessan.name}`);

  // 抽出元ファイルと、値補完に使用する各マスタを開く
  const masterFolder = getFolderByPath_(CONFIG.masterFolderPath);
  const ssAnalysis = SpreadsheetApp.openById(latestAnalysis.file.getId());
  const shAnalysis = ssAnalysis.getSheets()[0];

  const ssBaseMaster = openSpreadsheetByNameInFolder_(masterFolder, CONFIG.masterBaseInfoName);
  const shBaseMaster = ssBaseMaster.getSheets()[0];
  const ssAnalysisMaster = openSpreadsheetByNameInFolder_(masterFolder, CONFIG.masterAnalysisName);
  const shAnalysisMaster = ssAnalysisMaster.getSheets()[0];

  let extractDone = false;
  let ssOut = null;

  // ★ 抽出時の不足列補完に使用するマスタLookup
  const baseMasterLookup = buildMasterLookup_(shBaseMaster);
  const analysisMasterLookup = buildMasterLookup_(shAnalysisMaster);

  // (2) 抽出（条件付き）
  if (shouldRunExtract) {
    console.log('[RUN][EXTRACT] start');
    const outName = `${CONFIG.prefixOutput}${todayStr}`;
    ssOut = openOrCreateSpreadsheetInFolder_(outputFolder, outName);

    buildExtractionSheets_({
      ssOut,
      shAnalysisSource: shAnalysis,
      shKessanSource: shKessanSource || SpreadsheetApp.openById(latestKessan.file.getId()).getSheets()[0],
      baseMasterLookup,
      analysisMasterLookup,
    });
    deleteDefaultSheetIfExists_(ssOut);
    extractDone = true;

    // ★ (2)起動判定の保存（ファイル名）
    props.setProperty(CONFIG.propLastZenFileNameForExtract, latestAnalysis.name);
    props.setProperty(CONFIG.propLastKessanFileNameForExtract, latestKessan.name);
  }

  // (3) メール送信
  if (extractDone) {
    const subject = `分析結果の抽出：${todayMailStr}`;
    const body =
`本日の分析結果の抽出を終了しました。
処理対象のファイルは以下でした。

全銘柄日足分析：${latestAnalysis.name}
決算速報：${latestKessan.name}

抽出結果は以下です。
${ssOut ? ssOut.getUrl() : ''}
`;
    MailApp.sendEmail(CONFIG.mailTo, subject, body);
  }

  console.log('完了');
}

/** =========================
 * (2) 抽出シート作成
 * ========================= */
function buildExtractionSheets_(args) {
  const {
    ssOut, shAnalysisSource, shKessanSource,
    baseMasterLookup, analysisMasterLookup
  } = args;

  const specs = getExtractionSpecs_();
  specs.forEach(spec => {
    const sh = ensureSheet_(ssOut, spec.sheetName);
    sh.clearContents();
    sh.clearFormats();

    const source = spec.sourceType === 'analysis' ? shAnalysisSource : shKessanSource;

    const out = extractRows_(source, spec, {
      baseMasterLookup,
      analysisMasterLookup,
    });

    // 書き込み（ヘッダ＋データ）
    const values = [spec.headers].concat(out.rows);
    sh.getRange(1, 1, values.length, spec.headers.length).setValues(values);

    // ソート（データが2行以上ある場合のみ）
    if (out.sort && out.rows.length >= 2) {
      const headerToCol = new Map();
      spec.headers.forEach((h, i) => headerToCol.set(h, i + 1));

      const sortSpec = out.sort
        .map(s => ({ column: headerToCol.get(s.header), ascending: s.ascending }))
        .filter(s => !!s.column);

      if (sortSpec.length > 0) {
        sh.getRange(2, 1, sh.getLastRow() - 1, spec.headers.length).sort(sortSpec);
      }
    }

    // ★ 見出し固定：シートごとに列固定指定（無ければ従来通り行のみ）
    formatHeaderRow_(sh, spec.headers.length, spec.freezeCols);

    // ★ 共通フォーマット適用（存在する列のみ）
    applyCommonFormats_(sh, spec.headers);

    // ★ 追加：条件に該当するセルを薄い赤色に
    if (spec.alerts && spec.alerts.length > 0) {
      applyAlertCellColors_(sh, spec.headers, spec.alerts);
    }

    // ★ シート作成完了後：見出し文言を略称へ（表示だけ）
    rewriteHeaderAliases_(sh, HEADER_ALIASES);
  });

  // ★ 仕様書順にシート並び替え
  reorderSheetsBySpecOrder_(ssOut, specs);
}

function extractRows_(sourceSheet, spec, masterCtx) {
  const srcInfo = getHeaderMap_(sourceSheet);
  const values = sourceSheet.getDataRange().getValues();
  if (values.length < 2) return { rows: [], sort: spec.sort };

  const idx = (name) => srcInfo.map[name] ? (srcInfo.map[name] - 1) : null;

  // よく使う列（条件用）
  const iCode = idx('証券コード');
  const iName = idx('会社名');

  const iAi96 = idx('(96)AI基準判定');
  const iReg22 = idx('(32)終値22日移動平均の直近10日の回帰係数');
  const iReg45 = idx('(33)終値66日移動平均の直近10日の回帰係数');
  const iReg90 = idx('(34)終値132日移動平均の直近10日の回帰係数');

  const iPer = idx('PER');
  const iPbr = idx('PBR');
  const iYield = idx('利回り');
  const iMcap = idx('時価総額');

  const iLowVolInc = idx('(92)低ボラ出来高増');

  const iBeta72 = idx('(72)β') || idx('β');
  const iCorr73 = idx('(73)相関') || idx('相関係数') || idx('相関');
  const iRelVol74 = idx('(74)相対ボラ') || idx('相対ボラ');
  const iResVol75 = idx('(75)残差ボラ') || idx('残差ボラ');
  const iUpB76 = idx('(76)アップサイドβ') || idx('アップサイドβ');
  const iDownB77 = idx('(77)ダウンサイドβ') || idx('ダウンサイドβ');
  const iUpCap78 = idx('(78)Up Capture') || idx('Up Capture');
  const iDownCap79 = idx('(79)Down Capture') || idx('Down Capture');

  const iRsi80 = idx('(80)RSI') || idx('RSI');
  const iRsiReg81 = idx('(81)RSIの直近22日間の回帰係数') || idx('RSIの直近22日間の回帰係数');
  const iMacd82 = idx('(82)MACD') || idx('MACD');
  const iMacdReg83 = idx('(83)MACDの直近22日間の回帰係数') || idx('MACDの直近22日間の回帰係数');

  const iClass = idx('分類');
  const iCreditRatio = idx('信用倍率');
  const iPerfectOrder104 = idx('(104)パーフェクトオーダー判定');

  // ★ 追加：値幅不安定率
  const iUnstable22 = idx('(101)直近22日間の値幅不安定率');
  const iUnstable90 = idx('(103)直近132日間の値幅不安定率');
  const iCreditBuyDays98 = idx('(98)信用買い残日数');

  const rows = [];

  for (let r = 1; r < values.length; r++) {
    const row = values[r];
    const code = iCode !== null ? String(row[iCode] ?? '').trim() : '';
    const name = iName !== null ? String(row[iName] ?? '').trim() : '';
    if (!code) continue;

    let signal = ''; // ★ 売買判定（ロングショート用）

    if (spec.filterFn) {
      const res = spec.filterFn({
        row,
        idx,
        getNum: getNumber_,
        code,
        name,
        col: {
          iAi96, iReg22, iReg45, iReg90,
          iPer, iPbr, iYield, iMcap,
          iLowVolInc,

          // ★ 追加（不安定率・(98)）
          iUnstable22, iUnstable90,
          iCreditBuyDays98,

          iBeta72, iCorr73, iRelVol74, iResVol75,

          // ★ 別名も持たせる
          iUpB76, iDownB77, iUpCap78, iDownCap79,
          iUpBeta76: iUpB76,
          iDownBeta77: iDownB77,
          iDnCap79: iDownCap79,

          iRsi80, iRsiReg81, iMacd82, iMacdReg83,
          iClass, iCreditRatio, iPerfectOrder104,
        },
      });

      // ★ filterFn が boolean を返すシートと、{ok,signal} を返すシート両対応
      if (typeof res === 'boolean') {
        if (!res) continue;
      } else if (res && typeof res === 'object') {
        if (!res.ok) continue;
        signal = res.signal || '';
      } else {
        // 想定外は除外
        continue;
      }
    }

    const outRow = spec.headers.map(h => {
      // ★ ロングショート用：売買判定
      if (h === '売買判定') return signal;

      if (h === 'MIX係数') {
        const per = iPer !== null ? getNumber_(row[iPer]) : NaN;
        const pbr = iPbr !== null ? getNumber_(row[iPbr]) : NaN;
        if (isFinite(per) && isFinite(pbr) && per > 0 && pbr > 0) {
          return Math.floor(per * pbr * 100) / 100;
        }
        return '';
      }
      if (h === '株探') return `=HYPERLINK("https://kabutan.jp/stock/chart?code=${code}","株")`;
      if (h === '四季') return `=HYPERLINK("https://shikiho.toyokeizai.net/stocks/${code}","季")`;
      if (h === '銘偵') return `=HYPERLINK("https://monex.ifis.co.jp/index.php?sa=find&ta=e&wd=${code}&x=0&y=0","銘")`;

      // ★ 通常列：まず処理対象ファイルから取得。無ければマスタ（基本/分析）から補完。無ければ空欄。
      return pickValueWithFallback_(row, srcInfo, h, code, masterCtx);
    });

    rows.push(outRow);
  }

  return { rows, sort: spec.sort };
}

/**
 * ★ 処理対象ファイルに列が無い場合:
 * - 全銘柄基本情報マスタ -> 全銘柄日足分析マスタ の順に補完
 * - それでも無ければ空欄
 */
function pickValueWithFallback_(srcRow, srcInfo, header, code, masterCtx) {
  const si = srcInfo.map[header];
  if (si) return srcRow[si - 1];

  const { baseMasterLookup, analysisMasterLookup } = masterCtx || {};

  const v1 = getMasterValue_(baseMasterLookup, code, header);
  if (v1 !== undefined) return v1;

  const v2 = getMasterValue_(analysisMasterLookup, code, header);
  if (v2 !== undefined) return v2;

  return '';
}

/** =========================
 * 抽出シート共通フォーマット（★）
 * ========================= */
function applyCommonFormats_(sheet, headers) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return;

  const headerToCol = new Map();
  headers.forEach((h, i) => headerToCol.set(h, i + 1));

  const colOf = (h) => headerToCol.get(h);

  const applyNumberFormat = (h, fmt) => {
    const col = colOf(h);
    if (!col) return;
    sheet.getRange(2, col, lastRow - 1, 1).setNumberFormat(fmt);
  };

  const applyAlign = (h, align) => {
    const col = colOf(h);
    if (!col) return;
    sheet.getRange(2, col, lastRow - 1, 1).setHorizontalAlignment(align);
  };

  // 日付・時刻
  applyNumberFormat('日付', 'yyyy-MM-dd');
  applyNumberFormat('時刻', 'HH:mm:ss');
  applyNumberFormat('信用日付', 'yyyy-MM-dd');

  // 中央揃え（リンク）
  ['株探','四季','銘偵'].forEach(h => applyAlign(h, 'center'));

  // 右揃え（基本）
  [
    '証券コード','前日比','騰落率','出来高',
    '売上高','経常益','最終益'
  ].forEach(h => applyAlign(h, 'right'));

  // 売上・利益：整数
  ['売上高','経常益','最終益'].forEach(h =>
    applyNumberFormat(h, '#,##0')
  );

  // ★ 小数1位固定
  [
    '信用倍率','PER','PBR','信用売り残','信用買い残'
  ].forEach(h => {
    applyAlign(h, 'right');
    applyNumberFormat(h, '0.0');
  });

  // ★ 時価総額：整数表示（小数点なし）
  applyAlign('時価総額', 'right');
  applyNumberFormat('時価総額', '#,##0');

  // ★ 小数2位固定
  [
    '利回り','MIX係数',
    '(98)信用買い残日数',
    '(72)β','(73)相関','(74)相対ボラ','(75)残差ボラ',
    '(76)アップサイドβ','(77)ダウンサイドβ'
  ].forEach(h => {
    applyAlign(h, 'right');
    applyNumberFormat(h, '0.00');
  });

  // ★ 小数1位固定（ボラ・RSI・MACD系）
  [
    '(41)直近5日間の高値-安値の値幅のボラティリティ',
    '(42)直近10日間の高値-安値の値幅のボラティリティ',
    '(80)RSI','(81)RSIの直近22日間の回帰係数',
    '(82)MACD','(83)MACDの直近22日間の回帰係数'
  ].forEach(h => {
    applyAlign(h, 'right');
    applyNumberFormat(h, '0.0');
  });

  // ★ Up / Down Capture は％表示（小数1位固定）
  ['(78)Up Capture','(79)Down Capture'].forEach(h => {
    applyAlign(h, 'right');
    applyNumberFormat(h, '0.0%');
  });

  // ★（追加）値幅不安定率：％表示（小数1位固定）
  [
    '(101)直近22日間の値幅不安定率',
    '(103)直近132日間の値幅不安定率',
  ].forEach(h => {
    applyAlign(h, 'right');
    applyNumberFormat(h, '0.0%');
  });

  // ★（追加）乖離率：％表示（小数1位固定）
  [
    '(51)終値5日移動平均と終値の移動平均乖離率',
    '(53)終値22日移動平均と終値の移動平均乖離率',
    '(55)終値66日移動平均と終値の移動平均乖離率',
    '(56)終値132日移動平均と終値の移動平均乖離率',
  ].forEach(h => {
    applyAlign(h, 'right');
    applyNumberFormat(h, '0.0%');
  });

  // ★（追加）パーフェクトオーダー判定：右揃え
  applyAlign('(104)パーフェクトオーダー判定', 'right');

  // ★ 売買判定：中央揃え
  applyAlign('売買判定', 'center');

  
  // ★ 追加（オシレーター/週足/ボリンジャー）
  [
    '(128)週足RSI',
    '(129)週足RSIの直近5週間の回帰係数',
    '(130)ストキャスティクス%K',
    '(131)ストキャスティクス%D',
    '(132)週足ストキャスティクス%K',
    '(133)週足ストキャスティクス%D',
    '(134)ストキャスティクス%Kの直近5日間の回帰係数',
    '(135)ストキャスティクス%Dの直近5日間の回帰係数',
    '(136)週足ストキャスティクス%Kの直近5週間の回帰係数',
    '(137)週足ストキャスティクス%Dの直近5週間の回帰係数',
    '(138)ボリンジャーバンドのσ値',
  ].forEach(h => {
    applyAlign(h, 'right');
    applyNumberFormat(h, '0.0');
  });

}

/** =========================
 * 抽出仕様（見出し列は前回指定のまま）
 * ========================= */
function getExtractionSpecs_() {
  return [
    {
      sheetName: '中小型順張りスイング',
      sourceType: 'analysis',
      freezeCols: 2,
      headers: [
        '証券コード','会社名','(96)AI基準判定','MIX係数','利回り','(97)タイプ分類','(104)パーフェクトオーダー判定','(84)連続日数',
        '(51)終値5日移動平均と終値の移動平均乖離率','(53)終値22日移動平均と終値の移動平均乖離率','(56)終値132日移動平均と終値の移動平均乖離率',
        '(86)10日間上昇率','(90)10日間下落率','(101)直近22日間の値幅不安定率','(103)直近132日間の値幅不安定率',
        '信用倍率','(98)信用買い残日数',
        '業種','概要','株探','四季','銘偵','時価総額','上場区分','PER','PBR','終値','前日比','騰落率','出来高',
        '売上高','経常益','最終益','信用日付','信用売り残','信用買い残'      ],
      filterFn: ({row, getNum, col}) => {
        const ai = col.iAi96 !== null ? getNum(row[col.iAi96]) : NaN;
        if (!(isFinite(ai) && ai >= 8)) return false;

        const r22 = col.iReg22 !== null ? getNum(row[col.iReg22]) : NaN;
        const r45 = col.iReg45 !== null ? getNum(row[col.iReg45]) : NaN;
        const r90 = col.iReg90 !== null ? getNum(row[col.iReg90]) : NaN;
        if (!(isFinite(r22) && r22 > 0 && isFinite(r45) && r45 > 0 && isFinite(r90) && r90 > 0)) return false;

        const per = col.iPer !== null ? getNum(row[col.iPer]) : NaN;
        const pbr = col.iPbr !== null ? getNum(row[col.iPbr]) : NaN;
        const mix = (isFinite(per) && isFinite(pbr) && per > 0 && pbr > 0) ? (per * pbr) : NaN;
        if (!(isFinite(mix) && mix < 10)) return false;

        const mcap = col.iMcap !== null ? getNum(row[col.iMcap]) : NaN;
        if (!(isFinite(mcap) && mcap <= 1100)) return false;

        const y = col.iYield !== null ? getNum(row[col.iYield]) : NaN;
        if (!(isFinite(y) && y >= 1)) return false;

        const p22 = (col.iUnstable22 !== null) ? toPercent_(row[col.iUnstable22], getNum) : NaN;
        const p90 = (col.iUnstable90 !== null) ? toPercent_(row[col.iUnstable90], getNum) : NaN;
        if (!(isFinite(p22) && p22 < 2 && isFinite(p90) && p90 < 2)) return false;

        const cr = col.iCreditRatio !== null ? getNum(row[col.iCreditRatio]) : NaN;
        const days = col.iCreditBuyDays98 !== null ? getNum(row[col.iCreditBuyDays98]) : NaN;
        if (!(isFinite(cr) && cr < 10 && isFinite(days) && days < 3)) return false;

        return true;
      },
      // ★ MIX係数が低い順でソート
      sort: [{ header: 'MIX係数', ascending: true }],
    },

    {
      sheetName: '大型順張りスイング',
      sourceType: 'analysis',
      freezeCols: 2,
      headers: [
        '証券コード','会社名','(96)AI基準判定','MIX係数','利回り','(97)タイプ分類','(104)パーフェクトオーダー判定','(84)連続日数',
        '(51)終値5日移動平均と終値の移動平均乖離率','(53)終値22日移動平均と終値の移動平均乖離率','(56)終値132日移動平均と終値の移動平均乖離率',
        '(86)10日間上昇率','(90)10日間下落率','(101)直近22日間の値幅不安定率','(103)直近132日間の値幅不安定率',
        '信用倍率','(98)信用買い残日数',
        '業種','概要','株探','四季','銘偵','時価総額','上場区分','PER','PBR','終値','前日比','騰落率','出来高',
        '売上高','経常益','最終益','信用日付','信用売り残','信用買い残'
      ],
      filterFn: ({row, getNum, col}) => {
        const ai = col.iAi96 !== null ? getNum(row[col.iAi96]) : NaN;
        if (!(isFinite(ai) && ai >= 8)) return false;

        const r22 = col.iReg22 !== null ? getNum(row[col.iReg22]) : NaN;
        const r45 = col.iReg45 !== null ? getNum(row[col.iReg45]) : NaN;
        const r90 = col.iReg90 !== null ? getNum(row[col.iReg90]) : NaN;
        if (!(isFinite(r22) && r22 > 0 && isFinite(r45) && r45 > 0 && isFinite(r90) && r90 > 0)) return false;

        const per = col.iPer !== null ? getNum(row[col.iPer]) : NaN;
        const pbr = col.iPbr !== null ? getNum(row[col.iPbr]) : NaN;
        const mix = (isFinite(per) && isFinite(pbr) && per > 0 && pbr > 0) ? (per * pbr) : NaN;
        if (!(isFinite(mix) && mix < 22.5)) return false;

        const mcap = col.iMcap !== null ? getNum(row[col.iMcap]) : NaN;
        if (!(isFinite(mcap) && mcap >= 5000)) return false;

        const y = col.iYield !== null ? getNum(row[col.iYield]) : NaN;
        if (!(isFinite(y) && y >= 2)) return false;

        const p22 = (col.iUnstable22 !== null) ? toPercent_(row[col.iUnstable22], getNum) : NaN;
        const p90 = (col.iUnstable90 !== null) ? toPercent_(row[col.iUnstable90], getNum) : NaN;
        if (!(isFinite(p22) && p22 < 2 && isFinite(p90) && p90 < 2)) return false;

        const cr = col.iCreditRatio !== null ? getNum(row[col.iCreditRatio]) : NaN;
        const days = col.iCreditBuyDays98 !== null ? getNum(row[col.iCreditBuyDays98]) : NaN;
        if (!(isFinite(cr) && cr < 10 && isFinite(days) && days < 3)) return false;

        return true;
      },
      // ★ MIX係数が低い順でソート
      sort: [{ header: 'MIX係数', ascending: true }],
    },

    {
      sheetName: '低ボラ異常増量スイング',
      sourceType: 'analysis',
      freezeCols: 2,
      headers: [
        '証券コード','会社名','(92)低ボラ出来高増','(96)AI基準判定','(97)タイプ分類',
        '(101)直近22日間の値幅不安定率','(103)直近132日間の値幅不安定率','(104)パーフェクトオーダー判定',
        '信用倍率','(98)信用買い残日数',
        '(84)連続日数',
        '(51)終値5日移動平均と終値の移動平均乖離率','(53)終値22日移動平均と終値の移動平均乖離率','(56)終値132日移動平均と終値の移動平均乖離率',
        '(86)10日間上昇率','(90)10日間下落率',
        '(41)直近5日間の高値-安値の値幅のボラティリティ','(42)直近10日間の高値-安値の値幅のボラティリティ',
        '業種','概要','株探','四季','銘偵',
        '時価総額','上場区分','PER','PBR','利回り','終値','前日比','騰落率','出来高',
        '売上高','経常益','最終益','信用日付','信用売り残','信用買い残',
        '(72)β','(73)相関','(74)相対ボラ','(75)残差ボラ','(76)アップサイドβ','(77)ダウンサイドβ','(78)Up Capture','(79)Down Capture'
      ],
      filterFn: ({row, getNum, col}) => {
        const lv = col.iLowVolInc !== null ? getNum(row[col.iLowVolInc]) : NaN;
        if (!(isFinite(lv) && lv >= 3)) return false;

        const per = col.iPer !== null ? getNum(row[col.iPer]) : NaN;
        const pbr = col.iPbr !== null ? getNum(row[col.iPbr]) : NaN;
        if (!(isFinite(per) && per > 0 && isFinite(pbr) && pbr > 0)) return false;

        const p22 = (col.iUnstable22 !== null) ? toPercent_(row[col.iUnstable22], getNum) : NaN;
        const p90 = (col.iUnstable90 !== null) ? toPercent_(row[col.iUnstable90], getNum) : NaN;
        if (!(isFinite(p22) && p22 < 2 && isFinite(p90) && p90 < 2)) return false;

        const cr = col.iCreditRatio !== null ? getNum(row[col.iCreditRatio]) : NaN;
        const days = col.iCreditBuyDays98 !== null ? getNum(row[col.iCreditBuyDays98]) : NaN;
        if (!(isFinite(cr) && cr < 10 && isFinite(days) && days < 5)) return false;

        return true;
      },
      sort: [
        { header: '(92)低ボラ出来高増', ascending: false },
        { header: 'PER', ascending: true },
      ],
    },

    {
      sheetName: 'ロングショート',
      sourceType: 'analysis',
      freezeCols: 2,
      headers: [
        '証券コード','会社名','売買判定',
        '(72)β','(73)相関','(74)相対ボラ','(75)残差ボラ','(76)アップサイドβ','(77)ダウンサイドβ','(78)Up Capture','(79)Down Capture',
        '(80)RSI','(81)RSIの直近22日間の回帰係数','(97)タイプ分類','(104)パーフェクトオーダー判定',
        '業種','概要','株探','四季','銘偵',
        '(51)終値5日移動平均と終値の移動平均乖離率','(53)終値22日移動平均と終値の移動平均乖離率','(56)終値132日移動平均と終値の移動平均乖離率','(86)10日間上昇率','(90)10日間下落率',
        '信用倍率','(98)信用買い残日数','(96)AI基準判定','(101)直近22日間の値幅不安定率','(103)直近132日間の値幅不安定率','(84)連続日数','(82)MACD','(83)MACDの直近22日間の回帰係数',
        '時価総額','上場区分','PER','PBR','利回り','終値','前日比','騰落率','出来高','売上高','経常益','最終益','信用日付','信用売り残','信用買い残'
      ],
      filterFn: ({row, getNum, col}) => {
        const mcap = col.iMcap !== null ? getNum(row[col.iMcap]) : NaN;
        const corr = col.iCorr73 !== null ? getNum(row[col.iCorr73]) : NaN;

        const beta = col.iBeta72 !== null ? getNum(row[col.iBeta72]) : NaN;
        const ub = col.iUpBeta76 !== null ? getNum(row[col.iUpBeta76]) : NaN;
        const db = col.iDownBeta77 !== null ? getNum(row[col.iDownBeta77]) : NaN;

        const upCap = col.iUpCap78 !== null ? toPercent_(row[col.iUpCap78], getNum) : NaN;
        const dnCap = col.iDnCap79 !== null ? toPercent_(row[col.iDnCap79], getNum) : NaN;

        const po = col.iPerfectOrder104 !== null ? getNum(row[col.iPerfectOrder104]) : NaN;

        // 共通の前提
        if (!(isFinite(mcap) && mcap >= 5000)) return { ok:false };
        if (!(isFinite(corr) && corr >= 0.5)) return { ok:false };
        if (!(isFinite(beta) && beta >= 1.0 && beta <= 1.5)) return { ok:false };

        // 条件1（買）
        const condBuy =
          isFinite(ub) && ub >= 1.0 &&
          isFinite(db) && db < 1.0 &&
          isFinite(upCap) && upCap >= 110 &&
          isFinite(dnCap) && dnCap < 90 &&
          (po === 0 || po === 1);

        if (condBuy) return { ok:true, signal:'買' };

        // 条件2（売）
        const condSell =
          isFinite(ub) && ub < 1.0 &&
          isFinite(db) && db >= 1.0 &&
          isFinite(upCap) && upCap < 90 &&
          isFinite(dnCap) && dnCap >= 110 &&
          (po === 0 || po === -1);

        if (condSell) return { ok:true, signal:'売' };

        return { ok:false };
      },
      sort: [
        { header: '売買判定', ascending: false },
        { header: '(73)相関', ascending: false },
        { header: '(72)β', ascending: false },
      ],
    },

    {
      sheetName: '良決算低判定',
      sourceType: 'kessan',
      freezeCols: 2,
      headers: [
        '日付','時刻','証券コード','会社名','速報内容','分類','業種','概要','株探','四季','銘偵',
        '(96)AI基準判定','(97)タイプ分類','(101)直近22日間の値幅不安定率','(103)直近132日間の値幅不安定率','(104)パーフェクトオーダー判定','信用倍率','(98)信用買い残日数',
        '(84)連続日数','(51)終値5日移動平均と終値の移動平均乖離率','(53)終値22日移動平均と終値の移動平均乖離率','(56)終値132日移動平均と終値の移動平均乖離率','(86)10日間上昇率','(90)10日間下落率',
        '(80)RSI','(81)RSIの直近22日間の回帰係数','(82)MACD','(83)MACDの直近22日間の回帰係数',
        '時価総額','上場区分','PER','PBR','利回り','終値','前日比','騰落率','出来高','売上高','経常益','最終益','信用日付','信用売り残','信用買い残',
        '(72)β','(73)相関','(74)相対ボラ','(75)残差ボラ','(76)アップサイドβ','(77)ダウンサイドβ','(78)Up Capture','(79)Down Capture'
      ],
      filterFn: ({row, getNum, col}) => {
        const cls = col.iClass !== null ? String(row[col.iClass] ?? '').trim() : '';
        if (!['上方修正','最高益','増益','黒字浮上'].includes(cls)) return false;

        const ai = col.iAi96 !== null ? getNum(row[col.iAi96]) : NaN;
        if (!(isFinite(ai) && ai <= 3)) return false;

        return true;
      },
      sort: null,

      // ★ 追加：条件に該当するセルを薄赤に（列単位）
      alerts: [
        { header: 'PER', fn: (n) => n >= 16.0 },
        { header: 'PBR', fn: (n) => n >= 1.0 },
        { header: '利回り', fn: (n) => n <= 1.0 },
        { header: '信用倍率', fn: (n) => n >= 1.0 },
      ],
    },

    // =========================
    // ★ 新規：連続日数
    // =========================
    {
      sheetName: '連続日数',
      sourceType: 'analysis',
      freezeCols: 2,
      headers: [
        '証券コード','会社名',
        '(84)連続日数','(96)AI基準判定','(97)タイプ分類','(104)パーフェクトオーダー判定',
        '業種','概要','株探','四季','銘偵',
        '(51)終値5日移動平均と終値の移動平均乖離率',
        '(53)終値22日移動平均と終値の移動平均乖離率',
        '(55)終値66日移動平均と終値の移動平均乖離率',
        '信用倍率','(98)信用買い残日数',
        '(80)RSI','(128)週足RSI','(130)ストキャスティクス%K','(132)週足ストキャスティクス%K','(138)ボリンジャーバンドのσ値',
        '(86)10日間上昇率','(90)10日間下落率','(101)直近22日間の値幅不安定率',
        '時価総額','上場区分','PER','PBR','利回り','終値','出来高','売上高','経常益','最終益','信用日付','信用売り残','信用買い残'
      ],
      filterFn: ({row, getNum, col, idx}) => {
        const iStreak = idx('(84)連続日数');
        const streak = (iStreak !== null) ? getNum(row[iStreak]) : NaN;
        if (!isFinite(streak)) return false;
        return (streak >= 7 || streak <= -7);
      },
      sort: [{ header: '(84)連続日数', ascending: false }],
      alerts: [
        { header: 'PER', fn: (n) => n >= 16.0 },
        { header: 'PBR', fn: (n) => n >= 1.0 },
        { header: '利回り', fn: (n) => n <= 1.0 },
        { header: '信用倍率', fn: (n) => n >= 1.0 },
      ],
    },

    // =========================
    // ★ 新規：オシレーター
    // =========================
    {
      sheetName: 'オシレーター',
      sourceType: 'analysis',
      freezeCols: 2,
      headers: [
        '証券コード','会社名',
        '(80)RSI','(128)週足RSI','(130)ストキャスティクス%K','(132)週足ストキャスティクス%K','(138)ボリンジャーバンドのσ値',
        '(51)終値5日移動平均と終値の移動平均乖離率',
        '(53)終値22日移動平均と終値の移動平均乖離率',
        '(55)終値66日移動平均と終値の移動平均乖離率',
        '業種','概要','株探','四季','銘偵',
        '(96)AI基準判定','(97)タイプ分類','(104)パーフェクトオーダー判定','(84)連続日数',
        '信用倍率','(98)信用買い残日数',
        '(86)10日間上昇率','(90)10日間下落率','(101)直近22日間の値幅不安定率',
        '時価総額','上場区分','PER','PBR','利回り','終値','出来高','売上高','経常益','最終益','信用日付','信用売り残','信用買い残'
      ],
      filterFn: ({row, getNum, idx}) => {
        const rsi   = toNumOrNaN_(row, idx, '(80)RSI', getNum);
        const wrsi  = toNumOrNaN_(row, idx, '(128)週足RSI', getNum);
        const k     = toNumOrNaN_(row, idx, '(130)ストキャスティクス%K', getNum);
        const wk    = toNumOrNaN_(row, idx, '(132)週足ストキャスティクス%K', getNum);
        const sigma = toNumOrNaN_(row, idx, '(138)ボリンジャーバンドのσ値', getNum);
        if (![rsi,wrsi,k,wk,sigma].every(isFinite)) return false;

        const over =
          rsi >= 80 && wrsi >= 80 && k >= 80 && wk >= 80 && sigma >= 1.5;
        const under =
          rsi <= 20 && wrsi <= 20 && k <= 20 && wk <= 20 && sigma <= -1.5;
        return (over || under);
      },
      sort: [{ header: '(80)RSI', ascending: false }],
      alerts: [
        { header: 'PER', fn: (n) => n >= 16.0 },
        { header: 'PBR', fn: (n) => n >= 1.0 },
        { header: '利回り', fn: (n) => n <= 1.0 },
        { header: '信用倍率', fn: (n) => n >= 1.0 },
      ],
    },

    // =========================
    // ★ 新規：タイプ分類
    // =========================
    {
      sheetName: 'タイプ分類',
      sourceType: 'analysis',
      freezeCols: 2,
      headers: [
        '証券コード','会社名',
        '(97)タイプ分類','(96)AI基準判定','(104)パーフェクトオーダー判定','(84)連続日数',
        '業種','概要','株探','四季','銘偵',
        '(51)終値5日移動平均と終値の移動平均乖離率',
        '(53)終値22日移動平均と終値の移動平均乖離率',
        '(55)終値66日移動平均と終値の移動平均乖離率',
        '信用倍率','(98)信用買い残日数',
        '(80)RSI','(128)週足RSI','(130)ストキャスティクス%K','(132)週足ストキャスティクス%K','(138)ボリンジャーバンドのσ値',
        '(86)10日間上昇率','(90)10日間下落率','(101)直近22日間の値幅不安定率',
        '時価総額','上場区分','PER','PBR','利回り','終値','出来高','売上高','経常益','最終益','信用日付','信用売り残','信用買い残'
      ],
      filterFn: ({row, idx}) => {
        const iType = idx('(97)タイプ分類');
        const t = (iType !== null) ? String(row[iType] ?? '').trim() : '';
        return [
          '危険物',
          '只のハイボラ危険株',
          '統計拒否',
          '攻撃的順張り',
          '市場の写像',
          '非対称アルファ',
        ].includes(t);
      },
      sort: [{ header: '(97)タイプ分類', ascending: false }],
      alerts: [
        { header: 'PER', fn: (n) => n >= 16.0 },
        { header: 'PBR', fn: (n) => n >= 1.0 },
        { header: '利回り', fn: (n) => n <= 1.0 },
        { header: '信用倍率', fn: (n) => n >= 1.0 },
      ],
    },
  ];
}

/** デフォルトの「シート1」を削除（他にシートがある場合のみ） */
function deleteDefaultSheetIfExists_(ss) {
  const sh = ss.getSheetByName('シート1');
  if (!sh) return;
  if (ss.getSheets().length <= 1) return; // 0枚にできないため
  ss.deleteSheet(sh);
}



/** =========================
 * マスタ参照（★）
 * ========================= */
function buildMasterLookup_(sheet) {
  const info = getHeaderMap_(sheet);
  const codeCol = info.map['証券コード'];
  if (!codeCol) {
    return { headerMap: info.map, codeToRow: new Map() };
  }

  const lastRow = sheet.getLastRow();
  const lastCol = sheet.getLastColumn();
  if (lastRow < 2 || lastCol < 1) {
    return { headerMap: info.map, codeToRow: new Map() };
  }

  const vals = sheet.getRange(1, 1, lastRow, lastCol).getValues();
  const codeToRow = new Map();
  for (let r = 1; r < vals.length; r++) {
    const code = String(vals[r][codeCol - 1] ?? '').trim();
    if (!code) continue;
    codeToRow.set(code, vals[r]);
  }

  return { headerMap: info.map, codeToRow };
}

function getMasterValue_(lookup, code, header) {
  if (!lookup) return undefined;
  const col = lookup.headerMap[header];
  if (!col) return undefined;
  const row = lookup.codeToRow.get(code);
  if (!row) return undefined;
  return row[col - 1];
}

/** =========================
 * ユーティリティ
 * ========================= */
function getFolderByPath_(pathParts) {
  let folder = DriveApp.getRootFolder();
  for (const name of pathParts) {
    const it = folder.getFoldersByName(name);
    if (!it.hasNext()) {
      throw new Error(`フォルダが見つかりません: ${pathParts.join(' > ')}（途中: ${name}）`);
    }
    folder = it.next();
  }
  return folder;
}

function findLatestDatedFile_(folder, prefix) {
  const files = folder.getFiles();
  let best = null;

  while (files.hasNext()) {
    const f = files.next();
    const name = f.getName();
    if (!name.startsWith(prefix)) continue;

    const m = name.match(new RegExp('^' + escapeRegExp_(prefix) + '(\\d{4}-\\d{2}-\\d{2})$'));
    if (!m) continue;

    const d = m[1];
    if (!best || d > best.date) best = { file: f, name, date: d };
  }
  return best;
}

function openSpreadsheetByNameInFolder_(folder, fileName) {
  const files = folder.getFilesByName(fileName);
  if (!files.hasNext()) throw new Error(`スプレッドシートが見つかりません: ${fileName}`);
  return SpreadsheetApp.openById(files.next().getId());
}

function openOrCreateSpreadsheetInFolder_(folder, name) {
  const it = folder.getFilesByName(name);
  if (it.hasNext()) return SpreadsheetApp.openById(it.next().getId());

  const ss = SpreadsheetApp.create(name);
  const file = DriveApp.getFileById(ss.getId());
  folder.addFile(file);
  DriveApp.getRootFolder().removeFile(file);
  return ss;
}

function ensureSheet_(ss, sheetName) {
  const sh = ss.getSheetByName(sheetName);
  if (sh) return sh;
  return ss.insertSheet(sheetName);
}

function getHeaderMap_(sheet) {
  const lastCol = sheet.getLastColumn();
  if (lastCol < 1) return { headers: [], map: {} };

  const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0].map(h => String(h || '').trim());
  const map = {};
  headers.forEach((h, i) => { if (h) map[h] = i + 1; });
  return { headers, map };
}

function formatHeaderRow_(sheet, width, freezeCols) {
   if (width <= 0) return;
   sheet.setFrozenRows(1);
  // ★ 列固定（例：B列まで固定 → 2）
  if (freezeCols && freezeCols > 0) {
    sheet.setFrozenColumns(freezeCols);
  } else {
    sheet.setFrozenColumns(0);
  }
  sheet.getRange(1, 1, 1, width).setBackground('#FFA500');
}

function getNumber_(v) {
  if (v === null || v === undefined) return NaN;
  if (typeof v === 'number') return v;

  const s = String(v).trim();
  if (!s) return NaN;

  const cleaned = s.replace(/,/g, '').replace(/%/g, '');
  const n = Number(cleaned);
  return isNaN(n) ? NaN : n;
}

function escapeRegExp_(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function toPercent_(v, getNum) {
  const n = getNum(v);
  if (!isFinite(n)) return NaN;
  // 1.10 -> 110 / 110 -> 110
  return (Math.abs(n) <= 3) ? (n * 100) : n;
}

// ★ 見出し行を略称に置換（表示だけ）
function rewriteHeaderAliases_(sheet, aliasMap) {
  const lastCol = sheet.getLastColumn();
  if (lastCol < 1) return;
  const rng = sheet.getRange(1, 1, 1, lastCol);
  const headers = rng.getValues()[0].map(v => String(v ?? '').trim());
  const replaced = headers.map(h => aliasMap[h] ?? h);
  rng.setValues([replaced]);
}

// ★ 条件に該当するセルだけ薄い赤色にする（列単位）
function applyAlertCellColors_(sheet, headers, alerts) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return;

  const headerToCol = new Map();
  headers.forEach((h, i) => headerToCol.set(h, i + 1));

  const LIGHT_RED = '#f4cccc';

  alerts.forEach(rule => {
    const col = headerToCol.get(rule.header);
    if (!col) return;

    const rng = sheet.getRange(2, col, lastRow - 1, 1);
    const vals = rng.getValues(); // 2D
    const bgs = rng.getBackgrounds();

    for (let r = 0; r < vals.length; r++) {
      const v = vals[r][0];
      const n = getNumber_(v);
      if (!isFinite(n)) continue;
      if (rule.fn(n)) {
        bgs[r][0] = LIGHT_RED;
      }
    }
    rng.setBackgrounds(bgs);
  });
}

// ★ idx() + getNum を安全に使うための小物
function toNumOrNaN_(row, idxFn, header, getNum) {
  const i = idxFn(header);
  if (i === null) return NaN;
  return getNum(row[i]);
}

// ★ 作成したシートを spec 順（＝仕様書順）に並べ替える
function reorderSheetsBySpecOrder_(ss, specs) {
  const nameToPos = new Map();
  specs.forEach((s, i) => nameToPos.set(s.sheetName, i + 1)); // 1始まり

  // 仕様に出てくるシートだけを順番に先頭から詰める
  specs.forEach((s, i) => {
    const sh = ss.getSheetByName(s.sheetName);
    if (!sh) return;
    // moveActiveSheet は「アクティブ」対象なので activate してから移動
    sh.activate();
    ss.moveActiveSheet(i + 1);
  });
}




