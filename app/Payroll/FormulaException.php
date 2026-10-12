<?php

namespace App\Payroll;

use RuntimeException;

/** A formula problem the user can fix (syntax, unknown variable, division by zero...). */
class FormulaException extends RuntimeException {}
