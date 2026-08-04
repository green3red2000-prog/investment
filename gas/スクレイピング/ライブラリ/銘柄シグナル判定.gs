/**
 * 銘柄シグナル判定ライブラリ
 *
 * 公開関数：
 * createMasterViewInfo(code, basicInfo, analysisInfo)
 */

const STOCK_SIGNAL_MASTER_PATH_ = [
  '投資',
  'プログラミング',
  'GAS',
  'マスタ'
];

const STOCK_SIGNAL_MASTER_NAME_ =
  '銘柄シグナル判定マスタ';

let stockSignalConfigCache_ = null;

/**
 * 全銘リンク用URLと背景色を生成する。
 *
 * @param {string|number} code 証券コード
 * @param {Object|null} basicInfo 全銘柄基本情報
 * @param {Object|null} analysisInfo 全銘柄日足分析情報
 * @return {{
 *   url:string,
 *   background:(string|null),
 *   selectedColor:(string|null),
 *   red:string[],
 *   blue:string[],
 *   yellow:string[],
 *   green:string[],
 *   counts:Object
 * }}
 */
function createMasterViewInfo(
  code,
  basicInfo,
  analysisInfo
) {
  const config = loadStockSignalConfig_();

  const matched = {
    red: [],
    blue: [],
    yellow: [],
    green: []
  };

  /*
   * 各パラメータ・色が判定済みかを保持する。
   * 複合条件の依存判定で使用する。
   */
  const matchedFlags = {};

  /*
   * 先に単純条件を処理する。
   */
  config.rules
    .filter(rule =>
      rule.conditionType !==
      'DEPENDENCY_COLOR_AND_GE'
    )
    .forEach(rule => {
      const value = getRuleValue_(
        rule,
        basicInfo,
        analysisInfo
      );

      const result = evaluateRule_(
        rule,
        value
      );

      const key = makeMatchKey_(
        rule.masterType,
        rule.parameter,
        rule.color
      );

      matchedFlags[key] = result;

      if (result) {
        pushUnique_(
          matched[rule.color],
          rule.parameter
        );
      }
    });

  /*
   * 単純条件の結果を使って複合条件を処理する。
   */
  config.rules
    .filter(rule =>
      rule.conditionType ===
      'DEPENDENCY_COLOR_AND_GE'
    )
    .forEach(rule => {
      const value = getRuleValue_(
        rule,
        basicInfo,
        analysisInfo
      );

      const dependencyKey = makeMatchKey_(
        rule.masterType,
        rule.dependencyParameter,
        rule.dependencyColor
      );

      const dependencyMatched =
        matchedFlags[dependencyKey] === true;

      const num = toNumberOrNull_(value);

      const result =
        dependencyMatched &&
        num !== null &&
        num >= rule.threshold1;

      const key = makeMatchKey_(
        rule.masterType,
        rule.parameter,
        rule.color
      );

      matchedFlags[key] = result;

      if (result) {
        pushUnique_(
          matched[rule.color],
          rule.parameter
        );
      }
    });

  const counts = {
    red: matched.red.length,
    blue: matched.blue.length,
    yellow: matched.yellow.length,
    green: matched.green.length
  };

  const selectedColor =
    selectBackgroundColor_(
      counts,
      config.colorSettings
    );

  const background =
    selectedColor
      ? config.colorSettings[selectedColor]
          .background
      : null;

  const url = buildMasterViewUrl_(
    code,
    matched,
    config.urlSettings
  );

  return {
    url: url,
    background: background,
    selectedColor: selectedColor,
    red: matched.red,
    blue: matched.blue,
    yellow: matched.yellow,
    green: matched.green,
    counts: counts
  };
}

/**
 * 判定マスタを読み込む。
 * 同一実行中はメモリキャッシュを使用する。
 */
function loadStockSignalConfig_() {
  if (stockSignalConfigCache_ !== null) {
    return stockSignalConfigCache_;
  }

  const file = getFileFromMyDrivePath_(
    STOCK_SIGNAL_MASTER_PATH_,
    STOCK_SIGNAL_MASTER_NAME_
  );

  if (!file) {
    throw new Error(
      '「' +
      STOCK_SIGNAL_MASTER_PATH_.join('/') +
      '/' +
      STOCK_SIGNAL_MASTER_NAME_ +
      '」が見つかりません。'
    );
  }

  const ss = SpreadsheetApp.open(file);

  const ruleSheet =
    ss.getSheetByName('判定条件');

  const colorSheet =
    ss.getSheetByName('色設定');

  const urlSheet =
    ss.getSheetByName('URL設定');

  if (!ruleSheet) {
    throw new Error(
      '「判定条件」シートが見つかりません。'
    );
  }

  if (!colorSheet) {
    throw new Error(
      '「色設定」シートが見つかりません。'
    );
  }

  if (!urlSheet) {
    throw new Error(
      '「URL設定」シートが見つかりません。'
    );
  }

  stockSignalConfigCache_ = {
    rules: loadRules_(ruleSheet),
    colorSettings:
      loadColorSettings_(colorSheet),
    urlSettings:
      loadUrlSettings_(urlSheet)
  };

  return stockSignalConfigCache_;
}

