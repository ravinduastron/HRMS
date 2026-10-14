@extends('layouts.app')
@section('title', 'Edit '.$structure->name)
@section('content')
<x-page-header :title="'Edit '.$structure->name" description="Changes apply only to employees assigned from now on. Employees already assigned keep the components copied to them." />
@include('salary.structures._form')
@endsection
