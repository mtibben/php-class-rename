<?php

namespace Mtibben\PhpClassRename;

class Classname {
    public $classname;

    public function __construct($classname) {
        if ($this->classname[0] != '\\') {
            $this->classname = '\\'.$classname;
        } else {
            $this->classname = $classname;
        }
    }

    public function nameWithNamespace() {
        return ltrim($this->classname, '\\');
    }

    public function nameWithNamespaceAndLeadingSlash() {
        return $this->classname;
    }

    public function nameWithoutNamespace() {
        $parts = explode('\\', $this->classname);
        return array_pop($parts);
    }

    public function short() {
        $parts = explode('\\', $this->classname);
        $parts = explode('_', array_pop($parts));
        return array_pop($parts);
    }

    public function ns() {
        $parts = explode('\\', $this->classname);
        array_pop($parts);

        return implode('\\', $parts);
    }

    public function equals(Classname $c) {
        return $c->nameWithNamespace() == $this->nameWithNamespace();
    }

    public function __toString() {
        return $this->nameWithNamespace();
    }
}
