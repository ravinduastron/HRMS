@extends('layouts.app')
@section('title', 'Edit role')
@section('content')
<x-page-header :title="'Edit '.$role->name" />
@include('roles._form')
@endsection
