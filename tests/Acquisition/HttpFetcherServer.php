<?php

declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

match ($requestPath) {
    '/file.pdf' => print "%PDF-1.7\nserved\n%%EOF\n",
    '/redirect.pdf' => header('Location: /file.pdf', true, 302),
    '/gone.pdf' => http_response_code(410),
    '/error.pdf' => http_response_code(500),
    default => http_response_code(404),
};
