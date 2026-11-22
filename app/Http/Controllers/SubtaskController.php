<?php

namespace App\Http\Controllers;

use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SubtaskController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('work', $task);
        $data = $request->validate(['title' => ['required', 'string', 'min:2', 'max:150']], [
            'title.required' => 'Write a checklist item first.',
        ]);
        $task->subtasks()->create($data + ['position' => (int) $task->subtasks()->max('position') + 1]);

        return redirect()->to(route('tasks.show', $task).'#checklist');
    }

    public function toggle(Task $task, Subtask $subtask): RedirectResponse
    {
        $this->authorize('work', $task);
        abort_unless($subtask->task_id === $task->id, 404);
        $subtask->update(['is_done' => ! $subtask->is_done]);

        return redirect()->to(route('tasks.show', $task).'#checklist');
    }

    public function destroy(Task $task, Subtask $subtask): RedirectResponse
    {
        $this->authorize('work', $task);
        abort_unless($subtask->task_id === $task->id, 404);
        $subtask->delete();

        return redirect()->to(route('tasks.show', $task).'#checklist');
    }
}
