# Rotating APP_KEY (re-encryption)

Before you start: back up the database and .env. Keep the old key with any old backup (old backups can only be
decrypted with the key that was current when they were taken).

1. BEFORE changing the key, add to .env the exact current APP_KEY value as the audit checksum key, so audit:verify keeps working:
       AUDIT_CHECKSUM_KEY="base64:OLD_KEY_VALUE"
2. Generate a new key (does not change .env):   php artisan key:generate --show
3. Edit .env:
       APP_PREVIOUS_KEYS="base64:OLD_KEY_VALUE"
       APP_KEY=base64:NEW_KEY_VALUE
4. php artisan config:clear
5. php artisan data:reencrypt --dry-run      (must say every value can be decrypted)
6. php artisan data:reencrypt                (re-encrypts, rebuilds the account-number hashes, writes an audit entry)
7. php artisan queue:restart                 (workers must load the new key)
8. Check: open an employee's bank account and click Show; run php artisan audit:verify.
9. Once all is confirmed (and no other data still needs the old key) you may remove APP_PREVIOUS_KEYS.

Side effects: everyone is signed out (sessions/cookies are encrypted with the key) and signed URLs stop working.
Add any NEW encrypted column to config/reencrypt.php so it is covered next time.
