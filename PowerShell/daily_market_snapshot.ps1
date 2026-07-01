# Debug mode: 1 = local check only, 0 = normal download
# Test check number

$TestMode = 0
$TestCheck = 6

$TestHtmlPath = 'C:\work\share\development\investment\data\kabutan\daily_market_snapshot\20260614\05_pts_01_morning_news_page.html'

$Edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$Port = 9222
$Profile = 'C:\work\share\development\investment\browser-profile\edge-kabutan'
$SaveRoot = 'C:\work\share\development\investment\data\kabutan\daily_market_snapshot'

# Target date override for 02_holding.
# Leave blank to use today's date.
# Format: yyyy-MM-dd
$TargetDate02Holding = ''

# Target date override for 03_disclosure.
# Leave blank to use today's date.
# Format: yyyy-MM-dd
$TargetDate03Disclosure = ''

# Target date override for 04_earnings.
# Leave blank to use today's date.
# Format: yyyy-MM-dd
$TargetDate04Earnings = ''

$MaxPage06MorningNews = $null

$CdpTimeoutSec = 60

$Items = @(
  @{ Url = 'https://kabutan.jp/warning/trading_value_ranking'; File = '01_market_01_trading_value_ranking.html'; Check = 1 },
  @{ Url = 'https://kabutan.jp/warning/volume_ranking'; File = '01_market_02_volume_ranking.html'; Check = 1 },
  @{ Url = 'https://kabutan.jp/warning/?mode=2_1'; File = '01_market_03_today_price_rise.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=2_2'; File = '01_market_04_today_price_fall.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=3_1'; File = '01_market_05_stop_high.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=3_2'; File = '01_market_06_stop_low.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/record_w52_high_price?market=0&capitalization=-1&stc=code&stm=0&col=per'; File = '01_market_07_52week_high.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/record_w52_low_price?market=0&capitalization=-1&stc=code&stm=1&col=per'; File = '01_market_08_52week_low.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=3_3&market=0&capitalization=-1&stc=per&stm=0&col=per'; File = '01_market_09_ytd_high.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=3_4&market=0&capitalization=-1&stc=code&stm=1&col=per'; File = '01_market_10_ytd_low.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_11'; File = '01_market_11_week_rise.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_15'; File = '01_market_12_month_rise.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_19'; File = '01_market_13_year_rise.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_13'; File = '01_market_14_past_week_rise.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_17'; File = '01_market_15_past_month_rise.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_21'; File = '01_market_16_past_year_rise.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_12'; File = '01_market_17_week_fall.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_16'; File = '01_market_18_month_fall.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_20'; File = '01_market_19_year_fall.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_14'; File = '01_market_20_past_week_fall.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_18'; File = '01_market_21_past_month_fall.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/?mode=11_22'; File = '01_market_22_past_year_fall.html'; Check = 0 },

  @{ Url = 'https://maonline.jp/kabuhoyu?page=1'; File = '02_holding_01_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=2'; File = '02_holding_02_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=3'; File = '02_holding_03_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=4'; File = '02_holding_04_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=5'; File = '02_holding_05_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=6'; File = '02_holding_06_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=7'; File = '02_holding_07_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=8'; File = '02_holding_08_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=9'; File = '02_holding_09_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=10'; File = '02_holding_10_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=11'; File = '02_holding_11_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=12'; File = '02_holding_12_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=13'; File = '02_holding_13_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=14'; File = '02_holding_14_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=15'; File = '02_holding_15_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=16'; File = '02_holding_16_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=17'; File = '02_holding_17_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=18'; File = '02_holding_18_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=19'; File = '02_holding_19_kabuhoyu_page.html'; Check = 2 },
  @{ Url = 'https://maonline.jp/kabuhoyu?page=20'; File = '02_holding_20_kabuhoyu_page.html'; Check = 2 },
  
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=1'; File = '03_disclosure_01_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=2'; File = '03_disclosure_02_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=3'; File = '03_disclosure_03_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=4'; File = '03_disclosure_04_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=5'; File = '03_disclosure_05_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=6'; File = '03_disclosure_06_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=7'; File = '03_disclosure_07_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=8'; File = '03_disclosure_08_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=9'; File = '03_disclosure_09_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=10'; File = '03_disclosure_10_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=11'; File = '03_disclosure_11_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=12'; File = '03_disclosure_12_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=13'; File = '03_disclosure_13_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=14'; File = '03_disclosure_14_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=15'; File = '03_disclosure_15_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=16'; File = '03_disclosure_16_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=17'; File = '03_disclosure_17_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=18'; File = '03_disclosure_18_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=19'; File = '03_disclosure_19_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=20'; File = '03_disclosure_20_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=21'; File = '03_disclosure_21_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=22'; File = '03_disclosure_22_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=23'; File = '03_disclosure_23_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=24'; File = '03_disclosure_24_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=25'; File = '03_disclosure_25_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=26'; File = '03_disclosure_26_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=27'; File = '03_disclosure_27_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=28'; File = '03_disclosure_28_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=29'; File = '03_disclosure_29_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=30'; File = '03_disclosure_30_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=31'; File = '03_disclosure_31_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=32'; File = '03_disclosure_32_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=33'; File = '03_disclosure_33_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=34'; File = '03_disclosure_34_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=35'; File = '03_disclosure_35_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=36'; File = '03_disclosure_36_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=37'; File = '03_disclosure_37_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=38'; File = '03_disclosure_38_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=39'; File = '03_disclosure_39_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=40'; File = '03_disclosure_40_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=41'; File = '03_disclosure_41_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=42'; File = '03_disclosure_42_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=43'; File = '03_disclosure_43_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=44'; File = '03_disclosure_44_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=45'; File = '03_disclosure_45_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=46'; File = '03_disclosure_46_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=47'; File = '03_disclosure_47_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=48'; File = '03_disclosure_48_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=49'; File = '03_disclosure_49_disclosures_page.html'; Check = 3 },
  @{ Url = 'https://kabutan.jp/disclosures/?kubun=&page=50'; File = '03_disclosure_50_disclosures_page.html'; Check = 3 },

  @{ Url = 'https://kabutan.jp/news/?page=1'; File = '04_earnings_01_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=2'; File = '04_earnings_02_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=3'; File = '04_earnings_03_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=4'; File = '04_earnings_04_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=5'; File = '04_earnings_05_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=6'; File = '04_earnings_06_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=7'; File = '04_earnings_07_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=8'; File = '04_earnings_08_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=9'; File = '04_earnings_09_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=10'; File = '04_earnings_10_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=11'; File = '04_earnings_11_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=12'; File = '04_earnings_12_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=13'; File = '04_earnings_13_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=14'; File = '04_earnings_14_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=15'; File = '04_earnings_15_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=16'; File = '04_earnings_16_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=17'; File = '04_earnings_17_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=18'; File = '04_earnings_18_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=19'; File = '04_earnings_19_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=20'; File = '04_earnings_20_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=21'; File = '04_earnings_21_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=22'; File = '04_earnings_22_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=23'; File = '04_earnings_23_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=24'; File = '04_earnings_24_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=25'; File = '04_earnings_25_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=26'; File = '04_earnings_26_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=27'; File = '04_earnings_27_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=28'; File = '04_earnings_28_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=29'; File = '04_earnings_29_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=30'; File = '04_earnings_30_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=31'; File = '04_earnings_31_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=32'; File = '04_earnings_32_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=33'; File = '04_earnings_33_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=34'; File = '04_earnings_34_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=35'; File = '04_earnings_35_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=36'; File = '04_earnings_36_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=37'; File = '04_earnings_37_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=38'; File = '04_earnings_38_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=39'; File = '04_earnings_39_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=40'; File = '04_earnings_40_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=41'; File = '04_earnings_41_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=42'; File = '04_earnings_42_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=43'; File = '04_earnings_43_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=44'; File = '04_earnings_44_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=45'; File = '04_earnings_45_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=46'; File = '04_earnings_46_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=47'; File = '04_earnings_47_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=48'; File = '04_earnings_48_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=49'; File = '04_earnings_49_kabutan_news_page.html'; Check = 4 },
  @{ Url = 'https://kabutan.jp/news/?page=50'; File = '04_earnings_50_kabutan_news_page.html'; Check = 4 },

  @{ Url = 'https://kabutan.jp/warning/pts_night_price_increase'; File = '05_pts_01_pts_night_price_increase.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/warning/pts_night_price_decrease'; File = '05_pts_02_pts_night_price_decrease.html'; Check = 0 },

  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=1'; File = '06_news_01_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=2'; File = '06_news_02_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=3'; File = '06_news_03_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=4'; File = '06_news_04_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=5'; File = '06_news_05_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=6'; File = '06_news_06_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=7'; File = '06_news_07_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=8'; File = '06_news_08_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=9'; File = '06_news_09_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=10'; File = '06_news_10_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=11'; File = '06_news_11_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=12'; File = '06_news_12_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=13'; File = '06_news_13_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=14'; File = '06_news_14_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=15'; File = '06_news_15_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=16'; File = '06_news_16_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=17'; File = '06_news_17_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=18'; File = '06_news_18_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=19'; File = '06_news_19_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=20'; File = '06_news_20_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=21'; File = '06_news_21_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=22'; File = '06_news_22_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=23'; File = '06_news_23_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=24'; File = '06_news_24_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=25'; File = '06_news_25_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=26'; File = '06_news_26_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=27'; File = '06_news_27_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=28'; File = '06_news_28_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=29'; File = '06_news_29_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=30'; File = '06_news_30_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=31'; File = '06_news_31_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=32'; File = '06_news_32_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=33'; File = '06_news_33_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=34'; File = '06_news_34_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=35'; File = '06_news_35_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=36'; File = '06_news_36_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=37'; File = '06_news_37_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=38'; File = '06_news_38_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=39'; File = '06_news_39_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=40'; File = '06_news_40_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=41'; File = '06_news_41_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=42'; File = '06_news_42_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=43'; File = '06_news_43_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=44'; File = '06_news_44_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=45'; File = '06_news_45_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=46'; File = '06_news_46_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=47'; File = '06_news_47_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=48'; File = '06_news_48_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=49'; File = '06_news_49_morning_news_page.html'; Check = 6 },
  @{ Url = 'https://kabutan.jp/warning/?mode=4_1&market=0&capitalization=-1&stc=&stm=1&col=zenhiritsu&page=50'; File = '06_news_50_morning_news_page.html'; Check = 6 },
  
  @{ Url = 'https://kabutan.jp/stock/kabuka?code=0000&ashi=day'; File = '07_index_0000_market_price.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/stock/kabuka?code=0001&ashi=day'; File = '07_index_0001_market_price.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/stock/kabuka?code=0010&ashi=day'; File = '07_index_0010_market_price.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/stock/kabuka?code=0012&ashi=day'; File = '07_index_0012_market_price.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/stock/kabuka?code=0018&ashi=day'; File = '07_index_0018_market_price.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/stock/kabuka?code=0019&ashi=day'; File = '07_index_0019_market_price.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/stock/kabuka?code=0020&ashi=day'; File = '07_index_0020_market_price.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/stock/kabuka?code=0105&ashi=day'; File = '07_index_0105_market_price.html'; Check = 0 }
)

