/**
 * 売買シミュレーション：行ごと順次処理（全シート対象）
 * - マイドライブ > 投資 > 売買シミュレーション
 * - 1–2行目: ユーザー定義＆サマリ領域
 * - 3行目: 見出し
 * - 4行目以降: データ行
 * - タイムゾーン: Asia/Tokyo
 * - 価格は「全銘柄日足分析マスタ」から当日分（始値/終値）を参照
 * - 決済値/建て日/日付処理の実行タイミングは 23:30～23:59
 */

/**
 * トリガー専用（時間主導型は event オブジェクトを渡してくるので引数を受けない）
 */
function runSimulation() {
  runSimulation_(); // 引数なし＝現在日・現在時刻で処理
}

/**
 * テスト実行
 * - 処理日: YYYY-MM-DD
 * - 擬似現在時刻: HH:mm
 *
 * 例）建て日/決済値の確定ウィンドウ（23:30–23:59）を再現したい時に使う
 */
function runSimulation_TEST() {
  runSimulation_('2026-04-02', '23:45');
}

function runSimulation_(processDateISO /* optional: 'yyyy-MM-dd' */,
                       fakeNowHHmm    /* optional: 'HH:mm' */) {
  const TZ = 'Asia/Tokyo';

  // 処理日と現在時刻を指定できる（未指定なら実時刻）
  const realNow = new Date();
  const todayISO = Utilities.formatDate(realNow, TZ, 'yyyy-MM-dd');

  // 処理日（デフォルトは現在日）
  const procISO = (processDateISO && String(processDateISO).trim())
    ? String(processDateISO).trim()
    : todayISO;
  const procSlash = procISO.replace(/-/g, '/');
  const procDateObj = parseDateLoose_(procISO, TZ); // 00:00:00

  // “現在時刻”の扱い：
  // - fakeNowHHmm があれば (procISO + fakeNow) を擬似nowにする
  // - なければ realNow を使う
  const now = (fakeNowHHmm && String(fakeNowHHmm).trim())
    ? buildDateTimeFromISOAndHHmm_(procISO, String(fakeNowHHmm).trim(), TZ)
    : realNow;
  const minutes = getTokyoMinutesFromMidnight_(now, TZ); // 0..1439

  const file = getSpreadsheetFileFromMyDrive_('投資', '売買シミュレーション');
  if (!file) throw new Error('「投資/売買シミュレーション」のスプレッドシートが見つかりません。');

  // 全銘柄日足分析マスタ（当日分のみ）を読み込み
  const masterMap = loadZenmeigaraNisshiMasterTodayMap_(procISO, TZ);

  // 全銘柄基本情報マスタを読み込み
  const basicInfoMap = loadZenmeigaraBasicInfoMasterMap_();

  const ss = SpreadsheetApp.open(file);
  const sheets = ss.getSheets();
  const processedForMail = []; // {code, days, price}
  const debugStats = createDebugStats_();

  for (const sh of sheets) {
    processSheet_(
      sh,
      minutes,
      procISO,
      procDateObj,
      TZ,
      processedForMail,
      masterMap,
      basicInfoMap,
      debugStats
    );
  }

    // (5) メール送信 — 件数が0なら送信しない
  if (processedForMail.length > 0) {
    const to = 'green3red2000@gmail.com';
    const subject = `売買シミュレーション：${procSlash}`;
    const currentTime = Utilities.formatDate(now, TZ, 'HH:mm');

    const body =
`処理件数は、${processedForMail.length}件でした。

処理日：${procISO}
現在時刻：${currentTime}
現在時刻を0時からの経過分数に変換した値：${minutes}
走査行数：${debugStats.rowsScanned}
経過日数を更新した件数：${debugStats.updatedElapsed}
経過日数が既に最新だった件数：${debugStats.elapsedAlreadyUpToDate}
建て日が空白だった件数：${debugStats.buildDateBlank}
建て日が「始値」または「終値」のまま未確定だった件数：${debugStats.buildDateNotFixed}
建て日が不正値だった件数：${debugStats.buildDateInvalid}
「停止」が「〇」のためスキップした件数：${debugStats.skipStopped}
全銘柄日足分析マスタから始値を取得できなかった件数：${debugStats.missMasterOpen}
全銘柄日足分析マスタから終値を取得できなかった件数：${debugStats.missMasterClose}
「建値」列が存在しなかった件数：${debugStats.missBuildPriceCol}

スプレットシート：
${file.getUrl()}
`;

    MailApp.sendEmail(to, subject, body);
  }

  Logger.log(`処理件数: ${processedForMail.length}`);
  // ★ 0件だったときに理由をログ出力
  if (processedForMail.length === 0) {
    logZeroReason_(debugStats, procISO, minutes, TZ, now);
  }
}

