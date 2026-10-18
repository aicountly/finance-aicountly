<?php
declare(strict_types=1);

require_once __DIR__ . '/../Helpers/ResponseHelper.php';

class BaseController
{
    /** @var array<string,mixed>|null Decoded Finance JWT payload for the current request */
    protected ?array $currentUser = null;

    /** @var array<string,mixed>|null Parsed JSON body (cached) */
    protected ?array $input = null;

    /** @var array<string,string> Route parameters like {id} */
    protected array $params = [];

    /**
     * Declared explicitly so subclasses can open with the conventional
     * `parent::__construct()`. PHP raises `Error: Cannot call constructor`
     * when a child calls a parent constructor that does not exist, which
     * turned every request through AuthController into a 500.
     */
    public function __construct()
    {
    }

    /**
     * Read and cache the JSON request body.
     *
     * @return array<string,mixed>
     */
    public function getInput(): array
    {
        if ($this->input === null) {
            $raw = file_get_contents('php://input');
            $this->input = ($raw !== false && $raw !== '')
                ? (json_decode($raw, true) ?? [])
                : [];
        }
        return $this->input;
    }

    /** @param array<string,mixed> $user */
    public function setCurrentUser(array $user): void
    {
        $this->currentUser = $user;
    }

    /** @param array<string,string> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Who is acting, for audit_log and the four-eyes rule.
     *
     * @return array{actor:string, name:?string, ip:?string}
     */
    protected function actor(): array
    {
        global $currentUser;
        $user = $currentUser ?? $this->currentUser ?? [];

        return [
            'actor' => strtolower(trim((string) ($user['email'] ?? ''))) ?: 'unknown',
            'name'  => isset($user['name']) ? (string) $user['name'] : null,
            'ip'    => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
        ];
    }

    /** @return list<string> the current user's Finance roles */
    protected function roles(): array
    {
        global $currentUser;
        $user = $currentUser ?? $this->currentUser ?? [];

        return array_values(array_filter((array) ($user['roles'] ?? []), 'is_string'));
    }

    /** A positive integer route parameter, or 404. */
    protected function idParam(string $name = 'id'): int
    {
        $raw = (string) ($this->params[$name] ?? '');
        if (!ctype_digit($raw) || (int) $raw <= 0) {
            json_not_found('Not found.');
        }

        return (int) $raw;
    }

    protected function query(string $key): ?string
    {
        $v = $_GET[$key] ?? null;

        return is_string($v) ? trim($v) : null;
    }

    /** Send a CSV download and stop. */
    protected function sendCsv(string $filename, string $content, ?string $sha256 = null): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if ($sha256 !== null) {
            header('X-Content-SHA256: ' . $sha256);
        }
        echo $content;
        exit;
    }
}
