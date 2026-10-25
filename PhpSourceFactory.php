<?php

namespace Omnidocs\Php;

use Omnidocs\Config;
use Omnidocs\Html\Conventions;
use Omnidocs\SourceFactory;
use Omnidocs\SourceInterface;

/**
 * The "php" source:
 *
 *   root        the package's folder                                   required
 *   title       the reference's title                                  "API reference"
 *   repository  what root is in the repository, for the source links  ""
 *   exclude     folder or file names left out                          Tests, tests, vendor, docker, node_modules, var, docs
 *   labels      the callouts' words (deprecated...)
 */
final class PhpSourceFactory extends SourceFactory
{
    public function getName(): string
    {
        return 'php';
    }

    protected function populate(Config $config): void
    {
        $config->defaults([
            'title' => 'API reference',
            'repository' => '',
            'exclude' => ['Tests', 'tests', 'vendor', 'docker', 'node_modules', 'var', 'docs'],
            'labels' => [],
            'omnidocs.required_options' => ['root'],
        ]);
    }

    protected function build(Config $config): SourceInterface
    {
        return new PhpSource(
            $config->directory('root', $this->getName()),
            (string) $config->string('title'),
            (string) $config->string('repository'),
            $config->list('exclude'),
            new Conventions($config->map('labels')),
        );
    }
}
