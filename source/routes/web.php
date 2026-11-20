<?php

use App\Http\Controllers\LmsController;
use App\Http\Controllers\LtiController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LmsController::class, 'dashboard'])->middleware('auth.session')->name('login');
Route::post('/login', [LmsController::class, 'login'])->middleware(['guest', 'throttle:20,1']);
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::view('/account/password', 'password');
    Route::post('/logout', [LmsController::class, 'logout']);
    Route::post('/account/password', [LmsController::class, 'changePassword'])->middleware('throttle:5,1');
    Route::post('/admin/users/{id}/role', [LmsController::class, 'changeRole'])->whereNumber('id');
    Route::get('/courses/{id}/{screen?}', [LmsController::class, 'course'])->whereNumber('id')->whereIn('screen', ['activities', 'grades', 'enrollment', 'settings']);
    Route::post('/courses/{id?}', [LmsController::class, 'saveCourse'])->whereNumber('id');
    Route::delete('/courses/{id}', [LmsController::class, 'deleteCourse'])->whereNumber('id');
    Route::post('/courses/{id}/enroll', [LmsController::class, 'enroll'])->whereNumber('id');
    Route::delete('/courses/{id}/enroll/{user}', [LmsController::class, 'unenroll'])->whereNumber(['id', 'user']);
    Route::post('/courses/{id}/assign', [LmsController::class, 'assign'])->whereNumber('id');
    Route::post('/courses/{id}/activities/{activity?}', [LmsController::class, 'activity'])->whereNumber(['id', 'activity']);
    Route::put('/courses/{id}/activities/{activity}', [LmsController::class, 'activity'])->whereNumber(['id', 'activity']);
    Route::get('/admin/{screen?}', [LmsController::class, 'admin'])->whereIn('screen', ['users', 'tools', 'logs', 'tenants']);
    Route::post('/admin/tenants', [LmsController::class, 'createTenant']);
    Route::put('/admin/tenants/welcome', [LmsController::class, 'updateTenantWelcome']);
    Route::put('/admin/tenants/{tenant}/welcome', [LmsController::class, 'updateTenantWelcome'])->whereNumber('tenant');
    Route::post('/admin/tenants/{tenant}/managers/{user}', [LmsController::class, 'grantTenantManager'])->whereNumber(['tenant', 'user']);
    Route::post('/admin/users', [LmsController::class, 'createUser']);
    Route::post('/admin/users/{id}/lock', [LmsController::class, 'lockUser'])->whereNumber('id');
    Route::post('/admin/tools', [LmsController::class, 'tool']);
    Route::put('/admin/tools/{id}', [LmsController::class, 'tool'])->whereNumber('id');
    Route::post('/courses/{course}/activities/{activity}/launch', [LtiController::class, 'start'])->whereNumber(['course', 'activity']);
    Route::get('/courses/{course}/activities/{activity}/learn', [LtiController::class, 'learn'])->whereNumber(['course', 'activity']);
    Route::get('/courses/{course}/activities/{activity}/status', [LtiController::class, 'learningStatus'])->whereNumber(['course', 'activity']);
});
Route::match(['get', 'post'], '/lti/authorize', [LtiController::class, 'authorize'])->middleware(['auth.session', 'throttle:60,1'])->name('lti.authorize');
Route::get('/lti/jwks', [LtiController::class, 'jwks'])->name('lti.jwks');
Route::post('/lti/token', [LtiController::class, 'token'])->middleware('throttle:60,1')->name('lti.token');
Route::prefix('/lti/courses/{course}/lineitems')->where(['course' => '[0-9]+'])->group(function () {
    Route::get('/', [LtiController::class, 'lines'])->name('lti.lines');
    Route::get('/{activity}', [LtiController::class, 'line'])->whereNumber('activity')->name('lti.line');
    Route::get('/{activity}/results', [LtiController::class, 'results'])->whereNumber('activity');
    Route::post('/{activity}/scores', [LtiController::class, 'score'])->whereNumber('activity');
});
