@extends('layouts.app')
@section('title', 'Edit '.$group->name)
@section('content')
<x-page-header :title="'Edit '.$group->name" description="Changes apply to payslips calculated from now on. Past payslips keep the grouping they were calculated with." />
<x-card>@include('payroll.groups._form')</x-card>
@endsection
