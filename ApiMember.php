<?php

namespace Omnidocs\Php;

/** A public constant, enum case, property or method of a class, as its source declares it. */
final class ApiMember
{
    public function __construct(
        /** "constant", "case", "property", "method". */
        public readonly string $kind,
        public readonly string $name,
        /** As declared: "public function get(string $name): Drive". */
        public readonly string $signature,
        public readonly DocText $doc,
        public readonly int $line,
    ) {
    }
}
