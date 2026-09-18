<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CalendarEventController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\SavingGoalController;
use App\Http\Controllers\Api\TandaController;
use Illuminate\Support\Facades\Route;

// 🔐 AUTH (las rutas que tu app está usando: /api/auth/register, /api/auth/login)
Route::prefix('auth')->middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Todo lo de abajo requiere estar logueado con Sanctum
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // 📊 Dashboard
    Route::get('/dashboard', [DashboardController::class, 'show']);
    Route::post('/dashboard/weekly-income', [DashboardController::class, 'updateWeeklyIncome']);

    // 🧾 Recibos
    Route::apiResource('bills', BillController::class)->except(['create', 'edit']);

    // 📅 Calendario financiero (eventos combinados)
    Route::get('/calendar/events', [CalendarEventController::class, 'index']);
    Route::get('/calendar', [CalendarController::class, 'index']);

    // 🎯 Metas de ahorro
    Route::get('/saving-goals', [SavingGoalController::class, 'index']);
    Route::post('/saving-goals', [SavingGoalController::class, 'store']);
    Route::post('/saving-goals/{savingGoal}/contribute', [SavingGoalController::class, 'contribute']);
    Route::post('/saving-goals/{savingGoal}/members', [SavingGoalController::class, 'addMember']);

    // TANDAS
    Route::get('/tandas', [TandaController::class, 'index']);
    Route::post('/tandas', [TandaController::class, 'store']);
    Route::post('/tandas/{tanda}/members', [TandaController::class, 'addMember']);
    Route::post('/tandas/{tanda}/payments', [TandaController::class, 'registerPayment']);

    // 💸 Gastos
    Route::apiResource('expenses', ExpenseController::class)
        ->only(['index', 'store', 'destroy']);
});
