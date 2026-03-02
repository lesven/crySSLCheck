/**
 * E2E Tests: Domain-Verwaltung
 *
 * Covers:
 *  - Domain-Liste wird angezeigt (mit Fixture-Daten)
 *  - Neue Domain anlegen (Admin)
 *  - Domain bearbeiten (Admin)
 *  - Domain deaktivieren / reaktivieren (Toggle)
 *  - Domain löschen (Admin)
 *  - Suche / Filtern
 *  - Auditor hat keine Schreibrechte
 */

import { Selector, t as globalT } from 'testcafe';
import { loginAsAdmin, loginAsAuditor, BASE_URL } from '../helpers/auth';
const domains = require('../fixtures/domains.json');

const NEW_DOMAIN = domains.new;

fixture('Domain-Verwaltung – Admin')
    .page(`${BASE_URL}/login`)
    .beforeEach(async t => {
        await loginAsAdmin(t);
    });

// ──────────────────────────────────────────────
// Domain-Liste
// ──────────────────────────────────────────────
test('Domain-Liste zeigt Fixture-Domains an', async t => {
    await t.navigateTo(`${BASE_URL}/domains`);

    const tableRows = Selector('table tbody tr');
    await t
        .expect(tableRows.count).gte(1, 'Es sollten Fixture-Domains in der Tabelle sein')
        .expect(Selector('td').withText('google.com').exists).ok('google.com nicht in der Liste');
});

test('Domain-Liste zeigt Status-Badges an', async t => {
    await t.navigateTo(`${BASE_URL}/domains`);

    await t.expect(Selector('.badge.bg-success').exists).ok('Kein "Aktiv"-Badge gefunden');
});

// ──────────────────────────────────────────────
// Domain anlegen
// ──────────────────────────────────────────────
test('Admin kann neue Domain anlegen', async t => {
    await t.navigateTo(`${BASE_URL}/domains/new`);

    await t
        .typeText('#fqdn', NEW_DOMAIN.fqdn, { replace: true })
        .typeText('#port', NEW_DOMAIN.port, { replace: true })
        .typeText('#description', NEW_DOMAIN.description, { replace: true })
        .click('[type="submit"]');

    // Nach dem Speichern landen wir auf der Domain-Liste
    await t.expect(Selector('td').withText(NEW_DOMAIN.fqdn).exists).ok(`Domain ${NEW_DOMAIN.fqdn} nicht in der Liste nach dem Anlegen`);
});

// ──────────────────────────────────────────────
// Domain bearbeiten
// ──────────────────────────────────────────────
test('Admin kann eine Domain bearbeiten', async t => {
    await t.navigateTo(`${BASE_URL}/domains`);

    // Ersten Bearbeiten-Button klicken
    const editBtn = Selector('a[title="Bearbeiten"]').nth(0);
    await t
        .expect(editBtn.exists).ok('Kein Bearbeiten-Button in der Liste')
        .click(editBtn);

    // Beschreibung ändern
    const descField = Selector('#description');
    await t
        .selectText(descField)
        .typeText(descField, 'Aktualisiert durch E2E-Test', { replace: true })
        .click('[type="submit"]');

    await t.expect(Selector('.alert-success').exists).ok('Keine Erfolgsmeldung nach dem Bearbeiten');
});

// ──────────────────────────────────────────────
// Domain deaktivieren (Toggle)
// ──────────────────────────────────────────────
test('Admin kann Domain deaktivieren und reaktivieren', async t => {
    await t.navigateTo(`${BASE_URL}/domains`);

    // Toggle-Button (Pause-Icon) für eine aktive Domain
    const toggleBtn = Selector('button[title="Deaktivieren"]').nth(0);
    await t
        .expect(toggleBtn.exists).ok('Kein "Deaktivieren"-Button gefunden')
        .click(toggleBtn);

    // Nach Toggle sollte "Inaktiv"-Badge erscheinen
    await t.expect(Selector('.badge.bg-secondary').withText('Inaktiv').exists).ok('Kein "Inaktiv"-Badge nach Deaktivierung');

    // Reaktivieren
    const reactivateBtn = Selector('button[title="Reaktivieren"]').nth(0);
    await t
        .expect(reactivateBtn.exists).ok('Kein "Reaktivieren"-Button nach Deaktivierung')
        .click(reactivateBtn);

    await t.expect(Selector('.badge.bg-success').withText('Aktiv').exists).ok('Kein "Aktiv"-Badge nach Reaktivierung');
});

