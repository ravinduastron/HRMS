<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Scheme;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Run on MySQL/MariaDB (the bank-account migration uses a generated column):
 * set DB_CONNECTION/DB_DATABASE for testing in phpunit.xml, then: php artisan test --filter=SchemeScopeTest
 */
class SchemeScopeTest extends TestCase
{
    use RefreshDatabase;

    private Scheme $general;
    private Scheme $officer;
    private Employee $genEmp;
    private Employee $offEmp;
    private Employee $noSchemeEmp;
    private User $generalUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->general = Scheme::create(['name' => 'General', 'mihcm_name' => 'General', 'source' => 'mihcm']);
        $this->officer = Scheme::create(['name' => 'Officer', 'mihcm_name' => 'Officer', 'source' => 'mihcm']);

        $this->genEmp = Employee::create(['employee_no' => 'G1', 'full_name' => 'Gamini General', 'scheme_id' => $this->general->id]);
        $this->offEmp = Employee::create(['employee_no' => 'O1', 'full_name' => 'Olivia Officer', 'scheme_id' => $this->officer->id]);
        $this->noSchemeEmp = Employee::create(['employee_no' => 'N1', 'full_name' => 'Nobody Nogroup']);

        $this->generalUser = $this->userWith(['employees.view', 'employees.status', 'bank-accounts.view', 'bank-accounts.reveal'], [$this->general]);
    }

    private function userWith(array $permissions, array $schemes = []): User
    {
        $role = Role::create(['name' => 'Role '.uniqid(), 'guard_name' => 'web', 'is_active' => true]);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['username' => 'u'.uniqid(), 'password_changed_at' => now()]);
        $user->assignRole($role);
        $user->schemes()->sync(collect($schemes)->pluck('id'));

        return $user;
    }

    public function test_unauthenticated_context_is_not_filtered(): void
    {
        $this->assertSame(3, Employee::count()); // console / queue / sync
    }

    public function test_scoped_user_only_sees_own_scheme(): void
    {
        $this->actingAs($this->generalUser);

        $this->assertSame(['G1'], Employee::pluck('employee_no')->all());
    }

    public function test_user_without_schemes_sees_nothing(): void
    {
        $this->actingAs($this->userWith(['employees.view']));

        $this->assertSame(0, Employee::count());
    }

    public function test_all_schemes_permission_sees_everything_including_unassigned(): void
    {
        $this->actingAs($this->userWith(['employees.view', 'schemes.all']));

        $this->assertSame(3, Employee::count());
    }

    public function test_employee_list_and_detail_pages_respect_scope(): void
    {
        $this->actingAs($this->generalUser);

        $this->get(route('employees.index'))->assertOk()->assertSee('Gamini General')->assertDontSee('Olivia Officer')->assertDontSee('Nobody Nogroup');
        $this->get(route('employees.show', $this->genEmp))->assertOk();
        $this->get(route('employees.show', $this->offEmp))->assertNotFound();
        $this->get(route('employees.show', $this->noSchemeEmp))->assertNotFound();
    }

    public function test_cannot_change_status_of_other_scheme_employee(): void
    {
        $this->actingAs($this->generalUser);

        $this->patch(route('employees.status', $this->offEmp), ['field' => 'is_active'])->assertNotFound();
        $this->assertTrue($this->offEmp->fresh()->is_active);
    }

    public function test_bank_accounts_are_scoped_with_their_employee(): void
    {
        $bank = Bank::create(['code' => '7010', 'name' => 'Test Bank']);
        $branch = $bank->branches()->create(['code' => '001', 'name' => 'Main']);
        $account = (new BankAccount)->forceFill([
            'employee_id' => $this->offEmp->id, 'bank_id' => $bank->id, 'bank_branch_id' => $branch->id,
            'account_number' => '123456789', 'account_number_hash' => str_repeat('a', 64), 'account_last4' => '6789',
            'account_holder_name' => 'Olivia Officer', 'is_active' => true,
        ]);
        $account->save();

        $this->actingAs($this->generalUser);

        $this->assertSame(0, BankAccount::count());
        $this->getJson(route('employees.bank-accounts.reveal', [$this->offEmp, $account]))->assertNotFound();
        $this->getJson(route('employees.bank-accounts.reveal', [$this->genEmp, $account]))->assertNotFound();
    }

    public function test_audit_log_and_sync_history_need_all_schemes(): void
    {
        $scoped = $this->userWith(['audit.view', 'employees.sync-log'], [$this->general]);
        $this->actingAs($scoped)->get(route('audit.index'))->assertForbidden();
        $this->get(route('employees.sync.index'))->assertForbidden();

        $admin = $this->userWith(['audit.view', 'employees.sync-log', 'schemes.all']);
        $this->actingAs($admin)->get(route('audit.index'))->assertOk();
        $this->get(route('employees.sync.index'))->assertOk();
    }

    public function test_only_all_scheme_admins_can_allocate_schemes(): void
    {
        $scoped = $this->userWith(['schemes.view', 'schemes.allocate'], [$this->general]);
        $this->actingAs($scoped)->get(route('schemes.index'))->assertForbidden();
        $this->put(route('schemes.members', $this->officer), ['user_ids' => [$scoped->id]])->assertForbidden();
        $this->assertFalse($scoped->schemes()->whereKey($this->officer->id)->exists());
    }
}