function loadRules_(sheet) {
  const lastRow = sheet.getLastRow();
  const lastCol = sheet.getLastColumn();

  if (lastRow < 2 || lastCol < 1) {
    return [];
  }

  const values = sheet
    .getRange(
      1,
      1,
      lastRow,
      lastCol
    )
    .getValues();

  const header = values[0]
    .map(value => String(value).trim());

  const col = createHeaderMap_(header);

  const rules = [];

  for (let i = 1; i < values.length; i++) {
    const row = values[i];

    const enabled =
      toBoolean_(
        getValueByHeader_(
          row,
          col,
          '有効'
        )
      );

    if (!enabled) {
      continue;
    }

    const masterType = String(
      getValueByHeader_(
        row,
        col,
        'マスタ種別'
      ) || ''
    ).trim();

    const parameter = String(
      getValueByHeader_(
        row,
        col,
        'パラメータ'
      ) || ''
    ).trim();

    const color = String(
      getValueByHeader_(
        row,
        col,
        '色'
      ) || ''
    ).trim();

    const conditionType = String(
      getValueByHeader_(
        row,
        col,
        '判定種別'
      ) || ''
    ).trim();

    if (
      !masterType ||
      !parameter ||
      !color ||
      !conditionType
    ) {
      continue;
    }

    rules.push({
      masterType: masterType,
      itemName: String(
        getValueByHeader_(
          row,
          col,
          '項目名'
        ) || ''
      ).trim(),

      parameter: parameter,
      color: color,
      conditionType: conditionType,

      threshold1:
        toNumberOrNull_(
          getValueByHeader_(
            row,
            col,
            '閾値1'
          )
        ),

      threshold2:
        toNumberOrNull_(
          getValueByHeader_(
            row,
            col,
            '閾値2'
          )
        ),

      dependencyParameter: String(
        getValueByHeader_(
          row,
          col,
          '依存パラメータ'
        ) || ''
      ).trim(),

      dependencyColor: String(
        getValueByHeader_(
          row,
          col,
          '依存色'
        ) || ''
      ).trim(),

      textValues: String(
        getValueByHeader_(
          row,
          col,
          '文字列候補（|区切り）'
        ) || ''
      )
        .split('|')
        .map(value => value.trim())
        .filter(value => value !== '')
    });
  }

  return rules;
}

function loadColorSettings_(sheet) {
  const lastRow = sheet.getLastRow();

  if (lastRow < 2) {
    throw new Error(
      '「色設定」シートに設定がありません。'
    );
  }

  const values = sheet
    .getRange(
      1,
      1,
      lastRow,
      sheet.getLastColumn()
    )
    .getValues();

  const header = values[0]
    .map(value => String(value).trim());

  const col = createHeaderMap_(header);

  const settings = {};

  for (let i = 1; i < values.length; i++) {
    const row = values[i];

    const color = String(
      getValueByHeader_(
        row,
        col,
        '色'
      ) || ''
    ).trim();

    if (!color || color === '色なし') {
      continue;
    }

    settings[color] = {
      minimumCount:
        toNumberOrNull_(
          getValueByHeader_(
            row,
            col,
            '最低件数'
          )
        ),

      priority:
        toNumberOrNull_(
          getValueByHeader_(
            row,
            col,
            '優先順位'
          )
        ),

      background: String(
        getValueByHeader_(
          row,
          col,
          '背景色'
        ) || ''
      ).trim()
    };
  }

  /*
   * orange・purpleは候補色ではなく、
   * red/blueとyellowの複合色。
   */
  if (settings.red) {
    settings.red.minimumCount =
      settings.red.minimumCount || 3;
    settings.red.priority =
      settings.red.priority || 1;
  }

  if (settings.blue) {
    settings.blue.minimumCount =
      settings.blue.minimumCount || 3;
    settings.blue.priority =
      settings.blue.priority || 2;
  }

  if (settings.yellow) {
    settings.yellow.minimumCount =
      settings.yellow.minimumCount || 1;
    settings.yellow.priority =
      settings.yellow.priority || 3;
  }

  if (settings.green) {
    settings.green.minimumCount =
      settings.green.minimumCount || 1;
    settings.green.priority =
      settings.green.priority || 4;
  }

  return settings;
}

function loadUrlSettings_(sheet) {
  const lastRow = sheet.getLastRow();

  const values = sheet
    .getRange(
      2,
      1,
      Math.max(lastRow - 1, 1),
      2
    )
    .getValues();

  const map = {};

  values.forEach(row => {
    const key = String(row[0] || '').trim();
    const value = String(row[1] || '').trim();

    if (key) {
      map[key] = value;
    }
  });

  return {
    baseUrl:
      map['ベースURL'] ||
      'http://133.18.243.68/api/master_view.php',

    fixedParameter:
      map['固定パラメータ'] ||
      'mode=api',

    codeParameter:
      map['証券コードパラメータ'] ||
      'text',

    colorOrder: (
      map['色パラメータ順'] ||
      'red,blue,yellow,green'
    )
      .split(',')
      .map(value => value.trim())
      .filter(value => value !== ''),

    displayText:
      map['セル表示文字列'] ||
      '全'
  };
}

