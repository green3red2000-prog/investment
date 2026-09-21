-- J-Quants /v2/markets/margin-interest
-- 信用取引残高保存テーブル
--
-- APIの全レスポンス項目を保存する。
-- 2026-09-24以前は PubDate と金額6項目がNULLとなる仕様。
-- CodeはJ-Quantsの5桁コードをそのまま保存する。

CREATE TABLE margin_interest (
  data_date     DATE         NOT NULL COMMENT '申込日付 Date',
  pub_date      DATE         NULL     COMMENT '公表日 PubDate。2026-09-25申込分以降のみ',
  code          VARCHAR(8)   NOT NULL COMMENT '銘柄コード Code（4桁、証券コードマスタ準拠）',
  iss_type      TINYINT      NULL     COMMENT '銘柄区分 IssType 1:信用銘柄 2:貸借銘柄 3:その他',

  shrt_vol      BIGINT       NULL     COMMENT '売合計信用取引残高（株数） ShrtVol',
  long_vol      BIGINT       NULL     COMMENT '買合計信用取引残高（株数） LongVol',
  shrt_neg_vol  BIGINT       NULL     COMMENT '売一般信用取引残高（株数） ShrtNegVol',
  long_neg_vol  BIGINT       NULL     COMMENT '買一般信用取引残高（株数） LongNegVol',
  shrt_std_vol  BIGINT       NULL     COMMENT '売制度信用取引残高（株数） ShrtStdVol',
  long_std_vol  BIGINT       NULL     COMMENT '買制度信用取引残高（株数） LongStdVol',

  shrt_val      BIGINT       NULL     COMMENT '売合計信用取引残高（金額） ShrtVal。2026-09-25申込分以降',
  long_val      BIGINT       NULL     COMMENT '買合計信用取引残高（金額） LongVal。2026-09-25申込分以降',
  shrt_neg_val  BIGINT       NULL     COMMENT '売一般信用取引残高（金額） ShrtNegVal。2026-09-25申込分以降',
  long_neg_val  BIGINT       NULL     COMMENT '買一般信用取引残高（金額） LongNegVal。2026-09-25申込分以降',
  shrt_std_val  BIGINT       NULL     COMMENT '売制度信用取引残高（金額） ShrtStdVal。2026-09-25申込分以降',
  long_std_val  BIGINT       NULL     COMMENT '買制度信用取引残高（金額） LongStdVal。2026-09-25申込分以降',

  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (code, data_date),
  KEY idx_margin_interest_date (data_date),
  KEY idx_margin_interest_pub_date (pub_date)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='J-Quants 信用取引残高';
