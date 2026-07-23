/***** 設定ここから *****/
const ROOT_FOLDER_NAME = '出力結果';   // 対象フォルダ
const ARCHIVE_FOLDER_NAME = '整理先';  // 出力結果/整理先

// 何日より古いか（「より古い」なので > で判定）
const MOVE_THRESHOLD_DAYS = 10;   // 整理先へ移動
const DELETE_THRESHOLD_DAYS = 20; // 削除

// 1回の実行で処理する最大件数（環境に応じて調整）
const MAX_MOVES_PER_RUN = 200;
const MAX_DELETES_PER_RUN = 200;

// メール設定
const MAIL_TO = 'green3red2000@gmail.com';

// ドライランモード（true: ログとメールのみ / false: 実際に移動・削除も行う）
const DRY_RUN = false;
/***** 設定ここまで *****/


/**
 * メイン処理（毎日1時頃にトリガーで回す想定）
 */
function runDailyCleanup() {
  const timeZone = Session.getScriptTimeZone();
  const today = getToday_(); // 時刻を00:00:00にそろえた今日
  const todayStrForSubject = Utilities.formatDate(today, timeZone, 'yyyy/MM/dd');

  // フォルダ取得
  const rootFolder = getFolderByName_(ROOT_FOLDER_NAME);
  if (!rootFolder) {
    Logger.log('対象フォルダ「' + ROOT_FOLDER_NAME + '」が見つかりません。処理を終了します。');
    return;
  }

  const archiveFolder = getOrCreateSubFolder_(rootFolder, ARCHIVE_FOLDER_NAME);

  const moveTargets = [];   // 整理先へ移動対象
  const deleteTargets = []; // 削除対象

  /***** (1) 出力結果 直下のファイル → 整理対象判定 *****/
  const filesInRoot = rootFolder.getFiles();
  while (filesInRoot.hasNext()) {
    const file = filesInRoot.next();
    const fileName = file.getName();

    const fileDate = extractDateFromFilename_(fileName);
    if (!fileDate) {
      // 「_yyyy-MM-dd」形式で終わっていないファイルは無視
      continue;
    }

    const diffDays = diffDays_(today, fileDate);
    if (diffDays > MOVE_THRESHOLD_DAYS) {
      moveTargets.push(file);
    }
  }

  /***** (2) 整理先フォルダ内のファイル → 削除対象判定 *****/
  const filesInArchive = archiveFolder.getFiles();
  while (filesInArchive.hasNext()) {
    const file = filesInArchive.next();
    const fileName = file.getName();

    const fileDate = extractDateFromFilename_(fileName);
    if (!fileDate) {
      continue;
    }

    const diffDays = diffDays_(today, fileDate);
    if (diffDays > DELETE_THRESHOLD_DAYS) {
      deleteTargets.push(file);
    }
  }


  /***** ログ出力（確認用） *****/
  Logger.log('【整理対象ファイル】（出力結果 → 整理先 に移動予定）');
  if (moveTargets.length === 0) {
    Logger.log('なし');
  } else {
    moveTargets.forEach(function(file) {
      Logger.log(file.getName() + ' (ID: ' + file.getId() + ')');
    });
  }

  Logger.log('【削除対象ファイル】（整理先 から削除予定）');
  if (deleteTargets.length === 0) {
    Logger.log('なし');
  } else {
    deleteTargets.forEach(function(file) {
      Logger.log(file.getName() + ' (ID: ' + file.getId() + ')');
    });
  }

  /***** 実処理（DRY_RUN=false のときだけ動く） *****/
  let movedThisRun = 0;
  let deletedThisRun = 0;

  if (!DRY_RUN) {
    // (1) 整理先へ移動
    const moveLimit = Math.min(moveTargets.length, MAX_MOVES_PER_RUN);
    for (let i = 0; i < moveLimit; i++) {
      const file = moveTargets[i];
      // 高速化：addFile/removeFile ではなく moveTo を使用
      file.moveTo(archiveFolder);
      movedThisRun++;
    }

    // (2) 削除
    const deleteLimit = Math.min(deleteTargets.length, MAX_DELETES_PER_RUN);
    for (let j = 0; j < deleteLimit; j++) {
      const file = deleteTargets[j];
      file.setTrashed(true);  // ゴミ箱へ移動
      deletedThisRun++;
    }
  } else {
    // DRY_RUN のときは、候補件数をそのままレポート
    movedThisRun = moveTargets.length;
    deletedThisRun = deleteTargets.length;
  }

  /***** メール送信 *****/
  const subject = 'ガベレージ処理：' + todayStrForSubject + (DRY_RUN ? '（ドライラン）' : '');
  const body =
    '整理先への移動ファイルは、' + movedThisRun + '件でした。\n' +
    '削除ファイルは、' + deletedThisRun + '件でした。\n' +
    (DRY_RUN
      ? ''
      : '(候補数：移動 ' + moveTargets.length + '件／削除 ' + deleteTargets.length + '件のうち、本日処理した件数です)\n');

  MailApp.sendEmail(MAIL_TO, subject, body);
}


/**
 * フォルダ名から最初のフォルダを取得（見つからなければ null）
 */
function getFolderByName_(name) {
  const folders = DriveApp.getFoldersByName(name);
  return folders.hasNext() ? folders.next() : null;
}


/**
 * 親フォルダの中に指定名のサブフォルダを取得。なければ作成。
 */
function getOrCreateSubFolder_(parentFolder, name) {
  const folders = parentFolder.getFoldersByName(name);
  if (folders.hasNext()) {
    return folders.next();
  }
  return parentFolder.createFolder(name);
}


/**
 * ファイル名の末尾「_yyyy-MM-dd」から日付を抽出して Date オブジェクトで返す。
 * 取得できなければ null。
 * 例: "本日のニュース_2025-11-25" → 2025-11-25 の Date
 */
function extractDateFromFilename_(fileName) {
  const m = fileName.match(/_(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return null;

  const year = parseInt(m[1], 10);
  const month = parseInt(m[2], 10) - 1; // 0始まり
  const day = parseInt(m[3], 10);

  return new Date(year, month, day);
}


/**
 * 今日の日付（時刻 00:00:00）を返す
 */
function getToday_() {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}


/**
 * d1 - d2 の「日数差」を返す（どちらも 00:00:00 前提）
 * 例: d1 = 2025-11-25, d2 = 2025-11-20 → 5
 */
function diffDays_(d1, d2) {
  const msPerDay = 1000 * 60 * 60 * 24;
  const diffMs = d1.getTime() - d2.getTime();
  return Math.floor(diffMs / msPerDay);
}


/**
 * 初回に一度だけ実行して、毎日1時のトリガーを作る用
 */
function createDailyTrigger() {
  ScriptApp.newTrigger('runDailyCleanup')
    .timeBased()
    .everyDays(1)
    .atHour(1) // 午前1時台
    .create();
}
