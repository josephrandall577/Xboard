<?php

use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | 赠送管理页面
 |--------------------------------------------------------------------------
 | 页面本身不含敏感数据（仅 HTML/JS 壳），所有数据通过
 | /api/plugin/plan-bonus/* 接口（admin 中间件保护）获取。
 */
Route::get('/plugins/plan-bonus', 'PlanBonusController@page');
