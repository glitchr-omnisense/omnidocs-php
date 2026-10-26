# omnidocs/php

A PHP package's API reference, read from its sources - never loaded, never run - as an
[omnidocs](https://github.com/glitchr-omnisense/omnidocs) manual: a home that lists every namespace and
its classes, then a page per namespace, each class with its declaration (`final class Registry
implements Drives`), its PHPDoc, the **options of its configuration** (a factory's `->defaults([...])`
keys and defaults, the ones it requires), its public constants, enum cases, properties (promoted ones
included) and methods - signature, text, parameters, return, exceptions - each at its anchor, each an
API entry the search finds, each linked to its line in the repository.

It stands on what exists: [nikic/php-parser](https://github.com/nikic/PHP-Parser) reads the files,
[phpdocumentor/reflection-docblock](https://github.com/phpDocumentor/ReflectionDocBlock) the PHPDoc.
What is `@internal`, private or protected, or under Tests, vendor, docker, docs, is left out.

```php
$api = (new Omnidocs\Php\PhpSourceFactory())->create(['root' => 'vendor/omnisense/omnidrive', 'title' => 'API reference']);
```

| Option | Default | Meaning |
|---|---|---|
| `root` | (required) | the package's folder |
| `title` | `API reference` | the reference's title |
| `repository` | `""` | what `root` is in the repository: the links to the source |
| `exclude` | Tests, tests, vendor, docker, node_modules, var, docs | folder or file names left out |
| `labels` | English | the callouts' words |

## Verified, and not

- Verified (omnidocs' harness, PHP 8.4): omnidocs' conformance suite on omnisense/omnidocs' own
  sources (its classes, PHPDoc, promoted properties, the options of its "array" factory); `BareTest`.
- Verified in use: the Omnisense site's API reference of each of its 150 PHP packages (omnibase 4.x,
  the families, their providers), read at the commit each manual is pinned to.
- Not verified: options given otherwise than in an array literal (a constant, a method call); attributes
  (`#[...]`) are not shown; a package with two classes of the same name in one namespace (one wins).

Licence: MIT.
