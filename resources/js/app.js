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
    loading: false,
    
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

Alpine.start();
