<?php

namespace Mtibben\PhpClassRename;

use ArrayObject;

class File
{
    public $path;
    public $tokens;
    private $originalUse;
    private $newUse;
    private $ignoredClassKeywords = [ 'parent', 'self', 'static' ];
    private $newNamespace;
    private $classReplacements = [];

    public function __construct($src)
    {
        $this->tokens = token_get_all($src);
        $this->originalUse = UseAs::createFromSrc($src);
        $this->originalUse->addDisallowedAlias($this->getClass());
        $this->newUse = clone $this->originalUse;
    }

    public static function createFromPath($path)
    {
        $f = new File(file_get_contents($path));
        $f->path = realpath($path);

        return $f;
    }

    public function findAndfixClasses()
    {
        $classesToFix = $this->findClasses();
        $this->fixClasses($classesToFix);
    }

    public function getClass()
    {
        list($null, $pos) = $this->positionForSequence([
            [[T_CLASS,T_INTERFACE,T_TRAIT], 1],
            [T_WHITESPACE, '*'],
        ]);

        if ($pos) {
            return $this->findClassInNextTokens($pos+1)->name;
        }

        return '';
    }


    public function setClassname($classname)
    {
        list($first, $last) = $this->positionForSequence([
            [[T_CLASS,T_INTERFACE,T_TRAIT], 1],
            [T_WHITESPACE, '*'],
            [T_STRING, '1'],
        ]);

        if ($last) {
            $t = token_get_all($classname);
            array_splice($this->tokens, $last, 1, $t);
        } else {
            throw new Exception("no class found");
        }
    }


    public function getFullClassname()
    {
        $ns = $this->getNamespace();
        $class = $this->getClass();
        if ($ns) {
            return new Classname("\\$ns\\$class");
        } else {
            return new Classname($class);
        }
    }

    public function setPsr4Root($dir, $prefix = '\\') {
        $this->psr4RootDir = realpath($dir) ?: $dir;
    }

    public function getImpliedPsr4Classname()
    {
        $psr = preg_quote($this->psr4RootDir, '#');

        preg_match("#^$psr/(.+).php$#", $this->path, $matches);

        $c = str_replace('/','\\', $matches[1]);

        return new Classname($c);
    }

    private $originalNamespace;
    public function getOriginalNamespace()
    {
        return $this->originalNamespace ?: $this->getNamespace();
    }

    public function getNamespace()
    {

        list($null, $pos) = $this->positionForSequence([
            [T_NAMESPACE, 1],
            [T_WHITESPACE, '*'],
        ]);

        if ($pos) {
            return $this->findClassInNextTokens($pos+1)->name;
        }

        return '';
    }

    public function setNamespace($ns)
    {
        $this->originalNamespace = $this->getNamespace();

        $ns = trim($ns, '\\');

        list($first, $last) = $this->positionForSequence([
            [T_NAMESPACE, 1],
            [T_WHITESPACE, '*'],
            [[T_NS_SEPARATOR, T_STRING], '*'],
        ]);

        if ($first) {
            $nsSrc = "<?php\nnamespace $ns;";
            $t = token_get_all($nsSrc);
            array_shift($t);
            array_splice($this->tokens, $first, $last-$first+2, $t);
        } else {
            $whitespace="\n";
            if ($this->tokens[1][0] != T_WHITESPACE) {
                $whitespace="\n\n";
            }
            $nsSrc = "<?php\n\nnamespace $ns;$whitespace";
            $t = token_get_all($nsSrc);
            array_shift($t);
            list($first, $last) = $this->positionForSequence([
                [T_OPEN_TAG, 1],
            ]);

            array_splice($this->tokens, $last+1, 0, $t);
        }
    }

    private function seekToNextType($tokenIterator, $tokenType)
    {
        while ($tokenIterator->valid()) {
            if ($this->isTokenType($tokenIterator->current(), $tokenType)) {
                return;
            }
            $tokenIterator->next();
        }
    }

