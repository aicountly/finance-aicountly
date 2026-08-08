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
}
