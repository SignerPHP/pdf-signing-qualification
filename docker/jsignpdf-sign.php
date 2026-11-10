<?php

declare(strict_types=1);

use Jeidison\JSignPDF\Sign\JSignParam;
use Jeidison\JSignPDF\Sign\JSignService;

require '/opt/implementations/jsignpdf-php/vendor/autoload.php';

$options = getopt('', ['input:', 'output:', 'cert:', 'password:', 'java:', 'jsignpdf:', 'java-option:', 'hash-algorithm:', 'engine:']);

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
    ->addJSignParameters(['-ha' => $options['hash-algorithm'], '-eng' => $options['engine']]);

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