/** シート単位の処理（見出しは3行目、データは4行目〜） */
function processSheet_(
  sheet,
  minutes,
  procISO,
  procDateObj,
  TZ,
  processedForMail,
  masterMap,
  basicInfoMap,
  debugStats
) {

  const lastRow = sheet.getLastRow();
  const lastCol = sheet.getLastColumn();
  if (lastRow < 4 || lastCol < 1) return;

  // ヘッダー取得 & マッピング（3行目が見出し）
  const header = sheet.getRange(3, 1, 1, lastCol).getValues()[0].map(v => String(v).trim());
  const col = colIndexMap_(header, [
    '証券コード','会社名','売買','動機・着想','株数',
    '株探','四季','銘偵','全銘',
    '建て日','建値','経過日数','騰落率','決済値','決済日数',
    '損益','確定損益',
    '＋0','＋1','＋2','＋3','＋4','＋5','＋6','＋7',
    '＋10','＋22','＋45','＋90','＋120','＋180','＋365',
    '停止','評価・備考',
    'PER','PBR','利回り','信用倍率','特色','連結事業'
  ]);
  if (col['証券コード'] === undefined) return;

  const DATA_START = 4; // 4行目から処理
  let rowsCount = 0;    // サマリ対象範囲の行数（4行目から空行直前まで）

  // ★ 決済値/建て日/日付処理の確定タイミングを 23:30–23:59 に統一
  const isExecWindow = isInRange_(minutes, 23, 30, 23, 59);

  for (let r = DATA_START; r <= lastRow; r++) {
    const codeRaw = sheet.getRange(r, col['証券コード']).getValue();
    const code = normalizeCode_(codeRaw);
    if (!code) break; // 空行で終了
    rowsCount++;
    debugStats.rowsScanned += 1;

    // (1) 停止=「〇」ならスキップ
    if (col['停止'] !== undefined) {
      const stopFlag = String(sheet.getRange(r, col['停止']).getValue() || '').trim();
      if (stopFlag === '〇') {
        debugStats.skipStopped += 1;
        continue;
      }
    }

    // (2) 参考項目の書き込み
    writeReferenceItems_(sheet, r, col, code, basicInfoMap);


    /* =========================================================
     * (3) 決済値
     *  - 「〇」 → 時刻帯で「始値/終値」をセット（15:30–23:59は何もしない）
     *  - 「始値/終値」 → 23:30–23:59 のみマスタ参照して数値を書き込み、損益計算
     * ========================================================= */
    if (col['決済値'] !== undefined) {
      const cell = sheet.getRange(r, col['決済値']);
      const v = String(cell.getValue() || '').trim();

      if (v === '〇') {
        if (isInRange_(minutes, 0, 0, 8, 59)) {
          cell.setValue('始値');
        } else if (isInRange_(minutes, 9, 0, 15, 29)) {
          cell.setValue('終値');
        } else {
          // 15:30–23:59 は何もしない
        }
      } else if (v === '始値') {
        if (isExecWindow) {
          const px = getMasterTodayPrice_(masterMap, code, 'open');
          if (px != null) {
            cell.setValue(px);
            if (col['経過日数'] !== undefined && col['決済日数'] !== undefined) {
              sheet.getRange(r, col['決済日数']).setValue(sheet.getRange(r, col['経過日数']).getValue());
            }
            writePnLToBothIfPossible_(sheet, r, col, px);
          }else {
            debugStats.missMasterOpen += 1;
          }
        }
      } else if (v === '終値') {
        if (isExecWindow) {
          const px = getMasterTodayPrice_(masterMap, code, 'close');
          if (px != null) {
            cell.setValue(px);
            if (col['経過日数'] !== undefined && col['決済日数'] !== undefined) {
              sheet.getRange(r, col['決済日数']).setValue(sheet.getRange(r, col['経過日数']).getValue());
            }
            writePnLToBothIfPossible_(sheet, r, col, px);
          }else {
            debugStats.missMasterClose += 1;
          }
        }
      }
    }

    /* =========================================================
     * (4) 建て日
     *  - 空白 → 時刻帯で「始値/終値」をセット
     *  - 「始値/終値」 → 23:30–23:59 のみマスタ参照して建値を書き、建て日を日付へ
     *     ※日付を書いたら、同じ行内で「日付の場合」処理に遷移する（★）
     *  - 日付 → 23:30–23:59 のみ 経過日数等を更新（★）
     * ========================================================= */
    if (col['建て日'] !== undefined) {
      const buildDateRange = sheet.getRange(r, col['建て日']);
      let buildDateCell = buildDateRange.getValue();
      let buildDateStr = (typeof buildDateCell === 'string') ? buildDateCell.trim() : buildDateCell;

      const buildDateIsEmpty = (buildDateStr === '' || buildDateStr === null);

      if (buildDateIsEmpty) {
        debugStats.buildDateBlank += 1;
        // 空白 -> 時刻帯で「始値/終値」を書き出す
        if (isInRange_(minutes, 0, 0, 8, 59) || isInRange_(minutes, 15, 30, 23, 59)) {
          buildDateRange.setValue('始値');
        } else if (isInRange_(minutes, 9, 0, 15, 29)) {
          buildDateRange.setValue('終値');
        }
        // 空白処理の時点では、当日値取得はしない
      } else {
        // 「始値」「終値」の場合：23:30–23:59 のみ建値確定→建て日を日付に
        if (buildDateStr === '始値') {
          debugStats.buildDateNotFixed += 1; // ★ 建て日未確定（始値/終値のまま）
          if (isExecWindow) {
            const px = getMasterTodayPrice_(masterMap, code, 'open');
            if (px != null && col['建値'] !== undefined) {
              sheet.getRange(r, col['建値']).setValue(px);
              // ★ 「現在日」ではなく「処理日」を書く
              buildDateRange.setValue(procDateObj);
              // ★ 日付を書いたので、この行内で「日付の場合」処理へ遷移させる
              buildDateCell = buildDateRange.getValue();
              buildDateStr = buildDateCell;
            } else {
              if (px == null) debugStats.missMasterOpen += 1;
              if (col['建値'] === undefined) debugStats.missBuildPriceCol += 1;
              // 取れないなら、日付にも変えない
              continue;
            }
          } else {
            // 0:00–23:29 は何もしない
            continue;
          }
        } else if (buildDateStr === '終値') {
          debugStats.buildDateNotFixed += 1; // ★ 建て日未確定（始値/終値のまま）
          if (isExecWindow) {
            const px = getMasterTodayPrice_(masterMap, code, 'close');
            if (px != null && col['建値'] !== undefined) {
              sheet.getRange(r, col['建値']).setValue(px);
              // ★ 「現在日」ではなく「処理日」を書く
              buildDateRange.setValue(procDateObj);
              // ★ 日付を書いたので、この行内で「日付の場合」処理へ遷移させる
              buildDateCell = buildDateRange.getValue();
              buildDateStr = buildDateCell;
            } else {
              if (px == null) debugStats.missMasterClose += 1;
              if (col['建値'] === undefined) debugStats.missBuildPriceCol += 1;
              continue;
            }
          } else {
            // 0:00–23:29 は何もしない
            continue;
          }
        }

        // 日付の場合：23:30–23:59 のみ更新
        const isDateValue = (buildDateCell instanceof Date) || looksLikeDateString_(buildDateStr);
        if (isDateValue) {
          if (!isExecWindow) {
            // ★ 0:00–23:29 は何もしない
            continue;
          }

          const buildDate = (buildDateCell instanceof Date) ? buildDateCell : parseDateLoose_(buildDateStr, TZ);
          // ★ 経過日数は「処理日」を基準にする
          const elapsed = diffDays_(stripTime_(buildDate, TZ), stripTime_(procDateObj, TZ));

          const currentElapsed = (col['経過日数'] !== undefined)
            ? toNumberOrNull_(sheet.getRange(r, col['経過日数']).getValue())
            : null;

          if (col['経過日数'] !== undefined && elapsed !== currentElapsed) {
            // ★ 先にマスタ（終値）を取得し、正常時のみ以下を実行
            const endPrice = getMasterTodayPrice_(masterMap, code, 'close');
            if (endPrice != null) {
              // ＋列更新（+10 は 8～10 上書き）
              writePlusColumns_(sheet, r, col, elapsed, endPrice);

              // 騰落率 = (取得値 / 建値 - 1) * 100、小数第2位切り捨て
              if (col['騰落率'] !== undefined && col['建値'] !== undefined) {
                const cost = toNumberOrNull_(sheet.getRange(r, col['建値']).getValue());
                if (cost != null && cost !== 0) {
                  const raw = ((endPrice / cost) - 1) * 100;
                  const floored2 = Math.floor(raw * 100) / 100;
                  sheet.getRange(r, col['騰落率']).setValue(floored2);
                }
              }

              // 損益更新（確定損益は決済処理時に更新）
              writePnLIfPossible_(sheet, r, col, endPrice);

              // メール用に保持
              processedForMail.push({ code, days: elapsed, price: endPrice });

              // ★ 正常に取れた時だけ「経過日数」を書き込む
              sheet.getRange(r, col['経過日数']).setValue(elapsed);
              debugStats.updatedElapsed += 1;
            }else {
              debugStats.missMasterClose += 1;
            }
          }else {
            // ★ 「変化なし」ではなく分かる文言にする
            debugStats.elapsedAlreadyUpToDate += 1; // 経過日数更新なし
          }
        }else {
          // 日付でも始値/終値でもない（想定外文字列など）
          debugStats.buildDateInvalid += 1;
        }
      }
    }   
  }

  // (4) サマリ処理
  runSummary_(sheet, col, DATA_START, rowsCount);

  
}

