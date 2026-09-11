param(
  [Parameter(Mandatory = $true, Position = 0)]
  [ValidateSet('000', '001')]
  [string]$Mode
)

# Debug mode: 1 = local check only, 0 = normal download
# Test check number
# 起動例
# powershell -ExecutionPolicy Bypass -File .\daily_market_snapshot.ps1 001

$TestMode = 0
$TestCheck = 6

$TestHtmlPath = 'C:\work\share\development\investment\data\kabutan\daily_market_snapshot\20260614\05_pts_01_morning_news_page.html'

$Edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$Port = 9222
$Profile = 'C:\work\share\development\investment\browser-profile\edge-kabutan'
$SaveRoot = 'C:\work\share\development\investment\data\kabutan\daily_market_snapshot'

# Target file prefix.
# Leave blank to download all files allowed by Mode.
# Available values: 01 through 10.
# Multiple values are not supported.
$TargetFile = ''

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
  @{ Url = 'https://kabutan.jp/stock/kabuka?code=0105&ashi=day'; File = '07_index_0105_market_price.html'; Check = 0 },
  
  @{ Url = 'https://shikiho.toyokeizai.net/stocks/7203'; File = '08_shikiho_7203_market_price.html'; Check = 0 },
  @{ Url = 'https://shikiho.toyokeizai.net/stocks/6758'; File = '08_shikiho_6758_market_price.html'; Check = 0 },
  @{ Url = 'https://shikiho.toyokeizai.net/stocks/9432'; File = '08_shikiho_9432_market_price.html'; Check = 0 },

  @{ Url = 'https://nikkei225jp.com/chart/gyoushu.php'; File = '09_extract_01_tosho_sector_index.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/data/per.php'; File = '09_extract_02_nikkei225_valuation.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/data/touraku.php'; File = '09_extract_03_advance_decline_ratio.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/data/karauri.php'; File = '09_extract_04_short_selling_ratio.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/chart/nikkei.php'; File = '09_extract_05_nikkei225_contribution.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/data/vix.php'; File = '09_extract_06_volatility_index.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/bond/'; File = '09_extract_07_government_bond_yield.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/data/us_per.php'; File = '09_extract_08_us_market_valuation.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/schedule/'; File = '09_extract_09_economic_schedule.html'; Check = 0 },
  @{ Url = 'https://www.jpx.co.jp/'; File = '09_extract_10_jpx_home.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/data/sinyou.php'; File = '09_extract_11_margin_balance_profit_loss.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/data/new.php'; File = '09_extract_12_new_high_low.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/data/shutai.php'; File = '09_extract_13_investor_type_trading.html'; Check = 0 },
  @{ Url = 'https://kabutan.jp/info/accessranking/3_2'; File = '09_extract_14_kabutan_theme_access_ranking.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/data/buffett.php'; File = '09_extract_15_global_buffett_indicator.html'; Check = 0 },
  @{ Url = 'https://fred.stlouisfed.org/series/WALCL'; File = '09_extract_16_fed_total_assets.html'; Check = 0 },
  @{ Url = 'https://fred.stlouisfed.org/series/BAMLH0A0HYM2'; File = '09_extract_17_us_high_yield_spread.html'; Check = 0 },
  @{ Url = 'https://www.atlantafed.org/research-and-data/data/gdpnow'; File = '09_extract_18_gdpnow.html'; Check = 0 },
  @{ Url = 'https://nikkei225jp.com/'; File = '09_extract_19_global_market_realtime.html'; Check = 0 },
  @{ Url = 'https://fred.stlouisfed.org/series/BAMLC0A0CM'; File = '09_extract_20_us_corporate_spread.html'; Check = 0 },

  @{ Url = 'https://www.jpx.co.jp/markets/statistics-equities/program/index.html'; File = '10_download_01_jpx_arbitrage_daily.xls';  Check = 0; DownloadType = 'LatestExcel'; DownloadSectionTitle = '裁定取引の状況（日別）' },
  @{ Url = 'https://www.jpx.co.jp/markets/statistics-equities/program/01.html'; File = '10_download_02_jpx_program_trading_weekly.xls'; Check = 0; DownloadType = 'LatestExcel'; DownloadSectionTitle = 'プログラム売買の状況（週間）' },
  @{ Url = 'https://www.boj.or.jp/statistics/boj/fm/juq/index.htm'; File = '10_download_03_boj_current_account_final.xlsx'; Check = 0; DownloadType = 'LatestExcel'; DownloadSectionTitle = '公表データ（確報）' },
  @{ Url = 'https://www.boj.or.jp/statistics/boj/fm/ope/index.htm'; File = '10_download_04_boj_operation_offer_results.xlsx'; Check = 0; DownloadType = 'LatestExcel'; DownloadSectionTitle = 'オファー／落札結果' }
)

