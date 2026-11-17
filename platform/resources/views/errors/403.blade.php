@extends('errors.layout')

@section('code', '403')
@section('title', 'No access')
@section('heading', 'You do not have access to this screen')
{{-- A reason written in the application ("That tab is not available to you.") is shown; the framework's own
     sentence is replaced by a plainer one. --}}
@section('message', in_array($exception->getMessage(), ['', 'This action is unauthorized.', 'Forbidden'], true)
    ? 'Your role does not include it. If you need it for your work, ask the owner to change your role.'
    : $exception->getMessage())
