<?php

use App\Http\Controllers\CryptoController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PortfolioEntryController;
use Illuminate\Support\Facades\Route;

Route::get('/portfolio', [PortfolioController::class, 'index']);
Route::apiResource('cryptos', CryptoController::class);
Route::apiResource('platforms', PlatformController::class);
Route::apiResource('portfolio-entries', PortfolioEntryController::class);
