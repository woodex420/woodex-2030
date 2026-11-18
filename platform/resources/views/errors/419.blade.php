@extends('errors.layout')

@section('code', '419')
@section('title', 'Page expired')
@section('heading', 'This page was open for too long')
@section('message', 'For your safety a form can be sent only for a while after it was opened. Nothing was saved. Open the page again and repeat the last step.')

@section('actions')
    <a href="{{ url()->current() }}" class="wx-auth-submit flex h-11 items-center justify-center rounded-lg px-5 text-theme-sm font-medium">Open the page again</a>
@endsection
