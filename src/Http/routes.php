<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Padosoft\Routines\Http\Controllers\MetaController;
use Padosoft\Routines\Http\Controllers\RoutinesController;
use Padosoft\Routines\Http\Controllers\RunsController;
use Padosoft\Routines\Http\Controllers\StatsController;

Route::get('capabilities', [MetaController::class, 'capabilities'])->name('routines.capabilities');
Route::get('targets', [MetaController::class, 'targets'])->name('routines.targets');
Route::post('schedule/preview', [MetaController::class, 'preview'])->name('routines.schedule.preview');

Route::get('routines', [RoutinesController::class, 'index'])->name('routines.index');
Route::post('routines', [RoutinesController::class, 'store'])->name('routines.store');
Route::get('routines/{id}', [RoutinesController::class, 'show'])->name('routines.show');
Route::patch('routines/{id}', [RoutinesController::class, 'update'])->name('routines.update');
Route::post('routines/{id}/pause', [RoutinesController::class, 'pause'])->name('routines.pause');
Route::post('routines/{id}/resume', [RoutinesController::class, 'resume'])->name('routines.resume');
Route::post('routines/{id}/end', [RoutinesController::class, 'end'])->name('routines.end');
Route::post('routines/{id}/fire', [RoutinesController::class, 'fire'])->name('routines.fire');
Route::post('routines/{id}/duplicate', [RoutinesController::class, 'duplicate'])->name('routines.duplicate');

Route::get('attention', [RunsController::class, 'attention'])->name('routines.attention');
Route::get('runs', [RunsController::class, 'index'])->name('routines.runs.index');
Route::get('runs/{id}', [RunsController::class, 'show'])->name('routines.runs.show');
Route::post('runs/{id}/approve', [RunsController::class, 'approve'])->name('routines.runs.approve');
Route::post('runs/{id}/reject', [RunsController::class, 'reject'])->name('routines.runs.reject');

Route::get('stats/overview', [StatsController::class, 'overview'])->name('routines.stats.overview');
Route::get('stats/timeline', [StatsController::class, 'timeline'])->name('routines.stats.timeline');
Route::get('health', [StatsController::class, 'health'])->name('routines.health');
