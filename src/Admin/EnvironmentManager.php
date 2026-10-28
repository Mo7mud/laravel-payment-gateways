<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Admin;

use InvalidArgumentException;
use RuntimeException;

/**
 * Reads and updates individual keys of the application's .env file.
 *
 * Values are written single-quoted, so phpdotenv applies no variable
 * interpolation or escape processing ("$1", "${APP_KEY}" and "\" stay
 * literal). Values that cannot be represented that way are rejected.
 */
final class EnvironmentManager
{
    private const KEY_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    public function __construct(private readonly ?string $path = null) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path();
        if (! is_file($path) || ! is_readable($path)) {
            return $default;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return $default;
        }

        foreach ($lines as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $position = strpos($line, '=');
            if ($position === false || trim(substr($line, 0, $position)) !== $key) {
                continue;
            }

            return $this->decode(substr($line, $position + 1));
        }

        return $default;
    }

    /**
     * @param  array<string, string|int|bool|null>  $values  null values are skipped
     */
    public function set(array $values): void
    {
        $lines = [];
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException('Invalid environment variable name.');
            }

            $string = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $lines[$key] = $key.'='.$this->encode($key, $string);
        }

        if ($lines === []) {
            return;
        }

        $path = $this->path();
        if (! is_file($path) || ! is_writable($path) || ! is_writable(dirname($path))) {
            throw new RuntimeException('The application environment file is not writable.');
        }

        $lock = fopen(sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-gateway-env-'.md5($path).'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the application environment file.');
        }

        try {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Unable to read the application environment file.');
            }

            foreach ($lines as $key => $line) {
                $count = 0;
                // A callback, not a replacement string: "$1" or "\0" in a value must stay literal.
                $contents = preg_replace_callback(
                    '/^(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=.*$/m',
                    static fn (): string => $line,
                    $contents,
                    1,
                    $count,
                );

                if ($contents === null) {
                    throw new RuntimeException('Unable to update the application environment file.');
                }

                if ($count === 0) {
                    $contents = rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
                }
            }

            $this->atomicWrite($path, $contents);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.env.');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary environment file.');
        }

        if (file_put_contents($temporary, $contents) === false) {
            @unlink($temporary);
            throw new RuntimeException('Unable to write the temporary environment file.');
        }

        // tempnam() creates 0600; keep the original mode and group so php-fpm
        // and queue workers running as other users can still read the file.
        $permissions = @fileperms($path);
        if ($permissions !== false) {
            @chmod($temporary, $permissions & 0777);
        }
        $group = @filegroup($path);
        if ($group !== false) {
            @chgrp($temporary, $group);
        }

        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to replace the application environment file.');
        }
    }

    private function encode(string $key, string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("The value for {$key} cannot contain line breaks or control characters.");
        }

        if (str_contains($value, "'")) {
            throw new InvalidArgumentException("The value for {$key} cannot contain a single quote.");
        }

        return $value === '' ? '' : "'".$value."'";
    }

    private function decode(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if ($raw[0] === "'") {
            $end = strpos($raw, "'", 1);

            return $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
        }

        if ($raw[0] === '"') {
            $value = '';
            $length = strlen($raw);
            for ($i = 1; $i < $length; $i++) {
                $char = $raw[$i];
                if ($char === '\\' && $i + 1 < $length) {
                    $next = $raw[++$i];
                    $value .= match ($next) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        '"', '\\', '$' => $next,
                        default => '\\'.$next,
                    };

                    continue;
                }
                if ($char === '"') {
                    break;
                }
                $value .= $char;
            }

            return $value;
        }

        $comment = strpos($raw, ' #');
        if ($comment !== false) {
            $raw = substr($raw, 0, $comment);
        }

        return trim($raw);
    }

    private function path(): string
    {
        return $this->path ?? base_path('.env');
    }
}
