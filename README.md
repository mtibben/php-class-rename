# php-class-rename

`php-class-rename` is a tool to help rewrite PHP class names.

It can be used to convert code to use [PHP namespaces](http://php.net/manual/en/language.namespaces.php), be PSR-4 compliant or more generally to rename classes.

## TODO

This is a work in progress, currently the code only extracts the classnames for the `use` block.

- [ ] Determine namespace and implied namespace
- [ ] Namespace
- [ ] Search and replace strings
- [ ] Output a "replacements" file to be used by another tool like sed to update other references to old class names

## How it works

`php-class-rename` searches a directory for php files. It builds a map of classnames, then determines the implied PSR-4 classname from the location of the files. It then rewrites the classname and other PHP code to use the implied PSR-4 class name.

So for example, if I have a PHP class file called `MyClass.php`
```php
class OldVendor_OldNamespace_MyClass {
    public function getDate() {
        return new DateTime("2014-10-20");
    }
}
```

and I put that class in `src/NewVendor/NewNamespace/MyClass.php` and then run `php-class-rename src`, the file contents now look like this:

```php
namespace NewVendor\NewNamespace;

use DateTime;

class MyClass {
    public function getDate() {
        return new DateTime("2014-10-20");
    }
}
```

This is very useful if you need to move around large numbers of files around in your codebase - simply move the files to their desired location, and run `php-class-rename`.
