<?php

namespace Omnidocs\Php;

/** A class, an interface, a trait or an enum of a package's public API, as its source declares it. */
final class ApiClass
{
    /**
     * @param list<string>                                                              $modifiers  "abstract", "final", "readonly"
     * @param list<string>                                                              $extends
     * @param list<string>                                                              $implements
     * @param list<ApiMember>                                                           $members
     * @param list<array{name: string, default: ?string, required: bool, doc: string}> $options    a factory's configuration
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly array $modifiers,
        public readonly array $extends,
        public readonly array $implements,
        public readonly DocText $doc,
        public readonly array $members,
        public readonly array $options,
        /** Its file, relative to the folder read. */
        public readonly string $file,
        public readonly int $line,
    ) {
    }

    public function shortName(): string
    {
        $at = strrpos($this->name, '\\');

        return false === $at ? $this->name : substr($this->name, $at + 1);
    }

    public function namespace(): string
    {
        $at = strrpos($this->name, '\\');

        return false === $at ? '' : substr($this->name, 0, $at);
    }

    /** "final class Registry implements Drives". */
    public function declaration(): string
    {
        $short = static fn (string $name): string => ltrim(substr($name, (int) strrpos('\\'.$name, '\\')), '\\');

        return trim(implode(' ', $this->modifiers).' '.$this->kind.' '.$this->shortName()
            .([] !== $this->extends ? ' extends '.implode(', ', array_map($short, $this->extends)) : '')
            .([] !== $this->implements ? ' implements '.implode(', ', array_map($short, $this->implements)) : ''));
    }
}
