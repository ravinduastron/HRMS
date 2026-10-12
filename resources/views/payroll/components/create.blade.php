@extends('layouts.app')
@section('title', 'New pay component')
@section('content')
<x-page-header title="New pay component" description="An earning, deduction or contribution that can appear on a payslip." />
@include('payroll.components._form')
@endsection
