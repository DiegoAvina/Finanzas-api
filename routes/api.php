<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CalendarEventController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\IncomeDistributionRuleController;
use App\Http\Controllers\Api\IncomeOccurrenceController;
use App\Http\Controllers\Api\IncomeSourceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
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

    // 👤 Perfil — límite extra sobre las acciones sensibles (cambiar
    // contraseña, subir foto, borrar datos/cuenta) para dificultar
    // fuerza bruta contra la confirmación por contraseña.
    Route::middleware('throttle:10,1')->group(function () {
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::post('/profile/reset-data', [ProfileController::class, 'resetData']);
        Route::delete('/profile', [ProfileController::class, 'destroy']);
    });

    // 📊 Dashboard
    Route::get('/dashboard', [DashboardController::class, 'show']);
    Route::post('/dashboard/weekly-income', [DashboardController::class, 'updateWeeklyIncome']);

    // 📈 Reportes (para el panel web de escritorio)
    Route::get('/reports/monthly-summary', [ReportController::class, 'monthlySummary']);

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

    // 💰 Ingresos (fuentes + ocurrencias + reglas de distribución)
    Route::apiResource('income-sources', IncomeSourceController::class)->except(['create', 'edit']);
    Route::post('/income-sources/{incomeSource}/occurrences', [IncomeOccurrenceController::class, 'store']);
    Route::get('/income-sources/{incomeSource}/distribution-rules', [IncomeDistributionRuleController::class, 'index']);
    Route::post('/income-sources/{incomeSource}/distribution-rules', [IncomeDistributionRuleController::class, 'store']);
    Route::put('/income-distribution-rules/{rule}', [IncomeDistributionRuleController::class, 'update']);
    Route::delete('/income-distribution-rules/{rule}', [IncomeDistributionRuleController::class, 'destroy']);

    Route::get('/income-occurrences', [IncomeOccurrenceController::class, 'index']);
    Route::get('/income-occurrences/{incomeOccurrence}', [IncomeOccurrenceController::class, 'show']);
    Route::post('/income-occurrences/{incomeOccurrence}/receive', [IncomeOccurrenceController::class, 'receive']);
    Route::post('/income-occurrences/{incomeOccurrence}/partial', [IncomeOccurrenceController::class, 'partial']);
    Route::post('/income-occurrences/{incomeOccurrence}/miss', [IncomeOccurrenceController::class, 'miss']);
    Route::post('/income-occurrences/{incomeOccurrence}/cancel', [IncomeOccurrenceController::class, 'cancel']);
    Route::get('/income-occurrences/{incomeOccurrence}/distribution-preview', [IncomeOccurrenceController::class, 'distributionPreview']);
    Route::post('/income-occurrences/{incomeOccurrence}/distribute', [IncomeOccurrenceController::class, 'distribute']);
});
