@extends('layouts.app')
@section('title', 'New role')
@section('content')
<x-page-header title="New role" description="Choose what people with this role are allowed to do." />
@include('roles._form')
@endsection
