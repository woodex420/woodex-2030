@extends('errors.layout')

@section('code', '429')
@section('title', 'Too many attempts')
@section('heading', 'Too many attempts in a short time')
@section('message', 'Wait a minute, then try again. This limit protects the accounts and the forms from automated guessing.')

@section('actions')
    <a href="{{ url()->current() }}" class="wx-auth-submit flex h-11 items-center justify-center rounded-lg px-5 text-theme-sm font-medium">Try again</a>
@endsection
