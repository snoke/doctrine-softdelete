<?php

namespace Snoke\SoftDelete\Annotation;

#[\Attribute] final class SoftDeleteCascade
{
    public function __construct(
        public readonly bool $orphanRemoval = false
    ) {}
}