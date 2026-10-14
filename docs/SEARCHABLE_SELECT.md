# Searchable dropdown (`<x-combobox>`) and "Add a new group" popup

## Install
1. Copy over the project (overwrites resources/js/app.js, routes/web.php, PayComponentGroupController,
   payroll/components/_form and show, employees/tabs/_bank and employees/index).
2. `npm run build` (or keep `npm run dev` running): the component's script is in resources/js/app.js.
3. `php artisan optimize:clear`. No migration, no new permission (the popup uses pay-component-groups.manage).

## Using the dropdown
    <x-combobox name="department" label="Department" :options="[['value' => 1, 'label' => 'Finance']]"
        :value="request('department')" empty="All departments" />
* Submits like a normal field (hidden input `name`), so GET filters and POST forms work unchanged.
* The search box appears only when there are 8+ options (`min-search="..."` to change). Keyboard: arrows, Enter, Escape.
* Inside an Alpine form, bind it to the form's variable: `x-model="bank"` and react to a change with `@change="branch = ''"`.
* Options that depend on other form values: `options-js="(branches[bank] ?? []).map(b => ({ value: b.id, label: b.name }))"`
  and `disabled-js="!bank"`. Use single quotes inside these props.
* Keep short fixed lists (rounding, status, proration...) as plain `<select>`: searching 3 options only adds a click.

## Add a new group from the component form
"+ Add a new group" opens a popup (needs pay-component-groups.manage; others see "ask an administrator").
It posts to payroll/groups/quick as JSON, adds the group to the dropdown and selects it. The component form is not
reloaded or cleared. The popup is teleported outside the form, so its fields are never submitted with the component.

## Reserved variable names
`<x-combobox>` runs in its own Alpine scope that sits inside the form's. A form variable with the same name as one of the
component's own members is shadowed inside the component and breaks `x-model`. Do not name form variables:
`value, items, empty, placeholder, minSearch, disabled, open, q, hi, searchable, list, label, toggle, show, close, pick, move, choose, scroll`.