Write-Host "[DEBUG] script path = $PSCommandPath"
Write-Host "[DEBUG] TestMode = $TestMode"
Write-Host "[DEBUG] TestCheck = $TestCheck"

function Wait-Cdp {
  param($Port)

  for ($i = 0; $i -lt 30; $i++) {
    try {
      Invoke-RestMethod "http://127.0.0.1:$Port/json/version" | Out-Null
      return
    } catch {
      Start-Sleep -Seconds 1
    }
  }

  throw 'CDP not available'
}

function Send-Cdp {
  param($Ws, $Id, $Method, $Params)

  $Obj = @{
    id = $Id
    method = $Method
  }

  if ($Params -ne $null) {
    $Obj.params = $Params
  }

  $Json = $Obj | ConvertTo-Json -Depth 20 -Compress
  $Bytes = [System.Text.Encoding]::UTF8.GetBytes($Json)
  $Seg = New-Object System.ArraySegment[byte] -ArgumentList @(,$Bytes)

  $Cts = New-Object System.Threading.CancellationTokenSource
  $Cts.CancelAfter($CdpTimeoutSec * 1000)

  try {
    $Task = $Ws.SendAsync(
      $Seg,
      [System.Net.WebSockets.WebSocketMessageType]::Text,
      $true,
      $Cts.Token
    )

    if (-not $Task.Wait($CdpTimeoutSec * 1000)) {
      throw "CDP send timeout: id=$Id method=$Method"
    }
  } finally {
    $Cts.Dispose()
  }
}

