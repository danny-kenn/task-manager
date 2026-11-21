<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubtaskController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/* ---------------------------------------------------------------- guests */
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');
});

/* ---------------------------------------------------------------- authenticated */
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/', fn () => redirect()->route('tasks.index'));
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Tasks (static routes MUST come before the {task} resource routes)
    Route::post('tasks/reorder', [TaskController::class, 'reorder'])->name('tasks.reorder');
    Route::get('tasks/trash', [TaskController::class, 'trash'])->name('tasks.trash');
    Route::post('tasks/{id}/restore', [TaskController::class, 'restore'])->name('tasks.restore')->whereNumber('id');
    Route::delete('tasks/{id}/force', [TaskController::class, 'forceDestroy'])->name('tasks.force')->whereNumber('id');
    Route::patch('tasks/{task}/toggle', [TaskController::class, 'toggle'])->name('tasks.toggle');
    Route::resource('tasks', TaskController::class);

    // Checklist + comments
    Route::post('tasks/{task}/subtasks', [SubtaskController::class, 'store'])->name('subtasks.store');
    Route::patch('tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'toggle'])->name('subtasks.toggle');
    Route::delete('tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'destroy'])->name('subtasks.destroy');
    Route::post('tasks/{task}/comments', [TaskCommentController::class, 'store'])->name('comments.store');
    Route::delete('tasks/{task}/comments/{comment}', [TaskCommentController::class, 'destroy'])->name('comments.destroy');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');

    // AI assistant (every role; the AI can only do what the signed-in user may do)
    Route::prefix('ai')->name('ai.')->group(function () {
        Route::get('/history', [AiController::class, 'history'])->name('history');
        Route::post('/chat', [AiController::class, 'chat'])->name('chat');
        Route::post('/execute', [AiController::class, 'execute'])->name('execute');
        Route::delete('/history', [AiController::class, 'clear'])->name('clear');
    });

    /* ------------------------------------------------------------ manager + admin */
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');
    });

    /* ------------------------------------------------------------ admin only */
    Route::middleware('role:admin')->group(function () {
        Route::resource('users', UserController::class)->except('show');
    });
});
