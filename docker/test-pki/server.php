<?php

declare(strict_types=1);

$pkiDirectory = (string) getenv('PKI_DIRECTORY');
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

$staticFiles = [
    '/crl/root.crl' => ['root.crl', 'application/pkix-crl'],
    '/crl/intermediate.crl' => ['intermediate.crl', 'application/pkix-crl'],
    '/certs/root.crt' => ['root.der', 'application/pkix-cert'],
    '/certs/intermediate.crt' => ['intermediate.der', 'application/pkix-cert'],
];

if (isset($staticFiles[$requestPath])) {
    [$fileName, $contentType] = $staticFiles[$requestPath];
    header('Content-Type: '.$contentType);
    readfile($pkiDirectory.'/'.$fileName);

    return true;
}

if ($requestPath === '/health') {
    echo is_file($pkiDirectory.'/.done') ? 'ok' : 'pki-missing';

    return true;
}

if ($requestPath === '/tsa' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    respondWithOpenSsl(
        (string) file_get_contents('php://input'),
        static fn (string $queryPath, string $responsePath): array => ['openssl', 'ts', '-reply', '-config', $pkiDirectory.'/tsa.cnf', '-queryfile', $queryPath, '-out', $responsePath],
        'application/timestamp-reply',
    );

    return true;
}

if (str_starts_with($requestPath, '/ocsp')) {
    $ocspRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? file_get_contents('php://input')
        : base64_decode(rawurldecode(substr($requestPath, strlen('/ocsp/'))), true);

    respondWithOpenSsl(
        (string) $ocspRequest,
        static fn (string $queryPath, string $responsePath): array => [
            'openssl', 'ocsp',
            '-index', $pkiDirectory.'/intermediate/index.txt',
            '-CA', $pkiDirectory.'/intermediate.crt',
            '-rsigner', $pkiDirectory.'/ocsp.crt',
            '-rkey', $pkiDirectory.'/ocsp.key',
            '-reqin', $queryPath,
            '-respout', $responsePath,
            '-ndays', '7',
        ],
        'application/ocsp-response',
    );

    return true;
}

http_response_code(404);
echo 'not found';

return true;

/**
 * @param  callable(string, string): list<string>  $command
 */
function respondWithOpenSsl(string $query, callable $command, string $contentType): void
{
    if ($query === '') {
        http_response_code(400);
        echo 'empty request';

        return;
    }

    $queryPath = (string) tempnam(sys_get_temp_dir(), 'query');
    $responsePath = (string) tempnam(sys_get_temp_dir(), 'response');
    file_put_contents($queryPath, $query);

    $process = proc_open($command($queryPath, $responsePath), [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    $output = is_resource($process) ? (string) stream_get_contents($pipes[1]) : '';
    $exitCode = is_resource($process) ? proc_close($process) : 1;
    $response = (string) file_get_contents($responsePath);
    @unlink($queryPath);
    @unlink($responsePath);

    if ($exitCode !== 0 || $response === '') {
        http_response_code(500);
        error_log('openssl failed: '.$output);
        echo 'openssl failed';

        return;
    }

    header('Content-Type: '.$contentType);
    echo $response;
}
