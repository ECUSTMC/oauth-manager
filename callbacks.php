<?php

use App\Events\PluginWasDisabled;
use App\Events\PluginWasEnabled;

return [
    PluginWasEnabled::class => function () {
        // Nothing special to do on enable
    },

    PluginWasDisabled::class => function () {
        // Nothing special to do on disable
    },
];
