@extends('errors.layout')

@section('code', '409')
@section('title', 'Saved by somebody else')
@section('heading', 'Somebody else was saving the same records')
@section('message', 'Two people saved in the same moment and only one could go first. Nothing of what you just did was saved. Do it again: you will then see what the other person wrote.')

@section('actions')
    <a href="{{ url()->current() }}" class="wx-auth-submit flex h-11 items-center justify-center rounded-lg px-5 text-theme-sm font-medium">Try again</a>
@endsection
