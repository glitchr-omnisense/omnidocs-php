<?php

namespace Omnidocs\Php;

use Omnidocs\Html\Conventions;
use Omnidocs\Html\Headings;
use Omnidocs\Html\Text;
use Omnidocs\LinkerInterface;
use Omnidocs\Model\Heading;
use Omnidocs\Model\Node;
use Omnidocs\Model\Page;
use Omnidocs\Model\Rendered;
use Omnidocs\Model\Symbol;
use Omnidocs\SourceInterface;
use Omnidocs\Support\Path;

/**
 * A PHP package's API reference as a manual: a home that lists every
 * namespace and its classes - a card per class, its kind, what it is, what it
 * holds counted -, then a page per namespace - each class with its
 * declaration, its PHPDoc, the options of its configuration (a factory's),
 * its constants, cases, properties and methods; each with its anchor, each an
 * API entry (Symbol) the search finds. A class or a member links to its
 * line in the repository (the linker's file()).
 *
 *   new PhpSource('/path/to/omnidrive/core', title: 'API reference');
 */
final class PhpSource implements SourceInterface
{
    /** @var list<ApiClass>|null */
    private ?array $classes = null;

    /** @var array<string, list<ApiClass>>|null by namespace */
    private ?array $namespaces = null;

    /** @var array<string, array{html: string, headings: list<Heading>, symbols: list<Symbol>, anchors: array<string, string>}> */
    private array $built = [];

    private readonly Conventions $conventions;

    /**
     * @param string       $root       the package's folder (its composer.json's)
     * @param string       $repository what $root is in the repository ("" its root), for the links to the source
     * @param list<string> $exclude    folder or file names left out, at any depth
     */
    public function __construct(
        private readonly string $root,
        private readonly string $title = 'API reference',
        private readonly string $repository = '',
        private readonly array $exclude = ['Tests', 'tests', 'vendor', 'docker', 'node_modules', 'var', 'docs'],
        ?Conventions $conventions = null,
    ) {
        $this->conventions = $conventions ?? new Conventions();
    }

    /** @return list<ApiClass> */
    public function classes(): array
    {
        return $this->classes ??= (new Reader())->read($this->root, $this->exclude);
    }

    public function home(): ?Node
    {
        return [] !== $this->classes() ? new Node('', $this->title, null, 0) : null;
    }

    public function tree(): array
    {
        $tree = [];
        $order = 0;
        foreach (array_keys($this->namespaces()) as $namespace) {
            $tree[] = new Node(self::pathOf($namespace), '' !== $namespace ? $namespace : '(global)', null, ++$order);
        }

        return $tree;
    }

    public function page(string $path, ?LinkerInterface $links = null): ?Page
    {
        if ('' === $path) {
            if ([] === $this->classes()) {
                return null;
            }
            // The index: a group per namespace, a card per class - its name to its entry, its kind, what it is,
            // what it holds counted (the omnidocs conventions the JavaScript index shares).
            $html = '';
            $words = ['option' => 'options', 'method' => 'methods', 'property' => 'properties', 'constant' => 'constants', 'case' => 'cases'];
            foreach ($this->namespaces() as $namespace => $classes) {
                $html .= '<section class="api-group"><p class="api-group-title"><a href="'.Conventions::escape(null !== $links ? $links->page(self::pathOf($namespace)) : '#').'"><code>'.Conventions::escape($namespace ?: '(global)').'</code></a></p><ul class="api-cards">';
                foreach ($classes as $class) {
                    $anchor = $this->build($namespace, $links)['anchors'][$class->name] ?? null;
                    $counts = ['option' => \count($class->options)];
                    foreach ($class->members as $member) {
                        $counts[$member->kind] = ($counts[$member->kind] ?? 0) + 1;
                    }
                    $held = [];
                    foreach ($words as $one => $many) {
                        $n = $counts[$one] ?? 0;
                        if ($n > 0) {
                            $held[] = $n.' '.(1 === $n ? $one : $many);
                        }
                    }
                    $html .= '<li class="api-card" data-class="'.Conventions::escape($class->name).'">'
                        .'<p class="api-card-name"><a href="'.Conventions::escape(null !== $links ? $links->page(self::pathOf($namespace), $anchor) : '#').'"><code>'.Conventions::escape($class->shortName()).'</code></a> <span class="api-kind">'.Conventions::escape($class->kind).'</span></p>'
                        .('' !== $class->doc->summary ? '<p class="api-card-about">'.Conventions::escape($class->doc->summary).'</p>' : '')
                        .([] !== $held ? '<p class="api-card-counts">'.Conventions::escape(implode(' · ', $held)).'</p>' : '')
                        .'</li>';
                }
                $html .= '</ul></section>';
            }
            $html = '<div class="api-index">'.$html.'</div>';

            return new Page('', $this->title, new Rendered($html, $this->title, [], [], Text::of($html)));
        }
        foreach ($this->namespaces() as $namespace => $classes) {
            if (self::pathOf($namespace) === $path) {
                $built = $this->build($namespace, $links);

                return new Page($path, $namespace ?: '(global)', new Rendered($built['html'], $namespace, $built['headings'], [], Text::of($built['html'])), null, $built['symbols']);
            }
        }

        return null;
    }

    public function symbols(): array
    {
        $all = [];
        foreach (array_keys($this->namespaces()) as $namespace) {
            array_push($all, ...$this->build($namespace, null)['symbols']);
        }

        return $all;
    }

    /** @return array<string, list<ApiClass>> */
    private function namespaces(): array
    {
        if (null === $this->namespaces) {
            $namespaces = [];
            foreach ($this->classes() as $class) {
                $namespaces[$class->namespace()][] = $class;
            }
            ksort($namespaces);
            $this->namespaces = $namespaces;
        }

        return $this->namespaces;
    }

