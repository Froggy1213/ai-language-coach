<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Target Languages
    |--------------------------------------------------------------------------
    |
    | The languages a user can currently learn. Each one gets a sentinel
    | `grammar_points` row so that an unmatched mistake always has a bucket
    | and `mistakes.grammar_point_id` can stay NOT NULL (plan §5).
    |
    | Add a language here, then run `php artisan db:seed --class=GrammarPointSeeder`.
    |
    */

    'supported' => ['en'],

    /*
    |--------------------------------------------------------------------------
    | Display Names
    |--------------------------------------------------------------------------
    |
    | Used in human-readable titles such as a roadmap name. A language without
    | an entry here falls back to its upper-cased code in RoadmapGenerator, so
    | adding one to `supported` never breaks generation — only the label.
    |
    */

    'names' => [
        'en' => 'English',
    ],

];
