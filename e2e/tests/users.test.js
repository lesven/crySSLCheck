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

import { Selector, ClientFunction } from 'testcafe';
import { loginAsAdmin, loginAsAuditor, fillFields, submitForm, BASE_URL } from '../helpers/auth';

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
    await t.expect(Selector('#username').exists).ok({ timeout: 10000 });
    await t.wait(1000);

    await fillFields({
        '#username': NEW_USER.username,
        '#password': NEW_USER.password,
        '#email':    NEW_USER.email,
    });
    // Select role via native JS (ClientFunction-safe)
    const setRole = ClientFunction((role) => {
        document.querySelector('#role').value = role;
    });
    await setRole(NEW_USER.role);

    await submitForm();

    // Weiterleitung auf Benutzerliste erwartet
    await t
        .expect(Selector('td').withText(NEW_USER.username).exists).ok(`Neuer Benutzer "${NEW_USER.username}" nicht in der Liste`)
        .expect(Selector('td').withText(NEW_USER.email).exists).ok(`E-Mail "${NEW_USER.email}" nicht in der Liste`);
});

test('Doppelter Benutzername wird abgelehnt', async t => {
    await t.navigateTo(`${USERS_URL}/new`);
    await t.expect(Selector('#username').exists).ok({ timeout: 10000 });
    await t.wait(1000);

    // 'admin' existiert bereits als Fixture
    await fillFields({
        '#username': 'admin',
        '#password': 'somePassword123',
        '#email':    'duplicate@example.com',
    });
    await submitForm();

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

    await t.expect(Selector('#email').exists).ok({ timeout: 10000 });
    await t.wait(1000);
    await fillFields({ '#email': 'updated-by-e2e@tls-monitor.local' });
    await submitForm();

    await t.expect(Selector('.alert-success, td').withText('updated-by-e2e@tls-monitor.local').exists)
        .ok('Geänderte E-Mail nicht in der Benutzerliste');
});

// ──────────────────────────────────────────────
// Admin: Benutzer löschen
// ──────────────────────────────────────────────
test('Admin kann einen Benutzer löschen', async t => {
    // Erst neuen Benutzer anlegen
    await t.navigateTo(`${USERS_URL}/new`);
    await t.expect(Selector('#username').exists).ok({ timeout: 10000 });
    await t.wait(1000);

    await fillFields({
        '#username': 'delete-me-user',
        '#password': 'DeleteMe!123',
        '#email':    'deleteme@tls-monitor.local',
    });
    await submitForm();

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

    // Symfony zeigt bei fehlender Berechtigung eine 403-Seite (Access Denied),
    // die URL bleibt dabei /admin/users – deshalb prüfen wir den Seiteninhalt.
    await t
        .expect(Selector('h2').withText('Benutzerverwaltung').exists).notOk('Auditor sieht Benutzerverwaltung')
        .expect(Selector('a').withText('Benutzer anlegen').exists).notOk('Auditor sieht den Benutzer-anlegen-Link');
});

test('Auditor kann /admin/users/new nicht aufrufen', async t => {
    await t.navigateTo(`${USERS_URL}/new`);

    const formExists = Selector('form #username').exists;
    await t.expect(formExists).notOk('Auditor sieht das Benutzer-Anlegen-Formular');
});
