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

    public function __construct($src)
    {
        $this->tokens = token_get_all($src);
        // var_dump($this->tokens);
        $this->originalUse = UseAs::createFromSrc($src);
        $this->originalUse->addDisallowedAlias($this->getClass());
        $this->newUse = clone $this->originalUse;
        // echo 'added '.$this->getClass();
    }

    public static function createFromPath($path)
    {
        $f = new File(file_get_contents($path));
        $f->path = $path;

        return $f;
    }

    public function findAndfixClasses()
    {
        $classesToFix = $this->findClasses();
        // var_dump($classesToFix);
        $this->fixClasses($classesToFix);
        $this->insertUse();
    }

    public function getClass()
    {
        list($null, $pos) = $this->positionForSequence([
            [T_CLASS, 1],
            [T_WHITESPACE, '*'],
        ]);

        if ($pos) {
            return $this->findClassInNextTokens($pos+1)->name;
        }

        return '';
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
            // var_dump($position);
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

        // var_dump($classPosition);die();

        $usePositions = $this->allPositionsForSequence([
            [T_USE, 1],
            [[T_WHITESPACE, T_NS_SEPARATOR, T_STRING, T_AS], '*'],
            [';', 1],
            [T_WHITESPACE, '*'],
        ], $classPosition);

        // var_dump($usePositions);die();

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

        return new Classname($this->getNamespace().'\\'.$name);
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
            // var_dump($c->name);
            // var_dump($resolvedClass);
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

    // private function shortClassName($fullClassname) {
    //     preg_match('#^(.+[_\\\\])?(\w+)$#', $fullClassname, $matches);

    //     return $matches[2];
    // }

        // $uses = $this->findAll([
        //     [T_USE, 1],
        //     [T_WHITESPACE, '+'],
        //     [[T_NS_SEPARATOR, T_STRING], '+', 'class'],
        //     [T_WHITESPACE, '*'],
        //     [T_AS, '?'],
        //     [[T_NS_SEPARATOR, T_STRING], '?', 'as'],
        //     [T_WHITESPACE, '*'],
        //     [';', 1],
        // ]);

        // foreach($uses as $use) {
        //     $class = new Classname($use['class']);
        //     $as = (!isset($u['as']))
        //         ? $u['as']
        //         : $class->nameWithoutNamespace();

        //     $this->originalUse[$as] = $class;
        // }


    // public function findAll(array $tokenSequence)
    // {
    //     $seqIterator = new ArrayIterator($tokenSequence);
    //     $tokenIterator = new ArrayIterator($this->tokens);

    //     while($tokenIterator->valid()) {
    //         $seqIterator->rewind();
    //         list($allowedTokens, $timesAllowed, $namedParam) = $seqIterator->current();

    //         $this->seekToNextType($tokenIterator, $allowedTokens);

    //         while($tokenIterator->valid()) {
    //             $match = array();
    //             if (!$seqIterator->valid()) {
    //                 return $tokenIterator->key();
    //             }
    //             list($allowedTokens, $timesAllowed, $namedParam) = $seqIterator->current();
    //             if ($timesAllowed == '*') {
    //                 while($tokenIterator->valid() && $this->isTokenType($tokenIterator->current(), $allowedTokens)) {
    //                     $tokenIterator->next();
    //                 }
    //             } else if ($timesAllowed == '+') {
    //                 if ($this->isTokenType($tokenIterator->current(), $allowedTokens)) {
    //                     $tokenIterator->next();
    //                 } else {
    //                     continue 2;
    //                 }

    //                 while($tokenIterator->valid() && $this->isTokenType($tokenIterator->current(), $allowedTokens)) {
    //                     $tokenIterator->next();
    //                 }

    //             } else if ($timesAllowed == '?') {
    //                 if ($this->isTokenType($tokenIterator->current(), $allowedTokens)) {
    //                     $tokenIterator->next();
    //                 }
    //             } else {
    //                 for ($i=0; $i < $timesAllowed; $i++) {
    //                     if ($this->isTokenType($tokenIterator->current(), $allowedTokens)) {
    //                         $tokenIterator->next();
    //                     } else {
    //                         continue 2;
    //                     }
    //                 }
    //             }
    //             $seqIterator->next();
    //         }
    //     }

    //     return null;
    // }
}
