<?php
declare(strict_types=1);

/**
 * J-Quants API 共通処理
 *
 * - APIキー定数 JQUANTS_API_KEY は呼び出し元の conf/config.php で定義済みであること。
 */

const JQUANTS_BASE_URL = 'https://api.jquants.com';
const HTTP_TIMEOUT_SEC = 60;

const JQUANTS_API_MIN_INTERVAL_USEC = 1000000; // 1秒。J-Quants APIレートリミット対策
const JQUANTS_API_BURST_COUNT = 50;            // 50リクエストごと
const JQUANTS_API_BURST_SLEEP_SEC = 60;        // 60秒休憩
const JQUANTS_API_RETRY_SLEEP_SEC = 60;        // 429時60秒待機
const JQUANTS_API_MAX_RETRY = 3;               // 最大3回リトライ

class JQuantsApiException extends RuntimeException {}

function jquantsGetAll(string $path, array $params): array {
  $all = [];
  $paginationKey = null;

  do {
    $query = $params;
    if ($paginationKey !== null && $paginationKey !== '') {
      $query['pagination_key'] = $paginationKey;
    }

    $json = jquantsGetJson($path, $query);
    $data = $json['data'] ?? null;
    if (!is_array($data)) {
      throw new JQuantsApiException('response data is not array: ' . $path);
    }

    foreach ($data as $row) {
      if (is_array($row)) $all[] = $row;
    }

    $paginationKey = isset($json['pagination_key']) ? (string)$json['pagination_key'] : null;
  } while ($paginationKey !== null && $paginationKey !== '');

  return $all;
}

function jquantsGetJson(string $path, array $params): array {
  for ($retry = 0; $retry <= JQUANTS_API_MAX_RETRY; $retry++) {
    throttleJQuantsApi();

    $url = JQUANTS_BASE_URL . $path;
    if (!empty($params)) {
      $url .= '?' . http_build_query($params);
    }

    $ch = curl_init();
    if ($ch === false) {
      throw new JQuantsApiException('curl_init failed');
    }

    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => HTTP_TIMEOUT_SEC,
      CURLOPT_TIMEOUT => HTTP_TIMEOUT_SEC,
      CURLOPT_HTTPHEADER => [
        'x-api-key: ' . JQUANTS_API_KEY,
        'Accept: application/json',
      ],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
      $err = curl_error($ch);
      curl_close($ch);
      throw new JQuantsApiException('curl error: ' . $err . ' url=' . $url);
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
      throw new JQuantsApiException(
        'JSON decode error. HTTP=' . $httpCode .
        ' body=' . mb_substr((string)$response, 0, 500) .
        ' url=' . $url
      );
    }

    if ($httpCode === 429 && $retry < JQUANTS_API_MAX_RETRY) {
      fwrite(STDERR, "[WARN] HTTP429 retry=" . ($retry + 1) . "/" . JQUANTS_API_MAX_RETRY . " sleep " . JQUANTS_API_RETRY_SLEEP_SEC . " sec\n");
      sleep(JQUANTS_API_RETRY_SLEEP_SEC);
      continue;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
      throw new JQuantsApiException(
        'HTTP ' . $httpCode .
        ' body=' . json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
        ' url=' . $url
      );
    }

    return $json;
  }

  throw new JQuantsApiException('retry exceeded: ' . $path);
}

function throttleJQuantsApi(): void {
  static $lastRequestAt = 0.0;
  static $requestCount = 0;

  $now = microtime(true);

  if ($lastRequestAt > 0) {
    $elapsedUsec = (int)(($now - $lastRequestAt) * 1000000);
    if ($elapsedUsec < JQUANTS_API_MIN_INTERVAL_USEC) {
      usleep(JQUANTS_API_MIN_INTERVAL_USEC - $elapsedUsec);
    }
  }

  $requestCount++;

  if ($requestCount % JQUANTS_API_BURST_COUNT === 0) {
    echo "[INFO] API burst {$requestCount}. sleep "
        . JQUANTS_API_BURST_SLEEP_SEC
        . " sec\n";
    sleep(JQUANTS_API_BURST_SLEEP_SEC);
  }

  $lastRequestAt = microtime(true);
}