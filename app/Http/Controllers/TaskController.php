<?php

namespace App\Http\Controllers;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Requests\TaskRequest;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private TaskService $tasks) {}

    public function index(Request $request)
    {
        $filters = $request->only(['q', 'status', 'priority', 'category_id', 'assignee', 'due', 'tag', 'sort']);
        $perPage = in_array((int) $request->input('per_page'), [10, 20, 50], true) ? (int) $request->input('per_page') : 10;

        $list = $this->tasks->query($request->user(), $filters)->paginate($perPage)->withQueryString();

        return view('tasks.index', [
            'tasks' => $list,
            'filters' => $filters,
            'categories' => Category::orderBy('name')->get(),
            'people' => $request->user()->isStaff() ? User::where('is_active', true)->orderBy('name')->get() : collect(),
            'tagList' => Tag::orderBy('name')->get(),
            'canDrag' => ($filters['sort'] ?? 'manual') === 'manual',
            'hasFilters' => collect($filters)->except('sort')->filter()->isNotEmpty(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', Task::class);

        return view('tasks.create', $this->formData() + ['task' => new Task(['status' => TaskStatus::Pending, 'priority' => TaskPriority::Medium])]);
    }

    public function store(TaskRequest $request): RedirectResponse|JsonResponse
    {
        $task = $this->tasks->create($request->user(), $request->validated());

        if ($request->expectsJson()) {
            $task->loadCount(['subtasks', 'subtasks as subtasks_done_count' => fn ($s) => $s->where('is_done', true), 'comments']);

            return response()->json(['ok' => true, 'message' => "Task “{$task->title}” added.", 'id' => $task->id,
                'html' => view('tasks._row', ['task' => $task, 'canDrag' => false, 'statusFilter' => null])->render()], 201);
        }

        return redirect()->route('tasks.show', $task)->with('success', "Task “{$task->title}” created.");
    }

    public function show(Task $task)
    {
        $this->authorize('view', $task);
        $task->load(['category', 'tags', 'assignee', 'owner', 'subtasks', 'comments.author']);
        $history = ActivityLog::with('user')->where('task_id', $task->id)->latest('id')->limit(15)->get();

        return view('tasks.show', compact('task', 'history'));
    }

    public function edit(Task $task)
    {
        $this->authorize('update', $task);
        $task->load('tags');

        return view('tasks.edit', $this->formData() + ['task' => $task]);
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        $this->tasks->update($request->user(), $task, $request->validated());

        return redirect()->route('tasks.show', $task)->with('success', 'Task updated.');
    }

    public function toggle(Request $request, Task $task): JsonResponse|RedirectResponse
    {
        $this->authorize('toggle', $task);
        $task = $this->tasks->setStatus($request->user(), $task);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'status' => $task->status->value,
                'label' => $task->status->label(),
                'badge' => $task->status->badge(),
                'icon' => $task->status->icon(),
                'message' => $task->is_completed ? "Nice! “{$task->title}” is completed." : "“{$task->title}” is pending again.",
            ]);
        }

        return back()->with('success', $task->is_completed ? 'Task marked as completed.' : 'Task marked as pending.');
    }

    public function destroy(Request $request, Task $task): JsonResponse|RedirectResponse
    {
        $this->authorize('delete', $task);
        $title = $task->title;
        $this->tasks->delete($request->user(), $task);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => "“{$title}” moved to the trash."]);
        }

        return redirect()->route('tasks.index')->with('success', "Task “{$title}” moved to the trash.");
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:2', 'max:100'], 'ids.*' => ['integer']]);
        $n = $this->tasks->reorder($request->user(), $data['ids']);

        return response()->json(['ok' => true, 'moved' => $n, 'message' => 'Order saved.']);
    }

    /* ----------------------------------------------------------------- trash (staff) */

    public function trash(Request $request)
    {
        abort_unless($request->user()->can('view-trash'), 403);
        $me = $request->user();
        $tasks = Task::onlyTrashed()->with(['owner', 'category'])
            ->where(fn ($q) => $me->isStaff() ? $q->notPersonal()->orWhere('tasks.user_id', $me->id) : $q->where('tasks.user_id', $me->id))
            ->latest('deleted_at')->paginate(10);

        return view('tasks.trash', compact('tasks'));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $task = Task::onlyTrashed()->findOrFail($id);
        $this->authorize('restore', $task);
        $this->tasks->restore($request->user(), $task);

        return back()->with('success', "Task “{$task->title}” restored.");
    }

    public function forceDestroy(Request $request, int $id): RedirectResponse
    {
        $task = Task::onlyTrashed()->findOrFail($id);
        $this->authorize('forceDelete', $task);
        $this->tasks->forceDelete($request->user(), $task);

        return back()->with('success', 'Task permanently deleted.');
    }

    private function formData(): array
    {
        return [
            'categories' => Category::orderBy('name')->get(),
            'people' => auth()->user()->isStaff() ? User::where('is_active', true)->orderBy('name')->get() : collect(),
        ];
    }
}