    /**
     * A namespace's page.
     *
     * @return array{html: string, headings: list<Heading>, symbols: list<Symbol>, anchors: array<string, string>}
     */
    private function build(string $namespace, ?LinkerInterface $links): array
    {
        $key = $namespace.'@'.(null !== $links ? spl_object_id($links) : '');
        if (isset($this->built[$key])) {
            return $this->built[$key];
        }
        $path = self::pathOf($namespace);
        $taken = [];
        $html = '';
        $symbols = [];
        $anchors = [];
        $source = fn (string $file, int $line): string => null !== $links && null !== ($url = $links->file($this->inRepository($file), 'L'.$line)) ? ' <a class="api-source" href="'.Conventions::escape($url).'">'.Conventions::escape(basename($file)).':'.$line.'</a>' : '';
        foreach ($this->namespaces()[$namespace] ?? [] as $class) {
            $id = Headings::id($class->shortName(), $taken);
            $anchors[$class->name] = $id;
            $html .= '<h2 id="'.$id.'"><code>'.Conventions::escape($class->shortName()).'</code> <span class="api-kind">'.Conventions::escape($class->kind).'</span></h2>';
            $html .= '<p class="api-declaration"><code>'.Conventions::escape($class->declaration()).'</code>'.$source($class->file, $class->line).'</p>';
            if (null !== $class->doc->deprecated) {
                $html .= $this->conventions->callout('deprecated', '<p>'.Conventions::escape($class->doc->deprecated).'</p>');
            }
            $html .= $class->doc->html();
            $symbols[] = new Symbol($class->kind, $class->name, $path, $id, $class->doc->summary, $class->declaration(), '' !== $namespace ? $namespace : null, $this->inRepository($class->file).'#L'.$class->line);
            if ([] !== $class->options) {
                $optionsId = Headings::id($class->shortName().' options', $taken);
                $html .= '<h3 id="'.$optionsId.'">Options</h3><table class="api-options"><thead><tr><th>Option</th><th>Default</th><th></th></tr></thead><tbody>';
                foreach ($class->options as $option) {
                    $oid = Headings::id($class->shortName().' option '.$option['name'], $taken);
                    $html .= '<tr id="'.$oid.'"><td><code>'.Conventions::escape($option['name']).'</code></td><td>'.(null !== $option['default'] ? '<code>'.Conventions::escape($option['default']).'</code>' : '').'</td><td>'.($option['required'] ? '<strong>required</strong> ' : '').Conventions::escape($option['doc']).'</td></tr>';
                    $symbols[] = new Symbol('option', $class->shortName().' '.$option['name'], $path, $oid, trim(($option['required'] ? 'Required. ' : '').(null !== $option['default'] ? 'Default: '.$option['default'].'. ' : '').$option['doc']), null, $class->name);
                }
                $html .= '</tbody></table>';
            }
            $plain = array_values(array_filter($class->members, static fn (ApiMember $m): bool => 'method' !== $m->kind));
            if ([] !== $plain) {
                $html .= '<dl class="api-members">';
                foreach ($plain as $member) {
                    $mid = Headings::id($class->shortName().' '.$member->name, $taken);
                    $html .= '<dt id="'.$mid.'"><code>'.Conventions::escape($member->signature).'</code></dt><dd>'.($member->doc->html() ?: '').'</dd>';
                    $symbols[] = new Symbol($member->kind, $class->name.'::'.$member->name, $path, $mid, $member->doc->summary, $member->signature, $class->name);
                }
                $html .= '</dl>';
            }
            foreach ($class->members as $member) {
                if ('method' !== $member->kind) {
                    continue;
                }
                $mid = Headings::id($class->shortName().' '.$member->name, $taken);
                $html .= '<h3 id="'.$mid.'"><code>'.Conventions::escape($class->shortName().'::'.$member->name.'()').'</code></h3>';
                $html .= $this->conventions->code(Conventions::escape($member->signature), 'php');
                if (null !== $member->doc->deprecated) {
                    $html .= $this->conventions->callout('deprecated', '<p>'.Conventions::escape($member->doc->deprecated).'</p>');
                }
                $html .= $member->doc->html();
                $rows = '';
                foreach ($member->doc->params as $name => $text) {
                    if ('' !== $text) {
                        $rows .= '<dt><code>$'.Conventions::escape($name).'</code></dt><dd>'.DocText::toHtml($text).'</dd>';
                    }
                }
                if (null !== $member->doc->return) {
                    $rows .= '<dt>Returns</dt><dd>'.DocText::toHtml($member->doc->return).'</dd>';
                }
                foreach ($member->doc->throws as $throw) {
                    $rows .= '<dt>Throws</dt><dd>'.DocText::toHtml($throw).'</dd>';
                }
                if ('' !== $rows) {
                    $html .= '<dl class="api-params">'.$rows.'</dl>';
                }
                $symbols[] = new Symbol('method', $class->name.'::'.$member->name.'()', $path, $mid, $member->doc->summary, $member->signature, $class->name, $this->inRepository($class->file).'#L'.$member->line);
            }
        }

        return $this->built[$key] = ['html' => $html, 'headings' => Headings::of($html, 2, 3), 'symbols' => $symbols, 'anchors' => $anchors];
    }

    private function inRepository(string $file): string
    {
        return (string) Path::normalise(('' !== $this->repository ? trim($this->repository, '/').'/' : '').$file);
    }

    /** "Omnidrive\Model" → "omnidrive/model". */
    private static function pathOf(string $namespace): string
    {
        return '' === $namespace ? 'global' : implode('/', array_map(Path::slug(...), explode('\\', $namespace)));
    }
}