/* ====== サマリ処理 ====== */
function runSummary_(sheet, col, DATA_START, rowsCount) {
  if (rowsCount <= 0) {
    sheet.getRange('L1').setValue(0);
    sheet.getRange('L2').setValue(0);
    sheet.getRange('O1').setValue(0);
    sheet.getRange('O2').setValue(0);
    sheet.getRange('S1').setValue(0);
    return;
  }
  if (col['損益'] === undefined && col['確定損益'] === undefined) {
    sheet.getRange('L1').setValue(0);
    sheet.getRange('L2').setValue(0);
    sheet.getRange('O1').setValue(0);
    sheet.getRange('O2').setValue(0);
    sheet.getRange('S1').setValue(0);
    return;
  }

  let cntConfirmedBase = 0;
  let cntConfirmedWin  = 0;
  let cntOverallBase   = 0;
  let cntOverallWin    = 0;
  let sumConfirmedPnL  = 0;
  let sumUnrealizedPnL = 0;
  let sumInvestAmount  = 0;

  const plusColsRightToLeft = [
    '＋365','＋180','＋120','＋90','＋45','＋22','＋10','＋7','＋6','＋5','＋4','＋3','＋2','＋1','＋0'
  ];

  const lastRow = sheet.getLastRow();
  for (let r = DATA_START; r < DATA_START + rowsCount && r <= lastRow; r++) {
    const confirmed = (col['確定損益'] !== undefined) ? toNumberOrNull_(sheet.getRange(r, col['確定損益']).getValue()) : null;
    const pnl       = (col['損益']      !== undefined) ? toNumberOrNull_(sheet.getRange(r, col['損益']).getValue())      : null;

    if (confirmed != null) {
      cntConfirmedBase += 1;
      if (confirmed > 0) cntConfirmedWin += 1;
      sumConfirmedPnL += confirmed;
    }

    if (confirmed != null || pnl != null) {
      cntOverallBase += 1;
      const basis = (confirmed != null) ? confirmed : pnl;
      if (basis != null && basis > 0) cntOverallWin += 1;
    }

    if (confirmed == null && pnl != null) {
      sumUnrealizedPnL += pnl;
    }

    if (confirmed == null && col['株数'] !== undefined) {
      const qty = toNumberOrNull_(sheet.getRange(r, col['株数']).getValue());
      if (qty != null && qty !== 0) {
        let priceFromPlus = null;
        for (const name of plusColsRightToLeft) {
          if (col[name] === undefined) continue;
          const v = toNumberOrNull_(sheet.getRange(r, col[name]).getValue());
          if (v != null) { priceFromPlus = v; break; }
        }
        if (priceFromPlus != null) {
          sumInvestAmount += qty * priceFromPlus;
        }
      }
    }
  }

  const confirmedRate = (cntConfirmedBase > 0) ? Number(((cntConfirmedWin / cntConfirmedBase) * 100).toFixed(1)) : 0;
  const overallRate   = (cntOverallBase   > 0) ? Number(((cntOverallWin   / cntOverallBase)   * 100).toFixed(1)) : 0;

  sheet.getRange('L1').setValue(confirmedRate);
  sheet.getRange('L2').setValue(overallRate);
  sheet.getRange('O1').setValue(sumConfirmedPnL || 0);
  sheet.getRange('O2').setValue(sumUnrealizedPnL || 0);
  sheet.getRange('S1').setValue(sumInvestAmount || 0);
}

