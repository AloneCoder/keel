<?php

declare(strict_types=1);

namespace Keel\Kernel;

use Psr\Http\Message\ServerRequestInterface;

/**
 * HTML rendering of a Problem Details payload for browsers. In debug mode it shows the
 * exception chain, the trace and the request, with a "Copy as Markdown" button that produces
 * a bug-report-ready text; in production only the status and title.
 */
final class ErrorPage
{
    public static function prefersHtml(ServerRequestInterface $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));
        if ($accept === '') {
            return false;
        }

        $html = strpos($accept, 'text/html');
        $xhtml = strpos($accept, 'application/xhtml+xml');
        $json = strpos($accept, 'json');
        $htmlAt = min($html === false ? \PHP_INT_MAX : $html, $xhtml === false ? \PHP_INT_MAX : $xhtml);

        return $htmlAt !== \PHP_INT_MAX && ($json === false || $htmlAt < $json);
    }

    public static function render(ProblemDetails $problem, \Throwable $exception, ServerRequestInterface $request, bool $debug): string
    {
        $title = self::string($problem->body['title'] ?? 'Error');
        $detail = $debug ? self::string($problem->body['detail'] ?? $exception->getMessage()) : '';
        $h = static fn(string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $sections = '';
        if ($debug) {
            $sections .= self::exceptionSection($exception, $h);
            $sections .= self::requestSection($request, $h);
            $markdown = self::markdown($problem, $exception, $request);
            $sections .= '<textarea id="keel-md" hidden>' . $h($markdown) . '</textarea>'
                . '<p><button type="button" onclick="navigator.clipboard.writeText(document.getElementById(\'keel-md\').value).then(()=>{this.textContent=\'Copied\'})">Copy as Markdown</button></p>';
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $problem->status . ' ' . $h($title) . '</title>'
            . '<style>body{font:15px/1.5 -apple-system,Segoe UI,sans-serif;margin:0;padding:32px;color:#1c1c1c;background:#fafafa}'
            . 'main{max-width:960px;margin:0 auto}h1{font-size:22px;margin:0 0 4px}h1 span{color:#b3261e}p.detail{font-size:17px;margin:0 0 24px}'
            . 'h2{font-size:14px;text-transform:uppercase;letter-spacing:.06em;color:#666;margin:28px 0 8px}'
            . 'pre{background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:12px;overflow:auto;font-size:13px}'
            . 'table{border-collapse:collapse;background:#fff;border:1px solid #e0e0e0;border-radius:6px}td{padding:4px 10px;vertical-align:top;font-size:13px}td:first-child{color:#666;white-space:nowrap}'
            . 'button{font:inherit;padding:6px 12px;border:1px solid #bbb;border-radius:6px;background:#fff;cursor:pointer}</style></head>'
            . '<body><main><h1><span>' . $problem->status . '</span> ' . $h($title) . '</h1>'
            . ($detail !== '' ? '<p class="detail">' . $h($detail) . '</p>' : '')
            . $sections . '</main></body></html>';
    }

    /**
     * @param \Closure(string): string $h
     */
    private static function exceptionSection(\Throwable $exception, \Closure $h): string
    {
        $html = '';
        $current = $exception;
        $depth = 0;
        while ($current !== null && $depth < 10) {
            $html .= '<h2>' . ($depth === 0 ? 'Exception' : 'Caused by') . '</h2>'
                . '<p><strong>' . $h($current::class) . '</strong><br>' . $h($current->getMessage()) . '<br>'
                . '<small>' . $h($current->getFile()) . ':' . $current->getLine() . '</small></p>'
                . '<pre>' . $h($current->getTraceAsString()) . '</pre>';
            $current = $current->getPrevious();
            $depth++;
        }

        return $html;
    }

    /**
     * @param \Closure(string): string $h
     */
    private static function requestSection(ServerRequestInterface $request, \Closure $h): string
    {
        $rows = '<tr><td>Request</td><td>' . $h($request->getMethod() . ' ' . (string) $request->getUri()) . '</td></tr>';
        foreach ($request->getHeaders() as $name => $values) {
            $rows .= '<tr><td>' . $h($name) . '</td><td>' . $h(self::redact($name, implode(', ', $values))) . '</td></tr>';
        }

        return '<h2>Request</h2><table>' . $rows . '</table>';
    }

    public static function markdown(ProblemDetails $problem, \Throwable $exception, ServerRequestInterface $request): string
    {
        $lines = [
            '## ' . $problem->status . ' ' . self::string($problem->body['title'] ?? 'Error'),
            '',
            '**Request:** `' . $request->getMethod() . ' ' . (string) $request->getUri() . '`',
            '',
        ];
        $current = $exception;
        $depth = 0;
        while ($current !== null && $depth < 10) {
            $lines[] = ($depth === 0 ? '### Exception' : '### Caused by');
            $lines[] = '`' . $current::class . '`: ' . $current->getMessage();
            $lines[] = 'at `' . $current->getFile() . ':' . $current->getLine() . '`';
            $lines[] = '';
            $lines[] = '```';
            $lines[] = $current->getTraceAsString();
            $lines[] = '```';
            $lines[] = '';
            $current = $current->getPrevious();
            $depth++;
        }
        $lines[] = '### Headers';
        foreach ($request->getHeaders() as $name => $values) {
            $lines[] = '- ' . $name . ': ' . self::redact($name, implode(', ', $values));
        }

        return implode("\n", $lines) . "\n";
    }

    private static function redact(string $header, string $value): string
    {
        return in_array(strtolower($header), ['authorization', 'cookie', 'x-api-key', 'x-token', 'proxy-authorization'], true)
            ? '[redacted]'
            : $value;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
