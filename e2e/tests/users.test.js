/**
 * E2E Tests: Benutzerverwaltung
 *
 * Covers:
 *  - Admin sieht Benutzerliste mit Fixture-Benutzern
 *  - Admin kann neuen Benutzer anlegen
 *  - Admin kann Benutzer bearbeiten
 *  - Admin kann Benutzer löschen
 *  - Auditor wird von /admin/users ferngehalten (403 / Redirect)
 *  - Doppelter Benutzername wird abgelehnt
 */

import { Selector } from 'testcafe';
import { loginAsAdmin, loginAsAuditor, BASE_URL } from '../helpers/auth';

const USERS_URL = `${BASE_URL}/admin/users`;
const NEW_USER = {
    username: 'e2e-testuser',
    password: 'e2ePassword!99',
    email:    'e2e@tls-monitor.local',
    role:     'auditor',
};

// ──────────────────────────────────────────────
// Admin: Benutzerliste
// ──────────────────────────────────────────────
fixture('Benutzerverwaltung – Admin')
    .page(`${BASE_URL}/login`)
    .beforeEach(async t => {
        await loginAsAdmin(t);
    });

test('Admin sieht Benutzerliste mit Fixture-Benutzern', async t => {
    await t.navigateTo(USERS_URL);

    await t
        .expect(Selector('h2').withText('Benutzerverwaltung').exists).ok('Überschrift "Benutzerverwaltung" fehlt')
        .expect(Selector('td').withText('admin').exists).ok('Fixture-Benutzer "admin" fehlt')
        .expect(Selector('td').withText('auditor').exists).ok('Fixture-Benutzer "auditor" fehlt');
});

test('Rollen-Badges werden korrekt angezeigt', async t => {
    await t.navigateTo(USERS_URL);

    await t
        .expect(Selector('.badge.bg-primary').withText('admin').exists).ok('Admin-Badge (bg-primary) fehlt')
        .expect(Selector('.badge.bg-secondary').withText('auditor').exists).ok('Auditor-Badge (bg-secondary) fehlt');
});

// ──────────────────────────────────────────────
// Admin: Neuen Benutzer anlegen
// ──────────────────────────────────────────────
test('Admin kann neuen Benutzer anlegen', async t => {
    await t.navigateTo(`${USERS_URL}/new`);

    await t
        .typeText('#username', NEW_USER.username, { replace: true })
        .typeText('#password', NEW_USER.password, { replace: true })
        .typeText('#email', NEW_USER.email, { replace: true })
        .click(`input[type="radio"][value="${NEW_USER.role}"]`)
        .click('[type="submit"]');

    // Weiterleitung auf Benutzerliste erwartet
    await t
        .expect(Selector('td').withText(NEW_USER.username).exists).ok(`Neuer Benutzer "${NEW_USER.username}" nicht in der Liste`)
        .expect(Selector('td').withText(NEW_USER.email).exists).ok(`E-Mail "${NEW_USER.email}" nicht in der Liste`);
});

test('Doppelter Benutzername wird abgelehnt', async t => {
    await t.navigateTo(`${USERS_URL}/new`);

    // 'admin' existiert bereits als Fixture
    await t
        .typeText('#username', 'admin', { replace: true })
        .typeText('#password', 'somePassword123', { replace: true })
        .typeText('#email', 'duplicate@example.com', { replace: true })
        .click('[type="submit"]');

    await t.expect(Selector('.alert-danger').exists).ok('Keine Fehlermeldung bei doppeltem Benutzernamen');
});

// ──────────────────────────────────────────────
// Admin: Benutzer bearbeiten
// ──────────────────────────────────────────────
test('Admin kann E-Mail eines Benutzers ändern', async t => {
    await t.navigateTo(USERS_URL);

    // Ersten Bearbeiten-Button (nicht eigener Account) klicken
    const editBtn = Selector('a[title="Bearbeiten"]').nth(1);
    await t
        .expect(editBtn.exists).ok('Kein Bearbeiten-Button vorhanden')
        .click(editBtn);

    const emailField = Selector('#email');
    await t
        .selectText(emailField)
        .typeText(emailField, 'updated-by-e2e@tls-monitor.local', { replace: true })
        .click('[type="submit"]');

    await t.expect(Selector('.alert-success, td').withText('updated-by-e2e@tls-monitor.local').exists)
        .ok('Geänderte E-Mail nicht in der Benutzerliste');
});

// ──────────────────────────────────────────────
// Admin: Benutzer löschen
// ──────────────────────────────────────────────
test('Admin kann einen Benutzer löschen', async t => {
    // Erst neuen Benutzer anlegen
    await t.navigateTo(`${USERS_URL}/new`);
    await t
        .typeText('#username', 'delete-me-user', { replace: true })
        .typeText('#password', 'DeleteMe!123', { replace: true })
        .typeText('#email', 'deleteme@tls-monitor.local', { replace: true })
        .click('[type="submit"]');

    await t.expect(Selector('td').withText('delete-me-user').exists).ok();

    // Dann löschen
    const deleteRow = Selector('tr').withText('delete-me-user');
    const deleteBtn = deleteRow.find('button[title="Löschen"]');

    await t
        .setNativeDialogHandler(() => true)
        .click(deleteBtn);

    await t.expect(Selector('td').withText('delete-me-user').exists)
        .notOk('Gelöschter Benutzer sollte nicht mehr in der Liste sein');
});

// ──────────────────────────────────────────────
// Auditor: Zugriff verweigert
// ──────────────────────────────────────────────
fixture('Benutzerverwaltung – Auditor (kein Zugriff)')
    .page(`${BASE_URL}/login`)
    .beforeEach(async t => {
        await loginAsAuditor(t);
    });

test('Auditor kann Benutzerverwaltung nicht aufrufen', async t => {
    await t.navigateTo(USERS_URL);

    const currentUrl = await t.eval(() => window.location.pathname);

    // Entweder Redirect oder Access Denied – auf jeden Fall nicht auf /admin/users
    await t
        .expect(currentUrl).notEql('/admin/users', 'Auditor sollte nicht auf /admin/users gelangen')
        .expect(Selector('h2').withText('Benutzerverwaltung').exists).notOk('Auditor sieht Benutzerverwaltung');
});

test('Auditor kann /admin/users/new nicht aufrufen', async t => {
    await t.navigateTo(`${USERS_URL}/new`);

    const formExists = Selector('form #username').exists;
    await t.expect(formExists).notOk('Auditor sieht das Benutzer-Anlegen-Formular');
});
