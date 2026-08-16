<?php
/**
 * 应用开放 API (/v1/) 集成测试公共客户端。
 *
 * 采用 HTTP 层测试（file_get_contents + stream_context_create），
 * 不依赖 curl 扩展，可在任意启用了 php-openssl / php-json 的环境运行。
 *
 * 运行前需启动 holastack 站点（php -S 或你的 web server），
 * 且 load_config.php 解析出的 base_url 可达、api_key 有效。
 */
class ApiTestClient
{
    public string $base;
    public string $apiKey;
    public string $testDevEui;
    public int $pass = 0;
    public int $fail = 0;

    public function __construct(string $base, string $apiKey, string $testDevEui = '')
    {
        $this->base = rtrim($base, '/');
        $this->apiKey = $apiKey;
        $this->testDevEui = strtolower(preg_replace('/[^0-9a-f]/', '', $testDevEui));
    }

    public function check(string $name, bool $cond): void
    {
        if ($cond) {
            $this->pass++;
            echo "  PASS  $name\n";
        } else {
            $this->fail++;
            echo "  FAIL  $name\n";
        }
    }

    /** 带鉴权请求；返回 [httpCode, bodyArray|null, rawBody] */
    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        return $this->doRequest($method, $path, $query, $json, true);
    }

    /** 不带鉴权请求（用于验证 401 等）；返回 [httpCode, bodyArray|null, rawBody] */
    public function requestNoAuth(string $method, string $path, array $query = []): array
    {
        return $this->doRequest($method, $path, $query, null, false);
    }

    private function doRequest(string $method, string $path, array $query, ?array $json, bool $auth): array
    {
        $url = $this->base . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $headers = [];
        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Accept: application/json';
        }
        $ctx = ['http' => [
            'method'          => strtoupper($method),
            'header'          => $headers,
            'content'         => $json !== null ? json_encode($json) : null,
            'ignore_errors'   => true,
            'timeout'         => 15,
            'follow_location' => false,
        ]];
        $raw = @file_get_contents($url, false, stream_context_create($ctx));
        // $http_response_header 仅在 file_get_contents 调用所在作用域内可见
        $code = 0;
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) {
                    $code = (int) $m[1];
                    break;
                }
            }
        }
        $body = ($raw === false) ? null : json_decode($raw, true);
        return [$code, $body, $raw];
    }

    /** 汇总并退出码（0=全过，1=有失败） */
    public function summary(string $suite): int
    {
        echo "\n[summary $suite] {$this->pass} passed, {$this->fail} failed\n";
        return $this->fail > 0 ? 1 : 0;
    }
}
