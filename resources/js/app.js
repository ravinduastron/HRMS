import Alpine from 'alpinejs';

window.Alpine = Alpine;

const rand = (n) => crypto.getRandomValues(new Uint32Array(1))[0] % n;

// Password generator for the user form. Mirrors the server policy; the server still validates.
Alpine.data('userForm', (name = '', username = '') => ({
    name,
    username,
    password: '',
    showPw: false,
    copied: false,

    generate() {
        const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*?'];
        const all = sets.join('');
        const parts = [...this.name.split(/[^\p{L}\p{N}]+/u), this.username]
            .map((p) => p.toLowerCase()).filter((p) => p.length >= 3);
        let pw;
        do {
            const chars = sets.map((s) => s[rand(s.length)]);
            while (chars.length < 12) chars.push(all[rand(all.length)]);
            for (let i = chars.length - 1; i > 0; i--) {
                const j = rand(i + 1);
                [chars[i], chars[j]] = [chars[j], chars[i]];
            }
            pw = chars.join('');
        } while (parts.some((p) => pw.toLowerCase().includes(p)));
        this.password = pw;
        this.showPw = true;
    },

    async copy() {
        if (!this.password) return;
        await navigator.clipboard?.writeText(this.password);
        this.copied = true;
        setTimeout(() => (this.copied = false), 1500);
    },
}));

/*
 * Searchable dropdown (see resources/views/components/combobox.blade.php).
 * items: [{ value, label }]. `value` is the selected value (kept in sync with a parent x-model via x-modelable).
 * The search box only appears when there are at least `minSearch` options. Dispatches a bubbling "change" event on selection.
 */
Alpine.data('combo', (cfg = {}) => ({
    value: cfg.value ?? '',
    items: cfg.items ?? [],
    empty: cfg.empty ?? null,          // label of an "all / none" option (value ''), or null for none
    placeholder: cfg.placeholder ?? 'Select…',
    minSearch: cfg.minSearch ?? 8,
    disabled: false,
    open: false,
    q: '',
    hi: 0,

    get searchable() {
        return this.items.length >= this.minSearch;
    },

    get list() {
        const q = this.q.trim().toLowerCase();
        const out = this.items.filter((i) => !q || String(i.label).toLowerCase().includes(q));
        return this.empty !== null && !q ? [{ value: '', label: this.empty }, ...out] : out;
    },

    get label() {
        const m = this.items.find((i) => String(i.value) === String(this.value));
        if (m) return m.label;
        return this.empty !== null && (this.value === '' || this.value === null) ? this.empty : null;
    },

    toggle() {
        this.open ? this.close() : this.show();
    },

    show() {
        if (this.disabled) return;
        this.q = '';
        this.open = true;
        this.hi = Math.max(this.list.findIndex((i) => String(i.value) === String(this.value)), 0);
        this.$nextTick(() => {
            this.$refs.search?.focus();
            this.scroll();
        });
    },

    close() {
        this.open = false;
        this.q = '';
    },

    pick(item) {
        const changed = String(item.value) !== String(this.value);
        this.value = item.value;
        this.close();
        this.$refs.btn?.focus();
        if (changed) this.$nextTick(() => this.$dispatch('change', { value: item.value }));
    },

    move(step) {
        const n = this.list.length;
        if (!n) return;
        this.hi = (this.hi + step + n) % n;
        this.$nextTick(() => this.scroll());
    },

    choose() {
        const item = this.list[this.hi];
        if (item) this.pick(item);
    },

    scroll() {
        this.$refs.list?.children[this.hi]?.scrollIntoView({ block: 'nearest' });
    },
}));

/*
 * Global "busy on submit" behaviour (applies to EVERY form, current and future).
 * When a form is submitted: all buttons on the page are disabled, the clicked button shows a spinner,
 * links are blocked (see app.css), and a second submit is ignored. Reset automatically on back/forward.
 * Opt out for a form with:  <form data-no-loading>  (e.g. file downloads that don't leave the page).
 */
const SPINNER = '<svg data-busy-spinner class="size-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    + '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/>'
    + '<path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';
let busyTimer;

