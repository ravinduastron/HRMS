@php $creating = ! $user->exists; @endphp
<form method="POST" action="{{ $creating ? route('users.store') : route('users.update', $user) }}"
    x-data="userForm(@js(old('name', $user->name ?? '')), @js(old('username', $user->username ?? '')))" @submit="loading = true" class="space-y-6">
    @csrf
    @unless ($creating) @method('PUT') @endunless

    <x-card title="Account details">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-input name="name" label="Display name" x-model="name" :value="old('name', $user->name)" required />
            <x-input name="username" label="Username" x-model="username" :value="old('username', $user->username)" autocomplete="off" required
                hint="Letters, numbers, dot, dash, underscore." />
            <div class="sm:col-span-2">
                <x-input name="email" type="email" label="Email" :value="old('email', $user->email)" required
                    :hint="'Must be an @'.config('accounts.email_domain').' address.'" />
            </div>

            @if ($creating)
                <div class="sm:col-span-2">
                    <label for="password" class="block text-sm font-medium text-slate-700">Temporary password</label>
                    <div class="mt-1.5 flex gap-2">
                        <div class="relative flex-1">
                            <input id="password" name="password" x-model="password" :type="showPw ? 'text' : 'password'" autocomplete="new-password" required
                                class="block w-full rounded-md border border-slate-300 py-2 pl-3 pr-20 font-mono text-sm shadow-xs focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600">
                            <div class="absolute inset-y-0 right-0 flex items-center gap-1 pr-2 text-slate-400">
                                <button type="button" @click="showPw = !showPw" class="rounded p-1 hover:text-slate-600" :aria-label="showPw ? 'Hide password' : 'Show password'">
                                    <x-icon name="eye" x-show="!showPw" class="size-5" /><x-icon name="eye-slash" x-show="showPw" x-cloak class="size-5" />
                                </button>
                                <button type="button" @click="copy()" class="rounded px-1 text-xs font-medium hover:text-slate-600" x-text="copied ? 'Copied' : 'Copy'"></button>
                            </div>
                        </div>
                        <x-button variant="secondary" @click="generate()">Generate</x-button>
                    </div>
                    @foreach ($errors->get('password') as $m)<p class="mt-1.5 text-sm text-red-600">{{ $m }}</p>@endforeach
                    <p class="mt-1.5 text-sm text-slate-500">
                        {{ config('password_policy.min_length') }}+ characters with upper, lower, number and symbol, and no part of the name or username.
                        It is emailed to the user and must be changed at first sign-in.
                    </p>
                </div>
            @endif
        </div>
    </x-card>

    <x-card title="Roles">
        @php $selected = collect(old('roles', $user->roles->pluck('id')->all()))->map(fn ($i) => (int) $i)->all(); @endphp
        <p class="mb-3 text-sm text-slate-500">Select one or more roles. Permissions from all selected roles apply.</p>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($roles as $r)
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50 has-checked:border-brand-500 has-checked:bg-brand-50">
                    <input type="checkbox" name="roles[]" value="{{ $r->id }}" @checked(in_array($r->id, $selected)) class="mt-0.5 size-4 accent-brand-700">
                    <span>
                        <span class="font-medium text-slate-900">{{ $r->name }}</span>
                        @unless ($r->is_active)<x-badge class="ml-1">Inactive</x-badge>@endunless
                        @if ($r->description)<span class="block text-xs text-slate-500">{{ $r->description }}</span>@endif
                    </span>
                </label>
            @endforeach
        </div>
        @error('roles')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
        @error('roles.*')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </x-card>

    <div class="flex justify-end gap-2">
        <x-button :href="$creating ? route('users.index') : route('users.show', $user)" variant="secondary">Cancel</x-button>
        <x-button type="submit"  loading="loading">{{ $creating ? 'Create user & email password' : 'Save changes' }}</x-button>
    </div>
</form>
