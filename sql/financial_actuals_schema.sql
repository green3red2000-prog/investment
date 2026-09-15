-- =====================================================================
-- 財務実績時系列DB
-- MariaDB 10.3系を想定
--
-- 方針
--   * 1レコード = 1銘柄 × 1決算期間 × 1開示元 × 1開示文書 × 1連結区分
--   * このテーブルの取得元は TDNET / EDINET とする
--   * J-Quants は既存の jquants_fins_summary に保存し、このテーブルへ重複登録しない
--   * TDNET / EDINET は同一銘柄・同一決算期でも別レコードとして履歴保存する
--   * 年次だけでなく将来の四半期短信も同じテーブルへ保存できる
--   * preferred選択・派生指標計算はCSV生成処理側で行う
-- =====================================================================

CREATE TABLE IF NOT EXISTS financial_actuals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- -------------------------------------------------------------
    -- 銘柄識別
    -- -------------------------------------------------------------
    security_code VARCHAR(8) NOT NULL COMMENT '証券コード。英字コード対応のため文字列',
    edinet_code VARCHAR(16) DEFAULT NULL COMMENT 'EDINETコード',
    company_name VARCHAR(255) DEFAULT NULL COMMENT '会社名',

    -- -------------------------------------------------------------
    -- 決算期間
    -- -------------------------------------------------------------
    fiscal_period_start DATE DEFAULT NULL COMMENT '対象期間開始日',
    fiscal_period_end DATE NOT NULL COMMENT '対象期間終了日',
    period_type VARCHAR(8) NOT NULL COMMENT 'FY/Q1/Q2/Q3/Q4/H1',
    period_basis VARCHAR(16) NOT NULL DEFAULT 'cumulative'
        COMMENT 'cumulative/standalone',
    scope VARCHAR(20) NOT NULL
        COMMENT 'consolidated/nonconsolidated',
    accounting_standard VARCHAR(32) DEFAULT NULL
        COMMENT 'JGAAP/IFRS/USGAAP/OTHER等',

    -- -------------------------------------------------------------
    -- 開示元・文書
    -- -------------------------------------------------------------
    source VARCHAR(16) NOT NULL COMMENT 'EDINET/TDNET',
    source_record_key VARCHAR(255) NOT NULL
        COMMENT '取得元内で文書を一意にするキー',
    source_document_id VARCHAR(128) DEFAULT NULL
        COMMENT 'EDINET docID / TDnet文書識別子等',
    source_url TEXT DEFAULT NULL COMMENT '元文書URL',
    disclosure_title VARCHAR(512) DEFAULT NULL COMMENT '開示タイトル',
    disclosed_at DATETIME DEFAULT NULL COMMENT '開示日時',
    is_correction TINYINT(1) NOT NULL DEFAULT 0 COMMENT '訂正開示なら1',

    -- -------------------------------------------------------------
    -- 損益計算書
    -- -------------------------------------------------------------
    revenue DECIMAL(22,2) DEFAULT NULL COMMENT '売上高・営業収益等',
    cost_of_sales DECIMAL(22,2) DEFAULT NULL COMMENT '売上原価',
    gross_profit DECIMAL(22,2) DEFAULT NULL COMMENT '売上総利益',
    sga DECIMAL(22,2) DEFAULT NULL COMMENT '販売費及び一般管理費',
    operating_profit DECIMAL(22,2) DEFAULT NULL COMMENT '営業利益',
    ordinary_profit DECIMAL(22,2) DEFAULT NULL COMMENT '経常利益',
    pretax_profit DECIMAL(22,2) DEFAULT NULL COMMENT '税引前利益',
    net_income DECIMAL(22,2) DEFAULT NULL COMMENT '当期純利益・親会社株主帰属利益等',

    -- -------------------------------------------------------------
    -- 貸借対照表
    -- -------------------------------------------------------------
    total_assets DECIMAL(22,2) DEFAULT NULL COMMENT '総資産',
    total_liabilities DECIMAL(22,2) DEFAULT NULL COMMENT '負債合計',
    equity DECIMAL(22,2) DEFAULT NULL COMMENT '純資産・自己資本等',
    cash_and_equivalents DECIMAL(22,2) DEFAULT NULL COMMENT '現金及び現金同等物',
    inventory DECIMAL(22,2) DEFAULT NULL COMMENT '棚卸資産',
    interest_bearing_debt DECIMAL(22,2) DEFAULT NULL COMMENT '有利子負債。安全に取得できる場合のみ',

    -- -------------------------------------------------------------
    -- キャッシュフロー
    -- -------------------------------------------------------------
    cfo DECIMAL(22,2) DEFAULT NULL COMMENT '営業活動によるCF',
    cfi DECIMAL(22,2) DEFAULT NULL COMMENT '投資活動によるCF',
    cff DECIMAL(22,2) DEFAULT NULL COMMENT '財務活動によるCF',


    -- -------------------------------------------------------------
    -- XBRL監査情報
    -- -------------------------------------------------------------
    revenue_element VARCHAR(255) DEFAULT NULL,
    cost_of_sales_element VARCHAR(255) DEFAULT NULL,
    gross_profit_element VARCHAR(255) DEFAULT NULL,
    sga_element VARCHAR(255) DEFAULT NULL,
    operating_profit_element VARCHAR(255) DEFAULT NULL,
    ordinary_profit_element VARCHAR(255) DEFAULT NULL,
    pretax_profit_element VARCHAR(255) DEFAULT NULL,
    net_income_element VARCHAR(255) DEFAULT NULL,
    total_assets_element VARCHAR(255) DEFAULT NULL,
    total_liabilities_element VARCHAR(255) DEFAULT NULL,
    equity_element VARCHAR(255) DEFAULT NULL,
    cash_and_equivalents_element VARCHAR(255) DEFAULT NULL,
    inventory_element VARCHAR(255) DEFAULT NULL,
    interest_bearing_debt_element VARCHAR(255) DEFAULT NULL,
    cfo_element VARCHAR(255) DEFAULT NULL,
    cfi_element VARCHAR(255) DEFAULT NULL,
    cff_element VARCHAR(255) DEFAULT NULL,

    -- -------------------------------------------------------------
    -- 取得品質・監査
    -- -------------------------------------------------------------
    extraction_method VARCHAR(64) DEFAULT NULL
        COMMENT 'gross_direct/revenue_minus_cost/summary等',
    reconciliation_ok TINYINT(1) DEFAULT NULL
        COMMENT 'Revenue-Cost-Gross等の整合確認結果',
    status VARCHAR(16) NOT NULL DEFAULT 'OK'
        COMMENT 'OK/NG/PARTIAL',
    reason VARCHAR(255) DEFAULT NULL COMMENT 'NG/PARTIAL理由',
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- 同じ取得元・同じ文書・同じ決算期間・同じ連結区分を二重登録しない。
    -- 1文書から将来複数period/scopeを保存できるよう、文書キー単独では一意にしない。
    UNIQUE KEY uq_source_record (
        source,
        source_record_key,
        fiscal_period_end,
        period_type,
        period_basis,
        scope
    ),

    -- 検索・CSV生成処理用
    KEY idx_security_period (
        security_code,
        fiscal_period_end,
        period_type,
        period_basis,
        scope
    ),
    KEY idx_source_period (
        source,
        fiscal_period_end,
        period_type
    ),
    KEY idx_disclosed_at (disclosed_at),
    KEY idx_status (status)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='TDNET/EDINET 財務実績時系列';

-- =====================================================================
-- 設計方針
--
-- financial_actuals は TDNET / EDINET から取得した事実値・出典・監査情報のみ保持する。
-- 粗利率・販管費率・営業利益率などの派生指標は保存しない。
-- source優先順位、訂正優先、preferredレコード選択、派生指標計算は
-- 四季報登録／ChatGPT分析用CSV生成処理側で行う。
-- =====================================================================
