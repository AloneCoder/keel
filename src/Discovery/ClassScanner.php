<?php

declare(strict_types=1);

namespace Keel\Discovery;

/**
 * Finds class-like declarations in PHP files without loading them: tokens only.
 * Files without "#[" are skipped early, since discovery is attribute-driven.
 */
final class ClassScanner
{
    /**
     * @param list<string> $directories
     *
     * @return array{classes: list<class-string>, files: int, mtime: int}
     */
    public function scan(array $directories): array
    {
        $classes = [];
        $files = 0;
        $mtime = 0;

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php' || !$file->isFile()) {
                    continue;
                }

                $files++;
                $mtime = max($mtime, (int) $file->getMTime());
                $content = @file_get_contents($file->getPathname());
                if ($content === false || !str_contains($content, '#[')) {
                    continue;
                }

                foreach (self::declaredIn($content) as $class) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return ['classes' => array_values(array_unique($classes)), 'files' => $files, 'mtime' => $mtime];
    }

    /**
     * @return list<class-string>
     */
    public static function declaredIn(string $source): array
    {
        $tokens = array_values(\PhpToken::tokenize($source));
        $namespace = '';
        $classes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(\T_NAMESPACE)) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->is([\T_NAME_QUALIFIED, \T_STRING])) {
                        $namespace = $tokens[$j]->text;
                        break;
                    }
                    if ($tokens[$j]->is(['{', ';'])) {
                        break;
                    }
                }
                continue;
            }

            if (!$token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM])) {
                continue;
            }

            // "::class" and "new class" are not declarations.
            $previous = self::previousSignificant($tokens, $i);
            if ($previous !== null && $previous->is([\T_DOUBLE_COLON, \T_NEW])) {
                continue;
            }

            $next = self::nextSignificant($tokens, $i);
            if ($next !== null && $next->is(\T_STRING)) {
                /** @var class-string $fqcn */
                $fqcn = ($namespace === '' ? '' : $namespace . '\\') . $next->text;
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }

    /**
     * Keeps the values that name an existing class, interface or enum.
     *
     * @param array<mixed> $values
     *
     * @return list<class-string>
     */
    public static function classStrings(array $values): array
    {
        $classes = [];
        foreach ($values as $value) {
            if (is_string($value) && (class_exists($value) || interface_exists($value) || enum_exists($value))) {
                $classes[] = $value;
            }
        }

        return $classes;
    }

    /**
     * @param array<int, \PhpToken> $tokens
     */
    private static function previousSignificant(array $tokens, int $index): ?\PhpToken
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (!$tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @param array<int, \PhpToken> $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?\PhpToken
    {
        $count = count($tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
