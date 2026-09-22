<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Named Laravel queues
    |--------------------------------------------------------------------------
    |
    | Dispatch with ->onQueue(config('zak.queues.ai')).
    |
    */

    'queues' => [
        'high' => 'high',
        'channels' => 'channels',
        'ai' => 'ai',
        'ingestion' => 'ingestion',
        'meetings' => 'meetings',
        'notifications' => 'notifications',
        'default' => 'default',
    ],

];
