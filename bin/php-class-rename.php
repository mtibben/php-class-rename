#!/usr/bin/php
<?php

require_once __DIR__.'/../vendor/autoload.php';

use Mtibben\PhpClassRename\Runner;

$runner = new Runner();

$cmd = new Commando\Command();

$cmd->option()
    ->require()
    ->describedAs('A list of files or directories');

$cmd->option('dry-run')
    ->aka('n')
    ->describedAs('Dry run')
    ->boolean();


$runner->setDirs($cmd->getArgumentValues());

if ($cmd['dry-run']) {
    echo "Classes to be renamed:\n";
    foreach($runner->getClassReplacementMap() as $sourceClass => $targetClass) {
        echo "  $sourceClass -> $targetClass\n";
    }
} else {
    echo "Getting classes to be renamed\n";
    $runner->getClassReplacementMap();

    echo "Updating classes\n";
    $runner->renameClasses();
}
