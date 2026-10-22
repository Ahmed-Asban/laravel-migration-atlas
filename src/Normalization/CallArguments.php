<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Parsing\RawArgument;

final class CallArguments
{
    /**
     * Bind PHP positional/named arguments without evaluating or unpacking them.
     * Unknown names, duplicates and missing/extra arguments remain unresolved.
     *
     * @param  list<RawArgument>  $arguments
     * @param  list<string>  $parameters
     * @return array<string, mixed>|null
     */
    public static function bind(array $arguments, array $parameters, int $required): ?array
    {
        $values = [];
        $named = false;

        foreach ($arguments as $index => $argument) {
            if ($argument->unpack) {
                return null;
            }

            if ($argument->name !== null) {
                $named = true;
                $name = $argument->name;
            } else {
                if ($named || ! isset($parameters[$index])) {
                    return null;
                }

                $name = $parameters[$index];
            }

            if (! in_array($name, $parameters, true) || array_key_exists($name, $values)) {
                return null;
            }

            $values[$name] = $argument->value;
        }

        foreach (array_slice($parameters, 0, $required) as $parameter) {
            if (! array_key_exists($parameter, $values)) {
                return null;
            }
        }

        return $values;
    }
}
