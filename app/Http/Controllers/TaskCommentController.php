<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskComment;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskCommentController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $task);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']], [
            'body.required' => 'Write something before posting a comment.',
        ]);
        $task->comments()->create($data + ['user_id' => $request->user()->id]);
        ActivityLogger::record($request->user(), $task->id, 'commented', "Commented on “{$task->title}”");
        app(\App\Services\Notifier::class)->send(
            \App\Models\User::whereIn('id', array_filter([$task->user_id, $task->assignee_id]))->get(), 'task_commented',
            $request->user()->name." commented on “{$task->title}”", mb_substr($data['body'], 0, 140), route('tasks.show', $task).'#comments', $request->user()
        );

        app(\App\Services\Notifier::class)->team('team_commented', $task, $request->user()->name.' commented on “'.$task->title.'”', mb_substr($data['body'], 0, 140), $request->user(), array_filter([$task->user_id, $task->assignee_id]));

        return redirect()->to(route('tasks.show', $task).'#comments')->with('success', 'Comment added.');
    }

    public function destroy(Request $request, Task $task, TaskComment $comment): RedirectResponse
    {
        abort_unless($comment->task_id === $task->id, 404);
        abort_unless($request->user()->isStaff() || $comment->user_id === $request->user()->id, 403);
        $comment->delete();

        return redirect()->to(route('tasks.show', $task).'#comments')->with('success', 'Comment removed.');
    }
}
