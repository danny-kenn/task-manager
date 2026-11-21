@extends('layouts.app')
@section('title', 'New task')
@section('heading', 'New task')
@section('content')
<div class="page-head"><div><nav class="breadcrumb"><a href="{{ route('tasks.index') }}">Tasks</a><span class="mx-2">/</span>New</nav><h2>Create a task</h2></div></div>
<div class="card"><div class="card-body">@include('tasks._form')</div></div>
@endsection
