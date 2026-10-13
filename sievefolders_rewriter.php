<?php

/**
 * sievefolders_rewriter
 *
 * Text-level rewriting of the folder names in a Sieve script's `fileinto`
 * actions. Deliberately works on the raw script text rather than parsing
 * and regenerating it, so comments, formatting and anything the
 * managesieve UI doesn't model survive untouched -- only the quoted folder
 * name inside each matching fileinto changes.
 *
 * No Roundcube dependencies, so it can be tested on its own
 * (tests/rewriter_test.php).
 *
 * @author Gavin Taylor
 *
 * Copyright (C) Gavin Taylor
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://www.gnu.org/licenses/.
 */
class sievefolders_rewriter
{
    /**
     * `fileinto`, any tagged arguments (:copy, :create, :flags "..."), then
     * the quoted folder name. Sieve quoted strings escape only \" and \\.
     * Commented-out lines are matched too -- harmless, and it keeps a
     * disabled rule pointing at the right folder if it's re-enabled.
     */
    private const PATTERN = '/(\bfileinto\b(?:\s+:[a-z]+(?:\s+"(?:[^"\\\\]|\\\\.)*")?)*\s+)"((?:[^"\\\\]|\\\\.)*)"/i';

    /**
     * Folder names every `fileinto` in $script files into, unescaped.
     */
    public static function targets(string $script): array
    {
        preg_match_all(self::PATTERN, $script, $matches);

        return array_values(array_unique(array_map([self::class, 'unescape'], $matches[2])));
    }

    /**
     * Rewrites every fileinto target that is $old, or inside $old, to the
     * same place under $new. Returns [new script, number of targets changed].
     */
    public static function rename(string $script, string $old, string $new, string $delimiter): array
    {
        $count = 0;

        $result = preg_replace_callback(self::PATTERN, function ($m) use ($old, $new, $delimiter, &$count) {
            $target = self::unescape($m[2]);

            if ($target === $old) {
                $target = $new;
            } elseif ($delimiter !== '' && str_starts_with($target, $old . $delimiter)) {
                $target = $new . substr($target, strlen($old));
            } else {
                return $m[0];
            }

            $count++;

            return $m[1] . '"' . self::escape($target) . '"';
        }, $script);

        return [$result, $count];
    }

    /**
     * How many fileinto targets point at $folder or inside it.
     */
    public static function references(string $script, string $folder, string $delimiter): int
    {
        return count(array_filter(self::targets($script), fn ($t) => $t === $folder
            || ($delimiter !== '' && str_starts_with($t, $folder . $delimiter))));
    }

    private static function unescape(string $value): string
    {
        return preg_replace('/\\\\(.)/s', '$1', $value);
    }

    private static function escape(string $value): string
    {
        return addcslashes($value, '"\\');
    }
}