Write-Host "[DEBUG] script path = $PSCommandPath"
Write-Host "[DEBUG] TestMode = $TestMode"
Write-Host "[DEBUG] TestCheck = $TestCheck"

# Filter scraping targets based on the execution mode.
# 000: Index daily price files only (07_)
# 001: Daily market snapshot files and Shikiho monitoring files
#      (01_ through 06_, 08_, 09_, and 10_)
if ($Mode -eq '000') {
  $Items = @(
    $Items | Where-Object {
      $_.File -like '07_*'
    }
  )
} else {
  $Items = @(
    $Items | Where-Object {
      $_.File -match '^0[1-6]_' -or
      $_.File -like '08_*' -or
      $_.File -like '09_*' -or
      $_.File -like '10_*'
    }
  )
}

# Filter scraping targets by file prefix.
# Blank: all files allowed by Mode.
# 01 through 10: files whose names begin with the specified prefix.
if (-not [string]::IsNullOrWhiteSpace($TargetFile)) {
  $TargetFile = $TargetFile.Trim()

  if ($TargetFile -notmatch '^(0[1-9]|10)$') {
    throw "invalid TargetFile: $TargetFile. available values are 01 through 10"
  }

  $Items = @(
    $Items | Where-Object {
      $_.File -like "${TargetFile}_*"
    }
  )
}

if ($Items.Count -eq 0) {
  throw "scraping target not found. mode=$Mode targetFile=$TargetFile"
}

