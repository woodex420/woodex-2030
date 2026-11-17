@extends('layouts.setup')

@section('title', 'Updating')
@section('step', 'Please wait')
@section('heading', 'Woodex OS is being updated')
@section('subheading', 'A new version was just installed. The owner finishes the update in a moment; please try again in a few minutes.')

@section('content')
    <a href="{{ url()->current() }}" class="inline-flex h-11 items-center justify-center rounded-lg bg-navy-900 px-6 text-theme-sm font-medium text-white hover:bg-navy-800">Try again</a>
@endsection
