<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\WCController;
use App\Http\Controllers\API\AuthController;

Route::group([
    'middleware' => 'api',
], function ($router) {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::post('/profile', [AuthController::class, 'profile'])->middleware('auth:api');

    Route::get('/getProductsWithQuantities', [WCController::class, 'getProductsWithQuantities']);
    Route::get('/test', [WCController::class, 'getLanguages']);

});