    public function allPositionsForSequence(array $tokenSequence, $before = null)
    {
        $before = $before ?: PHP_INT_MAX;
        $positions = array();
        $lastpos = null;
        while ($position = $this->positionForSequence($tokenSequence, $lastpos)) {
            if ($position[1] > $before) {
                break;
            }
            $positions[] = $position;
            $lastpos = $position[1]+1;
        }

        return $positions;
    }

    public function positionForSequence(array $tokenSequence, $startFrom = null)
    {
        $seqIterator = (new ArrayObject($tokenSequence))->getIterator();
        $tokenIterator = (new ArrayObject($this->tokens))->getIterator();
        if ($startFrom != null) {
            $tokenIterator->seek($startFrom);
        }
        while ($tokenIterator->valid()) {
            $seqIterator->rewind();
            $keys = array();
            list($allowedTokens, $timesAllowed) = $seqIterator->current();
            $this->seekToNextType($tokenIterator, $allowedTokens);
            while ($tokenIterator->valid()) {
                if (!$seqIterator->valid()) {
                    $first = array_shift($keys);
                    $last = array_pop($keys);

                    return array($first, $last);
                }

                list($allowedTokens, $timesAllowed) = $seqIterator->current();
                if ($timesAllowed == '*') {
                    while ($tokenIterator->valid() && $this->isTokenType($tokenIterator->current(), $allowedTokens)) {
                        $keys[] = $tokenIterator->key();
                        $tokenIterator->next();
                    }
                } else {
                    for ($i = 0; $i < $timesAllowed; $i++) {
                        if ($this->isTokenType($tokenIterator->current(), $allowedTokens)) {
                            $keys[] = $tokenIterator->key();
                            $tokenIterator->next();
                        } else {
                            continue 3;
                        }
                    }
                }
                $seqIterator->next();
            }
        }

        return;
    }

    public function insertUse()
    {
        list($classPosition, $null) = $this->positionForSequence([
            [T_CLASS, 1],
        ]);

        $usePositions = $this->allPositionsForSequence([
            [T_USE, 1],
            [[T_WHITESPACE, T_NS_SEPARATOR, T_STRING, T_AS], '*'],
            [';', 1],
            [T_WHITESPACE, '*'],
        ], $classPosition);

        if ($usePositions) {
            $offsetPos = $usePositions[0][0];
            $length = $usePositions[count($usePositions)-1][1]-$offsetPos+1;
        } else {
            list($null, $openTagPosition) = $this->positionForSequence([
                [T_OPEN_TAG, 1],
                [T_WHITESPACE, '*'],
            ]);
            list($null, $namespaceTagPosition) = $this->positionForSequence([
                [T_NAMESPACE, 1],
                [T_WHITESPACE, '*'],
                [[T_NS_SEPARATOR, T_STRING], '*'],
                [';', 1],
                [T_WHITESPACE, '*'],
            ]);

            $offsetPos = ($namespaceTagPosition ?: $openTagPosition) + 1;
            $length = 0;
        }

        $t = $this->newUse->getTokens($this->getNamespace());

        array_splice($this->tokens, $offsetPos, $length, $t);
    }

