<?php

use App\Jobs\ClassifyItemRoles;
use App\Models\AiRunEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ingest:recover')->everyMinute()->withoutOverlapping();

Schedule::command('model:prune', ['--model' => [AiRunEvent::class]])->daily();

Schedule::call(fn () => ClassifyItemRoles::dispatchForEligibleOrganizations())
    ->name('classify-item-roles')
    ->dailyAt('04:00')
    ->withoutOverlapping();
