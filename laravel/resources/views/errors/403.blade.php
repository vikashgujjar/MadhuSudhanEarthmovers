@extends('errors.layout')
@section('title', 'Access denied')
@section('code', '403')
@section('heading', 'Access denied')
@section('message'){{ $exception->getMessage() ?: 'You do not have permission to view this page.' }} If you need access, ask your administrator.@endsection