    public function findClasses()
    {
        $classesToFix = array();

        foreach ($this->tokens as $i => $t) {
            if ($this->isTokenType($t, array(T_NEW, T_EXTENDS, T_IMPLEMENTS))) {
                $c = $this->findClassInNextTokens($i+2); // +2 to consume whitespace
                if ($c) {
                    $classesToFix[] = $c;
                }
            } elseif ($this->isTokenType($t, array(T_PAAMAYIM_NEKUDOTAYIM, T_DOUBLE_COLON))) {
                $c = $this->findClassInPreviousTokens($i-1);
                if ($c) {
                    $classesToFix[] = $c;
                }
            } elseif ($this->isTokenType($t, array(T_CATCH))) {
                $c = $this->findClassInNextTokens($i+3); // +3 to consume brace and whitespace
                if ($c) {
                    $classesToFix[] = $c;
                }
            } elseif ($this->isTokenType($t, array(T_FUNCTION))) {
                $j=$i+4;
                $c = $this->findClassInNextTokens($j); // +4 to consume whitespace, name, open brace
                if ($c) {
                    $classesToFix[] = $c;
                    $j = $c->to;
                }

                while($this->tokens[$j] != ")") {
                    if ($this->tokens[$j] == ',') {
                        $c = $this->findClassInNextTokens($j+2); // +2 to consume comma and whitepace
                        if ($c) {
                            $classesToFix[] = $c;
                            $j = $c->to;
                        }
                    }
                    $j++;
                }

            }
        }

        return $classesToFix;
    }

    public function resolveClass($name)
    {
        if ($name[0] === '\\') {
            return new Classname($name);
        }

        $classParts = explode('\\', $name);
        if (count($classParts) >= 2) {
            $b = array_shift($classParts);
            $baseClass = $this->originalUse->get($b);
            if ($baseClass) {
                return new Classname($baseClass.'\\'.implode('\\', $classParts));
            }
        }
        if ($c = $this->originalUse->get($name)) {
            return $c;
        }

        return new Classname($this->getOriginalNamespace().'\\'.$name);
    }

    private function createFoundClass($name, $from, $to)
    {
        $c = new FoundClass();
        $c->name = $name;
        $c->from = $from;
        $c->to = $to;

        return $c;
    }


    private function findClassInNextTokens($i)
    {
        $classname = '';
        for ($j = $i; $this->isTokenType($this->tokens[$j], array(T_NS_SEPARATOR, T_STRING)); $j++) {
            $classname .= $this->tokenStr($this->tokens[$j]);
        }
        if ($classname && !in_array($classname, $this->ignoredClassKeywords)) {
            return $this->createFoundClass($classname, $i, $j-1);
        }
    }

    private function findClassInPreviousTokens($i)
    {
        $classname = '';
        for ($j = $i; $this->isTokenType($this->tokens[$j], array(T_NS_SEPARATOR, T_STRING)); $j--) {
            $classname = $this->tokenStr($this->tokens[$j]).$classname;
        }
        if ($classname && !in_array($classname, $this->ignoredClassKeywords)) {
            return $this->createFoundClass($classname, $j+1, $i);
        }
    }

    public function fixClasses($classesToFix)
    {
        $cumulativeOffset = 0;

        foreach ($classesToFix as $c) {
            $resolvedClass = $this->resolveClass($c->name);
            $alias = $this->newUse->getOrAddClassname($resolvedClass);
            $offset = $c->from;
            $length = $c->to - $c->from + 1;
            $replacement = array(array(308, $alias, 2));

            array_splice($this->tokens, $offset+$cumulativeOffset, $length, $replacement);

            $cumulativeOffset -= $length - 1;
        }
    }

    private function isTokenType($t, $types)
    {
        if (!is_array($types)) {
            $types = array($types);
        }

        if (is_array($t)) {
            return in_array($t[0], $types);
        } else {
            return in_array($t, $types);
        }
    }

    private function tokenStr($t)
    {
        if (is_string($t)) {
            return $t;
        }

        return $t[1];
    }

    public function getSrc()
    {
        $this->updateUseWithClassReplacements();
        $this->insertUse();

        $content = "";
        foreach ($this->tokens as $t) {
            $content .= $this->tokenStr($t);
        }

        return $content;
    }

    public function save()
    {
        file_put_contents($this->path, $this->getSrc());
    }

    public function setClassnameReplacements($classReplacements)
    {
        $this->classReplacements = $classReplacements;
    }

    public function updateUseWithClassReplacements()
    {
        if ($this->classReplacements) {
            $this->newUse->replaceClasses($this->classReplacements);
        }
    }
}
