/**
 * 基本情報付加フォルダ内のスプレッドシートを順に処理し、
 * ファイル名のプレフィックスで KabutanFetcher.BaseInfo_run() の引数と
 * 移動先フォルダ（上書き先）を分岐。
 *
 * - 戻り値が -1 / 0 なら全体終了。
 * - 1 以上なら、上書き（ID維持）または移動を実施し、完了通知メール送信。
 * - 次ファイルの writeLimit は (TOTAL_WRITE_LIMIT - 累計処理件数)。
 * - 空ファイル（2行目以降データなし）は削除してスキップ。
 *
 * サポートするプレフィックスと主なパラメータ:
 * - 決算速報         : startCol=7,  companyNameCol=4
 * - 適時開示         : startCol=8,  companyNameCol=2, codeCol=1
 * - 大量保有速報     : startCol=7,  companyNameCol=4, codeCol=5
 * - 本日の株価動向   : startCol=10, companyNameCol=3, codeCol=2, bottomLineCol=1
 * - PTS＆朝刊ニュース : startCol=11, companyNameCol=3, codeCol=2, bottomLineCol=1
 * - 全銘柄日足分析   : startCol=0,  companyNameCol=2, codeCol=1
 */

function main() {
  const ROOT_FOLDER_NAME = '出力結果';
  const SRC_SUBFOLDER_NAME = '基本情報付加';
  const TOTAL_WRITE_LIMIT = 2000; // 上限（累計）
  const MAIL_TO = 'green3red2000@gmail.com';

  // 処理元（ソース）フォルダ: 出力結果/基本情報付加
  const root = findFolderByName_(ROOT_FOLDER_NAME);
  if (!root) throw new Error(`フォルダ「${ROOT_FOLDER_NAME}」が見つかりません。`);
  const src = findSubFolderByName_(root, SRC_SUBFOLDER_NAME);
  if (!src) throw new Error(`「${ROOT_FOLDER_NAME}」内にフォルダ「${SRC_SUBFOLDER_NAME}」が見つかりません。`);

  let remaining = TOTAL_WRITE_LIMIT;
  let totalWritten = 0;

  const files = listSpreadsheetFiles_(src).sort((a, b) => a.getName().localeCompare(b.getName(), 'ja'));
  Logger.log(`検出ファイル数: ${files.length}（対象: ${ROOT_FOLDER_NAME}/${SRC_SUBFOLDER_NAME}）`);

  for (const file of files) {
    if (remaining <= 0) {
      Logger.log(`上限(${TOTAL_WRITE_LIMIT}件)に達したため終了します。`);
      break;
    }

    const rawName = file.getName();
    const name = rawName.trim();

    // プレフィックス判定
    const isKessan   = name.startsWith('決算速報');
    const isTekiji   = name.startsWith('適時開示');
    const isTairyo   = name.startsWith('大量保有速報');
    const isHonjitsu = name.startsWith('本日の株価動向');
    const isPtsNews  = name.startsWith('PTS＆朝刊ニュース');
    const isZenMei   = name.startsWith('全銘柄日足分析');

    if (!isKessan && !isTekiji && !isTairyo && !isHonjitsu && !isPtsNews && !isZenMei) {
      Logger.log(`スキップ: 想定外のファイル名: "${name}"`);
      continue;
    }

    // ▼ 見出しのみファイルは削除してスキップ
    if (isSpreadsheetEmptyBeyondHeader_(file.getId())) {
      Logger.log(`削除: "${name}" は見出しのみ（2行目以降データなし）のため削除しました。`);
      try { src.removeFile(file); } catch (e) {}
      file.setTrashed(true);
      continue;
    }

    // ライブラリ呼び出し引数（共通）
    const args = {
      spreadsheetName: name,
      spreadsheetId: file.getId(),
      folderName: SRC_SUBFOLDER_NAME, // ライブラリには「ソースのサブフォルダ名」を渡す想定
      kabutanLinkType: 2,
      writeLimit: remaining
    };

    if (isKessan) {
      args.startCol = 7;       // G
      args.companyNameCol = 4; // D
      args.freezeRows = 1;
      args.freezeCols = 4;

    } else if (isTekiji) {
      args.startCol = 8;       // H
      args.companyNameCol = 2; // B
      args.codeCol = 1;        // A
      args.freezeRows = 1;
      args.freezeCols = 2;

    } else if (isTairyo) {
      args.startCol = 7;       // G
      args.companyNameCol = 4; // D
      args.codeCol = 5;        // E
      args.freezeRows = 1;
      args.freezeCols = 4;

    } else if (isHonjitsu) {
      args.startCol = 10;       // J
      args.companyNameCol = 3;  // C
      args.codeCol = 2;         // B
      args.bottomLineCol = 1;   // A
      args.freezeRows = 1;
      args.freezeCols = 3;

    } else if (isPtsNews) {
      args.startCol = 11;       // K
      args.companyNameCol = 3;  // C
      args.codeCol = 2;         // B
      args.bottomLineCol = 1;   // A
      args.freezeRows = 1;
      args.freezeCols = 3;

    } else if (isZenMei) {
      // 全銘柄日足分析
      args.startCol = 0;        // シート右端の次列
      args.companyNameCol = 2;  // B
      args.codeCol = 1;         // A
      args.sheetName = '';      // 全シート対象
      args.kabutanLinkType = 2; // 株探リンク＝チャート
      args.headerPattern = 1;
    }

    Logger.log(`処理開始: "${name}" (prefix=${
      isKessan ? '決算速報' :
      isTekiji ? '適時開示' :
      isTairyo ? '大量保有速報' :
      isHonjitsu ? '本日の株価動向' :
      isPtsNews ? 'PTS＆朝刊ニュース' :
      '全銘柄日足分析'
    }, writeLimit=${args.writeLimit})`);

    // ライブラリ実行
    let result;
    try {
      result = KabutanFetcher.BaseInfo_run(args);
    } catch (e) {
      Logger.log(`エラー: "${name}" / ${e && e.message ? e.message : e}`);
      return; // 例外は -1 相当扱いで全体終了
    }
    Logger.log(`戻り値: ${result}`);

    if (result === -1 || result === 0) {
      Logger.log('戻り値が-1または0のため、全体処理を終了します。');
      return;
    }

    // 上書き（ID維持）または移動（完了後メール）
    overwriteOrMovePreserveId_(file, root, src, MAIL_TO);

    // 残り枠
    totalWritten += result;
    remaining = Math.max(0, TOTAL_WRITE_LIMIT - totalWritten);
    Logger.log(`書き出した件数: ${result}行（累計: ${totalWritten}行 / 残り: ${remaining}行）`);
  }

  Logger.log('処理完了。');
}

