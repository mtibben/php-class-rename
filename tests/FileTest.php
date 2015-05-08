<?php

namespace Mtibben\PhpClassRename;

use PHPUnit_Framework_TestCase;

class FileTest extends PHPUnit_Framework_TestCase
{
    public function testFindClassesNew()
    {
        $src = <<<'EOT'
<?php
new Foo();
EOT;

        $f = new File($src);
        $classes = $f->findClasses();
        $c = array_pop($classes);


        $this->assertEquals('Foo', $c->name);
        $this->assertEquals(3, $c->from);
        $this->assertEquals(3, $c->to);
    }

    public function testFindClassesStatic()
    {
        $src = <<<'EOT'
<?php
Foo::bar();
EOT;

        $f = new File($src);
        $classes = $f->findClasses();
        $c = array_pop($classes);

        $this->assertEquals('Foo', $c->name);
        $this->assertEquals(1, $c->from);
        $this->assertEquals(1, $c->to);
    }

    public function testFindClassesExtends()
    {
        $src = <<<'EOT'
<?php
class Foo extends Bar {

}
EOT;

        $f = new File($src);
        $classes = $f->findClasses();
        $c = array_pop($classes);

        $this->assertEquals('Bar', $c->name);
        $this->assertEquals(7, $c->from);
        $this->assertEquals(7, $c->to);
    }

    public function testFindClassesImplements()
    {
        $src = <<<'EOT'
<?php
class Foo implements Bar {

}
EOT;

        $f = new File($src);
        $classes = $f->findClasses();
        $c = array_pop($classes);

        $this->assertEquals('Bar', $c->name);
        $this->assertEquals(7, $c->from);
        $this->assertEquals(7, $c->to);
    }

    public function testFindClassesCatch()
    {
        $src = <<<'EOT'
<?php

try {} catch (Bar $b) {}

EOT;

        $f = new File($src);
        $classes = $f->findClasses();
        $c = array_pop($classes);

        $this->assertEquals('Bar', $c->name);
        $this->assertEquals(10, $c->from);
        $this->assertEquals(10, $c->to);
    }

    public function testFindClassesWithNewVar()
    {
        $src = <<<'EOT'
<?php

new $blah;
parent::blah();
self::foo();
static::bar();
EOT;


        $f = new File($src);
        $classes = $f->findClasses();

        $this->assertEquals(0, count($classes));
   }

    public function testPositionForSequence()
    {
        $src = <<<'EOT'
<?php

namespace Foo\Bar\Baz;


EOT;

        $f = new File($src);
        list($first, $last) = $f->positionForSequence([
            [T_OPEN_TAG, 1],
        ]);
        $this->assertEquals(0, $first);
        $this->assertEquals(0, $last);
        list($first, $last) = $f->positionForSequence([
            [T_NAMESPACE, 1],
            [T_WHITESPACE, '*'],
            [[T_NS_SEPARATOR, T_STRING], '*'],
            [';', 1],
        ]);
        $this->assertEquals(9, $last);
    }

    public function testFindAndFix()
    {
        $src = <<<'EOT'
<?php
new \Foo\Bar();
EOT;

        $expected = <<<'EOT'
<?php
use Foo\Bar;

new Bar();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();


        $this->assertEquals($expected, $f->getSrc());
    }

    public function testFindAndFixWithNamespace()
    {
        $src = <<<'EOT'
<?php
namespace Ns;

new \Foo\Bar();
EOT;

        $expected = <<<'EOT'
<?php
namespace Ns;

use Foo\Bar;

new Bar();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
    }

    public function testGetNamespace()
    {
        $src = <<<'EOT'
<?php
namespace Ns\One\Two\Three;

new Foo\Bar();
EOT;

        $f = new File($src);

        $ns = $f->getNamespace();

        $this->assertEquals('Ns\One\Two\Three', $ns);
    }

    public function testFindAndFixWithRelativeClass()
    {
        $src = <<<'EOT'
<?php
namespace Ns;

new Foo\Bar();
EOT;

        $expected = <<<'EOT'
<?php
namespace Ns;

use Ns\Foo\Bar;

new Bar();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
    }


    public function testFindAndFixWithUnderscoredClass()
    {
        $src = <<<'EOT'
<?php
new Foo_Bar();
EOT;

        $expected = <<<'EOT'
<?php
use Foo_Bar as Bar;

new Bar();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
    }

    public function testFindAndFixWithNamespaceAndUnderscoredClass()
    {
        $src = <<<'EOT'
<?php
namespace Ns;

new \Foo_Bar();
EOT;

        $expected = <<<'EOT'
<?php
namespace Ns;

use Foo_Bar as Bar;

new Bar();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
    }

    public function testFindAndFixWithConflictingClass()
    {
        $src = <<<'EOT'
<?php
namespace Ns;

use Another\Bar;

new \Foo_Bar();
EOT;

        $expected = <<<'EOT'
<?php
namespace Ns;

use Another\Bar;
use Foo_Bar;

new Foo_Bar();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
   }

    public function testFindAndFixWithMultiple()
    {
        $src = <<<'EOT'
<?php
namespace Ns;

new \Foo_Bar();
\One\Two\Three::s();
new Wat();

EOT;

        $expected = <<<'EOT'
<?php
namespace Ns;

use Foo_Bar as Bar;
use One\Two\Three;

new Bar();
Three::s();
new Wat();

EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
   }

