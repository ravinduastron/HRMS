@extends('layouts.app')
@section('title', 'Edit '.$user->name)
@section('content')
<x-page-header :title="'Edit '.$user->name" description="To change the password, use Reset password on the user page." />
@include('users._form')
@endsection
