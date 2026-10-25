<?php

namespace Omnidocs\Php;

use Omnidocs\Html\Prose;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use phpDocumentor\Reflection\DocBlockFactory;
use PhpParser\Comment\Doc;

/**
 * A PHPDoc comment, read by phpdocumentor/reflection-docblock: its summary,
 * its description, what its tags say of the parameters, the return, the
 * exceptions; whether it is @internal or @deprecated. A comment it cannot
 * read is kept as its text.
 */
final class DocText
{
    private static ?DocBlockFactory $factory = null;

    /**
     * @param array<string, string> $params by name, without "$"
     * @param list<string>          $throws
     */
    public function __construct(
        public readonly string $summary = '',
        public readonly string $description = '',
        public readonly array $params = [],
        public readonly ?string $return = null,
        public readonly array $throws = [],
        public readonly bool $internal = false,
        public readonly ?string $deprecated = null,
    ) {
    }

    public static function of(?Doc $doc, ?string $fallback = null): self
    {
        if (null === $doc) {
            return new self((string) $fallback);
        }
        $text = $doc->getText();
        try {
            $block = (self::$factory ??= DocBlockFactory::createInstance())->create($text);
        } catch (\Throwable) {
            return new self(trim((string) preg_replace('#^\s*(/\*\*|\*/|\*)\s?#m', '', $text)));
        }
        $params = [];
        foreach ($block->getTagsByName('param') as $tag) {
            if ($tag instanceof Param && null !== $tag->getVariableName()) {
                $params[$tag->getVariableName()] = trim((string) $tag->getDescription());
            }
        }
        $return = null;
        foreach ($block->getTagsByName('return') as $tag) {
            if ($tag instanceof Return_) {
                $return = trim((string) $tag->getDescription()) ?: null;
            }
        }
        $throws = [];
        foreach ($block->getTagsByName('throws') as $tag) {
            if ($tag instanceof Throws) {
                $throws[] = trim(ltrim((string) $tag->getType(), '\\').' '.$tag->getDescription());
            }
        }
        $deprecated = $block->hasTag('deprecated') ? trim((string) ($block->getTagsByName('deprecated')[0] ?? '')) : null;

        return new self(
            trim($block->getSummary()),
            trim((string) $block->getDescription()),
            $params,
            $return,
            $throws,
            $block->hasTag('internal'),
            null !== $deprecated ? (str_starts_with($deprecated, '@deprecated') ? trim(substr($deprecated, 11)) : $deprecated) : null,
        );
    }

    /** What a parameter's @param says. */
    public function param(string $name): ?string
    {
        return isset($this->params[$name]) && '' !== $this->params[$name] ? $this->params[$name] : null;
    }

    /** The summary and the description, as HTML. */
    public function html(): string
    {
        return self::toHtml(trim($this->summary."\n\n".$this->description));
    }

    /** A comment's text as HTML (omnidocs' Prose). */
    public static function toHtml(string $text): string
    {
        return Prose::toHtml($text);
    }
}