function Receive-Cdp {
  param($Ws)

  $Buffer = New-Object byte[] 1048576
  $Ms = New-Object System.IO.MemoryStream

  $Cts = New-Object System.Threading.CancellationTokenSource
  $Cts.CancelAfter($CdpTimeoutSec * 1000)

  try {
    do {
      $Seg = New-Object System.ArraySegment[byte] -ArgumentList @(,$Buffer)
      $Task = $Ws.ReceiveAsync($Seg, $Cts.Token)

      if (-not $Task.Wait($CdpTimeoutSec * 1000)) {
        throw "CDP receive timeout"
      }

      $Result = $Task.Result

      if ($Result.MessageType -eq [System.Net.WebSockets.WebSocketMessageType]::Close) {
        throw "CDP websocket closed"
      }

      $Ms.Write($Buffer, 0, $Result.Count)

    } while (-not $Result.EndOfMessage)
  } finally {
    $Cts.Dispose()
  }

  $Text = [System.Text.Encoding]::UTF8.GetString($Ms.ToArray())
  return $Text | ConvertFrom-Json
}

function Invoke-Cdp {
  param($Ws, $Id, $Method, $Params)

  Send-Cdp $Ws $Id $Method $Params

  $Limit = (Get-Date).AddSeconds($CdpTimeoutSec)

  while ((Get-Date) -lt $Limit) {
    $Msg = Receive-Cdp $Ws

    if ($Msg.id -eq $Id) {
      if ($Msg.error -ne $null) {
        throw "CDP error: id=$Id method=$Method message=$($Msg.error.message)"
      }

      return $Msg
    }
  }

  throw "CDP invoke timeout: id=$Id method=$Method"
}

