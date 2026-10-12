<?php

namespace App\Services;

use App\Models\PayComponent;
use App\Models\PayComponentVersion;
use App\Models\User;
use App\Payroll\ComponentCalculator;
use App\Payroll\FormulaException;
use App\Payroll\SystemVariables;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayComponentService
{
    public function __construct(private ComponentCalculator $calculator) {}

    /** Definitions of every component as of $date (components with no version yet in force are left out). */
    public function definitionsAt(Carbon $date): array
    {
        return PayComponent::with('versions')->get()
            ->mapWithKeys(fn ($c) => ($v = $c->current($date)) ? [$c->code => $v->toDefinition($c)] : [])->all();
    }

    /** Newest approved definition of every component (used to validate for circular references). */
    public function latestDefinitions(): array
    {
        return PayComponent::with('versions')->get()
            ->mapWithKeys(fn ($c) => ($v = $c->latestVersion()) ? [$c->code => $v->toDefinition($c)] : [])->all();
    }

    public function create(array $header, array $version, ?User $by = null): PayComponent
    {
        return DB::transaction(function () use ($header, $version, $by) {
            $this->assertValid($header['code'], $header['name'], $header['type'], true, $version);

            $component = PayComponent::create($header);
            $this->insertVersion($component, $version, $by);
            Audit::log('pay_component.created', $component, "Pay component {$component->code} created", context: ['method' => $version['calc_method']]);

            return $component;
        });
    }

    public function addVersion(PayComponent $component, array $version, ?User $by = null): PayComponentVersion
    {
        return DB::transaction(function () use ($component, $version, $by) {
            $latest = $component->latestVersion();
            if ($latest && Carbon::parse($version['effective_from'])->lte($latest->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'The effective date must be after the latest version ('.$latest->effective_from->format('d M Y').').']);
            }
            $this->assertValid($component->code, $component->name, $component->type, $component->is_active, $version);

            $new = $this->insertVersion($component, $version, $by);
            Audit::log('pay_component.version_added', $component, "New version of {$component->code} effective {$new->effective_from->format('d M Y')}", context: ['method' => $version['calc_method']]);

            return $new;
        });
    }

    private function insertVersion(PayComponent $component, array $v, ?User $by): PayComponentVersion
    {
        $version = new PayComponentVersion;
        $version->forceFill($v + [
            'pay_component_id' => $component->id,
            'approval_status' => 'approved', 'approved_by' => $by?->id, 'approved_at' => now(), // approval engine hooks in here later
            'created_by' => $by?->id, 'created_by_name' => $by?->name,
        ])->save();

        return $component->unsetRelation('versions')->versions()->where('id', $version->id)->firstOrFail();
    }

    /** Syntax, unknown variables, inactive references and circular references: the same checks the "Check formula" button runs. */
    public function check(string $code, array $version, ?string $name = null, ?string $type = 'earning', bool $active = true): array
    {
        $def = [
            'name' => $name ?? $code, 'type' => $type, 'active' => $active, 'method' => $version['calc_method'],
            'formula' => $version['formula'] ?? null, 'amount' => '0',
            'percentage_rate' => $version['percentage_rate'] ?? null, 'percentage_of' => $version['percentage_of'] ?? [],
            'rounding_mode' => 'half_up', 'rounding_dp' => 2,
        ];
        $defs = $this->latestDefinitions();
        $defs[$code] = $def;

        $text = ComponentCalculator::effectiveFormula($def);
        if (in_array($def['method'], ['formula', 'percentage'], true) && $text === null) {
            throw new FormulaException($def['method'] === 'formula' ? 'Enter a formula.' : 'Choose at least one component and a percentage.');
        }
        if ($def['method'] === 'percentage' && collect($def['percentage_of'])->contains($code)) {
            throw new FormulaException('A component cannot be a percentage of itself.');
        }

        $refs = $this->calculator->references([$code => $def])[$code];
        foreach ($refs as $ref) {
            if ($ref === $code) {
                continue; // reported as a circular reference below
            }
            if (! isset($defs[$ref]) && ! in_array($ref, SystemVariables::names(), true)) {
                throw new FormulaException("Unknown variable {$ref}. Use a component code or one of the system variables.");
            }
            if (isset($defs[$ref]) && ! $defs[$ref]['active']) {
                throw new FormulaException("{$ref} is inactive and cannot be used in a formula.");
            }
        }
        $this->calculator->order($defs); // throws on circular references

        return ['references' => $refs, 'formula' => $text];
    }

    private function assertValid(string $code, string $name, string $type, bool $active, array $version): void
    {
        try {
            $this->check($code, $version, $name, $type, $active);
        } catch (FormulaException $e) {
            throw ValidationException::withMessages([$version['calc_method'] === 'percentage' ? 'percentage_of' : 'formula' => $e->getMessage()]);
        }
    }
}