    public function testFindAndFixWithExistingUse()
    {
        $src = <<<'EOT'
<?php
namespace Ns;

use One;
use Two;
use Three;

new \Foo_Bar();
\One\Two\Three\Four::s();
new Wat();
EOT;

        $expected = <<<'EOT'
<?php
namespace Ns;

use One;
use Two;
use Three;
use Foo_Bar as Bar;
use One\Two\Three\Four;

new Bar();
Four::s();
new Wat();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
   }

    public function testFindAndFixWithUseInClosure()
    {
        $src = <<<'EOT'
<?php

use Foo\Baz;

Baz::bar();

$foo = function ($data) use ($one, $two, $three) {
    $blah = 1;
};
EOT;
        $f = new File($src);
        $f->findAndfixClasses();
        $this->assertEquals($src, $f->getSrc());
   }

    public function testFindAndFixWithUseInClosure2()
    {
        $src = <<<'EOT'
<?php

$collect = function ($data) use ($that, $collected, $collect) {
    $a = $b;
};
EOT;
        $f = new File($src);
        $f->findAndfixClasses();
        $this->assertEquals($src, $f->getSrc());
   }

    public function testFindAndFixWithNamespacedClass()
    {
        $src = <<<'EOT'
<?php

use One\Two\Three;

new Three();
EOT;
        $f = new File($src);
        $f->findAndfixClasses();
        $this->assertEquals($src, $f->getSrc());
   }

    public function testFindAndFixWithDefinedUse()
    {
        $src = <<<'EOT'
<?php

use Edge_File_UploadException as FileUploadException;

FileUploadException::forErrorCode($fileError);
EOT;

        $f = new File($src);
        $f->findAndfixClasses();
        $this->assertEquals($src, $f->getSrc());
   }

    public function testFindAndFixWithRelativeClass2()
    {
        $src = <<<'EOT'
<?php

use One\Two\Three;

new Three\Four();
EOT;

        $expected = <<<'EOT'
<?php

use One\Two\Three;
use One\Two\Three\Four;

new Four();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
   }

    public function testDontUseStatementsWithNoEffect()
    {
        $src = <<<'EOT'
<?php

new Exception();
new Locale();
new InvalidArgumentException();
EOT;

        $expected = <<<'EOT'
<?php

new Exception();
new Locale();
new InvalidArgumentException();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
   }

    public function testClassWithTrait()
    {
        $src = <<<'EOT'
<?php

class My_Class {
    use MyTrait;
    function wat() {
        new Foo\Bar();
    }
}
EOT;

        $expected = <<<'EOT'
<?php

use Foo\Bar;

class My_Class {
    use MyTrait;
    function wat() {
        new Bar();
    }
}
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
   }

    public function testClassWithReservedKeyword()
    {
        $src = <<<'EOT'
<?php

new One_Function();
EOT;

        $expected = <<<'EOT'
<?php

new One_Function();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
   }

    public function testClassWithSameName()
    {
        $src = <<<'EOT'
<?php

class One extends Two\One {

}
EOT;

        $expected = <<<'EOT'
<?php

use Two\One as Two_One;

class One extends Two_One {

}
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
    }

    public function testConflictingClassnames()
    {
        $src = <<<'EOT'
<?php
namespace Ns;

new \One_Two();
\Two::three;
EOT;

        $expected = <<<'EOT'
<?php
namespace Ns;

use One_Two as Two;
use Two as Two1;

new Two();
Two1::three;
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
    }

    public function testConflictingClassnamesWithCase()
    {
        $src = <<<'EOT'
<?php
namespace Ns;

new \One_two();
\Two::three;
EOT;

        $expected = <<<'EOT'
<?php
namespace Ns;

use One_two as two;
use Two as Two1;

new two();
Two1::three;
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
    }

    public function testConflictingClassnamesAgain()
    {
        $src = <<<'EOT'
<?php

new One_Two_Three();
new Three();
EOT;

        $expected = <<<'EOT'
<?php

use One_Two_Three as Three;
use Three as Three1;

new Three();
new Three1();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
    }

    public function testClassesInCurrentNamespace()
    {
        $src = <<<'EOT'
<?php
namespace Ns;

new One();
EOT;

        $expected = <<<'EOT'
<?php
namespace Ns;

new One();
EOT;

        $f = new File($src);
        $f->findAndfixClasses();

        $this->assertEquals($expected, $f->getSrc());
    }

    public function testSetNamespace()
    {
        $src = <<<'EOT'
<?php

class Foo {}
EOT;

        $expected = <<<'EOT'
<?php

namespace Ns;

class Foo {}
EOT;

        $f = new File($src);
        $f->setNamespace('Ns');

        $this->assertEquals($expected, $f->getSrc());
    }

    public function testSetNamespaceWithExisting()
    {
        $src = <<<'EOT'
<?php

namespace Ns1;

class Foo {}
EOT;

        $expected = <<<'EOT'
<?php

namespace Ns2;

class Foo {}
EOT;

        $f = new File($src);
        $f->setNamespace('Ns2');

        $this->assertEquals($expected, $f->getSrc());
    }

}
