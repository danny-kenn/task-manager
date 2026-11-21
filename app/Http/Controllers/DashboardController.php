<?php

namespace App\Http\Controllers;

use App\Services\StatsService;

class DashboardController extends Controller
{
    public function index(StatsService $stats)
    {
        return view('dashboard', ['s' => $stats->for(auth()->user())]);
    }
}
