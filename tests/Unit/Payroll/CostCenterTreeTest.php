<?php

namespace Tests\Unit\Payroll;

use App\Payroll\CostCenterTree;
use App\Payroll\FormulaException;
use PHPUnit\Framework\TestCase;

class CostCenterTreeTest extends TestCase
{
    /** Manufacturing > Packing > (Office, Floor); Manufacturing > Production > (Office, Floor); Finance > Accounting */
    private function nodes(): array
    {
        $n = fn ($id, $p, $l, $name, $active = true) => [$id => ['id' => $id, 'parent_id' => $p, 'level' => $l, 'code' => "C{$id}", 'name' => $name, 'is_active' => $active]];

        return $n(1, null, 1, 'Manufacturing') + $n(2, 1, 2, 'Packing') + $n(3, 2, 3, 'Office') + $n(4, 2, 3, 'Floor')
            + $n(5, 1, 2, 'Production') + $n(6, 5, 3, 'Office') + $n(7, 5, 3, 'Floor') + $n(8, null, 1, 'Finance') + $n(9, 8, 2, 'Accounting');
    }

    public function test_path_and_label(): void
    {
        $this->assertSame('Manufacturing > Packing > Floor', CostCenterTree::label($this->nodes(), 4));
        $this->assertSame('Finance > Accounting', CostCenterTree::label($this->nodes(), 9));
    }

    public function test_leaf_can_be_level_two_or_three(): void
    {
        $this->assertTrue(CostCenterTree::isLeaf($this->nodes(), 9));
        $this->assertTrue(CostCenterTree::isLeaf($this->nodes(), 4));
        $this->assertFalse(CostCenterTree::isLeaf($this->nodes(), 2));
        $this->assertFalse(CostCenterTree::isLeaf($this->nodes(), 8));
    }

    public function test_a_node_whose_children_are_all_inactive_is_a_leaf(): void
    {
        $nodes = $this->nodes();
        $nodes[9]['is_active'] = false;
        $this->assertTrue(CostCenterTree::isLeaf($nodes, 8));
    }

    public function test_snapshot_has_three_levels_with_null_for_a_two_level_branch(): void
    {
        $s = CostCenterTree::snapshot($this->nodes(), 9);
        $this->assertSame(9, $s['cost_center_id']);
        $this->assertSame('Finance > Accounting', $s['path']);
        $this->assertSame('Finance', $s['levels'][1]['name']);
        $this->assertSame('Accounting', $s['levels'][2]['name']);
        $this->assertNull($s['levels'][3]);

        $f = CostCenterTree::snapshot($this->nodes(), 7);
        $this->assertSame(['Manufacturing', 'Production', 'Floor'], array_values(array_map(fn ($l) => $l['name'], $f['levels'])));
    }

    public function test_child_rules(): void
    {
        $this->assertNull(CostCenterTree::childProblem(null, 3));
        $this->assertNull(CostCenterTree::childProblem(['level' => 2, 'is_active' => true], 3));
        $this->assertNotNull(CostCenterTree::childProblem(['level' => 3, 'is_active' => true], 3));
        $this->assertNotNull(CostCenterTree::childProblem(['level' => 1, 'is_active' => false], 3));
    }

    public function test_flatten_is_depth_first(): void
    {
        $names = array_map(fn ($f) => str_repeat('-', $f['depth']).$f['node']['name'], CostCenterTree::flatten($this->nodes()));
        $this->assertSame(['Manufacturing', '-Packing', '--Office', '--Floor', '-Production', '--Office', '--Floor', 'Finance', '-Accounting'], $names);
    }

    public function test_loop_is_detected(): void
    {
        $this->expectException(FormulaException::class);
        $nodes = [1 => ['id' => 1, 'parent_id' => 2, 'level' => 1, 'code' => 'A', 'name' => 'A', 'is_active' => true], 2 => ['id' => 2, 'parent_id' => 1, 'level' => 2, 'code' => 'B', 'name' => 'B', 'is_active' => true]];
        CostCenterTree::path($nodes, 1);
    }
}
