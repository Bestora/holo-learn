<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\LearnController;
use App\Http\Controllers\OccurrenceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('learn', [LearnController::class, 'index'])->name('learn.index');
    Route::post('learn/start', [LearnController::class, 'start'])->name('learn.start');
    Route::post('learn/intro', [LearnController::class, 'intro'])->name('learn.intro');
    Route::post('learn/answer', [LearnController::class, 'answer'])->name('learn.answer');
    Route::post('learn/override', [LearnController::class, 'override'])->name('learn.override');

    Route::post('occurrences/{occurrence}/dead', [OccurrenceController::class, 'dead'])->middleware('throttle:30,1')->name('occurrences.dead');

    Route::get('favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::put('favorites', [FavoriteController::class, 'update'])->name('favorites.update');
});

require __DIR__.'/settings.php';
