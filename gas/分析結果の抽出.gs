/**
 * 分析マスタ更新＋分析結果抽出（5分おきトリガー想定）
 *
 * 仕様まとめ
 * (1) 出力結果フォルダから最新日付の
 *   - 全銘柄日足分析_yyyy-MM-dd
 *   - 決算速報_yyyy-MM-dd
 *   をそれぞれ1つずつ選び、前回処理ファイル名（ScriptProperties）と重複が1つでもあれば処理終了。
 * (2) 全銘柄日足分析_yyyy-MM-dd を使って 全銘柄日足分析マスタ を更新。
 *     さらに列差分（新規列追加／旧列削除）を行う。
 *     - 新規列：日足分析ファイルにあるが、基本情報マスタ＆日足分析マスタにない列 → 日足分析マスタへ追加
 *     - 旧列：日足分析マスタにあるが、日足分析ファイルにない、かつ基本情報マスタにもない列 → 日足分析マスタから削除
 * (3) 分析結果の抽出_yyyy-MM-dd を出力結果フォルダに作成し、指定シートを作成して抽出結果を書き出す。
 * (4) メール送信
 */
/**
 * 修正点
 * 1) 出力SS「分析結果の抽出_yyyy-MM-dd」からデフォルト「シート1」を削除（他で使っていない場合）
 * 2) 各シートの見出し列を指定どおりに変更
 * 3) 手動テスト用フラグ：重複チェックをスキップ可能に（skipDupCheck）
 */
/**
 * 追加修正（★）
 * - 抽出時に「処理対象ファイルに存在しない列」があれば、マスタ（基本/日足分析）から補完。無ければ空欄。
 * - 各出力シート共通のフォーマット適用（日付/時刻/右揃え）
 */

const CONFIG = {
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

  // Script Properties Keys
  propLastAnalysisFileName: 'LAST_PROCESSED_ANALYSIS_FILENAME',
  propLastKessanFileName: 'LAST_PROCESSED_KESSAN_FILENAME',

  // Email
  mailTo: 'green3red2000@gmail.com',
};

/**
 * 通常運用（トリガー実行想定）：重複チェックあり
 */
function run_masterUpdate_and_extract_and_mail() {
  return run_masterUpdate_and_extract_and_mail_impl_({ skipDupCheck: false });
}

/**
 * 手動テスト用：（手動実行想定）：重複チェックなし
 */
function run_masterUpdate_and_extract_and_mail_test() {
  return run_masterUpdate_and_extract_and_mail_impl_({ skipDupCheck: true });
}

function run_masterUpdate_and_extract_and_mail_impl_(opt) {
  const { skipDupCheck } = opt || { skipDupCheck: false };

  const tz = Session.getScriptTimeZone();
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
  const lastAnalysisName = props.getProperty(CONFIG.propLastAnalysisFileName) || '';
  const lastKessanName = props.getProperty(CONFIG.propLastKessanFileName) || '';

  // 一つでも重複があれば終了（ただしテスト時はスキップ）
  if (!skipDupCheck) {
    if (latestAnalysis.name === lastAnalysisName || latestKessan.name === lastKessanName) {
      console.log('ファイルが未更新のため処理なし');
      console.log(`前回: analysis=${lastAnalysisName}, kessan=${lastKessanName}`);
      console.log(`今回: analysis=${latestAnalysis.name}, kessan=${latestKessan.name}`);
      return;
    }
  } else {
    console.log('[TEST] skipDupCheck=true のため、重複チェックをスキップします');
  }

  // (2) マスタ更新（列差分＋値更新）
  const masterFolder = getFolderByPath_(CONFIG.masterFolderPath);

  const ssAnalysis = SpreadsheetApp.openById(latestAnalysis.file.getId());
  const shAnalysis = ssAnalysis.getSheets()[0];

  const ssBaseMaster = openSpreadsheetByNameInFolder_(masterFolder, CONFIG.masterBaseInfoName);
  const shBaseMaster = ssBaseMaster.getSheets()[0];

  const ssAnalysisMaster = openSpreadsheetByNameInFolder_(masterFolder, CONFIG.masterAnalysisName);
  const shAnalysisMaster = ssAnalysisMaster.getSheets()[0];

  syncAnalysisMasterColumns_({
    shBaseMaster,
    shAnalysisMaster,
    shAnalysisSource: shAnalysis,
  });

  updateAnalysisMasterValues_({
    shAnalysisMaster,
    shAnalysisSource: shAnalysis,
  });

  // ★ 抽出で参照するマスタLookupを作成（更新後のマスタを使う）
  const baseMasterLookup = buildMasterLookup_(shBaseMaster);
  const analysisMasterLookup = buildMasterLookup_(shAnalysisMaster);

  // (3) 分析結果の抽出
  const outName = `${CONFIG.prefixOutput}${todayStr}`;
  const ssOut = openOrCreateSpreadsheetInFolder_(outputFolder, outName);

  buildExtractionSheets_({
    ssOut,
    shAnalysisSource: shAnalysis,
    shKessanSource: SpreadsheetApp.openById(latestKessan.file.getId()).getSheets()[0],
    baseMasterLookup,
    analysisMasterLookup,
  });

  // デフォルト「シート1」削除（残っている場合）
  deleteDefaultSheetIfExists_(ssOut);

  // (4) メール送信
  const mailSubject = `分析結果の抽出：${todayMailStr}`;
  const mailBody =
`本日の全銘柄日足分析マスタ更新と分析結果の抽出を終了しました。
処理対象のファイルは以下でした。

${latestAnalysis.name}：
${latestKessan.name}：

抽出結果は以下です。
${ssOut.getUrl()}
`;
  MailApp.sendEmail(CONFIG.mailTo, mailSubject, mailBody);

  // 正常終了したら今回処理名を保存
  props.setProperty(CONFIG.propLastAnalysisFileName, latestAnalysis.name);
  props.setProperty(CONFIG.propLastKessanFileName, latestKessan.name);

  console.log('完了');
}