function Get-Html-From-Edge {
  param($Port, $Url)

  $Target = Invoke-RestMethod -Method Put "http://127.0.0.1:$Port/json/new?about:blank"

  $TargetId = $Target.id
  $WsUrl = $Target.webSocketDebuggerUrl

  $Ws = [System.Net.WebSockets.ClientWebSocket]::new()
  $CtsConnect = New-Object System.Threading.CancellationTokenSource
  $CtsConnect.CancelAfter($CdpTimeoutSec * 1000)

  try {
    $ConnectTask = $Ws.ConnectAsync([Uri]$WsUrl, $CtsConnect.Token)

    if (-not $ConnectTask.Wait($CdpTimeoutSec * 1000)) {
      throw "CDP connect timeout"
    }
  } finally {
    $CtsConnect.Dispose()
  }

  try {
    Invoke-Cdp $Ws 1 'Network.enable' $null | Out-Null
    Invoke-Cdp $Ws 2 'Page.enable' $null | Out-Null

    Invoke-Cdp $Ws 3 'Network.setCookie' @{
      name = 'shared_perpage'
      value = '50'
      domain = 'kabutan.jp'
      path = '/'
    } | Out-Null

    Invoke-Cdp $Ws 4 'Page.navigate' @{
      url = $Url
    } | Out-Null

    Start-Sleep -Seconds 8

    $Res = Invoke-Cdp $Ws 5 'Runtime.evaluate' @{
      expression = 'document.documentElement.outerHTML'
      returnByValue = $true
    }

    $Html = $Res.result.result.value
  } finally {
    try {
      if ($Ws.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
        $Ws.CloseAsync(
          [System.Net.WebSockets.WebSocketCloseStatus]::NormalClosure,
          'done',
          [Threading.CancellationToken]::None
        ).Wait()
      }
    } catch {
    }

    try {
      Invoke-RestMethod -Method Get "http://127.0.0.1:$Port/json/close/$TargetId" | Out-Null
    } catch {
    }
  }

  return $Html
}

function Get-PageNumberFromUrl {
  param([string]$Url)

  if ($Url -match '[?&]page=(\d+)') {
    return [int]$Matches[1]
  }

  return 1
}

function Get-MaxPage06MorningNews {
  param([string]$Html)

  $MaxPage = $null

  if ($Html -match '<div class="meigara_count">[\s\S]*?<li>\s*([0-9,]+)銘柄\s*</li>') {
    $CountText = $Matches[1] -replace ',', ''
    $Count = [int]$CountText
    $MaxPage = [math]::Ceiling($Count / 50)

    Write-Host "[CHECK] morning news stock count = $Count"
    Write-Host "[CHECK] morning news max page by count = $MaxPage"

    return [int]$MaxPage
  }

  $PageMatches = [regex]::Matches($Html, 'page=(\d+)')

  foreach ($m in $PageMatches) {
    $PageNo = [int]$m.Groups[1].Value

    if ($MaxPage -eq $null -or $PageNo -gt $MaxPage) {
      $MaxPage = $PageNo
    }
  }

  if ($MaxPage -eq $null) {
    throw 'morning news max page not found'
  }

  Write-Host "[CHECK] morning news max page by pagination = $MaxPage"

  return [int]$MaxPage
}

