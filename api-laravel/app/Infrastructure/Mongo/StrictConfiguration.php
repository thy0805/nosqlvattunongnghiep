<?php

namespace App\Infrastructure\Mongo;

use Illuminate\Foundation\Bootstrap\LoadConfiguration;

final class StrictConfiguration extends LoadConfiguration
{
    protected function mergeableOptions($name)
    {
        return $name === 'database' ? [] : parent::mergeableOptions($name);
    }
}
