<?php

use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | 赠送管理接口（管理员鉴权）
 |--------------------------------------------------------------------------
 | 外层组已带 api 中间件（PluginManager::loadRoutes），此处追加 admin。
 */
Route::middleware(['admin'])
    ->prefix('/api/plugin/plan-bonus')
    ->group(function () {
        Route::get('/plans', 'PlanBonusController@plans');
        Route::post('/save', 'PlanBonusController@save');
    });
