<?php
declare(strict_types=1);

return [
    'JWT_SECRET'      => $_ENV['JWT_SECRET']       ?? '',
    'TOKEN_TTL_HOURS' => (int) ($_ENV['TOKEN_TTL_HOURS'] ?? 8),
];
