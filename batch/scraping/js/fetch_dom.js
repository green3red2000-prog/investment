const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const url = process.argv[2];
  const out = process.argv[3];
  const metaOut = process.argv[4];

  if (!url || !out || !metaOut) {
    console.error(
      'Usage: node fetch_dom.js <url> <output_html> <output_meta_json>'
    );
    process.exit(1);
  }
  
  const HARD_TIMEOUT_MS = 115_000;
  const hardTimeout = setTimeout(() => {
    console.error(`[FATAL] hard timeout ${HARD_TIMEOUT_MS}ms url=${url}`);
    process.exit(2);
  }, HARD_TIMEOUT_MS);

  // Proxy settings (from env)  ※scraping_common.php から渡す
  const proxyServer = process.env.WS_PROXY_SERVER || '';
  const proxyUser = process.env.WS_PROXY_USER || '';
  const proxyPass = process.env.WS_PROXY_PASS || '';

  const proxy =
    proxyServer
      ? {
          server: proxyServer,
          ...(proxyUser ? { username: proxyUser } : {}),
          ...(proxyPass ? { password: proxyPass } : {}),
        }
      : null;

  console.log(`[INFO] url=${url}`);
  console.log(`[INFO] out=${out}`);
  console.log(`[INFO] metaOut=${metaOut}`);
  console.log(`[INFO] proxy=${proxyServer || '(none)'}`);

  const launchOpt = {
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
    ...(proxy ? { proxy } : {}),
  };

  const browser = await chromium.launch(launchOpt);

  const context = await browser.newContext({
    locale: 'ja-JP',
  });

  const page = await context.newPage();
  
  // HTML解析に不要な重いリソースを遮断する。
  // script、stylesheet、xhr、fetchはDOM生成に必要なので遮断しない。
  await page.route('**/*', async route => {
    const resourceType = route.request().resourceType();

    if (
      resourceType === 'image' ||
      resourceType === 'media' ||
      resourceType === 'font'
    ) {
      await route.abort();
      return;
    }

    await route.continue();
  });

  await page.setExtraHTTPHeaders({
    'User-Agent':
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' +
      'AppleWebKit/537.36 (KHTML, like Gecko) ' +
      'Chrome/120.0 Safari/537.36',
    'Accept-Language': 'ja-JP,ja;q=0.9,en-US;q=0.8,en;q=0.7',
  });

  // --- ここから改修ポイント ---
  // networkidleは広告・計測通信があると完了しないことがあるため、
  // まずcommitでレスポンス受信を確認し、その後DOMを一定時間だけ待つ。
  const resp = await page.goto(url, {
    // 最初のHTTPレスポンスを受け取った時点で先へ進む。
    // 広告や計測処理の影響でDOMContentLoadedが遅れるのを避ける。
    waitUntil: 'commit',
    timeout: 45_000,
  });
  const status = resp ? resp.status() : 0;
  if (status && status >= 400) {
    console.error(`[WARN] goto status=${status} url=${url}`);
  }
  
  try {
    await page.waitForLoadState('domcontentloaded', {
      timeout: 20_000,
    });
  } catch (e) {
    console.error(
      '[WARN] waitForLoadState(domcontentloaded) timeout: ' +
      (e && e.message ? e.message : e)
    );
  }
  
  // 日経ニュース一覧
  const isNikkeiNews = /^https:\/\/www\.nikkei\.com\/news\/category\//i.test(url);

  if (isNikkeiNews) {
    try {
      await page.waitForSelector(
        'article time[datetime]',
        {
          state: 'attached',
          timeout: 45_000,
        }
      );
    } catch (e) {
      console.error(
        '[WARN] waitForSelector(Nikkei article) timeout: ' +
        (e && e.message ? e.message : e)
      );
    }
  }
  
  // stocksページはDOM生成が遅い時があるので、主要ブロックを少し待つ
  const isShikihoStocks = /^https:\/\/shikiho\.toyokeizai\.net\/stocks\//i.test(url);
  if (isShikihoStocks) {
    try {
      // 特色/連結事業のDL or スコアブロックが出るまで待つ（どちらか出ればOK）
      await Promise.race([
        page.waitForSelector('dl.information__list', { timeout: 20000 }),
        page.waitForSelector('div.score__chart-wrapper__main', { timeout: 20000 }),
      ]);
    } catch (e) {
      console.error(`[WARN] waitForSelector(stocks key blocks) timeout: ${e && e.message ? e.message : e}`);
      // 続行
    }
  }
  
  // 四季報オンラインの news 一覧は、DOM生成が遅い場合があるので selector 待ちを入れる
  // （stocks ページ等に影響しないようにURLで限定）
  const isShikihoNewsList = /^https:\/\/shikiho\.toyokeizai\.net\/news\?/i.test(url);
  if (isShikihoNewsList) {
    // 記事リンクが出るまで待つ（出ない場合もあるのでタイムアウトは許容）
    try {
      await page.waitForSelector('a.newsList__title', { timeout: 60000 });
    } catch (e) {
      console.error(`[WARN] waitForSelector(newsList__title) timeout: ${e && e.message ? e.message : e}`);
      // 続行（HTMLは取れる範囲で取る）
    }
  }  
  
  // nikkei225jp.com 東証業種別株価指数
  // 外部JavaScriptデータのAjax取得後にランキング表が生成されるため、
  // 値上がり・値下がりランキング各10件と連続行の生成完了を待つ。
  const isToshoSectorIndex =
    /^https:\/\/nikkei225jp\.com\/chart\/gyoushu\.php(?:[?#].*)?$/i.test(url);

  const isGovernmentBondYield =
    /^https:\/\/nikkei225jp\.com\/bond\/(?:[?#].*)?$/i.test(
    url
  );

  if (isGovernmentBondYield) {
    try {
      await page.waitForFunction(
        () => {
          const table =
            document.querySelector('#ajaxTbl');

          if (!table) {
            return false;
          }

          const rows = Array.from(
            table.querySelectorAll(
              'tbody > tr'
            )
          ).filter(row => {
            return (
              row.querySelector('th') !== null &&
              row.querySelector('td') !== null
            );
          });

          if (rows.length === 0) {
            return false;
          }

          /*
           * Each target row must have the values
           * required by mde_government_bond_yield.php.
           */
          return rows.every(row => {
            const name =
              row.querySelector('th .THp');

            const currentValue =
              row.querySelector('td .val4');

            const change =
              row.querySelector('td .zen4');

            const rate =
              row.querySelector('td .chg4');

            const updated =
              row.querySelector(
                'td .tim4 .scol'
              );

            if (
              !name ||
              !currentValue ||
              !change ||
              !rate ||
              !updated
            ) {
              return false;
            }

            const nameText =
              (name.textContent || '').trim();

            const currentValueText =
              (currentValue.textContent || '')
                .trim();

            const changeText =
              (change.textContent || '')
                .trim();

            const rateText =
              (rate.textContent || '')
                .trim();

            const updatedText =
              (updated.textContent || '')
                .trim();

            return (
              nameText !== '' &&
              currentValueText !== '' &&
              changeText !== '' &&
              updatedText !== '' &&
              (
                nameText === 'FFレート' ||
                rateText !== ''
              )
            );
          });
        },
        {
          timeout: 60_000,
        }
      );

      console.log(
        '[INFO] government bond yield dynamic DOM ready'
      );

    } catch (e) {
      throw new Error(
        'government bond yield dynamic DOM processing failed: ' +
        (e && e.message ? e.message : e)
      );
    }
  }

  if (isToshoSectorIndex) {
    try {
      await page.waitForFunction(
        () => {
          const rankingTable = document.querySelector('#gyornk');
          const changeTable = document.querySelector('#gtbl');

          if (!rankingTable || !changeTable) {
            return false;
          }

          const rankingCells =
            rankingTable.querySelectorAll('td.tptd');

          const rankingRows =
            rankingTable.querySelectorAll('tr.trG');

          const continuousCells =
            changeTable.querySelectorAll(
              'tr > th.gn2:first-child ~ td.day2'
            );

          const updatedText =
            document.querySelector('#gyornkupdatetime')
              ?.textContent
              ?.trim() || '';

          return (
            rankingCells.length === 2 &&
            rankingRows.length === 20 &&
            continuousCells.length === 33 &&
            updatedText !== ''
          );
        },
        {
          timeout: 60_000,
        }
      );

      console.log(
        '[INFO] gyoushu dynamic DOM ready: ' +
        'rankingCells=2 rankingRows=20 continuousCells=33'
      );
    } catch (e) {
      console.error(
        '[WARN] waitForFunction(gyoushu dynamic DOM) timeout: ' +
        (e && e.message ? e.message : e)
      );
    }
  }
  // nikkei225jp.com 日経225バリュエーション
  //
  // 当該ページでは、指数ベースと加重平均が同じ表
  // #datatblへ切り替えて表示される。
  //
  // 両方の表を順番に取得し、PHP解析用のJSONを
  // script#mde_nikkei225_valuation_dataとしてDOMへ追加する。
  const isNikkei225Valuation =
    /^https:\/\/nikkei225jp\.com\/data\/per\.php(?:[?#].*)?$/i.test(
      url
    );

  if (isNikkei225Valuation) {
    try {
      /*
       * 初期表示の表が完成するまで待つ。
       */
      await page.waitForFunction(
        () => {
          const table = document.querySelector('#datatbl');

          if (!table) {
            return false;
          }

          const rows = Array.from(
            table.querySelectorAll('tbody > tr')
          ).filter(row => row.querySelectorAll('td').length === 11);

          return (
            rows.length === 60 &&
            rows.every(row => {
              const cells = row.querySelectorAll('td');

              return (
                cells.length === 11 &&
                (cells[0].textContent || '').trim() !== '' &&
                (cells[4].textContent || '').trim() !== '' &&
                (cells[5].textContent || '').trim() !== ''
              );
            })
          );
        },
        {
          timeout: 60_000,
        }
      );

      /*
       * 現在表示されている#datatblから、
       * 60営業日分の11項目を取得する関数。
       */
      const extractValuationRows = async () => {
        return await page.evaluate(() => {
          const table = document.querySelector('#datatbl');

          if (!table) {
            throw new Error(
              '日経225バリュエーションの表#datatblがありません。'
            );
          }

          const tableRows = Array.from(
            table.querySelectorAll('tbody > tr')
          ).filter(row => row.querySelectorAll('td').length === 11);

          return tableRows.map((row, index) => {
            const cells = Array.from(row.querySelectorAll('td'));

            if (cells.length !== 11) {
              throw new Error(
                '日経225バリュエーションの列数が11列ではありません。' +
                ` row=${index + 1} cells=${cells.length}`
              );
            }

            const text = cell =>
              (cell.textContent || '')
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            return {
              date: text(cells[0]),
              nikkei225: text(cells[1]),
              change: text(cells[2]),
              prime_volume: text(cells[3]),
              per: text(cells[4]),
              pbr: text(cells[5]),
              eps: text(cells[6]),
              bps: text(cells[7]),
              earnings_yield: text(cells[8]),
              dividend_yield: text(cells[9]),
              jgb_yield: text(cells[10]),
            };
          });
        });
      };

      /*
       * ラジオボタンを切り替え、
       * ページ側のonclick処理で表を更新する。
       *
       * value=0：指数ベース
       * value=1：加重平均
       */
      const selectValuationBasis = async value => {
        await page.evaluate(selectedValue => {
          const input = document.querySelector(
            `input[name="typeT1"][value="${selectedValue}"]`
          );

          if (!input) {
            throw new Error(
              '日経225バリュエーションの切替ボタンがありません: ' +
              selectedValue
            );
          }

          /*
           * inputはCSSで非表示になっているため、
           * Playwrightの通常clickではなくDOM上でclickする。
           */
          input.click();
        }, value);

        await page.waitForFunction(
          selectedValue => {
            const input = document.querySelector(
              `input[name="typeT1"][value="${selectedValue}"]`
            );

            if (!input || !input.checked) {
              return false;
            }

            const table = document.querySelector('#datatbl');

            if (!table) {
              return false;
            }

            const rows = Array.from(
              table.querySelectorAll('tbody > tr')
            ).filter(row => row.querySelectorAll('td').length === 11);

            return (
              rows.length === 60 &&
              rows.every(row => {
                const cells = row.querySelectorAll('td');

                return (
                  cells.length === 11 &&
                  (cells[0].textContent || '').trim() !== '' &&
                  (cells[4].textContent || '').trim() !== '' &&
                  (cells[5].textContent || '').trim() !== ''
                );
              })
            );
          },
          value,
          {
            timeout: 30_000,
          }
        );

        /*
         * ページ側のData_write()によるDOM書換え完了を
         * 確実に待つための短い待機。
         */
        await page.waitForTimeout(300);
      };

      /*
       * 指数ベースを取得する。
       */
      await selectValuationBasis('0');
      const indexBaseRows = await extractValuationRows();

      /*
       * 加重平均を取得する。
       */
      await selectValuationBasis('1');
      const weightedAverageRows = await extractValuationRows();

      if (indexBaseRows.length !== 60) {
        throw new Error(
          '指数ベースの取得件数が60件ではありません: ' +
          indexBaseRows.length
        );
      }

      if (weightedAverageRows.length !== 60) {
        throw new Error(
          '加重平均の取得件数が60件ではありません: ' +
          weightedAverageRows.length
        );
      }

      /*
       * PHP解析用JSONをHTMLへ埋め込む。
       *
       * 既に同じ要素が存在する場合は削除してから作り直す。
       */
      await page.evaluate(
        data => {
          const elementId =
            'mde_nikkei225_valuation_data';

          const existing =
            document.getElementById(elementId);

          if (existing) {
            existing.remove();
          }

          const script =
            document.createElement('script');

          script.id = elementId;
          script.type = 'application/json';
          script.textContent = JSON.stringify(data);

          document.body.appendChild(script);
        },
        {
          index_base: indexBaseRows,
          weighted_average: weightedAverageRows,
        }
      );

      console.log(
        '[INFO] nikkei225 valuation dynamic DOM ready: ' +
        `indexBase=${indexBaseRows.length} ` +
        `weightedAverage=${weightedAverageRows.length}`
      );

    } catch (e) {
      /*
       * 今回は専用PHPがJSON必須としているため、
       * 警告だけで続行せずfetch_dom.js自体を失敗させる。
       */
      throw new Error(
        '日経225バリュエーションの動的DOM取得に失敗しました: ' +
        (e && e.message ? e.message : e)
      );
    }
  }
  
  // nikkei225jp.com 米国株バリュエーション
  //
  // 当該ページでは、予想PERと実績PERが同じ表
  // #datatblへ切り替えて表示される。
  //
  // 両方の表を順番に取得し、PHP解析用のJSONを
  // script#mde_us_market_valuation_dataとしてDOMへ追加する。
  const isUsMarketValuation =
    /^https:\/\/nikkei225jp\.com\/data\/us_per\.php(?:[?#].*)?$/i.test(
      url
    );

  if (isUsMarketValuation) {
    try {
      /*
       * 初期表示の表が完成するまで待つ。
       */
      await page.waitForFunction(
        () => {
          const table = document.querySelector('#datatbl');

          if (!table) {
            return false;
          }

          const rows = Array.from(
            table.querySelectorAll('tbody > tr')
          ).filter(row => row.querySelectorAll('td').length === 16);

          return (
            rows.length === 60 &&
            rows.every(row => {
              const cells = row.querySelectorAll('td');

              return (
                cells.length === 16 &&
                (cells[0].textContent || '').trim() !== '' &&
                (cells[1].textContent || '').trim() !== '' &&
                (cells[2].textContent || '').trim() !== '' &&
                (cells[4].textContent || '').trim() !== '' &&
                (cells[5].textContent || '').trim() !== ''
              );
            })
          );
        },
        {
          timeout: 60_000,
        }
      );

      /*
       * 現在表示されている#datatblから、
       * 60営業日分の16項目を取得する関数。
       */
      const extractUsMarketValuationRows = async () => {
        return await page.evaluate(() => {
          const table = document.querySelector('#datatbl');

          if (!table) {
            throw new Error(
              '米国株バリュエーションの表#datatblがありません。'
            );
          }

          const tableRows = Array.from(
            table.querySelectorAll('tbody > tr')
          ).filter(row => row.querySelectorAll('td').length === 16);

          return tableRows.map((row, index) => {
            const cells = Array.from(
              row.querySelectorAll('td')
            );

            if (cells.length !== 16) {
              throw new Error(
                '米国株バリュエーションの列数が16列ではありません。' +
                ` row=${index + 1} cells=${cells.length}`
              );
            }

            const text = cell =>
              (cell.textContent || '')
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            return {
              date: text(cells[0]),

              nikkei225_price: text(cells[1]),
              nikkei225_per: text(cells[2]),
              nikkei225_dividend_yield: text(cells[3]),

              dow30_price: text(cells[4]),
              dow30_per: text(cells[5]),
              dow30_dividend_yield: text(cells[6]),

              sp500_price: text(cells[7]),
              sp500_per: text(cells[8]),
              sp500_dividend_yield: text(cells[9]),

              nasdaq100_price: text(cells[10]),
              nasdaq100_per: text(cells[11]),
              nasdaq100_dividend_yield: text(cells[12]),

              russell2000_price: text(cells[13]),
              russell2000_per: text(cells[14]),
              russell2000_dividend_yield: text(cells[15]),
            };
          });
        });
      };

      /*
       * ラジオボタンを切り替え、
       * ページ側のonclick処理で表を更新する。
       *
       * value=0：予想PER
       * value=1：実績PER
       */
      const selectUsMarketValuationBasis = async value => {
        await page.evaluate(selectedValue => {
          const input = document.querySelector(
            `input[name="typeT1"][value="${selectedValue}"]`
          );

          if (!input) {
            throw new Error(
              '米国株バリュエーションの切替ボタンがありません: ' +
              selectedValue
            );
          }

          /*
           * inputはCSSで非表示になっているため、
           * Playwrightの通常clickではなくDOM上でclickする。
           */
          input.click();
        }, value);

        await page.waitForFunction(
          selectedValue => {
            const input = document.querySelector(
              `input[name="typeT1"][value="${selectedValue}"]`
            );

            if (!input || !input.checked) {
              return false;
            }

            const table = document.querySelector('#datatbl');

            if (!table) {
              return false;
            }

            const rows = Array.from(
              table.querySelectorAll('tbody > tr')
            ).filter(
              row => row.querySelectorAll('td').length === 16
            );

            return (
              rows.length === 60 &&
              rows.every(row => {
                const cells =
                  row.querySelectorAll('td');

                return (
                  cells.length === 16 &&
                  (cells[0].textContent || '').trim() !== '' &&
                  (cells[1].textContent || '').trim() !== '' &&
                  (cells[2].textContent || '').trim() !== '' &&
                  (cells[4].textContent || '').trim() !== '' &&
                  (cells[5].textContent || '').trim() !== ''
                );
              })
            );
          },
          value,
          {
            timeout: 30_000,
          }
        );

        /*
         * ページ側のData_write()によるDOM書換え完了を
         * 確実に待つための短い待機。
         */
        await page.waitForTimeout(300);
      };

      /*
       * 予想PERを取得する。
       */
      await selectUsMarketValuationBasis('0');

      const forwardPerRows =
        await extractUsMarketValuationRows();

      /*
       * 実績PERを取得する。
       */
      await selectUsMarketValuationBasis('1');

      const trailingPerRows =
        await extractUsMarketValuationRows();

      if (forwardPerRows.length !== 60) {
        throw new Error(
          '予想PERの取得件数が60件ではありません: ' +
          forwardPerRows.length
        );
      }

      if (trailingPerRows.length !== 60) {
        throw new Error(
          '実績PERの取得件数が60件ではありません: ' +
          trailingPerRows.length
        );
      }

      /*
       * PHP解析用JSONをHTMLへ埋め込む。
       *
       * 既に同じ要素が存在する場合は削除してから作り直す。
       */
      await page.evaluate(
        data => {
          const elementId =
            'mde_us_market_valuation_data';

          const existing =
            document.getElementById(elementId);

          if (existing) {
            existing.remove();
          }

          const script =
            document.createElement('script');

          script.id = elementId;
          script.type = 'application/json';
          script.textContent = JSON.stringify(data);

          document.body.appendChild(script);
        },
        {
          forward_per: forwardPerRows,
          trailing_per: trailingPerRows,
        }
      );

      console.log(
        '[INFO] us market valuation dynamic DOM ready: ' +
        `forwardPer=${forwardPerRows.length} ` +
        `trailingPer=${trailingPerRows.length}`
      );

    } catch (e) {
      /*
       * 専用PHPがJSON必須としているため、
       * 警告だけで続行せずfetch_dom.js自体を失敗させる。
       */
      throw new Error(
        '米国株バリュエーションの動的DOM取得に失敗しました: ' +
        (e && e.message ? e.message : e)
      );
    }
  }
  
  // --- ここまで改修ポイント ---

  const html = await page.content();
  const finalUrl = page.url();
  const title = await page.title();

  fs.writeFileSync(out, html);

  const meta = {
    http_code: status,
    final_url: finalUrl,
    title,
    html_bytes: Buffer.byteLength(html, 'utf8'),
  };

  fs.writeFileSync(
    metaOut,
    JSON.stringify(meta, null, 2),
    'utf8'
  );

  console.log(
    `[OK] saved: ${out} ` +
    `bytes=${meta.html_bytes} ` +
    `status=${status} ` +
    `final_url=${finalUrl}`
  );

  await browser.close();
  clearTimeout(hardTimeout);
})();
