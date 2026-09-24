<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord\Adapters\Clickhouse;

use ActiveRecord\Exceptions\DatabaseException;

/**
 * Minimal client for the ClickHouse HTTP interface.
 *
 * Keeps one curl handle, and so one keep-alive connection, for its whole life.
 * Queries go in the POST body and settings in the URL.
 *
 * @package ActiveRecord
 */
class HttpClient
{
    private $curl;
    private $url;
    private $headers;

    /** @var string Response body of the request in flight */
    private $body;

    /** @var array Response headers of the request in flight, lower-cased names */
    private $response_headers;

    /**
     * @param string $url Base URL, such as http://127.0.0.1:8123/
     * @param string $user
     * @param string $password
     * @param string|null $database Database the queries run in
     * @param bool $compress Ask for compressed responses (zstd, gzip...)
     * @param int $connect_timeout Seconds
     * @param int $timeout Seconds for a whole request, 0 for no limit
     */
    public function __construct($url, $user, $password, $database, $compress = true, $connect_timeout = 10, $timeout = 0)
    {
        if (!extension_loaded('curl')) {
            throw new DatabaseException('The ClickHouse adapter needs the curl extension');
        }

        $this->url = $url;
        $this->headers = [
            'X-ClickHouse-User: ' . $user,
            'X-ClickHouse-Key: ' . $password,
            // curl otherwise waits for a "100 Continue" before sending large bodies
            'Expect:',
        ];

        if ($database !== null && $database !== '') {
            $this->headers[] = 'X-ClickHouse-Database: ' . $database;
        }

        $this->curl = curl_init();

        curl_setopt_array($this->curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_CONNECTTIMEOUT => $connect_timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) {
                $this->body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($curl, $line) {
                if (strpos($line, ':') !== false) {
                    [$name, $value] = explode(':', $line, 2);
                    $this->response_headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);

        if ($compress) {
            // an empty string accepts every encoding this curl build can decode
            curl_setopt($this->curl, CURLOPT_ENCODING, '');
        }
    }

    /**
     * Runs one query.
     *
     * @param string $sql Query text
     * @param array $settings ClickHouse settings for this query
     * @return array{body: string, format: string|null, summary: array|null, timezone: string|null}
     * @throws DatabaseException on any error reported by the server or by curl
     */
    public function execute($sql, array $settings = [])
    {
        $this->body = '';
        $this->response_headers = [];

        curl_setopt($this->curl, CURLOPT_URL, $this->url . ($settings ? '?' . http_build_query($settings) : ''));
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $sql);

        $ok = curl_exec($this->curl);
        $status = curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);

        if ($ok === false || $status != 200) {
            $this->fail($ok === false ? curl_error($this->curl) : null, $status);
        }

        $summary = $this->response_headers['x-clickhouse-summary'] ?? null;

        return [
            'body' => $this->body,
            'format' => $this->response_headers['x-clickhouse-format'] ?? null,
            'summary' => $summary ? json_decode($summary, true) : null,
            'timezone' => $this->response_headers['x-clickhouse-timezone'] ?? null,
        ];
    }

    /**
     * @param string|null $curl_error
     * @param int $status HTTP status, 0 if there was no response
     */
    private function fail($curl_error, $status)
    {
        $code = (int)($this->response_headers['x-clickhouse-exception-code'] ?? 0);
        $message = trim($this->body);

        // An error after the result started streaming arrives with status 200:
        // the server writes __exception__ and the message, then cuts the
        // transfer. 25.10 and later also wrap the message in a random tag.
        $marker = strpos($this->body, '__exception__', max(0, strlen($this->body) - 65536));

        if ($marker !== false) {
            $tail = substr($this->body, $marker);
            $message = preg_match('/Code: \d+\..*?\(version [^()]*(?:\([^()]*\))?\)/s', $tail, $matches)
                ? $matches[0]
                : trim(str_replace('__exception__', '', $tail));
        } elseif ($curl_error !== null) {
            $message = $status == 200
                ? "ClickHouse stopped sending the result midway ($curl_error)"
                : "Cannot reach ClickHouse at {$this->url}: $curl_error";
        }

        if (!$code && preg_match('/^Code: (\d+)\./', $message, $matches)) {
            $code = (int)$matches[1];
        }

        $this->body = '';
        throw new DatabaseException($message, $code);
    }
}
