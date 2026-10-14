@extends('layouts.app')
@section('title', 'New salary structure')
@section('content')
<x-page-header title="New salary structure" description="Choose the scheme, give it a name, and list the components employees on it receive." />
@include('salary.structures._form')
@endsection