/* ====== 全銘柄日足分析マスタ読み取り ====== */
/**
 * 「投資/プログラミング/GAS/マスタ/全銘柄日足分析マスタ」を開き、
 *  - 「証券コード」が一致する行を特定し
 *  - 「(0)直近の日付」が procISO と一致する行だけを採用
 * して code -> {open, close} を返す
 *
 * 想定ヘッダ：
 *  - 証券コード
 *  - (0)直近の日付
 *  - (1)直近の始値
 *  - (4)直近の終値
 */
function loadZenmeigaraNisshiMasterTodayMap_(procISO, TZ) {
  const file = getFileFromMyDrivePath_(['投資','プログラミング','GAS','マスタ'], '全銘柄日足分析マスタ');
  if (!file) throw new Error('「投資/プログラミング/GAS/マスタ/全銘柄日足分析マスタ」が見つかりません。');

  const ss = SpreadsheetApp.open(file);
  const sheet = ss.getSheets()[0]; // 先頭シート
  const lastRow = sheet.getLastRow();
  const lastCol = sheet.getLastColumn();
  if (lastRow < 2 || lastCol < 1) return {};

  const header = sheet.getRange(1, 1, 1, lastCol).getValues()[0].map(v => String(v).trim());
  // ★ 日付比較は「更新日」ではなく「(0)直近の日付」を使う
  const col = colIndexMap_(header, ['証券コード','(0)直近の日付','(1)直近の始値','(4)直近の終値']);
  if (col['証券コード'] === undefined || col['(0)直近の日付'] === undefined) return {};

  const values = sheet.getRange(2, 1, lastRow - 1, lastCol).getValues();

  const map = {}; // code -> {open, close}
  for (let i = 0; i < values.length; i++) {
    const row = values[i];
    const code = normalizeCode_(row[col['証券コード'] - 1]);
    if (!code) continue;

    const nearISO = toISODateString_(row[col['(0)直近の日付'] - 1], TZ);
    // ★ 「(0)直近の日付」が処理日と一致する行のみ採用
    if (nearISO !== procISO) continue;

    const open  = (col['(1)直近の始値'] !== undefined) ? toNumberOrNull_(row[col['(1)直近の始値'] - 1]) : null;
    const close = (col['(4)直近の終値'] !== undefined) ? toNumberOrNull_(row[col['(4)直近の終値'] - 1]) : null;

    map[code] = { open, close };
  }
  return map;
}

