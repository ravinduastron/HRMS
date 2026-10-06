<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        // Sample data: replace with real queries as modules are built.
        return view('home', [
            'stats' => [
                ['label' => 'Active employees',  'value' => '248',       'delta' => '+6 this month',     'trend' => 'up',   'icon' => 'users'],
                ['label' => 'Net payroll (Sep)', 'value' => 'LKR 18.4M', 'delta' => '+2.1% vs Aug',      'trend' => 'up',   'icon' => 'banknotes'],
                ['label' => 'Pending leave',     'value' => '12',        'delta' => '3 older than 5 days', 'trend' => 'down', 'icon' => 'calendar'],
                ['label' => 'Unapproved OT',     'value' => '37 hrs',    'delta' => 'Cut-off in 2 days', 'trend' => 'down', 'icon' => 'clock'],
            ],
            'steps' => [
                ['label' => 'Attendance locked',    'state' => 'done'],
                ['label' => 'Adjustments added',    'state' => 'done'],
                ['label' => 'Review variances',     'state' => 'current'],
                ['label' => 'Approve',              'state' => 'todo'],
                ['label' => 'Bank file & payslips', 'state' => 'todo'],
            ],
            'activity' => [
                ['who' => 'Nadeesha Perera', 'what' => 'Approved overtime for Finance', 'when' => '10 min ago', 'status' => 'Approved'],
                ['who' => 'Kasun Fernando',  'what' => 'Submitted a loan request',      'when' => '1 hr ago',   'status' => 'Pending'],
                ['who' => 'System',          'what' => 'EPF/ETF file generated for Aug', 'when' => 'Yesterday', 'status' => 'Completed'],
                ['who' => 'Ishara Silva',    'what' => 'Salary revision rejected',      'when' => 'Yesterday',  'status' => 'Rejected'],
            ],
        ]);
    }
}
