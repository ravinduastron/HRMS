<?php

return [
    // Deepest level of the cost center tree (an employee is assigned to a LEAF, which can be at level 2 or 3).
    'max_level' => 3,

    // Fixed names of the levels, shown on the screens and in reports.
    'levels' => [1 => 'Division', 2 => 'Department', 3 => 'Section'],

    // Between the levels of a path: Manufacturing > Packing > Floor
    'separator' => ' > ',
];
