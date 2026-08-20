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
