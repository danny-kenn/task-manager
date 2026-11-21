<?php

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** One request class for create AND edit, so both always validate identically. */
class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task
            ? $this->user()->can('update', $task)
            : $this->user()->can('create', Task::class);
    }

    public function rules(): array
    {
        return static::baseRules();
    }

    /** Shared with the AI assistant so a task created by chat obeys exactly the same rules as the form. */
    public static function baseRules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(TaskStatus::values())],
            'priority' => ['required', Rule::in(TaskPriority::values())],
            'due_date' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'tags' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Please give the task a title.',
            'title.min' => 'The title must be at least :min characters.',
            'title.max' => 'The title may not be longer than :max characters.',
            'status.required' => 'Please choose a status (Pending or Completed).',
            'status.in' => 'The status must be either Pending or Completed.',
            'priority.in' => 'Choose Low, Medium, High or Urgent.',
            'due_date.date' => 'That is not a valid due date.',
            'description.max' => 'The description may not be longer than :max characters.',
            'assignee_id.exists' => 'The selected assignee does not exist or is deactivated.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $assignee = $this->input('assignee_id');
            if ($assignee && ! $this->user()->isStaff() && (int) $assignee !== $this->user()->id) {
                $v->errors()->add('assignee_id', 'Members can only assign tasks to themselves.');
            }
        });
    }
}
