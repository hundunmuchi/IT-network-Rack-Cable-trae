<?php

namespace App\Translation;

use Illuminate\Support\Arr;
use Illuminate\Translation\Translator as BaseTranslator;

/**
 * Extends Laravel's Translator to work around a Windows-specific bug:
 *
 * On case-insensitive filesystems (Windows / macOS default), a call like
 * __('Plugins') makes Laravel look for lang/en/Plugins.php.  PHP's
 * file_exists() matches case-insensitively, so it loads plugins.php
 * instead and returns the entire file array (because the requested
 * item key is empty).  That array is then handed to htmlspecialchars()
 * which fails with "expects parameter 1 to be string, array given".
 *
 * On Linux the file lookup is case-sensitive so the original code works.
 *
 * This subclass detects that exact case (file resolved to an array while
 * the caller asked for no specific item) and returns null so Laravel
 * falls back to returning the original key string - mirroring the
 * Linux behaviour.
 */
class WindowsSafeTranslator extends BaseTranslator
{
    protected function getLine($namespace, $group, $locale, $item, array $replace)
    {
        $this->load($namespace, $group, $locale);

        $line = Arr::get($this->loaded[$namespace][$group][$locale] ?? [], $item);

        // Windows case-insensitive file lookup returned the whole lang
        // file array even though the caller asked for a single key (item
        // is empty).  Bail out so Laravel returns the original key.
        if (is_array($line) && ($item === '' || $item === null)) {
            return null;
        }

        if (is_string($line)) {
            return $this->makeReplacements($line, $replace);
        } elseif (is_array($line) && count($line) > 0) {
            foreach ($line as $key => $value) {
                $line[$key] = $this->makeReplacements($value, $replace);
            }

            return $line;
        }
    }
}
