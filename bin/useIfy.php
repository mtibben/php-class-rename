#!/usr/bin/php
<?php

require_once __DIR__.'/../vendor/autoload.php';

function useifyDir($dir)
{
    $files = scandir($dir);

    foreach ($files as $f) {
        if ($f[0] == '.') {
            continue;
        }

        useifyFile("$dir/$f");
    }
}

function useifyFile($path)
{
    if (is_dir($path)) {
        useifyDir($path);
        return;
    }

    if (!preg_match('/.php$/', $path)) {
        return;
    }

    echo "Usifying $path\n";

    $f = \Mtibben\PhpClassRename\File::createFromPath($path);
    $f->findAndfixClasses();
    $f->save();
}

for ($i=1; $i < count($argv); $i++) {
    useifyFile($argv[$i]);
}