function Test-KabutanMorningNews {
  param([string]$Html)

  $StockCount = [regex]::Matches($Html, '<td class="tac">\s*<a href="/stock/\?code=([0-9A-Z]{4})">').Count
  Write-Host "[CHECK] morning news stock row count = $StockCount"

  if ($StockCount -lt 1) {
    throw "morning news stock row count is zero: $StockCount"
  }

  return $true
}

function Test-Html-Basic {
  param(
    [string]$Html
  )

  if ([string]::IsNullOrWhiteSpace($Html)) {
    throw 'html is empty'
  }

  if ($Html.Length -lt 1000) {
    throw "html too short: $($Html.Length)"
  }

  if ($Html -notmatch '(?i)<html') {
    throw 'html does not contain html tag'
  }

  if ($Html -notmatch '(?i)</html>') {
    throw 'html does not contain closing html tag'
  }

  if ($Html -match 'アクセスが集中|しばらくしてから') {
    throw "html contains kabutan error word: $($Matches[0])"
  }

  return $true
}

function Test-StockCount50 {
  param(
    [string]$Html
  )

  $Pattern = '<td class="tac">\s*<a href="/stock/\?code=([0-9A-Z]{4})">\1</a>\s*</td>'

  $Count = [regex]::Matches($Html, $Pattern).Count
  
  Write-Host "[CHECK] stock count = $Count"

  if ($Count -ne 50) {
    throw "stock count is not 50: $Count"
  }

  return $true
}

function Test-MaonlineKabuhoyu {
  param(
    [string]$Html
  )

  $NewsCount = [regex]::Matches($Html, '<div class="news">').Count
  Write-Host "[CHECK] news count = $NewsCount"

  if ($NewsCount -lt 1) {
    throw "news count is zero: $NewsCount"
  }

  return $true
}

function Test-KabutanDisclosures {
  param(
    [string]$Html
  )

  $RowCount = [regex]::Matches($Html, '<tr[^>]*>[\s\S]*?<time[^>]*>[\s\S]*?</time>[\s\S]*?</tr>').Count
  Write-Host "[CHECK] disclosure row count = $RowCount"

  if ($RowCount -lt 1) {
    throw "disclosure row count is zero: $RowCount"
  }

  return $true
}

function Test-KabutanEarnings {
  param(
    [string]$Html
  )

  $RowCount = [regex]::Matches($Html, '<tr[^>]*>[\s\S]*?<time[^>]*datetime="[^"]+"[\s\S]*?</tr>').Count
  Write-Host "[CHECK] earnings row count = $RowCount"

  if ($RowCount -lt 1) {
    throw "earnings row count is zero: $RowCount"
  }

  return $true
}

function Test-ShouldContinueNextPage04Earnings {
  param(
    [string]$Html
  )

  if ([string]::IsNullOrWhiteSpace($TargetDate04Earnings)) {
    $TargetDate = (Get-Date).Date
  } else {
    $TargetDate = [datetime]::ParseExact(
      $TargetDate04Earnings,
      'yyyy-MM-dd',
      $null
    ).Date
  }

  Write-Host "[CHECK] target date 04_earnings = $($TargetDate.ToString('yyyy-MM-dd'))"

  $DateMatches = [regex]::Matches($Html, '<time[^>]*datetime="([^"]+)"')

  Write-Host "[CHECK] earnings date count = $($DateMatches.Count)"

  if ($DateMatches.Count -lt 1) {
    throw 'earnings date count is zero'
  }

  foreach ($m in $DateMatches) {
    $IsoText = $m.Groups[1].Value
    Write-Host "[CHECK] earnings datetime = $IsoText"

    $DateValue = ([datetime]$IsoText).Date

    if ($DateValue -lt $TargetDate) {
      Write-Host "[STOP] old earnings date found: $($DateValue.ToString('yyyy-MM-dd'))"
      return $false
    }
  }

  return $true
}

