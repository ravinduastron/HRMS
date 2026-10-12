@extends('layouts.app')
@section('title', 'New version of '.$payComponent->code)
@section('content')
<x-page-header :title="'New version of '.$payComponent->code" :description="$payComponent->name.' · the current version stays in force until the new one starts'" />
@include('payroll.components._form')
@endsection
