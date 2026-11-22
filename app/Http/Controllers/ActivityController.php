<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('view-activity');

        $logs = ActivityLog::with('user')->withoutPersonalTasks()
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->source))
            ->when($request->filled('q'), fn ($q) => $q->where('description', 'like', '%'.$request->q.'%'))
            ->latest('id')->paginate(20)->withQueryString();

        return view('activity.index', [
            'logs' => $logs,
            'actions' => ActivityLog::select('action')->distinct()->orderBy('action')->pluck('action'),
            'people' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
