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
    // Multipart upload (field 'logo'), see LogoStorage. Own throttle: the
    // catalog is shared, so these write files every user will download.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/assets/{asset}/logo', [AssetController::class, 'storeLogo']);
        Route::delete('/assets/{asset}/logo', [AssetController::class, 'destroyLogo']);
        Route::post('/platforms/{platform}/logo', [PlatformController::class, 'storeLogo']);
        Route::delete('/platforms/{platform}/logo', [PlatformController::class, 'destroyLogo']);
    });
    Route::apiResource('portfolio-entries', PortfolioEntryController::class);
    // Own throttle on top of the global 60/min: this one calls Gemini
    // (shared 500/day free-tier quota across every user), so a single
    // account looping the form shouldn't be able to eat a disproportionate
    // share of it.
    Route::post('/price-alerts/parse', [PriceAlertController::class, 'parse'])->middleware('throttle:10,1');
    Route::apiResource('price-alerts', PriceAlertController::class);
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/notification-settings', [NotificationSettingsController::class, 'show']);
    Route::patch('/notification-settings', [NotificationSettingsController::class, 'update']);
    Route::get('/market/fear-greed-index', [MarketController::class, 'fearGreedIndex']);
    Route::get('/exchange-rates', [MarketController::class, 'exchangeRates']);
});
