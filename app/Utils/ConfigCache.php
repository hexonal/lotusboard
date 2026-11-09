<?php

namespace App\Utils;

use Illuminate\Support\Facades\Artisan;

class ConfigCache
{
    public static function refresh()
    {
        // Blue/green pools read the same mutable admin settings. Keep each
        // release's full config cache empty so a rollback reads current settings.
        return Artisan::call(filter_var(getenv('APP_DISABLE_CONFIG_CACHE'), FILTER_VALIDATE_BOOLEAN)
            ? 'config:clear'
            : 'config:cache');
    }
}