/** =========================
 * (3) 抽出シート作成
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

    // 見出し行の装飾
    formatHeaderRow_(sh, spec.headers.length);

    // ★ 共通フォーマット適用（存在する列のみ）
    applyCommonFormats_(sh, spec.headers);
  });
}

function extractRows_(sourceSheet, spec, masterCtx) {
  const srcInfo = getHeaderMap_(sourceSheet);
  const values = sourceSheet.getDataRange().getValues();
  if (values.length < 2) return { rows: [], sort: spec.sort };

  const idx = (name) => srcInfo.map[name] ? (srcInfo.map[name] - 1) : null;

  // よく使う列（条件用）
  const iCode = idx('証券コード');
  const iName = idx('会社名');

  const iAi96 = idx('(96) AI基準判定');
  const iReg22 = idx('(32)終値22日移動平均の直近10日の回帰係数');
  const iReg45 = idx('(33)終値45日移動平均の直近10日の回帰係数');
  const iReg90 = idx('(34)終値90日移動平均の直近10日の回帰係数');

  const iPer = idx('PER');
  const iPbr = idx('PBR');
  const iYield = idx('利回り');
  const iMcap = idx('時価総額');

  const iLowVolInc = idx('(92) 低ボラ出来高増');

  const iBeta72 = idx('(72) β') || idx('β');
  const iCorr73 = idx('(73) 相関') || idx('相関係数') || idx('相関');
  const iRelVol74 = idx('(74) 相対ボラ') || idx('相対ボラ');
  const iResVol75 = idx('(75) 残差ボラ') || idx('残差ボラ');
  const iUpB76 = idx('(76) アップサイドβ') || idx('アップサイドβ');
  const iDownB77 = idx('(77) ダウンサイドβ') || idx('ダウンサイドβ');
  const iUpCap78 = idx('(78) Up Capture') || idx('Up Capture');
  const iDownCap79 = idx('(79) Down Capture') || idx('Down Capture');

  const iRsi80 = idx('(80) RSI') || idx('RSI');
  const iRsiReg81 = idx('(81) RSIの直近22日間の回帰係数') || idx('RSIの直近22日間の回帰係数');
  const iMacd82 = idx('(82) MACD') || idx('MACD');
  const iMacdReg83 = idx('(83) MACDの直近22日間の回帰係数') || idx('MACDの直近22日間の回帰係数');

  const iClass = idx('分類');
  const iCreditRatio = idx('信用倍率');

  const rows = [];

  for (let r = 1; r < values.length; r++) {
    const row = values[r];
    const code = iCode !== null ? String(row[iCode] ?? '').trim() : '';
    const name = iName !== null ? String(row[iName] ?? '').trim() : '';
    if (!code) continue;

    if (spec.filterFn) {
      const pass = spec.filterFn({
        row,
        idx,
        getNum: getNumber_,
        code,
        name,
        col: {
          iAi96, iReg22, iReg45, iReg90,
          iPer, iPbr, iYield, iMcap,
          iLowVolInc,
          iBeta72, iCorr73, iRelVol74, iResVol75, iUpB76, iDownB77, iUpCap78, iDownCap79,
          iRsi80, iRsiReg81, iMacd82, iMacdReg83,
          iClass, iCreditRatio,
        },
      });
      if (!pass) continue;
    }

    const outRow = spec.headers.map(h => {
      if (h === 'MIX係数') {
        const per = iPer !== null ? getNumber_(row[iPer]) : NaN;
        const pbr = iPbr !== null ? getNumber_(row[iPbr]) : NaN;
        if (isFinite(per) && isFinite(pbr) && per > 0 && pbr > 0) {
          return Math.floor(per * pbr * 100) / 100;
        }
        return '';
      }
      if (h === '株探') return `=HYPERLINK("https://kabutan.jp/stock/news?code=${code}","株")`;
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

  const applyNumberFormat = (h, fmt) => {
    const col = headerToCol.get(h);
    if (!col) return;
    sheet.getRange(2, col, lastRow - 1, 1).setNumberFormat(fmt);
  };

  const applyAlign = (h, align) => {
    const col = headerToCol.get(h);
    if (!col) return;
    sheet.getRange(2, col, lastRow - 1, 1).setHorizontalAlignment(align);
  };

  // ★ 日付/時刻/信用日付
  applyNumberFormat('日付', 'yyyy-MM-dd');
  applyNumberFormat('時刻', 'HH:mm:ss');
  applyNumberFormat('信用日付', 'yyyy-MM-dd');

  // ★ 右揃え（既存）
  applyAlign('証券コード', 'right');
  applyAlign('前日比', 'right');
  applyAlign('騰落率', 'right');
  applyAlign('信用倍率', 'right');

  // ★（既存）売上/利益系は数値表示（=日付誤解釈防止）＋右揃え
  applyNumberFormat('売上高', '#,##0');
  applyNumberFormat('経常益', '#,##0');
  applyNumberFormat('最終益', '#,##0');

  applyAlign('売上高', 'right');
  applyAlign('経常益', 'right');
  applyAlign('最終益', 'right');

  // ★（追加）右揃え
  applyAlign('PER', 'right');
  applyAlign('PBR', 'right');
  applyAlign('利回り', 'right');
  applyAlign('出来高', 'right');

  // ★（追加）中央揃え（リンク列）
  applyAlign('株探', 'center');
  applyAlign('四季', 'center');
  applyAlign('銘偵', 'center');
}


/** =========================
 * 抽出仕様（見出し列は前回指定のまま）
 * ========================= */
