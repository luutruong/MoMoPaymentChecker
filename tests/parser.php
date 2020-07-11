<?php

require __DIR__ . '/../vendor/autoload.php';

$testHtml = file_get_contents(__DIR__ . '/email.html');
$parser = new \MoMoPaymentChecker\Parser($testHtml);


$result = $parser->parse();
var_dump($result);die;
