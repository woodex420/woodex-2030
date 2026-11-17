@extends('errors.layout')

@section('code', '503')
@section('title', 'Unavailable')
@section('heading', 'WOODEX OS is unavailable for a moment')
@section('message', 'It is being updated or maintained. Your records are safe. Try again in a few minutes.')

@section('actions')
    <a href="{{ url()->current() }}" class="wx-auth-submit flex h-11 items-center justify-center rounded-lg px-5 text-theme-sm font-medium">Try again</a>
@endsection