/** 親フォルダ直下で指定名のサブフォルダ（1件目） */
function findSubFolderByName_(parentFolder, name) {
  const it = parentFolder.getFoldersByName(name);
  return it.hasNext() ? it.next() : null;
}

/** 指定フォルダ内のスプレッドシート DriveFile を配列で返す */
function listSpreadsheetFiles_(folder) {
  const out = [];
  const mime = MimeType.GOOGLE_SHEETS;
  const files = folder.getFiles();
  while (files.hasNext()) {
    const f = files.next();
    if (f.getMimeType && f.getMimeType() === mime) out.push(f);
  }
  return out;
}

/** スプレッドシートが「見出し（1行目）のみ」か判定（すべてのシートで lastRow <= 1） */
function isSpreadsheetEmptyBeyondHeader_(spreadsheetId) {
  const ss = SpreadsheetApp.openById(spreadsheetId);
  const sheets = ss.getSheets();
  for (const sh of sheets) {
    if (sh.getLastRow() > 1) return false;
  }
  return true;
}

/**
 * 上書き（ID維持）または移動:
 * - 出力先に同名のスプレッドシートがある場合:
 *   既存ファイル（＝IDを残したい側）の中身を src の内容で置換。src は削除（ゴミ箱へ）。
 *   → 「上書き完了（ID維持）...」のログ・メールを送信
 * - 出力先に同名が無い場合:
 *   src を通常移動（add/remove）。
 *   → 「移動完了...」のログ・メールを送信
 * - 出力先に同名はあるがどちらかがシートでない:
 *   警告ログを出し、通常移動を実施。
 *   → 「警告...」のメールを送信
 */
function overwriteOrMovePreserveId_(srcFile, destFolder, currentFolder, mailTo) {
  const name = srcFile.getName();
  const mimeSheets = MimeType.GOOGLE_SHEETS;

  const existing = destFolder.getFilesByName(name);
  if (existing.hasNext()) {
    const destFile = existing.next();

    if (srcFile.getMimeType() === mimeSheets && destFile.getMimeType() === mimeSheets) {
      const srcId = srcFile.getId();
      const destId = destFile.getId();

      const ssSrc  = SpreadsheetApp.openById(srcId);
      const ssDest = SpreadsheetApp.openById(destId);

      // --- 置換処理: 既存の中身を空にしてから、src の全シートをコピー ---
      const tmp = ssDest.insertSheet('___TMP_TO_BE_DELETED___');
      ssDest.getSheets().forEach(sh => {
        if (sh.getSheetId() !== tmp.getSheetId()) {
          ssDest.deleteSheet(sh);
        }
      });

      ssSrc.getSheets().forEach(s => {
        const copied = s.copyTo(ssDest);
        copied.setName(s.getName());
      });

      ssDest.deleteSheet(tmp);

      // src は不要（IDは dest を維持）
      try { currentFolder.removeFile(srcFile); } catch (e) {}
      srcFile.setTrashed(true);

      const msg = `上書き完了（ID維持）: "${name}" / destId=${destId} に srcId=${srcId} の内容を反映`;
      Logger.log(msg);
      sendCompletionEmail_(mailTo, name, msg);
      return;
    }

    const warn = `警告: 同名は存在するがスプレッドシート以外のため移動で処理: "${name}"`;
    Logger.log(warn);
    sendCompletionEmail_(mailTo, name, warn);

    // フォールバック：通常移動
    destFolder.addFile(srcFile);
    try { currentFolder.removeFile(srcFile); } catch (e) {}
    Logger.log(`移動完了: ${name} -> ${destFolder.getName()}`);
    return;
  }

  // 同名が無ければ通常移動
  destFolder.addFile(srcFile);
  try { currentFolder.removeFile(srcFile); } catch (e) {}

  const moved = `移動完了: ${name} -> ${destFolder.getName()}`;
  Logger.log(moved);
  sendCompletionEmail_(mailTo, name, moved);
}

/** 完了通知メール送信（本文はそのまま転載） */
function sendCompletionEmail_(to, fileName, bodyText) {
  const subject = `基本情報付加：${fileName}処理完了通知`;
  try {
    MailApp.sendEmail({ to, subject, body: bodyText });
  } catch (e) {
    Logger.log(`メール送信エラー: ${e && e.message ? e.message : e}`);
  }
}

/** 補助: 「出力結果」直下フォルダを取得 */
function findFolderByName_(name) {
  const it = DriveApp.getFoldersByName(name);
  return it.hasNext() ? it.next() : null;
}