function Test-ShouldContinueNextPage03Disclosure {
  param(
    [string]$Html
  )

  if ([string]::IsNullOrWhiteSpace($TargetDate03Disclosure)) {
    $TargetDate = (Get-Date).Date
  } else {
    $TargetDate = [datetime]::ParseExact(
      $TargetDate03Disclosure,
      'yyyy-MM-dd',
      $null
    ).Date
  }

  Write-Host "[CHECK] target date 03_disclosure = $($TargetDate.ToString('yyyy-MM-dd'))"

  $DateMatches = [regex]::Matches($Html, '<time[^>]*>([\s\S]*?)</time>')

  Write-Host "[CHECK] disclosure date count = $($DateMatches.Count)"

  if ($DateMatches.Count -lt 1) {
    throw 'disclosure date count is zero'
  }

  foreach ($m in $DateMatches) {
    $DateTextRaw = $m.Groups[1].Value
    $DateText = ($DateTextRaw -replace '<[^>]+>', '')
    $DateText = ($DateText -replace '&nbsp;', ' ')
    $DateText = ($DateText -replace '\s+', ' ').Trim()

    $DateOnly = $null

    if ($DateText -match '(\d{4})/(\d{2})/(\d{2})') {
      $DateOnly = "$($Matches[1])-$($Matches[2])-$($Matches[3])"
    } elseif ($DateText -match '(?:^|\s)(\d{2})/(\d{2})/(\d{2})(?:\s|$)') {
      $DateOnly = "20$($Matches[1])-$($Matches[2])-$($Matches[3])"
    }

    if ($DateOnly -eq $null) {
      continue
    }
    
    Write-Host "[CHECK] disclosure datetime = $DateText"

    $DateValue = [datetime]::ParseExact($DateOnly, 'yyyy-MM-dd', $null).Date

    if ($DateValue -lt $TargetDate) {
      Write-Host "[STOP] old disclosure date found: $DateOnly"
      return $false
    }
  }

  return $true
}

function Test-ShouldContinueNextPage {
  param(
    [string]$Html
  )

  if ([string]::IsNullOrWhiteSpace($TargetDate02Holding)) {
    $TargetDate = (Get-Date).Date
  } else {
    $TargetDate = [datetime]::ParseExact(
      $TargetDate02Holding,
      'yyyy-MM-dd',
      $null
    ).Date
  }

  Write-Host "[CHECK] target date = $($TargetDate.ToString('yyyy-MM-dd'))"
    
  $DateMatches = [regex]::Matches($Html, '<div class="date">(\d{4}-\d{2}-\d{2})</div>')

  Write-Host "[CHECK] date count = $($DateMatches.Count)"

  if ($DateMatches.Count -lt 1) {
    throw 'date count is zero'
  }

  foreach ($m in $DateMatches) {
    $DateText = $m.Groups[1].Value
    $DateValue = [datetime]::ParseExact($DateText, 'yyyy-MM-dd', $null)

    Write-Host "[CHECK] date = $DateText"

    if ($DateValue -lt $TargetDate) {
      Write-Host "[STOP] old date found: $DateText"
      return $false
    }
  }

  return $true
}

function Test-Html-ByCheck {
  param(
    [string]$Html,
    [int]$Check
  )

  Test-Html-Basic $Html | Out-Null

  if ($Check -eq 1) {
    Test-StockCount50 $Html | Out-Null
  }

  if ($Check -eq 2) {
    Test-MaonlineKabuhoyu $Html | Out-Null
  }
  
  if ($Check -eq 3) {
    Test-KabutanDisclosures $Html | Out-Null
  }
  
  if ($Check -eq 4) {
    Test-KabutanEarnings $Html | Out-Null
  }
  
  if ($Check -eq 6) {
    Test-KabutanMorningNews $Html | Out-Null
  }

  return $true
}

function Invoke-ItemDownload {
  param(
    $Item,
    [string]$SaveDir,
    $Utf8NoBom,
    [int]$Index,
    [int]$Total
  )

  $Url = $Item.Url
  $FileName = $Item.File
  $Check = 0

  if ($Item.ContainsKey('Check')) {
    $Check = [int]$Item.Check
  }

  $SavePath = Join-Path $SaveDir $FileName

  Write-Host "[INFO] $Index/$Total start"
  Write-Host $Url
  Write-Host "[INFO] check=$Check"

  $Html = Get-Html-From-Edge $Port $Url

  Test-Html-ByCheck $Html $Check | Out-Null

  [System.IO.File]::WriteAllText($SavePath, $Html, $Utf8NoBom)

  Write-Host "[OK] saved: $SavePath"
  
  return $Html
}

