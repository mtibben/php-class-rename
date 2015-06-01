<?php

namespace Mtibben\PhpClassRename;

class Runner
{
    private $dirs;
    private $replacementMap = array();

    private function processDir($dir, $root, $action)
    {
        $files = scandir($dir);

        foreach ($files as $f) {
            if ($f[0] == '.') {
                continue;
            }

            $this->processFile("$dir/$f", $root, $action);
        }
    }

    private function processFile($path, $root, $action)
    {
        if (is_dir($path)) {
            $this->processDir($path, $root, $action);
            return;
        }

        if (!preg_match('/.php$/', $path) || preg_match('#/Resources/#', $path)) {
            // echo "skipping $path\n";
            return;
        }

        $action($path, $root);
    }

    private function addFileToReplacementMap($path, $root)
    {
        $f = File::createFromPath($path);
        $f->setPsr4Root($root);
        $fullClassname = (string)$f->getFullClassname();
        $impliedPsr4Classname = (string)$f->getImpliedPsr4Classname();

        if ($fullClassname != $impliedPsr4Classname) {
            $this->replacementMap[$fullClassname] = $impliedPsr4Classname;
        }
    }

    private function createReplacementMap()
    {
        foreach($this->dirs as $path) {
            $this->processFile($path, $path, [$this, 'addFileToReplacementMap']);
        }
    }

    public function getClassReplacementMap()
    {
        if (!$this->replacementMap) {
            $this->createReplacementMap();
        }

        return $this->replacementMap;
    }

    private function renameClassesInFile($path, $root)
    {
        $f = File::createFromPath($path);
        $f->setPsr4Root($root);
        $f->findAndfixClasses();
        $impliedPsr4Classname = $f->getImpliedPsr4Classname();
        $f->setNamespace($impliedPsr4Classname->ns());
        $f->setClassname($impliedPsr4Classname->nameWithoutNamespace());
        $f->setClassnameReplacements($this->getClassReplacementMap());
        $f->save();
    }

    public function renameClasses()
    {
        $this->getClassReplacementMap();

        foreach($this->dirs as $path) {
            $this->processFile($path, $path, [$this, 'renameClassesInFile']);
        }
    }

    public function setDirs(array $dirs)
    {
        $this->dirs = $dirs;
    }

    private function useifyFile($path, $root)
    {
        $f = File::createFromPath($path);
        $f->findAndfixClasses();
        $f->save();
    }

    public function useify()
    {
        foreach($this->dirs as $path) {
            $this->processFile($path, $path, [$this, 'useifyFile']);
        }
    }
}
