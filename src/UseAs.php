<?php

namespace Mtibben\PhpClassRename;

class UseAs
{
    private $container = array();
    private $keywords = array('__halt_compiler', 'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'namespace', 'new', 'or', 'print', 'private', 'protected', 'public', 'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor');
    private $conflictCtr = 1;

    public static function createFromSrc($src)
    {
        $useas = new static();
        preg_match_all('/\nuse ([\w\\\\]+)( as ([\w\\\\]+))?;/', $src, $matches, PREG_SET_ORDER);
        // var_dump($matches);
        foreach ($matches as $m) {
            $class = new Classname($m[1]);
            $useas->addClassname(
                $class,
                isset($m[3]) ? $m[3] : $class->nameWithoutNamespace()
            );
        }

        return $useas;
    }

    public function addDisallowedAlias($alias)
    {
        $this->keywords[] = strtolower($alias);
    }

    public function isAliasValid($alias)
    {
        return !in_array(strtolower($alias), $this->keywords);
    }

    public function addClassname(Classname $class, $alias = null)
    {
        // echo "adding ";
        // var_dump($class);
        if (!$alias) {
            $alias = $class->short();
            if ($this->has($alias) || !$this->isAliasValid($alias)) {
                $alias = $class->nameWithoutNamespace();
                if ($this->has($alias) || !$this->isAliasValid($alias)) {
                    $alias = str_replace('\\', '_', $class->nameWithNamespace());
                }
            }
        }
        if ($this->has($alias) || !$this->isAliasValid($alias)) {
            $alias .= $this->conflictCtr++;
        }

        $this->container[] = [
            'classname' => $class,
            'alias' => $alias,
        ];

        return $alias;
    }

    public function getOrAddClassname(Classname $class)
    {
        // echo "getOrAddClassname ";
        // var_dump($class);
        // var_dump($this->getAliasForClassname($class));
        if ($name = $this->getAliasForClassname($class)) {
            return $name;
        }

        return $this->addClassname($class);
    }


    public function getAliasForClassname(Classname $class)
    {
        foreach ($this->container as $c) {
            if ($c['classname']->equals($class)) {
                return $c['alias'];
            }
        }

        return false;
    }

    public function hasClassname(Classname $class)
    {
        foreach ($this->container as $c) {
            if ($c['classname']->equals($class)) {
                return true;
            }
        }

        return false;
    }

    public function has($classAlias)
    {
        foreach ($this->container as $c) {
            if (strtolower($c['alias']) == strtolower($classAlias)) {
                return true;
            }
        }

        return false;
    }

    public function get($classAlias)
    {
        foreach ($this->container as $c) {
            if ($c['alias'] == $classAlias) {
                return $c['classname'];
            }
        }

        return false;
    }

    public function getTokens($namespace)
    {
        $ignoreNonCompound = !$namespace;
        if (!$this->container) {
            return [];
        }

        $phpSrc = "<?php\n";
        $suffix = '';
        foreach ($this->container as $c) {
            $hasAlias = $c['alias'] != $c['classname']->nameWithoutNamespace();
            if ($ignoreNonCompound && !$hasAlias && !strpos($c['classname'], '\\')) {
                continue;
            }
            // var_dump($c);
            // var_dump($hasAlias);
            // var_dump('\\'.$namespace);
            // var_dump($c['classname']->ns());
            if(!$hasAlias && '\\'.$namespace == $c['classname']->ns()) {
                continue;
            }


            $phpSrc .= 'use '.$c['classname']->nameWithNamespace();
            if ($hasAlias) {
                $phpSrc .= " as ".$c['alias'];
            }
            $phpSrc .= ";\n";
            $suffix = "\n";
        }
        $t = token_get_all($phpSrc.$suffix);

        return array_slice($t, 1);
    }

    public function replaceClasses($map)
    {
        foreach ($this->container as &$c) {
            $classkey = (string)$c['classname'];
            if(isset($map[$classkey])) {
                $c['classname'] = new Classname($map[$classkey]);
            }
        }
    }

    // public function replaceClass($from, $to)
    // {
    //     echo "Replacing $from with $to\n";
    //     $fromClass = new Classname($from);
    //     // $toClass = new Classname($from);
    //     $newContainer = [];
    //     foreach ($this->container as $c) {
    //         // var_dump($c);
    //         if ($c['classname']->equals($fromClass)) {
    //             echo "Got a match\n";
    //             $c['classname'] = new Classname($to);
    //         }
    //         $newContainer[] = $c;
    //     }

    //     $this->container = $newContainer;
    // }
}
