<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthSsoController;
use App\Http\Controllers\Api\FormationController;
use App\Http\Controllers\Api\LearnerEnrollmentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/sso/login', [AuthSsoController::class, 'login']);
Route::post('/sso/forgot-password', [AuthSsoController::class, 'forgotPassword']);
Route::post('/sso/reset-password', [AuthSsoController::class, 'resetPassword']);
Route::middleware('sso')->group(function () {
    Route::get('/sso/me', [AuthSsoController::class, 'me']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/my-formations', [FormationController::class, 'myFormations']);
    Route::post('/formations', [FormationController::class, 'store']);
    Route::put('/formations/{formation}', [FormationController::class, 'update']);
    Route::patch('/formations/{formation}', [FormationController::class, 'update']);
    Route::delete('/formations/{formation}', [FormationController::class, 'destroy']);
    Route::post('/token', [AuthController::class, 'token']);

    Route::prefix('formateur')->group(function () {
        Route::get('/formations', [FormationController::class, 'index']);
        Route::post('/formations', [FormationController::class, 'store']);
        Route::put('/formations/{formation}', [FormationController::class, 'update']);
        Route::patch('/formations/{formation}', [FormationController::class, 'update']);
        Route::delete('/formations/{formation}', [FormationController::class, 'destroy']);
    });

    Route::prefix('learner')->group(function () {
        Route::get('/formations', [LearnerEnrollmentController::class, 'availableFormations']);
        Route::get('/enrollments', [LearnerEnrollmentController::class, 'index']);
        Route::post('/enrollments', [LearnerEnrollmentController::class, 'store']);
        Route::patch('/enrollments/{enrollment}/complete', [LearnerEnrollmentController::class, 'complete']);
        Route::delete('/enrollments/{enrollment}', [LearnerEnrollmentController::class, 'destroy']);
    });
});