function setBusy(form, submitter) {
    document.documentElement.dataset.busy = '1';

    document.querySelectorAll('button').forEach((b) => {
        if (b.disabled) return;
        b.disabled = true;
        b.dataset.busyDisabled = '1';
    });

    const btn = submitter || form.querySelector('button[type="submit"], button:not([type])');
    if (btn) {
        btn.dataset.busyActive = '1';
        btn.setAttribute('aria-busy', 'true');
        // swap a leading icon for the spinner so the button doesn't change width
        btn.querySelectorAll(':scope > svg:not(.animate-spin)').forEach((s) => {
            s.dataset.busyHidden = '1';
            s.style.display = 'none';
        });
        // Reuse a spinner the button already shows (x-button "loading"); otherwise add ours.
        // A hidden one (e.g. its Alpine variable is undefined) must not stop us.
        const own = [...btn.querySelectorAll('svg.animate-spin')].some((s) => s.getClientRects().length > 0);
        if (!own) btn.insertAdjacentHTML('afterbegin', SPINNER);
    }

    clearTimeout(busyTimer);
    busyTimer = setTimeout(clearBusy, 60000); // safety net if the request never completes
}

function clearBusy() {
    delete document.documentElement.dataset.busy;
    document.querySelectorAll('[data-busy-disabled]').forEach((b) => { b.disabled = false; delete b.dataset.busyDisabled; });
    document.querySelectorAll('[data-busy-spinner]').forEach((s) => s.remove());
    document.querySelectorAll('[data-busy-hidden]').forEach((s) => { s.style.display = ''; delete s.dataset.busyHidden; });
    document.querySelectorAll('[data-busy-active]').forEach((b) => { b.removeAttribute('aria-busy'); delete b.dataset.busyActive; });
    document.querySelectorAll('form[data-submitting]').forEach((f) => delete f.dataset.submitting);
}

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || 'noLoading' in form.dataset || form.target === '_blank') return;

    if (form.dataset.submitting) { // double-click / double-Enter guard
        e.preventDefault();
        return;
    }

    // Defer so other handlers can cancel first and the browser has already collected the form data.
    setTimeout(() => {
        if (e.defaultPrevented) return;
        form.dataset.submitting = '1';
        setBusy(form, e.submitter);
    }, 0);
});

// Returning with the browser Back button must not show a frozen, disabled page.
window.addEventListener('pageshow', (e) => { if (e.persisted) clearBusy(); });

/*
 * Toasts: short-lived confirmations (success etc.). Usage anywhere:
 *   window.toast('Saved')                       // success, 5s
 *   window.toast('Could not save', 'error')     // errors stay until dismissed
 *   $dispatch('toast', { message: 'Copied', type: 'info' })   // from Alpine
 * Laravel: redirect()->with('status', 'User created.') is shown as a toast automatically.
 */
const toastTimers = new Map();

Alpine.store('toasts', {
    items: [],
    seq: 0,

    add({ message, type = 'success', title = null, timeout } = {}) {
        if (!message) return;
        const id = ++this.seq;
        const sticky = type === 'error' || type === 'warning'; // problems stay until dismissed
        this.items.push({ id, message, type, title, leaving: false });
        this.items = this.items.slice(-4); // never stack more than 4
        const ms = timeout ?? (sticky ? 0 : 5000);
        if (ms > 0) this.schedule(id, ms);
    },

    schedule(id, ms) {
        this.cancel(id);
        toastTimers.set(id, setTimeout(() => this.remove(id), ms));
    },

    cancel(id) {
        clearTimeout(toastTimers.get(id));
        toastTimers.delete(id);
    },

    remove(id) {
        this.cancel(id);
        const t = this.items.find((i) => i.id === id);
        if (!t) return;
        t.leaving = true; // lets the leave transition play
        setTimeout(() => (this.items = this.items.filter((i) => i.id !== id)), 200);
    },
});

window.toast = (message, type = 'success', options = {}) => Alpine.store('toasts').add({ message, type, ...options });
window.addEventListener('toast', (e) => Alpine.store('toasts').add(e.detail));

Alpine.start();