if ($TestMode -eq 1) {

  Write-Host "[TEST MODE]"
  Write-Host "[TEST] path : $TestHtmlPath"
  Write-Host "[TEST] check: $TestCheck"

  if (-not (Test-Path $TestHtmlPath)) {
    throw "test html not found: $TestHtmlPath"
  }

  $Html = Get-Content $TestHtmlPath -Raw -Encoding UTF8

  Write-Host "[TEST] html length = $($Html.Length)"

  try {

    Test-Html-ByCheck $Html $TestCheck | Out-Null

    if ($TestCheck -eq 2) {
      $ContinueNext = Test-ShouldContinueNextPage $Html
      Write-Host "[TEST] ContinueNext = $ContinueNext"
    }
    
    if ($TestCheck -eq 3) {
      $ContinueNext = Test-ShouldContinueNextPage03Disclosure $Html
      Write-Host "[TEST] ContinueNext03Disclosure = $ContinueNext"
    }
    
    if ($TestCheck -eq 4) {
      $ContinueNext = Test-ShouldContinueNextPage04Earnings $Html
      Write-Host "[TEST] ContinueNext04Earnings = $ContinueNext"
    }
    
    if ($TestCheck -eq 6) {
      $MaxPage = Get-MaxPage06MorningNews $Html
      Write-Host "[TEST] MaxPage06MorningNews = $MaxPage"
    }

    Write-Host "[TEST OK]"

  } catch {

    Write-Host "[TEST NG]"
    Write-Host $_

    throw
  }

  exit
}


if (-not (Test-Path $Edge)) {
  throw 'msedge.exe not found'
}

New-Item -ItemType Directory -Force -Path $Profile | Out-Null

$SaveDir = Join-Path $SaveRoot (Get-Date -Format 'yyyyMMdd')
New-Item -ItemType Directory -Force -Path $SaveDir | Out-Null

Write-Host '[INFO] kill existing edge'

try {
  taskkill /F /IM msedge.exe 2>$null | Out-Null
} catch {
}

Start-Sleep -Seconds 5

Write-Host '[INFO] start edge'

Start-Process $Edge -ArgumentList "--remote-debugging-port=$Port --user-data-dir=`"$Profile`" about:blank"

Start-Sleep -Seconds 5
Wait-Cdp $Port

$Utf8NoBom = New-Object System.Text.UTF8Encoding $false

$RetryItems = @()
$FailedItems = @()

$SkipMaonlineKabuhoyu = $false
$SkipKabutanDisclosures = $false
$SkipKabutanEarnings = $false

for ($i = 0; $i -lt $Items.Count; $i++) {

  $Item = $Items[$i]

  if ($SkipMaonlineKabuhoyu -and $Item.Url -match 'maonline\.jp/kabuhoyu\?page=') {
    Write-Host "[SKIP] maonline kabuhoyu old date already found"
    Write-Host $Item.Url
    continue
  }
  
  if ($SkipKabutanDisclosures -and $Item.Url -match 'kabutan\.jp/disclosures/') {
    Write-Host "[SKIP] kabutan disclosures old date already found"
    Write-Host $Item.Url
    continue
  }
  
  if ($SkipKabutanEarnings -and $Item.Url -match 'kabutan\.jp/news/') {
    Write-Host "[SKIP] kabutan earnings old date already found"
    Write-Host $Item.Url
    continue
  }
  
  if ($Item.Url -match 'kabutan\.jp/warning/\?mode=4_1') {
    $CurrentPage06 = Get-PageNumberFromUrl $Item.Url

    if ($MaxPage06MorningNews -ne $null -and $CurrentPage06 -gt $MaxPage06MorningNews) {
      Write-Host "[SKIP] morning news page exceeds max page"
      Write-Host "[SKIP] current=$CurrentPage06 max=$MaxPage06MorningNews"
      Write-Host $Item.Url
      continue
    }
  }

  try {
    $Html = Invoke-ItemDownload `
      -Item $Item `
      -SaveDir $SaveDir `
      -Utf8NoBom $Utf8NoBom `
      -Index ($i + 1) `
      -Total $Items.Count

    if ($Item.Url -match 'maonline\.jp/kabuhoyu\?page=') {
      $ContinueNext = Test-ShouldContinueNextPage $Html

      if (-not $ContinueNext) {
        $SkipMaonlineKabuhoyu = $true
      }
    }
    
    if ($Item.Url -match 'kabutan\.jp/disclosures/') {
      $ContinueNext = Test-ShouldContinueNextPage03Disclosure $Html

      if (-not $ContinueNext) {
        $SkipKabutanDisclosures = $true
      }
    }

    if ($Item.Url -match 'kabutan\.jp/news/') {
      $ContinueNext = Test-ShouldContinueNextPage04Earnings $Html

      if (-not $ContinueNext) {
        $SkipKabutanEarnings = $true
      }
    }
    
    if ($Item.Url -match 'kabutan\.jp/warning/\?mode=4_1') {
      $CurrentPage06 = Get-PageNumberFromUrl $Item.Url

      if ($CurrentPage06 -eq 1 -and $MaxPage06MorningNews -eq $null) {
        $MaxPage06MorningNews = Get-MaxPage06MorningNews $Html
      }
    }
    
  } catch {
    Write-Host "[WARN] failed, queued for retry"
    Write-Host $Item.Url
    Write-Host $_

    $RetryItems += $Item
  }

  if ($i -lt $Items.Count - 1) {
    $Rand = Get-Random -Minimum 10 -Maximum 31
    $Wait = 40 + $Rand
    Write-Host "[INFO] wait $Wait sec"
    Start-Sleep -Seconds $Wait
  }
}

