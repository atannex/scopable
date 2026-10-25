<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Calendar timezone
    |--------------------------------------------------------------------------
    |
    | Calendar periods ("today", "this week", "on 2026-05-03") are cut in this
    | timezone, then converted to the storage timezone (app.timezone) before
    | they reach the database. Null uses app.timezone.
    |
    */

    'timezone' => env('SCOPABLE_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | First day of the week
    |--------------------------------------------------------------------------
    |
    | 0 = Sunday, 1 = Monday … 6 = Saturday. Used by thisWeek / lastWeek / nextWeek.
    |
    */

    'week_starts_on' => 1,

    /*
    |--------------------------------------------------------------------------
    | Automatic date aliases
    |--------------------------------------------------------------------------
    |
    | Models using HasScopes get period scopes for these columns when they have
    | them: createdToday(), updatedWithin('7 days'), deletedLastMonth()…
    |
    */

    'dates' => [
        'created' => true,
        'updated' => true,
        'deleted' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Models swept by scopable:sweep (time-based StateEntered / StateLeft
    | events for tracked states) and listed by scopable:show.
    |
    */

    'models' => [],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Fire StateEntered / StateLeft when a save moves a tracked state.
    |
    */

    'events' => true,

    'sweep' => [
        // Cache store remembering the last sweep (null = default store).
        'store' => null,

        // How far back the first sweep (or one after a cache flush) looks.
        'lookback' => '10 minutes',

        'chunk' => 500,
    ],

];
