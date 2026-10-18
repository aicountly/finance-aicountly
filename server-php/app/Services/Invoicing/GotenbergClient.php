<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

/**
 * Server-to-server PDF rendering through a self-hosted Gotenberg (Chromium) service.
 *
 * Ported from Smart Books (books-react-app server-php/app/Services/Printing/GotenbergPdfClient.php): the same
 * multipart form built by hand exactly like `curl -F` (Gotenberg rejects other encodings), the same auth modes and
 * one controlled retry on transient failures (network, 502, 503, 504). Finance renders one self-contained
 * index.html (no separate header / footer documents) and checks the answer really is a PDF.
 *
 *   GOTENBERG_URL                 base URL, e.g. https://pdf.apis.aicountly.com   (unset → no PDF renderer)
 *   GOTENBERG_AUTH_MODE           none | basic | bearer                          (default none)
 *   GOTENBERG_USERNAME / GOTENBERG_PASSWORD                                      (basic)
 *   GOTENBERG_BEARER_TOKEN                                                       (bearer)
 *   GOTENBERG_TIMEOUT_SECONDS     default 30
 *
 * Nothing here is ever sent to the browser; errors are reduced to safe messages.
 */
final class GotenbergClient
{
    public const HTML_PATH = '/forms/chromium/convert/html';

    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
    }

    public static function fromEnv(): ?self
    {
        if (!self::isConfigured()) {
            return null;
        }

        return new self([
            'base_url'     => rtrim(finance_env('GOTENBERG_URL'), '/'),
            'auth_mode'    => strtolower(finance_env('GOTENBERG_AUTH_MODE', 'none')) ?: 'none',
            'username'     => finance_env('GOTENBERG_USERNAME'),
            'password'     => finance_env('GOTENBERG_PASSWORD'),
            'bearer_token' => finance_env('GOTENBERG_BEARER_TOKEN'),
            'timeout'      => max(5, (int) (finance_env('GOTENBERG_TIMEOUT_SECONDS') ?: 30)),
        ]);
    }

    /** GOTENBERG_URL is an http(s) URL. Reported as invoicing.checks.pdf_renderer in /api/health. */
    public static function isConfigured(): bool
    {
        return (bool) preg_match('#^https?://[^\s/]+#i', finance_env('GOTENBERG_URL'));
    }

    /**
     * @return array{ok:bool, status:int, body:string, error:?string}
     */
    public function render(string $html, string $outputFilename): array
    {
        $headers = [
            'Gotenberg-Trace'           => bin2hex(random_bytes(8)),
            'Gotenberg-Output-Filename' => preg_replace('/[^A-Za-z0-9._-]/', '_', $outputFilename),
            'Accept'                    => 'application/pdf',
        ];
        $this->applyAuth($headers);
        [$body, $contentType] = self::multipart([
            'preferCssPageSize'           => 'true',
            'printBackground'             => 'true',
            'failOnConsoleExceptions'     => 'true',
            'failOnResourceLoadingFailed' => 'true',
        ], [['index.html', $html]]);
        $headers['Content-Type']   = $contentType;
        $headers['Content-Length'] = (string) strlen($body);

        $last = ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'PDF service was not reachable.'];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $last = $this->post($this->config['base_url'] . self::HTML_PATH, $headers, $body);
            if ($last['ok'] || !in_array($last['status'], [0, 502, 503, 504], true)) {
                break;
            }
        }

        return $last;
    }

    /**
     * multipart/form-data exactly like curl -F (required by Gotenberg).
     *
     * @param array<string,string> $fields
     * @param list<array{0:string,1:string}> $files [filename, html]
     * @return array{0:string, 1:string} body and Content-Type
     */
    public static function multipart(array $fields, array $files): array
    {
        $boundary = '----GotenbergForm' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        foreach ($files as [$filename, $contents]) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"files\"; filename=\"{$filename}\"\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n{$contents}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return [$body, 'multipart/form-data; boundary=' . $boundary];
    }

    /**
     * @param array<string,string> $headers
     * @return array{ok:bool, status:int, body:string, error:?string}
     */
    private function post(string $url, array $headers, string $body): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => (int) $this->config['timeout'],
        ];
        if ($this->config['auth_mode'] === 'basic' && ($this->config['username'] !== '' || $this->config['password'] !== '')) {
            $opts[CURLOPT_USERPWD]  = $this->config['username'] . ':' . $this->config['password'];
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }
        curl_setopt_array($ch, $opts);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('[finance] Gotenberg request failed: ' . $err);

            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'PDF service network error.'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => self::safeError($status)];
        }
        if (!str_starts_with((string) $raw, '%PDF-')) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'PDF service answered something that is not a PDF (check GOTENBERG_URL).'];
        }

        return ['ok' => true, 'status' => $status, 'body' => (string) $raw, 'error' => null];
    }

    /** @param array<string,string> $headers */
    private function applyAuth(array &$headers): void
    {
        if ($this->config['auth_mode'] === 'bearer' && $this->config['bearer_token'] !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->config['bearer_token'];
        }
    }

    private static function safeError(int $status): string
    {
        return match (true) {
            $status === 400 => 'PDF service rejected the document.',
            $status === 401, $status === 403 => 'PDF service authentication failed (GOTENBERG_AUTH_MODE / credentials).',
            $status === 503 => 'PDF service unavailable.',
            $status === 504 => 'PDF service timed out.',
            default         => 'PDF service error (HTTP ' . $status . ').',
        };
    }
}