/* ====== 全銘柄基本情報マスタ読み取り ====== */

/**
 * 「投資/プログラミング/GAS/マスタ/全銘柄基本情報マスタ」を開き、
 * 各行を証券コードごとの基本情報として採用し、
 * code -> 基本情報を返す。
 */
function loadZenmeigaraBasicInfoMasterMap_() {
  const file = getFileFromMyDrivePath_(
    ['投資', 'プログラミング', 'GAS', 'マスタ'],
    '全銘柄基本情報マスタ'
  );

  if (!file) {
    throw new Error(
      '「投資/プログラミング/GAS/マスタ/全銘柄基本情報マスタ」が見つかりません。'
    );
  }

  const ss = SpreadsheetApp.open(file);
  const sheet = ss.getSheetByName('シート1');

  if (!sheet) {
    throw new Error(
      '「全銘柄基本情報マスタ」に「シート1」が見つかりません。'
    );
  }

  const lastRow = sheet.getLastRow();
  const lastCol = sheet.getLastColumn();

  if (lastRow < 2 || lastCol < 1) {
    return {};
  }

  const header = sheet
    .getRange(1, 1, 1, lastCol)
    .getValues()[0]
    .map(v => String(v).trim());

  const col = colIndexMap_(header, [
    '証券コード',
    'PER',
    'PBR',
    '利回り',
    '信用倍率',
    '特色',
    '連結事業'
  ]);

  if (col['証券コード'] === undefined) {
    return {};
  }

  const values = sheet
    .getRange(2, 1, lastRow - 1, lastCol)
    .getValues();

  const map = {};

  for (let i = 0; i < values.length; i++) {
    const row = values[i];

    const code = normalizeCode_(
      row[col['証券コード'] - 1]
    );

    if (!code) continue;   

    map[code] = {
      per:
        col['PER'] !== undefined
          ? row[col['PER'] - 1]
          : '',

      pbr:
        col['PBR'] !== undefined
          ? row[col['PBR'] - 1]
          : '',

      yieldValue:
        col['利回り'] !== undefined
          ? row[col['利回り'] - 1]
          : '',

      marginRatio:
        col['信用倍率'] !== undefined
          ? row[col['信用倍率'] - 1]
          : '',

      feature:
        col['特色'] !== undefined
          ? row[col['特色'] - 1]
          : '',

      consolidatedBusiness:
        col['連結事業'] !== undefined
          ? row[col['連結事業'] - 1]
          : ''
    };
  }

  return map;
}

