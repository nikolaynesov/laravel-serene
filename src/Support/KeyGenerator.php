<?php

namespace Nikolaynesov\LaravelSerene\Support;

use Throwable;

class KeyGenerator
{
    public static function fromException(Throwable $e): string
    {
        return strtolower('auto:' . class_basename($e) . ':' . md5($e->getMessage()));
    }
}