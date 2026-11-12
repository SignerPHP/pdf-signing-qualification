<?php

declare(strict_types=1);

use Jeidison\JSignPDF\Sign\JSignParam;
use Jeidison\JSignPDF\Sign\JSignService;

require '/opt/implementations/jsignpdf-php/vendor/autoload.php';

$options = getopt('', ['input:', 'output:', 'cert:', 'password:', 'java:', 'jsignpdf:', 'java-option:', 'hash-algorithm:', 'engine:', 'page:', 'lower-left-x:', 'lower-left-y:', 'upper-right-x:', 'upper-right-y:']);

$placementOptions = ['page', 'lower-left-x', 'lower-left-y', 'upper-right-x', 'upper-right-y'];
$givenPlacementOptions = array_filter($placementOptions, static fn (string $option): bool => is_string($options[$option] ?? null));
if ($givenPlacementOptions !== [] && count($givenPlacementOptions) !== count($placementOptions)) {
    fwrite(STDERR, "A visible signature needs --page, --lower-left-x, --lower-left-y, --upper-right-x and --upper-right-y\n");
    exit(2);
}
$visibleSignature = $givenPlacementOptions === []
    ? []
    : ['-V', '-pg' => $options['page'], '-llx' => $options['lower-left-x'], '-lly' => $options['lower-left-y'], '-urx' => $options['upper-right-x'], '-ury' => $options['upper-right-y']];

$workDirectory = dirname($options['output']).'/';
$parameters = (new JSignParam)
    ->setPdf((string) file_get_contents($options['input']))
    ->setCertificate((string) file_get_contents($options['cert']))
    ->setPassword($options['password'])
    ->setTempPath($workDirectory)
    ->setPathPdfSigned($workDirectory)
    ->setJavaDownloadUrl('')
    ->setJavaPath($options['java'])
    ->setJavaOptions((array) $options['java-option'])
    ->setJSignPdfDownloadUrl('')
    ->setJSignPdfPath($options['jsignpdf'])
    ->addJSignParameters(['-ha' => $options['hash-algorithm'], '-eng' => $options['engine'], ...$visibleSignature]);

try {
    $signedDocument = (new JSignService)->sign($parameters);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

if ($signedDocument === '' || file_put_contents($options['output'], $signedDocument) !== strlen($signedDocument)) {
    fwrite(STDERR, "JSignPdf did not produce a signed document\n");
    exit(1);
}
