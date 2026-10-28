<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('privacy:purge-transcripts')->daily();
Schedule::command('privacy:purge-orphan-audio')->daily();
