@extends('errors.layout')

@section('code', '500')
@section('title', 'Error')
@section('heading', 'Something went wrong on our side')
@section('message', 'The error was recorded. Try again; if it happens a second time, tell the owner what you were doing and the time shown below.')

@section('actions')
    <a href="{{ url()->current() }}" class="wx-auth-submit flex h-11 items-center justify-center rounded-lg px-5 text-theme-sm font-medium">Try again</a>
@endsection