/** masterMap から「証券コード」一致の当日分の始値/終値を返す（なければ null） */
function getMasterTodayPrice_(masterMap, code, kind /* 'open'|'close' */) {
  if (!masterMap) return null;
  const key = normalizeCode_(code);
  const rec = masterMap[key];
  if (!rec) return null;
  const v = rec[kind];
  return (v == null || isNaN(v)) ? null : v;
}

/** セル値(Date or string)を yyyy-MM-dd に正規化（不可なら null） */
function toISODateString_(cell, TZ) {
  if (cell instanceof Date) return Utilities.formatDate(cell, TZ, 'yyyy-MM-dd');
  const s = String(cell || '').trim();
  if (!s) return null;
  // "2026/02/02 23:03" や "2026-02-02T00:00:00" など、時刻付きでも日付部分を抽出
  const m = s.match(/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/);
  if (!m) return null;
  const y = Number(m[1]);
  const mo = Number(m[2]) - 1;
  const d = Number(m[3]);
  const dt = new Date(y, mo, d, 0, 0, 0, 0);
  return Utilities.formatDate(dt, TZ, 'yyyy-MM-dd');
}

/* ====== ユーティリティ群 ====== */

function colIndexMap_(headerRow, names) {
  const map = {};
  names.forEach(n => {
    const idx = headerRow.findIndex(h => h === n);
    if (idx >= 0) map[n] = idx + 1;
  });
  return map;
}

function writeReferenceItems_(sheet, r, col, code, basicInfoMap) {
  const encodedCode = encodeURIComponent(code);

  // 株探
  if (col['株探'] !== undefined) {
    const url = `https://kabutan.jp/stock/?code=${encodedCode}`;
    sheet.getRange(r, col['株探']).setFormula(
      `=HYPERLINK("${url}","株")`
    );
  }

  // 四季
  if (col['四季'] !== undefined) {
    const url = `https://shikiho.toyokeizai.net/stocks/${encodedCode}`;
    sheet.getRange(r, col['四季']).setFormula(
      `=HYPERLINK("${url}","季")`
    );
  }

  // 銘偵
  if (col['銘偵'] !== undefined) {
    const url =
      `https://monex.ifis.co.jp/index.php?sa=find&ta=e&wd=${encodedCode}&x=0&y=0`;

    sheet.getRange(r, col['銘偵']).setFormula(
      `=HYPERLINK("${url}","銘")`
    );
  }

  // 全銘
  if (col['全銘'] !== undefined) {
    const url =
      `http://133.18.243.68/api/master_view.php?mode=api&text=${encodedCode}`;

    sheet.getRange(r, col['全銘']).setFormula(
      `=HYPERLINK("${url}","全")`
    );
  }

  const key = normalizeCode_(code);
  const info = basicInfoMap ? basicInfoMap[key] : null;

  // 基本情報マスタに対象証券コードがない場合は空白で上書き
  writeValueIfColumnExists_(
    sheet,
    r,
    col,
    'PER',
    info ? info.per : ''
  );

  writeValueIfColumnExists_(
    sheet,
    r,
    col,
    'PBR',
    info ? info.pbr : ''
  );

  writeValueIfColumnExists_(
    sheet,
    r,
    col,
    '利回り',
    info ? info.yieldValue : ''
  );

  writeValueIfColumnExists_(
    sheet,
    r,
    col,
    '信用倍率',
    info ? info.marginRatio : ''
  );

  writeValueIfColumnExists_(
    sheet,
    r,
    col,
    '特色',
    info ? info.feature : ''
  );

  writeValueIfColumnExists_(
    sheet,
    r,
    col,
    '連結事業',
    info ? info.consolidatedBusiness : ''
  );
}
function writeValueIfColumnExists_(sheet, r, col, columnName, value) {
  if (col[columnName] === undefined) return;

  sheet.getRange(r, col[columnName]).setValue(
    value === null || value === undefined ? '' : value
  );
}

