<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Story 1.5: approved cross-branch movements take effect ON their effective
// date — apply them hourly so the association changes even if nobody is
// logged in at that moment (immediate application on approval also happens
// when the effective date has already arrived).
Schedule::command('movements:apply-due')->hourly();

// Story 13.5: scheduled report generation and distribution
Schedule::command('reports:process-due')->everyFiveMinutes();

// Story 14.2: archive expired church documents
Schedule::command('documents:process-lifecycle')->hourly();

// Story 14.3: retry failed global search index synchronizations
Schedule::command('search:process-retries')->hourly();

// Story 15.4: deliver pending outbound webhooks
Schedule::command('webhooks:process-due')->everyFiveMinutes();

// Story 15.5: process external adapter operations
Schedule::command('adapters:process-due')->everyFiveMinutes();

// Story 15.6: collect operations telemetry
Schedule::command('operations:collect-telemetry')->everyFiveMinutes();
