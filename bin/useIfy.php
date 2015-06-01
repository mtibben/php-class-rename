#!/usr/bin/php
<?php

require_once __DIR__.'/../vendor/autoload.php';

use Mtibben\PhpClassRename\Runner;

$runner = new Runner();

$cmd = new Commando\Command();

$cmd->option()
    ->require()
    ->describedAs('A list of files or directories');

$runner->setDirs($cmd->getArgumentValues());
$runner->useify();