Write-Host "[INFO] mode=$Mode"
Write-Host "[INFO] target file=$TargetFile"
Write-Host "[INFO] target items=$($Items.Count)"


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
function Add-Nikkei225ValuationExtractData {
  param(
    [Parameter(Mandatory = $true)]
    $Ws
  )

  Write-Host '[INFO] nikkei225 valuation DOM processing start'

      $Expression = @'
(function () {
  var DATA_ELEMENT_ID = "mde_nikkei225_valuation_data";
  var EXPECTED_ROW_COUNT = 60;
  var REQUIRED_SOURCE_COUNT = EXPECTED_ROW_COUNT + 1;

  if (!Array.isArray(window.DAILY)) {
    throw new Error("DAILY is not available");
  }

  if (typeof window.gdt !== "function") {
    throw new Error("gdt is not available");
  }

  if (window.DAILY.length <= 65 + REQUIRED_SOURCE_COUNT) {
    throw new Error(
      "DAILY row count is too small: " +
      window.DAILY.length
    );
  }

  function toNumber(value, name, rowNumber) {
    var text =
      value === null || value === undefined
        ? ""
        : String(value);

    var number = Number(
      text.replace(/,/g, "").trim()
    );

    if (!isFinite(number)) {
      throw new Error(
        name +
        " is not numeric. row=" +
        rowNumber +
        " value=" +
        text
      );
    }

    return number;
  }

  function formatNumber(value, decimals, forceSign) {
    var sign =
      forceSign && value > 0
        ? "+"
        : "";

    return (
      sign +
      value.toLocaleString(
        "en-US",
        {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals,
          useGrouping: true
        }
      )
    );
  }

  function buildRows(basis) {
    var perColumn =
      basis === "index_base"
        ? 25
        : 12;

    var pbrColumn =
      basis === "index_base"
        ? 26
        : 13;

    var dividendColumn =
      basis === "index_base"
        ? 27
        : 14;

    var sourceRows = [];

    for (var i = 65; i < window.DAILY.length; i++) {
      var sourceRow = window.DAILY[i];

      if (
        Array.isArray(sourceRow) &&
        Number(sourceRow[1]) > 0
      ) {
        sourceRows.push({
          row: sourceRow,
          date: window.gdt(sourceRow[0])
        });
      }
    }

    sourceRows.sort(function (a, b) {
      if (a.date < b.date) {
        return 1;
      }

      if (a.date > b.date) {
        return -1;
      }

      return 0;
    });

    if (sourceRows.length < REQUIRED_SOURCE_COUNT) {
      throw new Error(
        "source row count is too small: " +
        sourceRows.length
      );
    }

    var result = [];

    for (
      var index = 0;
      index < EXPECTED_ROW_COUNT;
      index++
    ) {
      var item = sourceRows[index];
      var row = item.row;
      var previousRow =
        sourceRows[index + 1].row;
      var rowNumber = index + 1;

      var nikkei225 =
        toNumber(
          row[1],
          "nikkei225",
          rowNumber
        );

      var previousNikkei225 =
        toNumber(
          previousRow[1],
          "previous_nikkei225",
          rowNumber
        );

      var change =
        nikkei225 -
        previousNikkei225;

      var primeVolume =
        toNumber(
          row[2],
          "prime_volume",
          rowNumber
        );

      var per =
        toNumber(
          row[perColumn],
          "per",
          rowNumber
        );

      var pbr =
        toNumber(
          row[pbrColumn],
          "pbr",
          rowNumber
        );

      var dividendYield =
        toNumber(
          row[dividendColumn],
          "dividend_yield",
          rowNumber
        );

      var jgbYield =
        toNumber(
          row[16],
          "jgb_yield",
          rowNumber
        );

      if (per <= 0 || pbr <= 0) {
        throw new Error(
          "invalid per/pbr. row=" +
          rowNumber +
          " per=" +
          per +
          " pbr=" +
          pbr
        );
      }

      result.push({
        date:
          item.date,

        nikkei225:
          formatNumber(
            nikkei225,
            2,
            false
          ),

        change:
          formatNumber(
            change,
            2,
            true
          ),

        prime_volume:
          formatNumber(
            primeVolume,
            0,
            false
          ),

        per:
          formatNumber(
            per,
            2,
            false
          ),

        pbr:
          formatNumber(
            pbr,
            2,
            false
          ),

        eps:
          formatNumber(
            nikkei225 / per,
            2,
            false
          ),

        bps:
          formatNumber(
            nikkei225 / pbr,
            2,
            false
          ),

        earnings_yield:
          formatNumber(
            100 / per,
            2,
            false
          ),

        dividend_yield:
          formatNumber(
            dividendYield,
            2,
            false
          ),

        jgb_yield:
          formatNumber(
            jgbYield,
            3,
            false
          )
      });
    }

    return result;
  }

  var indexBaseRows =
    buildRows("index_base");

  var weightedAverageRows =
    buildRows("weighted_average");

  for (
    var j = 0;
    j < EXPECTED_ROW_COUNT;
    j++
  ) {
    if (
      indexBaseRows[j].date !==
      weightedAverageRows[j].date
    ) {
      throw new Error(
        "date mismatch. row=" +
        (j + 1)
      );
    }
  }

  var existing =
    document.getElementById(
      DATA_ELEMENT_ID
    );

  if (existing) {
    existing.parentNode.removeChild(existing);
  }

  var script =
    document.createElement("script");

  script.id =
    DATA_ELEMENT_ID;

  script.type =
    "application/json";

  script.textContent =
    JSON.stringify({
      index_base:
        indexBaseRows,

      weighted_average:
        weightedAverageRows
    });

  document.body.appendChild(script);

  return {
    indexBaseCount:
      indexBaseRows.length,

    weightedAverageCount:
      weightedAverageRows.length,

    firstDate:
      indexBaseRows[0].date,

    lastDate:
      indexBaseRows[
        indexBaseRows.length - 1
      ].date
  };
})()
'@

  $Res = Invoke-Cdp $Ws 5 'Runtime.evaluate' @{
    expression = $Expression
    returnByValue = $true
  }

  if ($Res.result.exceptionDetails -ne $null) {
    $Description = ''

    if (
      $Res.result.exceptionDetails.exception -ne $null -and
      $Res.result.exceptionDetails.exception.description -ne $null
    ) {
      $Description =
        [string]$Res.result.exceptionDetails.exception.description
    } elseif (
      $Res.result.exceptionDetails.text -ne $null
    ) {
      $Description =
        [string]$Res.result.exceptionDetails.text
    }

    throw (
      'nikkei225 valuation DOM processing failed: ' +
      $Description
    )
  }

  $Value = $Res.result.result.value

  if ($Value -eq $null) {
    throw 'nikkei225 valuation DOM processing returned no result'
  }

  Write-Host (
    '[INFO] nikkei225 valuation DOM ready: ' +
    "indexBase=$($Value.indexBaseCount) " +
    "weightedAverage=$($Value.weightedAverageCount) " +
    "firstDate=$($Value.firstDate) " +
    "lastDate=$($Value.lastDate)"
  )
}

