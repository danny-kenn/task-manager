@extends('layouts.app')
@section('title', 'Edit task')
@section('heading', 'Edit task')
@section('content')
<div class="page-head"><div><nav class="breadcrumb"><a href="{{ route('tasks.index') }}">Tasks</a><span class="mx-2">/</span><a href="{{ route('tasks.show', $task) }}">{{ \Illuminate\Support\Str::limit($task->title, 40) }}</a><span class="mx-2">/</span>Edit</nav><h2>Edit task</h2></div></div>
<div class="card"><div class="card-body">@include('tasks._form')</div></div>
@endsection
