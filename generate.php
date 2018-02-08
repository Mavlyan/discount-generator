<?php

namespace Mygento\Discount\Generator;

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/Source.php');
require_once(__DIR__ . '/Source_M1.php');
require_once(__DIR__ . '/Source_M2.php');
require_once(__DIR__ . '/Generator.php');

/**
 * Example to run: php generate.php -p=m1 --class=Mygento_Boxberry_Helper_Discount --code=boxberry
 */


$platforms = ['m1', 'm2'];
$args      = getopt('p:', ['class::', 'code::']);

//===Validate===
if (!isset($args['p']) || !in_array($args['p'], $platforms)) {
    die("=== Error === \n Please set required param `-p`. Possible values: m1 or m2. Example: `php generator.php -p=m1` \n");
}
if ($args['p'] == 'm1' && !isset($args['class'])) {
    die("=== Error === \n Please specify class for Magento 1. Example: `php generator.php -p=m1 --class=Mygento_Boxberry_Helper_Discount --code=boxberry` \n");
}
if ($args['p'] == 'm1' && !isset($args['code'])) {
    die("=== Error === \n Please specify code for Magento 1. Example: `php generator.php -p=m1 --class=Mygento_Kkm_Helper_Discount --code=kkm` \n");
}

if ($args['p'] == 'm2') {
    $generator = new Generator();
    $generator->setSource('\Mygento\Discount\Generator\Source_M2');
    $generator->setPlatform('m2');

    $generator->generate();
}

if ($args['p'] == 'm1') {
    $generator = new Generator();
    $generator->setSource('\Mygento\Discount\Generator\Source_M1');
    $generator->setPlatform('m1');
    $generator->setCode($args['code']);
    $generator->setClass($args['class']);

    $generator->generate();
}

echo '0';