function writePnLIfPossible_(sheet, r, col, priceMaybe) {
  if (col['損益'] === undefined || col['株数'] === undefined || col['建値'] === undefined) return;

  const qty  = toNumberOrNull_(sheet.getRange(r, col['株数']).getValue());
  const cost = toNumberOrNull_(sheet.getRange(r, col['建値']).getValue());
  const px   = toNumberOrNull_(priceMaybe);
  if (qty == null || cost == null || px == null) return;

  let side = '買';
  if (col['売買'] !== undefined) side = String(sheet.getRange(r, col['売買']).getValue() || '').trim();

  const pnl = (side === '売') ? (cost * qty) - (px * qty) : (px * qty) - (cost * qty);
  sheet.getRange(r, col['損益']).setValue(pnl);
}

function writePnLToBothIfPossible_(sheet, r, col, priceMaybe) {
  if (col['株数'] === undefined || col['建値'] === undefined) return;

  const qty  = toNumberOrNull_(sheet.getRange(r, col['株数']).getValue());
  const cost = toNumberOrNull_(sheet.getRange(r, col['建値']).getValue());
  const px   = toNumberOrNull_(priceMaybe);
  if (qty == null || cost == null || px == null) return;

  let side = '買';
  if (col['売買'] !== undefined) side = String(sheet.getRange(r, col['売買']).getValue() || '').trim();

  const pnl = (side === '売') ? (cost * qty) - (px * qty) : (px * qty) - (cost * qty);

  if (col['損益'] !== undefined)     sheet.getRange(r, col['損益']).setValue(pnl);
  if (col['確定損益'] !== undefined) sheet.getRange(r, col['確定損益']).setValue(pnl);
}

function writePlusColumns_(sheet, r, col, elapsed, endPrice) {
  const writeIfEmpty = (name) => {
    if (col[name] === undefined) return;
    const cur = sheet.getRange(r, col[name]).getValue();
    if (String(cur).trim() === '') sheet.getRange(r, col[name]).setValue(endPrice);
  };
  const overwrite = (name) => {
    if (col[name] === undefined) return;
    sheet.getRange(r, col[name]).setValue(endPrice);
  };

  if (elapsed === 0) writeIfEmpty('＋0');
  else if (elapsed === 1) writeIfEmpty('＋1');
  else if (elapsed === 2) writeIfEmpty('＋2');
  else if (elapsed === 3) writeIfEmpty('＋3');
  else if (elapsed === 4) writeIfEmpty('＋4');
  else if (elapsed === 5) writeIfEmpty('＋5');
  else if (elapsed === 6) writeIfEmpty('＋6');
  else if (elapsed === 7) writeIfEmpty('＋7');

  if (elapsed >= 8 && elapsed <= 10)    overwrite('＋10');
  if (elapsed >= 11 && elapsed <= 22)   overwrite('＋22');
  if (elapsed >= 23 && elapsed <= 45)   overwrite('＋45');
  if (elapsed >= 46 && elapsed <= 90)   overwrite('＋90');
  if (elapsed >= 91 && elapsed <= 120)  overwrite('＋120');
  if (elapsed >= 121 && elapsed <= 180) overwrite('＋180');
  if (elapsed >= 181 && elapsed <= 365) overwrite('＋365');
}

function getSpreadsheetFileFromMyDrive_(folderName, fileName) {
  const folders = DriveApp.getFoldersByName(folderName);
  if (!folders.hasNext()) return null;
  const folder = folders.next();

  const files = folder.getFilesByName(fileName);
  if (!files.hasNext()) return null;
  return files.next();
}

function getFileFromMyDrivePath_(folderPathArray, fileName) {
  let it = DriveApp.getFoldersByName(folderPathArray[0]);
  if (!it.hasNext()) return null;
  let folder = it.next();

  for (let i = 1; i < folderPathArray.length; i++) {
    const name = folderPathArray[i];
    const it2 = folder.getFoldersByName(name);
    if (!it2.hasNext()) return null;
    folder = it2.next();
  }

  const files = folder.getFilesByName(fileName);
  if (!files.hasNext()) return null;
  return files.next();
}

function getTokyoMinutesFromMidnight_(d, TZ) {
  const h = parseInt(Utilities.formatDate(d, TZ, 'H'), 10);
  const m = parseInt(Utilities.formatDate(d, TZ, 'm'), 10);
  return h * 60 + m;
}

