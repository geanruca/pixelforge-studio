<?php

use App\Console\Commands\ReconcileGenerations;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ReconcileGenerations::class)->everyMinute()->withoutOverlapping();