function Add-UsMarketValuationExtractData {
  param(
    [Parameter(Mandatory = $true)]
    $Ws
  )

  Write-Host '[INFO] us market valuation DOM processing start'

  $Expression = @'
(function () {
  var DATA_ELEMENT_ID =
    "mde_us_market_valuation_data";

  var EXPECTED_ROW_COUNT = 60;

  if (!Array.isArray(window.US_DAILY)) {
    throw new Error("US_DAILY is not available");
  }

  if (!Array.isArray(window.VOL_DAILY)) {
    throw new Error("VOL_DAILY is not available");
  }

  if (!Array.isArray(window.DAILY)) {
    throw new Error("DAILY is not available");
  }

  if (typeof window.gdt !== "function") {
    throw new Error("gdt is not available");
  }

  function toNumber(value, name, rowNumber) {
    var text =
      value === null || value === undefined
        ? ""
        : String(value);

    var number = Number(
      text.replace(/,/g, "").trim()
    );

    if (!isFinite(number)) {
      throw new Error(
        name +
        " is not numeric. row=" +
        rowNumber +
        " value=" +
        text
      );
    }

    return number;
  }

  function formatNumber(value, decimals) {
    return value.toLocaleString(
      "en-US",
      {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
        useGrouping: true
      }
    );
  }

  function buildMap(rows, valueColumn) {
    var map = {};

    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];

      if (
        !Array.isArray(row) ||
        row.length <= valueColumn
      ) {
        continue;
      }

      var value = Number(row[valueColumn]);

      if (
        !isFinite(value) ||
        value <= 0
      ) {
        continue;
      }

      map[window.gdt(row[0])] = value;
    }

    return map;
  }

  /*
   * Common values
   *
   * US_DAILY
   *   [1]  DOW30 price
   *   [2]  S&P500 price
   *   [3]  NASDAQ100 price
   *   [4]  Russell2000 price
   *   [5]  DOW30 forward PER
   *   [6]  S&P500 forward PER
   *   [7]  NASDAQ100 forward PER
   *   [8]  Russell2000 forward PER
   *   [9]  DOW30 trailing PER
   *   [10] S&P500 trailing PER
   *   [11] NASDAQ100 trailing PER
   *   [12] Russell2000 trailing PER
   *   [13] DOW30 dividend yield
   *   [14] S&P500 dividend yield
   *   [15] NASDAQ100 dividend yield
   *   [16] Russell2000 dividend yield
   *
   * VOL_DAILY
   *   [1] Nikkei225 price
   *
   * DAILY
   *   [25] Nikkei225 PER
   *   [27] Nikkei225 dividend yield
   */

  var mapNikkeiPrice =
    buildMap(window.VOL_DAILY, 1);

  var mapNikkeiPer =
    buildMap(window.DAILY, 25);

  var mapNikkeiDividend =
    buildMap(window.DAILY, 27);

  var mapDowPrice =
    buildMap(window.US_DAILY, 1);

  var mapSp500Price =
    buildMap(window.US_DAILY, 2);

  var mapNasdaq100Price =
    buildMap(window.US_DAILY, 3);

  var mapRussell2000Price =
    buildMap(window.US_DAILY, 4);

  var mapDowDividend =
    buildMap(window.US_DAILY, 13);

  var mapSp500Dividend =
    buildMap(window.US_DAILY, 14);

  var mapNasdaq100Dividend =
    buildMap(window.US_DAILY, 15);

  var mapRussell2000Dividend =
    buildMap(window.US_DAILY, 16);

  function buildRows(mode) {
    var dowPerColumn =
      mode === "forward_per"
        ? 5
        : 9;

    var sp500PerColumn =
      mode === "forward_per"
        ? 6
        : 10;

    var nasdaq100PerColumn =
      mode === "forward_per"
        ? 7
        : 11;

    var russell2000PerColumn =
      mode === "forward_per"
        ? 8
        : 12;

    var mapDowPer =
      buildMap(
        window.US_DAILY,
        dowPerColumn
      );

    var mapSp500Per =
      buildMap(
        window.US_DAILY,
        sp500PerColumn
      );

    var mapNasdaq100Per =
      buildMap(
        window.US_DAILY,
        nasdaq100PerColumn
      );

    var mapRussell2000Per =
      buildMap(
        window.US_DAILY,
        russell2000PerColumn
      );

    /*
     * 必要項目がすべて取得できる日だけを対象とする。
     *
     * 取得元では最新日の一部指標だけ更新が遅れる場合があるため、
     * DOW30 PERだけではなく、米国4指数の株価・予想PER・実績PER・
     * 配当利回り、および日本225の必要項目が揃っている日を採用する。
     */
    var dates = [];

    for (
      var i = 0;
      i < window.US_DAILY.length;
      i++
    ) {
      var sourceRow =
        window.US_DAILY[i];

      if (
        !Array.isArray(sourceRow) ||
        sourceRow.length <= 16
      ) {
        continue;
      }

      var date =
        window.gdt(sourceRow[0]);

      /*
       * US_DAILY
       * [1]～[16] は今回使用する米国4指数の
       * 株価・予想PER・実績PER・配当利回り。
       */
      var complete = true;

      for (
        var column = 1;
        column <= 16;
        column++
      ) {
        var value =
          Number(sourceRow[column]);

        if (
          !isFinite(value) ||
          value <= 0
        ) {
          complete = false;
          break;
        }
      }

      if (!complete) {
        continue;
      }

      /*
       * 日本225側の比較データも揃っていることを確認する。
       */
      var nikkeiValues = [
        mapNikkeiPrice[date],
        mapNikkeiPer[date],
        mapNikkeiDividend[date]
      ];

      for (
        var j = 0;
        j < nikkeiValues.length;
        j++
      ) {
        var value =
          nikkeiValues[j];

        if (
          value === undefined ||
          value === null ||
          !isFinite(Number(value)) ||
          Number(value) <= 0
        ) {
          complete = false;
          break;
        }
      }

      if (!complete) {
        continue;
      }

      dates.push(date);
    }

    dates.sort();

    /*
     * The original page reverses DOWper,
     * takes up to 65 of the latest entries,
     * and then sorts them by date in descending order.
     *
     * Use the latest 60 entries in the final result.
     */
    dates =
      dates.slice(
        Math.max(
          0,
          dates.length - 65
        )
      );

    dates.sort(function (a, b) {
      if (a < b) {
        return 1;
      }

      if (a > b) {
        return -1;
      }

      return 0;
    });

    dates =
      dates.slice(
        0,
        EXPECTED_ROW_COUNT
      );

    if (
      dates.length !==
      EXPECTED_ROW_COUNT
    ) {
      throw new Error(
        mode +
        " date count is not 60: " +
        dates.length
      );
    }

    var result = [];

    for (
      var index = 0;
      index < dates.length;
      index++
    ) {
      var date = dates[index];
      var rowNumber = index + 1;

      var requiredValues = {
        nikkei225_price:
          mapNikkeiPrice[date],

        nikkei225_per:
          mapNikkeiPer[date],

        nikkei225_dividend_yield:
          mapNikkeiDividend[date],

        dow30_price:
          mapDowPrice[date],

        dow30_per:
          mapDowPer[date],

        dow30_dividend_yield:
          mapDowDividend[date],

        sp500_price:
          mapSp500Price[date],

        sp500_per:
          mapSp500Per[date],

        sp500_dividend_yield:
          mapSp500Dividend[date],

        nasdaq100_price:
          mapNasdaq100Price[date],

        nasdaq100_per:
          mapNasdaq100Per[date],

        nasdaq100_dividend_yield:
          mapNasdaq100Dividend[date],

        russell2000_price:
          mapRussell2000Price[date],

        russell2000_per:
          mapRussell2000Per[date],

        russell2000_dividend_yield:
          mapRussell2000Dividend[date]
      };

      Object.keys(
        requiredValues
      ).forEach(function (key) {
        var value =
          requiredValues[key];

        if (
          value === undefined ||
          value === null ||
          !isFinite(Number(value))
        ) {
          throw new Error(
            key +
            " is missing. row=" +
            rowNumber +
            " date=" +
            date
          );
        }
      });

      result.push({
        date:
          date,

        nikkei225_price:
          formatNumber(
            toNumber(
              requiredValues.nikkei225_price,
              "nikkei225_price",
              rowNumber
            ),
            2
          ),

        nikkei225_per:
          formatNumber(
            toNumber(
              requiredValues.nikkei225_per,
              "nikkei225_per",
              rowNumber
            ),
            2
          ),

        nikkei225_dividend_yield:
          formatNumber(
            toNumber(
              requiredValues.nikkei225_dividend_yield,
              "nikkei225_dividend_yield",
              rowNumber
            ),
            2
          ),

        dow30_price:
          formatNumber(
            toNumber(
              requiredValues.dow30_price,
              "dow30_price",
              rowNumber
            ),
            2
          ),

        dow30_per:
          formatNumber(
            toNumber(
              requiredValues.dow30_per,
              "dow30_per",
              rowNumber
            ),
            2
          ),

        dow30_dividend_yield:
          formatNumber(
            toNumber(
              requiredValues.dow30_dividend_yield,
              "dow30_dividend_yield",
              rowNumber
            ),
            2
          ),

        sp500_price:
          formatNumber(
            toNumber(
              requiredValues.sp500_price,
              "sp500_price",
              rowNumber
            ),
            2
          ),

        sp500_per:
          formatNumber(
            toNumber(
              requiredValues.sp500_per,
              "sp500_per",
              rowNumber
            ),
            2
          ),

        sp500_dividend_yield:
          formatNumber(
            toNumber(
              requiredValues.sp500_dividend_yield,
              "sp500_dividend_yield",
              rowNumber
            ),
            2
          ),

        nasdaq100_price:
          formatNumber(
            toNumber(
              requiredValues.nasdaq100_price,
              "nasdaq100_price",
              rowNumber
            ),
            2
          ),

        nasdaq100_per:
          formatNumber(
            toNumber(
              requiredValues.nasdaq100_per,
              "nasdaq100_per",
              rowNumber
            ),
            2
          ),

        nasdaq100_dividend_yield:
          formatNumber(
            toNumber(
              requiredValues.nasdaq100_dividend_yield,
              "nasdaq100_dividend_yield",
              rowNumber
            ),
            2
          ),

        russell2000_price:
          formatNumber(
            toNumber(
              requiredValues.russell2000_price,
              "russell2000_price",
              rowNumber
            ),
            2
          ),

        russell2000_per:
          formatNumber(
            toNumber(
              requiredValues.russell2000_per,
              "russell2000_per",
              rowNumber
            ),
            2
          ),

        russell2000_dividend_yield:
          formatNumber(
            toNumber(
              requiredValues.russell2000_dividend_yield,
              "russell2000_dividend_yield",
              rowNumber
            ),
            2
          )
      });
    }

    return result;
  }

  var forwardPerRows =
    buildRows("forward_per");

  var trailingPerRows =
    buildRows("trailing_per");

  if (
    forwardPerRows.length !==
    EXPECTED_ROW_COUNT
  ) {
    throw new Error(
      "forward PER row count is not 60: " +
      forwardPerRows.length
    );
  }

  if (
    trailingPerRows.length !==
    EXPECTED_ROW_COUNT
  ) {
    throw new Error(
      "trailing PER row count is not 60: " +
      trailingPerRows.length
    );
  }

  for (
    var j = 0;
    j < EXPECTED_ROW_COUNT;
    j++
  ) {
    if (
      forwardPerRows[j].date !==
      trailingPerRows[j].date
    ) {
      throw new Error(
        "date mismatch. row=" +
        (j + 1) +
        " forward=" +
        forwardPerRows[j].date +
        " trailing=" +
        trailingPerRows[j].date
      );
    }
  }

  var existing =
    document.getElementById(
      DATA_ELEMENT_ID
    );

  if (existing) {
    existing.parentNode.removeChild(
      existing
    );
  }

  var script =
    document.createElement("script");

  script.id =
    DATA_ELEMENT_ID;

  script.type =
    "application/json";

  script.textContent =
    JSON.stringify({
      forward_per:
        forwardPerRows,

      trailing_per:
        trailingPerRows
    });

  document.body.appendChild(script);

  return {
    forwardPerCount:
      forwardPerRows.length,

    trailingPerCount:
      trailingPerRows.length,

    firstDate:
      forwardPerRows[0].date,

    lastDate:
      forwardPerRows[
        forwardPerRows.length - 1
      ].date
  };
})()
'@

  $Res = Invoke-Cdp $Ws 7 'Runtime.evaluate' @{
    expression = $Expression
    returnByValue = $true
  }

  if ($Res.result.exceptionDetails -ne $null) {
    $Description = ''

    if (
      $Res.result.exceptionDetails.exception -ne $null -and
      $Res.result.exceptionDetails.exception.description -ne $null
    ) {
      $Description =
        [string]$Res.result.exceptionDetails.exception.description
    } elseif (
      $Res.result.exceptionDetails.text -ne $null
    ) {
      $Description =
        [string]$Res.result.exceptionDetails.text
    }

    throw (
      'us market valuation DOM processing failed: ' +
      $Description
    )
  }

  $Value =
    $Res.result.result.value

  if ($Value -eq $null) {
    throw (
      'us market valuation DOM processing returned no result'
    )
  }

  Write-Host (
    '[INFO] us market valuation DOM ready: ' +
    "forwardPer=$($Value.forwardPerCount) " +
    "trailingPer=$($Value.trailingPerCount) " +
    "firstDate=$($Value.firstDate) " +
    "lastDate=$($Value.lastDate)"
  )
}
function Add-LatestDownloadLinkData {
  param(
    [Parameter(Mandatory = $true)]
    $Ws,

    [Parameter(Mandatory = $true)]
    [string]$SectionTitle
  )

  Write-Host (
    '[INFO] latest download link DOM processing start: ' +
    $SectionTitle
  )

  $SectionTitleJson =
    $SectionTitle |
    ConvertTo-Json -Compress

  $Expression = @"
(function () {
  var SECTION_TITLE = $SectionTitleJson;
  var DATA_ELEMENT_ID = "daily_snapshot_download_data";

  function normalizeText(value) {
    return String(value || "")
      .replace(/\s+/g, " ")
      .trim();
  }

  /*
   * 指定された見出しを探す。
   */
  var headings =
    Array.from(
      document.querySelectorAll(
        "h1, h2, h3, h4, h5, h6"
      )
    );

  var heading = headings.find(function (element) {
    return (
      normalizeText(element.textContent) ===
      SECTION_TITLE
    );
  });

  if (!heading) {
    throw new Error(
      "section heading not found: " +
      SECTION_TITLE
    );
  }

  /*
   * 見出しより後にある最初のtableを取得する。
   */
  var tables =
    Array.from(
      document.querySelectorAll("table")
    );

  var table = null;

  for (var i = 0; i < tables.length; i++) {
    var position =
      heading.compareDocumentPosition(
        tables[i]
      );

    if (
      position &
      Node.DOCUMENT_POSITION_FOLLOWING
    ) {
      table = tables[i];
      break;
    }
  }

  if (!table) {
    throw new Error(
      "table not found after section: " +
      SECTION_TITLE
    );
  }

  /*
   * 対象table内で最初に現れるExcelリンクを取得する。
   *
   * JPXは先頭データ行にExcelリンクがあるが、
   * 日銀は「掲載日」と「データ」が別trになっているため、
   * 行位置には依存しない。
   */
  var links =
    Array.from(
      table.querySelectorAll("a[href]")
    );

  var excelLink =
    links.find(function (link) {
      var href =
        String(
          link.getAttribute("href") || ""
        );

      return /\.(xlsx|xls)(?:[?#].*)?$/i.test(
        href
      );
    });

  if (!excelLink) {
    throw new Error(
      "excel link not found in table: " +
      SECTION_TITLE
    );
  }

  var excelRow =
    excelLink.closest("tr");

  if (!excelRow) {
    throw new Error(
      "excel row not found: " +
      SECTION_TITLE
    );
  }

  var downloadUrl =
    new URL(
      excelLink.getAttribute("href"),
      document.baseURI
    ).href;

  /*
   * 後段のPowerShellから取得しやすいよう、
   * JSONをDOMへ埋め込む。
   */
  var existing =
    document.getElementById(
      DATA_ELEMENT_ID
    );

  if (existing) {
    existing.parentNode.removeChild(
      existing
    );
  }

  var script =
    document.createElement("script");

  script.id =
    DATA_ELEMENT_ID;

  script.type =
    "application/json";

  script.textContent =
    JSON.stringify({
      section_title:
        SECTION_TITLE,

      row_text:
        normalizeText(
          excelRow.textContent
        ),

      download_url:
        downloadUrl
    });

  document.body.appendChild(script);

  return {
    sectionTitle:
      SECTION_TITLE,

    rowText:
      normalizeText(
        excelRow.textContent
      ),

    downloadUrl:
      downloadUrl
  };
})()
"@

  $Res =
    Invoke-Cdp `
      $Ws `
      7 `
      'Runtime.evaluate' `
      @{
        expression = $Expression
        returnByValue = $true
      }

  if ($Res.result.exceptionDetails -ne $null) {

    $Description = ''

    if (
      $Res.result.exceptionDetails.exception -ne $null -and
      $Res.result.exceptionDetails.exception.description -ne $null
    ) {
      $Description =
        [string]$Res.result.exceptionDetails.exception.description

    } elseif (
      $Res.result.exceptionDetails.text -ne $null
    ) {
      $Description =
        [string]$Res.result.exceptionDetails.text
    }

    throw (
      'latest download link DOM processing failed: ' +
      $Description
    )
  }

  $Value =
    $Res.result.result.value

  if ($Value -eq $null) {
    throw (
      'latest download link DOM processing ' +
      'returned no result'
    )
  }

  Write-Host (
    '[INFO] latest download link ready: ' +
    "section=$($Value.sectionTitle) " +
    "row=$($Value.rowText) " +
    "url=$($Value.downloadUrl)"
  )
}
function Get-Html-From-Edge {
  param(
    $Port,
    $Url,
    [string]$FileName,
    [string]$DownloadSectionTitle = ''
  )

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

    # Run target-specific DOM processing before saving HTML.
    switch ($FileName) {
      '09_extract_02_nikkei225_valuation.html' {
        Add-Nikkei225ValuationExtractData `
          -Ws $Ws
      }

      '09_extract_08_us_market_valuation.html' {
        Add-UsMarketValuationExtractData `
          -Ws $Ws
      }

      default {
        # No target-specific DOM processing.
      }
    }

    if (
      -not [string]::IsNullOrWhiteSpace(
        $DownloadSectionTitle
      )
    ) {
      Add-LatestDownloadLinkData `
        -Ws $Ws `
        -SectionTitle $DownloadSectionTitle
    }

    # CDP IDs 5 and 7 are reserved for target-specific preprocessing.
    $Res = Invoke-Cdp $Ws 6 'Runtime.evaluate' @{
      expression = 'document.documentElement.outerHTML'
      returnByValue = $true
    }

    if ($Res.result.exceptionDetails -ne $null) {
      throw 'document.documentElement.outerHTML evaluation failed'
    }

    $Html = $Res.result.result.value

    if ([string]::IsNullOrWhiteSpace($Html)) {
      throw 'document.documentElement.outerHTML is empty'
    }
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

  $RowCount = [regex]::Matches(
    $Html,
    '<td[^>]*class="[^"]*\bnews_time\b[^"]*"[^>]*>\s*<time[^>]*datetime="[^"]+"'
  ).Count

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

  $DateMatches = [regex]::Matches($Html,'<td[^>]*class="[^"]*\bnews_time\b[^"]*"[^>]*>\s*<time[^>]*datetime="([^"]+)"')

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
function Get-LatestDownloadUrlFromHtml {
  param(
    [Parameter(Mandatory = $true)]
    [string]$Html,

    [Parameter(Mandatory = $true)]
    [string]$PageUrl
  )

  $Pattern =
    '(?is)' +
    '<script[^>]*' +
    'id=["'']daily_snapshot_download_data["'']' +
    '[^>]*>' +
    '(.*?)' +
    '</script>'

  $Match =
    [regex]::Match(
      $Html,
      $Pattern
    )

  if (-not $Match.Success) {
    throw (
      "download data not found in DOM: " +
      $PageUrl
    )
  }

  $Json =
    [System.Net.WebUtility]::HtmlDecode(
      $Match.Groups[1].Value
    )

  try {
    $Data =
      $Json |
      ConvertFrom-Json
  } catch {
    throw (
      "download data JSON parse failed: " +
      $PageUrl
    )
  }

  $DownloadUrl =
    [string]$Data.download_url

  if (
    [string]::IsNullOrWhiteSpace(
      $DownloadUrl
    )
  ) {
    throw (
      "download URL is empty: " +
      $PageUrl
    )
  }

  Write-Host (
    "[INFO] download section=" +
    $Data.section_title
  )

  Write-Host (
    "[INFO] download row=" +
    $Data.row_text
  )

  Write-Host (
    "[INFO] download url=" +
    $DownloadUrl
  )

  return $DownloadUrl
}
function Save-ExcelFile {
  param(
    [Parameter(Mandatory = $true)]
    [string]$Url,

    [Parameter(Mandatory = $true)]
    [string]$SavePath,

    [Parameter(Mandatory = $true)]
    [string]$Referer
  )

  Write-Host "[INFO] excel download start"
  Write-Host "[INFO] url=$Url"
  Write-Host "[INFO] save=$SavePath"

  $Headers = @{
    'User-Agent' =
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' +
      'AppleWebKit/537.36 (KHTML, like Gecko) ' +
      'Chrome/151.0.0.0 Safari/537.36'

    'Referer' = $Referer
  }

  Invoke-WebRequest `
    -Uri $Url `
    -Headers $Headers `
    -OutFile $SavePath `
    -UseBasicParsing

  if (-not (Test-Path $SavePath)) {
    throw "excel file was not saved: $SavePath"
  }

  $FileInfo = Get-Item $SavePath

  if ($FileInfo.Length -le 0) {
    throw "excel file is empty: $SavePath"
  }

  Write-Host (
    "[OK] excel saved: $SavePath " +
    "bytes=$($FileInfo.Length)"
  )
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

  $DownloadSectionTitle = ''

  if (
    $Item.ContainsKey(
      'DownloadSectionTitle'
    )
  ) {
    $DownloadSectionTitle =
      [string]$Item.DownloadSectionTitle
  }

    $Html = Get-Html-From-Edge `
    -Port $Port `
    -Url $Url `
    -FileName $FileName `
    -DownloadSectionTitle $DownloadSectionTitle

  Test-Html-ByCheck $Html $Check | Out-Null

  $DownloadType = ''

  if ($Item.ContainsKey('DownloadType')) {
    $DownloadType = [string]$Item.DownloadType
  }

  if ($DownloadType -eq 'LatestExcel') {

    $ExcelUrl =
      Get-LatestDownloadUrlFromHtml `
        -Html $Html `
        -PageUrl $Url

    Save-ExcelFile `
      -Url $ExcelUrl `
      -SavePath $SavePath `
      -Referer $Url

  } else {

    [System.IO.File]::WriteAllText(
      $SavePath,
      $Html,
      $Utf8NoBom
    )

    Write-Host "[OK] saved: $SavePath"
  }

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

Write-Host "[DONE] scraping finished. mode=$Mode"