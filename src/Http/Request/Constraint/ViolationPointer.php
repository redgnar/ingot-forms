<?php

declare(strict_types=1);

namespace App\Http\Request\Constraint;

/**
 * Violations raised on behalf of the engine carry the exact JSON Pointer the
 * engine computed, because Symfony's property-path syntax cannot round-trip
 * one: a form field may legitimately be named `a.b`, and `a.b` as a path
 * means something else entirely.
 *
 * {@see \App\Http\Problem\ViolationReportFactory} reads the parameter when it
 * is present and falls back to converting the property path when it is not.
 */
final class ViolationPointer
{
    /** Violation parameter carrying the absolute pointer. */
    public const string PARAMETER = '{{ pointer }}';

    /**
     * The pointer prefix matching a Symfony property path — `definition`
     * becomes `/definition`, the root path stays empty.
     */
    public static function prefixOf(string $propertyPath): string
    {
        if ($propertyPath === '') {
            return '';
        }

        return '/' . str_replace(['[', ']', '.'], ['/', '', '/'], $propertyPath);
    }
}
