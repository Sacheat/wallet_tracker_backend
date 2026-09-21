<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PortfolioEntryController;
use App\Http\Controllers\PriceAlertController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/portfolio', [PortfolioController::class, 'index']);
    Route::get('/portfolio/news', [PortfolioController::class, 'news']);
    // Must come before apiResource: otherwise GET /assets/search is caught
    // by GET /assets/{asset} and Laravel tries to route-model-bind "search".
    Route::get('/assets/search', [AssetController::class, 'search']);
    Route::apiResource('assets', AssetController::class);
    Route::get('/assets/{asset}/details', [AssetController::class, 'details']);
    Route::get('/assets/{asset}/news', [AssetController::class, 'news']);
    Route::apiResource('platforms', PlatformController::class);
    Route::apiResource('portfolio-entries', PortfolioEntryController::class);
    Route::apiResource('price-alerts', PriceAlertController::class);
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/notification-settings', [NotificationSettingsController::class, 'show']);
    Route::patch('/notification-settings', [NotificationSettingsController::class, 'update']);
    Route::get('/market/fear-greed-index', [MarketController::class, 'fearGreedIndex']);
});
