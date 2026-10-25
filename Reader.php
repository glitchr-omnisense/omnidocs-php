<?php

namespace Omnidocs\Php;

use Omnidocs\Exception\SourceException;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Reads a package's PHP files - never loads them - into its public API: the
 * classes, interfaces, traits and enums, their public constants, properties
 * (promoted ones included) and methods, each with its PHPDoc; and, for a
 * factory, the options its configuration takes: the keys of the arrays it
 * gives as defaults (`->defaults([...])`) and the ones it requires
 * (`'*.required_options' => [...]`, `->validateNotEmpty([...])`).
 *
 * What is @internal, private, or under a folder left out (Tests, vendor...)
 * is not part of it.
 */
final class Reader
{
    private readonly Parser $parser;

    private readonly Standard $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->printer = new Standard(['shortArraySyntax' => true]);
    }

    /**
     * @param list<string> $exclude folder or file names left out, at any depth
     *
     * @return list<ApiClass> sorted by name
     */
    public function read(string $root, array $exclude = ['Tests', 'tests', 'vendor', 'docker', 'node_modules', 'var']): array
    {
        $root = rtrim($root, '/');
        if (!is_dir($root)) {
            throw new SourceException(\sprintf('No folder %s to read PHP from.', $root));
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => '.' !== $file->getFilename()[0] && !\in_array($file->getFilename(), $exclude, true),
        ));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        $classes = [];
        foreach ($files as $file) {
            foreach ($this->file($file, substr($file, \strlen($root) + 1)) as $class) {
                $classes[$class->name] = $class;
            }
        }
        ksort($classes);

        return array_values($classes);
    }

    /** @return list<ApiClass> */
    public function file(string $file, string $relative): array
    {
        try {
            $statements = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (\PhpParser\Error $e) {
            throw new SourceException(\sprintf('%s does not parse: %s', $relative, $e->getMessage()), 0, $e);
        }
        $classes = [];
        $namespace = '';
        $walk = function (array $nodes) use (&$walk, &$classes, &$namespace, $relative): void {
            foreach ($nodes as $node) {
                if ($node instanceof Stmt\Namespace_) {
                    $namespace = null !== $node->name ? $node->name->toString() : '';
                    $walk($node->stmts);
                } elseif ($node instanceof Stmt\ClassLike && null !== $node->name) {
                    $class = $this->classLike($node, $namespace, $relative);
                    if (null !== $class) {
                        $classes[] = $class;
                    }
                }
            }
        };
        $walk($statements);

        return $classes;
    }

    private function classLike(Stmt\ClassLike $node, string $namespace, string $file): ?ApiClass
    {
        $doc = DocText::of($node->getDocComment());
        if ($doc->internal) {
            return null;
        }
        $short = (string) $node->name;
        $kind = match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };
        $modifiers = [];
        if ($node instanceof Stmt\Class_) {
            if ($node->isAbstract()) {
                $modifiers[] = 'abstract';
            }
            if ($node->isFinal()) {
                $modifiers[] = 'final';
            }
            if ($node->isReadonly()) {
                $modifiers[] = 'readonly';
            }
        }
        $extends = [];
        $implements = [];
        if ($node instanceof Stmt\Class_) {
            $extends = null !== $node->extends ? [$node->extends->toString()] : [];
            $implements = array_map(static fn (Node\Name $n): string => $n->toString(), $node->implements);
        } elseif ($node instanceof Stmt\Interface_) {
            $extends = array_map(static fn (Node\Name $n): string => $n->toString(), $node->extends);
        } elseif ($node instanceof Stmt\Enum_) {
            $implements = array_map(static fn (Node\Name $n): string => $n->toString(), $node->implements);
        }
        $members = [];
        foreach ($node->stmts as $statement) {
            array_push($members, ...$this->members($statement, $kind));
        }

        return new ApiClass(
            ('' !== $namespace ? $namespace.'\\' : '').$short,
            $kind,
            $modifiers,
            $extends,
            $implements,
            $doc,
            $members,
            $this->options($node),
            $file,
            $node->getStartLine(),
        );
    }

    /** @return list<ApiMember> */
    private function members(Stmt $statement, string $kind): array
    {
        $members = [];
        $public = static fn (Stmt\ClassMethod|Stmt\Property|Stmt\ClassConst $s): bool => 'interface' === $kind || $s->isPublic();
        if ($statement instanceof Stmt\ClassConst && $public($statement)) {
            foreach ($statement->consts as $const) {
                $doc = DocText::of($statement->getDocComment());
                if (!$doc->internal) {
                    $members[] = new ApiMember('constant', $const->name->toString(), 'const '.$const->name->toString().' = '.$this->print($const->value), $doc, $statement->getStartLine());
                }
            }
        } elseif ($statement instanceof Stmt\EnumCase) {
            $members[] = new ApiMember('case', $statement->name->toString(), 'case '.$statement->name->toString().(null !== $statement->expr ? ' = '.$this->print($statement->expr) : ''), DocText::of($statement->getDocComment()), $statement->getStartLine());
        } elseif ($statement instanceof Stmt\Property && $public($statement)) {
            $doc = DocText::of($statement->getDocComment());
            foreach ($statement->props as $property) {
                if (!$doc->internal) {
                    $members[] = new ApiMember('property', '$'.$property->name->toString(), trim(self::modifiers($statement->flags).' '.$this->type($statement->type).' $'.$property->name->toString().(null !== $property->default ? ' = '.$this->print($property->default) : '')), $doc, $statement->getStartLine());
                }
            }
        } elseif ($statement instanceof Stmt\ClassMethod && $public($statement)) {
            $doc = DocText::of($statement->getDocComment());
            if ($doc->internal) {
                return [];
            }
            $params = [];
            foreach ($statement->params as $param) {
                $params[] = $this->param($param);
                // A promoted property is the class's too.
                if (0 !== $param->flags && ($param->flags & Modifiers::PUBLIC) !== 0 && $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                    $members[] = new ApiMember('property', '$'.$param->var->name, trim(self::modifiers($param->flags).' '.$this->type($param->type).' $'.$param->var->name), DocText::of($param->getDocComment(), $doc->param($param->var->name)), $statement->getStartLine());
                }
            }
            $modifiers = trim(($statement->isAbstract() && 'interface' !== $kind ? 'abstract ' : '').($statement->isFinal() ? 'final ' : '').'public '.($statement->isStatic() ? 'static ' : ''));
            $return = null !== $statement->returnType ? ': '.$this->type($statement->returnType) : '';
            array_unshift($members, new ApiMember('method', $statement->name->toString(), trim($modifiers.' function '.$statement->name->toString().'('.implode(', ', $params).')'.$return), $doc, $statement->getStartLine()));
        }

        return $members;
    }

    /**
     * A factory's options: the keys of the arrays given to ->defaults([...]), their defaults, and the
     * ones required.
     *
     * @return list<array{name: string, default: ?string, required: bool, doc: string}>
     */
    private function options(Stmt\ClassLike $node): array
    {
        $finder = new NodeFinder();
        $options = [];
        $required = [];
        foreach ($finder->findInstanceOf($node->stmts, Node\Expr\MethodCall::class) as $call) {
            if (!$call->name instanceof Node\Identifier || [] === $call->args || !$call->args[0] instanceof Node\Arg) {
                continue;
            }
            $array = $call->args[0]->value;
            if (!$array instanceof Node\Expr\Array_) {
                continue;
            }
            $method = $call->name->toString();
            if ('defaults' === $method) {
                foreach ($array->items as $item) {
                    if (null === $item || !$item->key instanceof Node\Scalar\String_) {
                        continue;
                    }
                    $key = $item->key->value;
                    if (str_ends_with($key, '.required_options') && $item->value instanceof Node\Expr\Array_) {
                        $required = [...$required, ...self::strings($item->value)];
                        continue;
                    }
                    if (str_contains($key, '.factory_')) {
                        continue; // the factory's own name and title
                    }
                    $comments = $item->getComments();
                    $options[$key] = ['name' => $key, 'default' => $this->print($item->value), 'required' => false, 'doc' => trim(implode(' ', array_map(static fn ($c): string => trim((string) preg_replace('#^\s*(//|/\*+|\*/|\*)\s?#m', '', $c->getText())), $comments)))];
                }
            } elseif ('validateNotEmpty' === $method) {
                $required = [...$required, ...self::strings($array)];
            }
        }
        foreach ($required as $key) {
            $options[$key] = ['name' => $key, 'default' => $options[$key]['default'] ?? null, 'required' => true, 'doc' => $options[$key]['doc'] ?? ''];
        }

        return array_values($options);
    }

    /** @return list<string> */
    private static function strings(Node\Expr\Array_ $array): array
    {
        $out = [];
        foreach ($array->items as $item) {
            if (null !== $item && $item->value instanceof Node\Scalar\String_) {
                $out[] = $item->value->value;
            }
        }

        return $out;
    }

    private function param(Node\Param $param): string
    {
        $name = $param->var instanceof Node\Expr\Variable && \is_string($param->var->name) ? $param->var->name : '?';

        return trim(($param->flags ? self::modifiers($param->flags).' ' : '').$this->type($param->type).($param->byRef ? ' &' : ' ').($param->variadic ? '...' : '').'$'.$name.(null !== $param->default ? ' = '.$this->print($param->default) : ''));
    }

    private function type(Node\Identifier|Node\Name|Node\ComplexType|null $type): string
    {
        if (null === $type) {
            return '';
        }

        return $this->printType($type);
    }

    private function printType(Node $type): string
    {
        return match (true) {
            $type instanceof Node\Identifier => $type->toString(),
            $type instanceof Node\Name => $type->toCodeString(),
            $type instanceof Node\NullableType => '?'.$this->printType($type->type),
            $type instanceof Node\UnionType => implode('|', array_map(fn (Node $t): string => $t instanceof Node\IntersectionType ? '('.$this->printType($t).')' : $this->printType($t), $type->types)),
            $type instanceof Node\IntersectionType => implode('&', array_map(fn (Node $t): string => $this->printType($t), $type->types)),
            default => '',
        };
    }

    private function print(Node\Expr $expr): string
    {
        $code = $this->printer->prettyPrintExpr($expr);

        return mb_strlen($code) > 160 ? mb_substr($code, 0, 157).'…' : $code;
    }

    private static function modifiers(int $flags): string
    {
        $out = [];
        if ($flags & Modifiers::PUBLIC) {
            $out[] = 'public';
        }
        if ($flags & Modifiers::PROTECTED) {
            $out[] = 'protected';
        }
        if ($flags & Modifiers::STATIC) {
            $out[] = 'static';
        }
        if ($flags & Modifiers::READONLY) {
            $out[] = 'readonly';
        }

        return implode(' ', $out);
    }
}
