<?php

namespace JsonApiSdk\Generators\TestGenerators\Traits;

trait MockJsonDataTrait
{
    /**
     * Format an array as PHP code string
     */
    protected function formatArrayAsPhp(array $data): string
    {
        $lines = [];

        foreach ($data as $key => $value) {
            $keyStr = is_string($key) ? "'$key'" : $key;

            if (is_array($value)) {
                $lines[] = "$keyStr => ".$this->formatArrayAsPhp($value).',';
            } elseif (is_object($value)) {
                $lines[] = "$keyStr => (object) [],";
            } elseif (is_string($value)) {
                $escapedValue = addslashes($value);
                $lines[] = "$keyStr => '$escapedValue',";
            } elseif (is_bool($value)) {
                $lines[] = "$keyStr => ".($value ? 'true' : 'false').',';
            } elseif (is_null($value)) {
                $lines[] = "$keyStr => null,";
            } else {
                $lines[] = "$keyStr => $value,";
            }
        }

        if (empty($lines)) {
            return '[]';
        }

        return "[\n".implode("\n", $lines)."\n]";
    }
}
