<?php

use App\Events\PluginWasDisabled;
use App\Events\PluginWasEnabled;
use App\Services\Facades\Option;

return [
    PluginWasEnabled::class => function () {
        $items = [
            'oauth_record_enable_auth_record' => 'true',
            'oauth_record_enable_app_hall' => 'true',
            'oauth_record_auto_cleanup' => 'false',
            'oauth_record_clean_revoked' => 'false',
        ];

        foreach ($items as $key => $value) {
            if (!Option::get($key)) {
                Option::set($key, $value);
            }
        }
    },

    PluginWasDisabled::class => function () {
        // Keep options on disable so settings are preserved if re-enabled
    },
];
