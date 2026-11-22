<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\ReportController;
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

/* ---------------------------------------------------------------- PWA (public) */
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/offline', [PwaController::class, 'offline'])->name('pwa.offline');

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
    Route::post('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar'])->name('profile.avatar.remove');
    Route::put('/profile/notifications', [ProfileController::class, 'notifications'])->name('profile.notifications');

    // Notification centre (bell), polled cheaply from the browser + Web Push registration
    Route::post('/onboarding/done', [ProfileController::class, 'tourDone'])->name('tour.done');
    Route::post('/onboarding/reset', [ProfileController::class, 'tourReset'])->name('tour.reset');
    Route::get('/notifications/poll', [NotificationController::class, 'poll'])->name('notifications.poll');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read_all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read')->whereNumber('id');
    Route::post('/push/subscribe', [NotificationController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [NotificationController::class, 'unsubscribe'])->name('push.unsubscribe');

    // Reports in any format (csv, xlsx, docx, pdf, html, md, txt, json); role is re-checked on every download
    Route::get('/reports/download', [ReportController::class, 'download'])->name('reports.download');

    // AI assistant (every role; the AI can only do what the signed-in user may do)
    Route::prefix('ai')->name('ai.')->group(function () {
        Route::get('/history', [AiController::class, 'history'])->name('history');           // bootstrap: mode, name, starters, chat list, latest chat
        Route::get('/conversations', [AiController::class, 'conversations'])->name('conversations');
        Route::get('/conversations/{conversation}', [AiController::class, 'conversation'])->name('conversation');
        Route::delete('/conversations/{conversation}', [AiController::class, 'destroyConversation'])->name('conversation.destroy');
        Route::post('/chat', [AiController::class, 'chat'])->name('chat');                    // plain JSON (used by tests and as fallback)
        Route::post('/stream', [AiController::class, 'stream'])->name('stream');              // Server-Sent Events: steps, thinking, text, blocks
        Route::post('/execute', [AiController::class, 'execute'])->name('execute');
        Route::get('/attachments/{message}/{index}', [AiController::class, 'attachment'])->name('attachment')->whereNumber(['message', 'index']);
        Route::get('/memories', [AiController::class, 'memories'])->name('memories');
        Route::delete('/memories/{memory}', [AiController::class, 'forget'])->name('memories.forget');
        Route::delete('/memories', [AiController::class, 'forgetAll'])->name('memories.clear');
        Route::delete('/history', [AiController::class, 'clear'])->name('clear');             // delete ALL of the user's chats
    });

    /* ------------------------------------------------------------ manager + admin */
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');

        // People: the controller enforces who may touch whom (admin: managers+members, manager: members only)
        Route::post('/users/{user}/approve', [UserController::class, 'approve'])->name('users.approve');
        Route::resource('users', UserController::class)->except('show');
    });
});
