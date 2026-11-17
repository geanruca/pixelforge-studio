<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [GenerationController::class, 'create'])->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])->middleware('throttle:30,1')->name('generations.store');
    Route::get('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::get('generations/{generation}/image', [GenerationController::class, 'image'])
        ->can('view', 'generation')
        ->name('generations.image');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditController::class, 'store'])->middleware('throttle:30,1')->name('credits.store');

    Route::get('admin/report', ReportController::class)->can('view-admin-report')->name('admin.report');
});

Route::get('generations/{generation}/image/signed', [GenerationController::class, 'signedImage'])
    ->middleware('signed')
    ->name('generations.image.signed');

require __DIR__.'/settings.php';