// ──────────────────────────────────────────────
// Domain löschen
// ──────────────────────────────────────────────
test('Admin kann eine Domain löschen', async t => {
    // Zuerst Testdomain anlegen, damit wir sie löschen können
    await t.navigateTo(`${BASE_URL}/domains/new`);
    await t
        .typeText('#fqdn', 'delete-me.e2e.test', { replace: true })
        .typeText('#port', '443', { replace: true })
        .click('[type="submit"]');

    // Warten bis Domain in der Liste sichtbar ist
    await t.expect(Selector('td').withText('delete-me.e2e.test').exists).ok();

    // Löschen-Button für diese Domain klicken
    const deleteRow = Selector('tr').withText('delete-me.e2e.test');
    const deleteBtn = deleteRow.find('button[title="Löschen"]');

    await t
        .setNativeDialogHandler(() => true) // Browser-Confirm bestätigen
        .click(deleteBtn);

    await t.expect(Selector('td').withText('delete-me.e2e.test').exists).notOk('Domain sollte nach dem Löschen nicht mehr in der Liste sein');
});

// ──────────────────────────────────────────────
// Suche
// ──────────────────────────────────────────────
test('Suche filtert Domains nach FQDN', async t => {
    await t.navigateTo(`${BASE_URL}/domains`);

    const searchInput = Selector('input[name="search"]');
    await t
        .typeText(searchInput, 'google', { replace: true })
        .click('button[type="submit"]');

    await t
        .expect(Selector('td').withText('google.com').exists).ok('google.com sollte in den Suchergebnissen sein')
        .expect(Selector('td').withText('github.com').exists).notOk('github.com sollte nicht in den Suchergebnissen sein');
});

test('Suche ohne Treffer zeigt Hinweismeldung', async t => {
    await t.navigateTo(`${BASE_URL}/domains`);

    const searchInput = Selector('input[name="search"]');
    await t
        .typeText(searchInput, 'xxxxxxnonexistentxxxxxx', { replace: true })
        .click('button[type="submit"]');

    await t.expect(Selector('.alert-warning').exists).ok('Keine Warnmeldung bei leerer Suche');
});

// ──────────────────────────────────────────────
// Auditor hat keine Schreibrechte
// ──────────────────────────────────────────────
fixture('Domain-Verwaltung – Auditor (Schreibschutz)')
    .page(`${BASE_URL}/login`)
    .beforeEach(async t => {
        await loginAsAuditor(t);
    });

test('Auditor sieht keine Admin-Aktionsbuttons', async t => {
    await t.navigateTo(`${BASE_URL}/domains`);

    await t
        .expect(Selector('a').withText('Domain anlegen').exists).notOk('Auditor sieht "Domain anlegen"')
        .expect(Selector('a[title="Bearbeiten"]').exists).notOk('Auditor sieht Bearbeiten-Button')
        .expect(Selector('button[title="Löschen"]').exists).notOk('Auditor sieht Löschen-Button');
});

test('Auditor wird bei direktem Aufruf von /domains/new weitergeleitet', async t => {
    await t.navigateTo(`${BASE_URL}/domains/new`);

    // Sollte auf Domains-Liste oder Fehlermeldung landen, nicht auf dem Formular
    const formExists = Selector('form #fqdn').exists;
    await t.expect(formExists).notOk('Auditor darf das Domain-Formular nicht sehen');
});