if ($RetryItems.Count -gt 0) {
  Write-Host "[INFO] retry start: $($RetryItems.Count) item(s)"

  for ($i = 0; $i -lt $RetryItems.Count; $i++) {
    $Item = $RetryItems[$i]

    try {
      $Rand = Get-Random -Minimum 20 -Maximum 41
      $Wait = 60 + $Rand
      Write-Host "[INFO] retry wait $Wait sec"
      Start-Sleep -Seconds $Wait

      $Html = Invoke-ItemDownload `
        -Item $Item `
        -SaveDir $SaveDir `
        -Utf8NoBom $Utf8NoBom `
        -Index ($i + 1) `
        -Total $Items.Count

      if ($Item.Url -match 'maonline\.jp/kabuhoyu\?page=') {
        $ContinueNext = Test-ShouldContinueNextPage $Html

        if (-not $ContinueNext) {
          $SkipMaonlineKabuhoyu = $true
        }
      }
      
      if ($Item.Url -match 'kabutan\.jp/disclosures/') {
        $ContinueNext = Test-ShouldContinueNextPage03Disclosure $Html

        if (-not $ContinueNext) {
          $SkipKabutanDisclosures = $true
        }
      }
      
      if ($Item.Url -match 'kabutan\.jp/news/') {
        $ContinueNext = Test-ShouldContinueNextPage04Earnings $Html

        if (-not $ContinueNext) {
          $SkipKabutanEarnings = $true
        }
      }

      if ($Item.Url -match 'kabutan\.jp/warning/\?mode=4_1') {
        $CurrentPage06 = Get-PageNumberFromUrl $Item.Url

        if ($CurrentPage06 -eq 1 -and $MaxPage06MorningNews -eq $null) {
          $MaxPage06MorningNews = Get-MaxPage06MorningNews $Html
        }
      }

    } catch {
      Write-Host "[ERROR] retry failed"
      Write-Host $Item.Url
      Write-Host $_

      $FailedItems += @{
        Url = $Item.Url
        File = $Item.File
        Check = $Item.Check
        Error = $_.ToString()
      }
    }
  }
}

if ($FailedItems.Count -gt 0) {
  Write-Host "[FATAL] failed item(s) remain: $($FailedItems.Count)"

  foreach ($Failed in $FailedItems) {
    Write-Host "----------------------------------------"
    Write-Host "URL   : $($Failed.Url)"
    Write-Host "File  : $($Failed.File)"
    Write-Host "Check : $($Failed.Check)"
    Write-Host "Error : $($Failed.Error)"
  }

  throw 'download finished with errors'
}

Write-Host '[DONE] all finished'