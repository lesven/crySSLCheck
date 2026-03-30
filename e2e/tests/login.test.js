/**
 * E2E Tests: Login / Authentication
 *
 * Covers:
 *  - Successful admin login → redirect to domain list
 *  - Successful auditor login → redirect to domain list
 *  - Failed login with wrong credentials → error message
 *  - Redirect to login when accessing protected page unauthenticated
 *  - Logout clears session
 */

import { Selector } from 'testcafe';
import { loginAsAdmin, loginAsAuditor, loginWith, logout, users, BASE_URL } from '../helpers/auth';

fixture('Login / Authentication')
    .page(`${BASE_URL}/login`);

// ──────────────────────────────────────────────
// Positive: Admin login
// ──────────────────────────────────────────────
test('Admin kann sich einloggen und landet auf der Domain-Liste', async t => {
    await loginAsAdmin(t);

    await t
        .expect(Selector('h2').withText('Domains').exists).ok('Domain-Überschrift nicht gefunden')
        .expect(Selector('a').withText('Domain anlegen').exists).ok('Admin sieht "Domain anlegen"-Button nicht');
});

// ──────────────────────────────────────────────
// Positive: Auditor login
// ──────────────────────────────────────────────
test('Auditor kann sich einloggen und landet auf der Domain-Liste', async t => {
    await loginAsAuditor(t);

    await t
        .expect(Selector('h2').withText('Domains').exists).ok('Domain-Überschrift nicht gefunden');

    // Auditor darf keine Admin-Buttons sehen
    await t
        .expect(Selector('a').withText('Domain anlegen').exists).notOk('Auditor sieht "Domain anlegen"-Button – darf er nicht');
});

// ──────────────────────────────────────────────
// Negative: Falsche Zugangsdaten
// ──────────────────────────────────────────────
test('Login mit falschen Zugangsdaten zeigt Fehlermeldung', async t => {
    await loginWith(t, users.invalid.username, users.invalid.password);

    // CI headless Chrome can be slow to complete the POST → 302 → GET redirect
    // chain after a failed login; give the assertion a generous timeout.
    await t
        .expect(Selector('.alert-danger').exists).ok('Fehlermeldung bei falschem Login fehlt', { timeout: 15000 })
        .expect(Selector('.alert-danger').innerText).contains('Invalid credentials', 'Fehlermeldungstext passt nicht');
});

// ──────────────────────────────────────────────
// Negative: Leere Felder
// ──────────────────────────────────────────────
test('Login-Formular ohne Eingabe bleibt auf Login-Seite', async t => {
    await t.click('[type="submit"]');

    // HTML5-Validierung oder Symfony – in jedem Fall kein Redirect auf /domains
    const currentUrl = await t.eval(() => window.location.pathname);
    await t.expect(currentUrl).notEql('/domains', 'Leeres Login sollte nicht auf /domains weiterleiten');
});

// ──────────────────────────────────────────────
// Redirect: Unauthentifizierter Zugriff
// ──────────────────────────────────────────────
test('Unauthentifizierter Zugriff auf /domains leitet auf Login um', async t => {
    await t.navigateTo(`${BASE_URL}/domains`);

    const currentUrl = await t.eval(() => window.location.pathname);
    await t.expect(currentUrl).eql('/login', 'Unauthentifizierter Zugriff sollte auf /login umleiten');
});

// ──────────────────────────────────────────────
// Logout
// ──────────────────────────────────────────────
test('Logout beendet die Session und leitet auf Login um', async t => {
    await loginAsAdmin(t);

    // Sicherstellen, dass wir eingeloggt sind
    await t.expect(Selector('h2').withText('Domains').exists).ok();

    await logout(t);

    // Nach Logout sollte man auf Login landen oder auf /domains (was dann auf /login weiterleitet)
    await t.navigateTo(`${BASE_URL}/domains`);
    const currentUrl = await t.eval(() => window.location.pathname);
    await t.expect(currentUrl).eql('/login', 'Nach Logout sollte /domains auf /login umleiten');
});