function isInRange_(minutes, h1, m1, h2, m2) {
  const a = h1 * 60 + m1;
  const b = h2 * 60 + m2;
  return minutes >= a && minutes <= b;
}

function normalizeCode_(v) {
  if (v === null || v === undefined) return '';
  if (typeof v === 'number') return String(Math.trunc(v));
  return String(v).trim();
}

function toNumberOrNull_(v) {
  if (v === null || v === undefined) return null;
  const s = String(v).trim().replace(/,/g, '').replace(/[－–—ー−]/g, '-');
  if (s === '' || s === '-' || s === '—' || s === 'ー') return null;
  const num = Number(s);
  return isNaN(num) ? null : num;
}

function diffDays_(d1, d2) {
  const ms = d2.getTime() - d1.getTime();
  return Math.floor(ms / (24 * 60 * 60 * 1000));
}

function stripTime_(d, TZ) {
  const y = Number(Utilities.formatDate(d, TZ, 'yyyy'));
  const m = Number(Utilities.formatDate(d, TZ, 'MM')) - 1;
  const day = Number(Utilities.formatDate(d, TZ, 'dd'));
  return new Date(y, m, day, 0, 0, 0, 0);
}

function looksLikeDateString_(s) {
  return /^\d{4}[-/]\d{1,2}[-/]\d{1,2}$/.test(String(s));
}
function parseDateLoose_(s, TZ) {
  const parts = String(s).replace(/-/g, '/').split('/');
  const y = Number(parts[0]);
  const m = Number(parts[1]) - 1;
  const d = Number(parts[2]);
  return new Date(y, m, d);
}
/**
 * 'yyyy-MM-dd' と 'HH:mm' から Date を組み立てる（Asia/Tokyo前提で扱う）
 * - GASのDateは内部UTCだが、ここでは「日付の部品」を作る用途なので new Date(y,m,d,h,mi) でOK
 */
function buildDateTimeFromISOAndHHmm_(iso, hhmm, TZ) {
  const sIso = String(iso || '').trim();
  const sHm  = String(hhmm || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(sIso)) throw new Error(`processDateISO が不正です: ${sIso}`);
  if (!/^\d{1,2}:\d{2}$/.test(sHm)) throw new Error(`fakeNowHHmm が不正です: ${sHm}`);

  const [y, mo, d] = sIso.split('-').map(n => Number(n));
  const [hh, mm]  = sHm.split(':').map(n => Number(n));
  if (hh < 0 || hh > 23 || mm < 0 || mm > 59) throw new Error(`fakeNowHHmm が不正です: ${sHm}`);

  return new Date(y, mo - 1, d, hh, mm, 0, 0);
}

/* ====== 0件理由ログ（デバッグ） ====== */
function createDebugStats_() {
  return {
    rowsScanned: 0,
    updatedElapsed: 0,
    elapsedAlreadyUpToDate: 0, // ★ 経過日数更新なし
    buildDateBlank: 0,
    buildDateNotFixed: 0,      // ★ 建て日未確定（始値/終値のまま）
    buildDateInvalid: 0,
    skipStopped: 0,
    missMasterOpen: 0,
    missMasterClose: 0,
    missBuildPriceCol: 0
  };
}

function logZeroReason_(st, procISO, minutes, TZ, now) {
  const cur = (now instanceof Date) ? now : new Date();
  const realNow = new Date();

  Logger.log('==== 売買シミュレーション: 0件理由サマリ ====');
  Logger.log(`処理日=${procISO} / 現在時刻=${Utilities.formatDate(cur, TZ, 'HH:mm')}`);
  Logger.log(`minutes=${minutes}`);
  Logger.log(`走査行数=${st.rowsScanned}`);
  Logger.log(`更新発生（経過日数更新）=${st.updatedElapsed}`);
  Logger.log(`経過日数更新なし（既に最新）=${st.elapsedAlreadyUpToDate}`);
  Logger.log(`建て日が空白=${st.buildDateBlank}`);
  Logger.log(`建て日未確定（始値/終値のまま）=${st.buildDateNotFixed}`);
  Logger.log(`建て日が不正値（想定外）=${st.buildDateInvalid}`);
  Logger.log(`停止行（〇）でスキップ=${st.skipStopped}`);
  Logger.log(`マスタ始値が取れない=${st.missMasterOpen}`);
  Logger.log(`マスタ終値が取れない=${st.missMasterClose}`);
  Logger.log(`建値列が存在しない=${st.missBuildPriceCol}`);
  Logger.log('=========================================');
}

