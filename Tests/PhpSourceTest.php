<?php

namespace Omnidocs\Php\Tests;

use Omnidocs\Php\PhpSourceFactory;
use Omnidocs\Php\Reader;
use Omnidocs\Registry;
use Omnidocs\SourceInterface;
use Omnidocs\Testing\RecordingLinker;
use Omnidocs\Testing\SourceContractTests;
use PHPUnit\Framework\TestCase;

/**
 * A real package's sources: omnisense/omnidocs itself, as Composer installed
 * it beside this package (its classes, its PHPDoc, the options of its
 * "array" factory), and this package's own.
 */
final class PhpSourceTest extends TestCase
{
    use SourceContractTests;

    private static function core(): string
    {
        return \dirname((string) (new \ReflectionClass(Registry::class))->getFileName());
    }

    protected function source(): SourceInterface
    {
        return (new PhpSourceFactory())->create(['root' => self::core()]);
    }

    protected function documentsSymbols(): bool
    {
        return true;
    }

    public function testTheCoresClassesAreReadWithTheirPhpdoc(): void
    {
        $classes = [];
        foreach ((new Reader())->read(self::core()) as $class) {
            $classes[$class->name] = $class;
        }

        self::assertArrayHasKey('Omnidocs\Registry', $classes);
        self::assertArrayNotHasKey('Omnidocs\Tests\ModelTest', $classes, 'no test');
        self::assertSame('final class Registry', $classes['Omnidocs\Registry']->declaration());
        self::assertSame("The application's sources by name, each built once from its factory and\noptions:", $classes['Omnidocs\Registry']->doc->summary);
        $members = [];
        foreach ($classes['Omnidocs\Registry']->members as $member) {
            $members[$member->name] = $member->signature;
        }
        self::assertSame('public function get(string $name): SourceInterface', $members['get']);
        self::assertSame('interface SourceInterface', $classes['Omnidocs\SourceInterface']->declaration());
        self::assertSame('final class Heading', $classes['Omnidocs\Model\Heading']->declaration());
        self::assertSame(['__construct', '$level', '$id', '$text', 'toArray'], array_map(static fn ($m) => $m->name, $classes['Omnidocs\Model\Heading']->members), 'the promoted properties are its properties');
    }

    public function testAFactorysOptionsAreReadFromItsDefaults(): void
    {
        foreach ((new Reader())->read(self::core()) as $class) {
            if ('Omnidocs\Testing\ArraySourceFactory' === $class->name) {
                self::assertSame([['pages', '[]', true], ['symbols', '[]', false]], array_map(static fn (array $o): array => [$o['name'], $o['default'], $o['required']], $class->options));

                return;
            }
        }
        self::fail('the ArraySourceFactory');
    }

    public function testEveryNamespaceIsAPageAndEveryEntryASymbol(): void
    {
        $source = $this->source();
        $paths = array_map(static fn ($n) => $n->path, $source->tree());

        self::assertContains('omnidocs', $paths);
        self::assertContains('omnidocs/model', $paths);
        $links = new RecordingLinker();
        $page = $source->page('omnidocs', $links);
        self::assertNotNull($page);
        self::assertStringContainsString('<h2 id="registry"><code>Registry</code> <span class="api-kind">class</span></h2>', $page->html());
        self::assertStringContainsString('<code class="language-php">public function get(string $name): SourceInterface</code>', $page->html());
        self::assertStringContainsString('href="repo:Registry.php#L', $page->html());
        $names = array_map(static fn ($s) => $s->name, $source->symbols());
        self::assertContains('Omnidocs\Registry::get()', $names);
        self::assertContains('ArraySourceFactory pages', $names);
        self::assertContains('Omnidocs\Model\Heading::$level', $names);
    }
}