function getRuleValue_(
  rule,
  basicInfo,
  analysisInfo
) {
  const source =
    rule.masterType ===
    '全銘柄基本情報マスタ'
      ? basicInfo
      : analysisInfo;

  if (
    !source ||
    !source.valuesByParam
  ) {
    return null;
  }

  return source
    .valuesByParam[rule.parameter];
}

function evaluateRule_(rule, value) {
  if (rule.conditionType === 'IN') {
    const text = String(
      value === null ||
      value === undefined
        ? ''
        : value
    ).trim();

    return rule.textValues.indexOf(text) >= 0;
  }

  const num = toNumberOrNull_(value);

  if (num === null) {
    return false;
  }

  switch (rule.conditionType) {
    case 'GE':
      return num >= rule.threshold1;

    case 'LE':
      return num <= rule.threshold1;

    case 'BETWEEN':
      return (
        num >= rule.threshold1 &&
        num <= rule.threshold2
      );

    case 'ABS_GE':
      return Math.abs(num) >= rule.threshold1;

    default:
      return false;
  }
}

function selectBackgroundColor_(
  counts,
  colorSettings
) {
  const order = [
    'red',
    'blue',
    'yellow',
    'green'
  ];

  const candidates = order.filter(color => {
    const setting = colorSettings[color];

    if (!setting) {
      return false;
    }

    return counts[color] >=
      setting.minimumCount;
  });

  if (candidates.length === 0) {
    return null;
  }

  candidates.sort((a, b) => {
    const countDiff =
      counts[b] - counts[a];

    if (countDiff !== 0) {
      return countDiff;
    }

    return (
      colorSettings[a].priority -
      colorSettings[b].priority
    );
  });

  let selected = candidates[0];

  if (
    selected === 'red' &&
    counts.yellow >= 1
  ) {
    selected = 'orange';

  } else if (
    selected === 'blue' &&
    counts.yellow >= 1
  ) {
    selected = 'purple';
  }

  return selected;
}

function buildMasterViewUrl_(
  code,
  matched,
  urlSettings
) {
  const queryParts = [];

  if (urlSettings.fixedParameter) {
    queryParts.push(
      urlSettings.fixedParameter
    );
  }

  queryParts.push(
    urlSettings.codeParameter +
    '=' +
    encodeURIComponent(
      String(code)
    )
  );

  urlSettings.colorOrder
    .forEach(color => {
      const parameters =
        matched[color] || [];

      if (parameters.length === 0) {
        return;
      }

      /*
       * カンマ自体はURL例に合わせて残し、
       * 各パラメータだけをURLエンコードする。
       */
      const joined =
        parameters
          .map(parameter =>
            encodeURIComponent(parameter)
          )
          .join(',');

      queryParts.push(
        color + '=' + joined
      );
    });

  return (
    urlSettings.baseUrl +
    '?' +
    queryParts.join('&')
  );
}

function makeMatchKey_(
  masterType,
  parameter,
  color
) {
  return [
    masterType,
    parameter,
    color
  ].join('|');
}

function pushUnique_(array, value) {
  if (array.indexOf(value) < 0) {
    array.push(value);
  }
}

function createHeaderMap_(header) {
  const map = {};

  header.forEach((name, index) => {
    map[name] = index;
  });

  return map;
}

function getValueByHeader_(
  row,
  col,
  name
) {
  if (col[name] === undefined) {
    return null;
  }

  return row[col[name]];
}

function toBoolean_(value) {
  if (value === true) {
    return true;
  }

  const text = String(
    value === null ||
    value === undefined
      ? ''
      : value
  ).trim().toLowerCase();

  return (
    text === 'true' ||
    text === '1' ||
    text === 'yes' ||
    text === '有効'
  );
}

function toNumberOrNull_(value) {
  if (
    value === null ||
    value === undefined ||
    value === ''
  ) {
    return null;
  }

  const text = String(value)
    .trim()
    .replace(/,/g, '')
    .replace(/[－–—ー−]/g, '-');

  if (
    text === '' ||
    text === '-'
  ) {
    return null;
  }

  const num = Number(text);

  return isNaN(num)
    ? null
    : num;
}

function getFileFromMyDrivePath_(
  folderPathArray,
  fileName
) {
  let iterator =
    DriveApp.getFoldersByName(
      folderPathArray[0]
    );

  if (!iterator.hasNext()) {
    return null;
  }

  let folder = iterator.next();

  for (
    let i = 1;
    i < folderPathArray.length;
    i++
  ) {
    const childIterator =
      folder.getFoldersByName(
        folderPathArray[i]
      );

    if (!childIterator.hasNext()) {
      return null;
    }

    folder = childIterator.next();
  }

  const files =
    folder.getFilesByName(fileName);

  return files.hasNext()
    ? files.next()
    : null;
}