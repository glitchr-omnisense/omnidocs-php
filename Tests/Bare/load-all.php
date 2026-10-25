<?php

/*
 * Loads every class of the package (but its tests and docs) with Composer's
 * autoloader, then prints what was loaded: BareTest's child process.
 * Arguments: the autoloader, the package's directory.
 */
[, $autoload, $root] = $argv;
require $autoload;
$root = (string) realpath($root);
$loaded = [];
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static fn (SplFileInfo $file) => !in_array($file->getFilename(), ['Tests', 'vendor', 'docs', 'assets', 'docker', '.git', '.github'], true),
));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
        continue;
    }
    $class = 'Omnidocs\\Php\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
    if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
        $loaded[] = $class;
    }
}
echo json_encode([
    'loaded' => $loaded,
    'symbols' => [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
], JSON_UNESCAPED_SLASHES);
