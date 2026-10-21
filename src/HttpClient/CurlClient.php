<?php

declare(strict_types=1);

namespace Keel\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 client on ext-curl. One handle per instance, reused across requests of a worker, so
 * connections stay open between requests to the same host. Responses are buffered in memory.
 * Status codes are never turned into exceptions, as PSR-18 requires.
 */
final class CurlClient implements ClientInterface
{
    private readonly HttpClientConfig $config;

    private ?\CurlHandle $handle = null;

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        ?HttpClientConfig $config = null,
    ) {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('CurlClient needs ext-curl');
        }
        $this->config = $config ?? new HttpClientConfig();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $scheme = $request->getUri()->getScheme();
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RequestException($request, sprintf('Unsupported scheme "%s"', $scheme));
        }
        $url = (string) $request->getUri();
        $method = $request->getMethod();
        if ($url === '' || $method === '') {
            throw new RequestException($request, 'Request needs a URL and a method');
        }

        $handle = $this->handle ??= curl_init();
        curl_reset($handle);

        /** @var list<string> $responseHeaders */
        $responseHeaders = [];
        $options = [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HEADER => false,
            \CURLOPT_TIMEOUT_MS => (int) round($this->config->timeout * 1000),
            \CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->config->connectTimeout * 1000),
            \CURLOPT_FOLLOWLOCATION => $this->config->maxRedirects > 0,
            \CURLOPT_MAXREDIRS => max(0, $this->config->maxRedirects),
            \CURLOPT_SSL_VERIFYPEER => $this->config->verifyTls,
            \CURLOPT_SSL_VERIFYHOST => $this->config->verifyTls ? 2 : 0,
            \CURLOPT_HTTP_VERSION => $request->getProtocolVersion() === '1.0' ? \CURL_HTTP_VERSION_1_0 : \CURL_HTTP_VERSION_1_1,
            \CURLOPT_HEADERFUNCTION => static function (\CurlHandle $h, string $line) use (&$responseHeaders): int {
                $responseHeaders[] = $line;

                return strlen($line);
            },
        ];
        if ($this->config->userAgent !== '') {
            $options[\CURLOPT_USERAGENT] = $this->config->userAgent;
        }
        if ($this->config->proxy !== null) {
            $options[\CURLOPT_PROXY] = $this->config->proxy;
        }
        if ($request->getMethod() === 'HEAD') {
            $options[\CURLOPT_NOBODY] = true;
        }

        $headers = [];
        foreach ($this->config->headers as $name => $value) {
            if (!$request->hasHeader($name)) {
                $headers[] = $name . ': ' . $value;
            }
        }
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        $body = $request->getBody();
        if ($body->getSize() !== 0 && !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $options[\CURLOPT_POSTFIELDS] = (string) $body;
            if (!$request->hasHeader('Content-Type')) {
                $headers[] = 'Content-Type:';
            }
        } elseif (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            $headers[] = 'Content-Length: 0';
        }
        $headers[] = 'Expect:';
        $options[\CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            $error = curl_error($handle);
            $code = curl_errno($handle);
            // The handle may be in a bad state after a failure: start over next time.
            curl_close($handle);
            $this->handle = null;

            throw new NetworkException($request, sprintf('%s (curl %d)', $error !== '' ? $error : 'request failed', $code), $code);
        }

        return $this->response($responseHeaders, $raw);
    }

    /**
     * Builds the response from the headers of the last hop (redirect hops are discarded).
     *
     * @param list<string> $lines
     */
    private function response(array $lines, string $body): ResponseInterface
    {
        $status = 200;
        $reason = '';
        $version = '1.1';
        $headers = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            if (preg_match('~^HTTP/(\d(?:\.\d)?)\s+(\d{3})\s*(.*)$~', $line, $m) === 1) {
                // A new status line: a redirect hop or a 100 Continue; forget the headers so far.
                [$version, $status, $reason] = [$m[1] === '2' ? '2' : $m[1], (int) $m[2], $m[3]];
                $headers = [];
                continue;
            }
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)][] = trim($value);
        }

        $response = $this->responses->createResponse($status, $reason)->withProtocolVersion($version);
        foreach ($headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withBody($this->streams->createStream($body));
    }
}
