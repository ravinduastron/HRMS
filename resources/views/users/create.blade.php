@extends('layouts.app')
@section('title', 'New user')
@section('content')
<x-page-header title="New user" description="The login details are emailed to the user automatically." />
@include('users._form')
@endsection