function getExtractionSpecs_() {
  return [
    {
      sheetName: '中小型順張りスイング',
      sourceType: 'analysis',
      headers: [
        '証券コード','会社名','(96) AI基準判定','MIX係数','業種','概要','株探','四季','銘偵',
        '時価総額','上場区分','PER','PBR','利回り','終値','前日比','騰落率','出来高',
        '売上高','経常益','最終益','信用日付','信用売り残','信用買い残','信用倍率',
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
        if (!(isFinite(mix) && mix < 10)) return false;

        const mcap = col.iMcap !== null ? getNum(row[col.iMcap]) : NaN;
        if (!(isFinite(mcap) && mcap <= 1100)) return false;

        const y = col.iYield !== null ? getNum(row[col.iYield]) : NaN;
        if (!(isFinite(y) && y >= 1)) return false;

        return true;
      },
      sort: [{ header: 'PER', ascending: true }],
    },

    {
      sheetName: '大型順張りスイング',
      sourceType: 'analysis',
      headers: [
        '証券コード','会社名','(96) AI基準判定','MIX係数','業種','概要','株探','四季','銘偵',
        '時価総額','上場区分','PER','PBR','利回り','終値','前日比','騰落率','出来高',
        '売上高','経常益','最終益','信用日付','信用売り残','信用買い残','信用倍率',
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

        return true;
      },
      sort: [{ header: 'PER', ascending: true }],
    },

    {
      sheetName: '低ボラ異常増量スイング',
      sourceType: 'analysis',
      headers: [
        '証券コード','会社名','(92) 低ボラ出来高増','(96) AI基準判定',
        '(41)直近5日間の高値-安値の値幅のボラティリティ','(42)直近10日間の高値-安値の値幅のボラティリティ',
        '(72) β','(73) 相関','(74) 相対ボラ','(75) 残差ボラ','(76) アップサイドβ','(77) ダウンサイドβ','(78) Up Capture','(79) Down Capture',
        '業種','概要','株探','四季','銘偵','時価総額','上場区分','PER','PBR','利回り','終値','前日比','騰落率','出来高',
        '売上高','経常益','最終益','信用日付','信用売り残','信用買い残','信用倍率',
      ],
      filterFn: ({row, getNum, col}) => {
        const lv = col.iLowVolInc !== null ? getNum(row[col.iLowVolInc]) : NaN;
        if (!(isFinite(lv) && lv >= 3)) return false;

        const per = col.iPer !== null ? getNum(row[col.iPer]) : NaN;
        const pbr = col.iPbr !== null ? getNum(row[col.iPbr]) : NaN;
        if (!(isFinite(per) && per > 0 && isFinite(pbr) && pbr > 0)) return false;

        return true;
      },
      sort: [
        { header: '(92) 低ボラ出来高増', ascending: false },
        { header: 'PER', ascending: true },
      ],
    },

    {
      sheetName: 'ロングショート',
      sourceType: 'analysis',
      headers: [
        '証券コード','会社名',
        '(72) β','(73) 相関','(74) 相対ボラ','(75) 残差ボラ','(76) アップサイドβ','(77) ダウンサイドβ','(78) Up Capture','(79) Down Capture',
        '(80) RSI','(81) RSIの直近22日間の回帰係数','(82) MACD','(83) MACDの直近22日間の回帰係数','(96) AI基準判定',
        '業種','概要','株探','四季','銘偵','時価総額','上場区分',
        'PER','PBR','利回り','終値','前日比','騰落率','出来高','売上高','経常益','最終益','信用日付','信用売り残','信用買い残','信用倍率',
      ],
      filterFn: ({row, getNum, col}) => {
        const corr = col.iCorr73 !== null ? getNum(row[col.iCorr73]) : NaN;
        if (!(isFinite(corr) && corr >= 0.5)) return false;

        const mcap = col.iMcap !== null ? getNum(row[col.iMcap]) : NaN;
        if (!(isFinite(mcap) && mcap >= 5000)) return false;

        return true;
      },
      sort: [{ header: '(80) RSI', ascending: false }],
    },

    {
      sheetName: '良決算低判定',
      sourceType: 'kessan',
      headers: [
        '日付','時刻','証券コード','会社名','速報内容','分類','業種','概要','株探','四季','銘偵',
        '時価総額','上場区分','PER','PBR','利回り','終値','前日比','騰落率','出来高','売上高','経常益','最終益',
        '信用日付','信用売り残','信用買い残','信用倍率',
        '(96) AI基準判定',
        '(72) β','(73) 相関','(74) 相対ボラ','(75) 残差ボラ','(76) アップサイドβ','(77) ダウンサイドβ','(78) Up Capture','(79) Down Capture',
        '(80) RSI','(81) RSIの直近22日間の回帰係数','(82) MACD','(83) MACDの直近22日間の回帰係数',
      ],
      filterFn: ({row, getNum, col}) => {
        const cls = col.iClass !== null ? String(row[col.iClass] ?? '').trim() : '';
        if (!['上方修正','最高益','増益','黒字浮上'].includes(cls)) return false;

        const per = col.iPer !== null ? getNum(row[col.iPer]) : NaN;
        if (!(isFinite(per) && per <= 60.0)) return false;

        const pbr = col.iPbr !== null ? getNum(row[col.iPbr]) : NaN;
        if (!(isFinite(pbr) && pbr <= 5.0)) return false;

        const y = col.iYield !== null ? getNum(row[col.iYield]) : NaN;
        if (!(isFinite(y) && y >= 0)) return false;

        const cr = col.iCreditRatio !== null ? getNum(row[col.iCreditRatio]) : NaN;
        if (!(isFinite(cr) && cr <= 1.5)) return false;

        const ai = col.iAi96 !== null ? getNum(row[col.iAi96]) : NaN;
        if (!(isFinite(ai) && ai <= 10)) return false;

        return true;
      },
      sort: null, // 並び順変更なし
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
 * (2) 列差分（追加／削除）
 * ========================= */
function syncAnalysisMasterColumns_(args) {
  const { shBaseMaster, shAnalysisMaster, shAnalysisSource } = args;

  const baseHeaders = getHeaderMap_(shBaseMaster).headers;
  const masterHeadersInfo = getHeaderMap_(shAnalysisMaster);
  const srcHeadersInfo = getHeaderMap_(shAnalysisSource);

  const baseSet = new Set(baseHeaders);
  const masterSet = new Set(masterHeadersInfo.headers);
  const srcSet = new Set(srcHeadersInfo.headers);

  // ①新しい列：sourceにあるが、基本＆分析マスタにない → 分析マスタへ追加
  const toAdd = srcHeadersInfo.headers.filter(h => !baseSet.has(h) && !masterSet.has(h));
  if (toAdd.length > 0) {
    const lastCol = shAnalysisMaster.getLastColumn();
    shAnalysisMaster.insertColumnsAfter(lastCol, toAdd.length);
    shAnalysisMaster.getRange(1, lastCol + 1, 1, toAdd.length).setValues([toAdd]);
  }

  // ②古い列：分析マスタにあるが、sourceにない、かつ基本マスタにもない → 分析マスタから削除
  const refreshedMasterHeadersInfo = getHeaderMap_(shAnalysisMaster);
  const refreshedMasterHeaders = refreshedMasterHeadersInfo.headers;

  const toDelete = [];
  for (let i = 0; i < refreshedMasterHeaders.length; i++) {
    const h = refreshedMasterHeaders[i];
    if (!srcSet.has(h) && !baseSet.has(h)) {
      toDelete.push(i + 1);
    }
  }
  toDelete.sort((a, b) => b - a).forEach(colIdx => shAnalysisMaster.deleteColumn(colIdx));
}

/** =========================
 * (2) 値更新（同名ヘッダで更新）
 * ========================= */
function updateAnalysisMasterValues_(args) {
  const { shAnalysisMaster, shAnalysisSource } = args;

  const masterInfo = getHeaderMap_(shAnalysisMaster);
  const srcInfo = getHeaderMap_(shAnalysisSource);

  const masterCodeCol = masterInfo.map['証券コード'];
  const srcCodeCol = srcInfo.map['証券コード'];
  if (!masterCodeCol || !srcCodeCol) throw new Error('「証券コード」列が見つかりません。');

  const masterLastRow = shAnalysisMaster.getLastRow();
  const masterLastCol = shAnalysisMaster.getLastColumn();
  if (masterLastRow < 2) return;

  const masterValues = shAnalysisMaster.getRange(1, 1, masterLastRow, masterLastCol).getValues();

  const srcLastRow = shAnalysisSource.getLastRow();
  const srcLastCol = shAnalysisSource.getLastColumn();
  if (srcLastRow < 2) return;

  const srcValues = shAnalysisSource.getRange(1, 1, srcLastRow, srcLastCol).getValues();

  const codeToMasterRow = new Map();
  for (let r = 1; r < masterValues.length; r++) {
    const code = String(masterValues[r][masterCodeCol - 1] ?? '').trim();
    if (code) codeToMasterRow.set(code, r);
  }

  const commonHeaders = masterInfo.headers.filter(h => srcInfo.map[h]);

  for (let r = 1; r < srcValues.length; r++) {
    const code = String(srcValues[r][srcCodeCol - 1] ?? '').trim();
    if (!code) continue;

    const mr = codeToMasterRow.get(code);
    if (mr === undefined) continue;

    for (const h of commonHeaders) {
      const mCol = masterInfo.map[h] - 1;
      const sCol = srcInfo.map[h] - 1;
      masterValues[mr][mCol] = srcValues[r][sCol];
    }
  }

  shAnalysisMaster.getRange(1, 1, masterLastRow, masterLastCol).setValues(masterValues);
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

function formatHeaderRow_(sheet, width) {
  if (width <= 0) return;
  sheet.setFrozenRows(1);
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
