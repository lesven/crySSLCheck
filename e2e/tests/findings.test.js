/**
 * E2E Tests: Findings-Übersicht
 *
 * Covers:
 *  - Findings-Liste zeigt Fixture-Daten (verschiedene Severity-Badges)
 *  - Filter: Nur Probleme anzeigen (problems_only)
 *  - Filter: Domain-Suche
 *  - Severity-Badge-Klassen entsprechen den Enum-Werten
 */

import { Selector } from 'testcafe';
import { loginAsAdmin, loginAsAuditor, BASE_URL } from '../helpers/auth';

// ──────────────────────────────────────────────
// Admin sieht alle Findings
// ──────────────────────────────────────────────
fixture('Findings – Übersicht (Admin)')
    .page(`${BASE_URL}/login`)
    .beforeEach(async t => {
        await loginAsAdmin(t);
        await t.navigateTo(`${BASE_URL}/findings`);
    });

test('Findings-Seite wird geladen und zeigt Tabelle', async t => {
    await t
        .expect(Selector('h2').withText('Findings').exists).ok('Überschrift "Findings" nicht gefunden')
        .expect(Selector('table').exists).ok('Findings-Tabelle fehlt');
});

test('Fixture-Domains erscheinen in der Findings-Tabelle', async t => {
    await t
        .expect(Selector('td').withText('google.com').exists).ok('google.com-Finding fehlt')
        .expect(Selector('td').withText('expired.badssl.com').exists).ok('expired.badssl.com-Finding fehlt');
});

test('Severity-Badges sind in der Tabelle sichtbar', async t => {
    // Es sollten Badges für verschiedene Schweregrade da sein (aus Fixtures)
    await t
        .expect(Selector('.badge.bg-success').exists).ok('Kein "ok"-Badge gefunden')
        .expect(Selector('.badge.bg-danger').exists).ok('Kein "critical"-Badge gefunden');
});

test('Filter "Nur Probleme" blendet OK-Findings aus', async t => {
    // Ohne Filter: OK-Findings sichtbar
    const okCell = Selector('td').withText('OK');
    await t.expect(okCell.exists).ok('Ohne Filter sollten OK-Findings sichtbar sein');

    // Filter über URL aktivieren – zuverlässiger als onchange-basierte Formularübermittlung in CI
    await t.navigateTo(`${BASE_URL}/findings?problems_only=1`);
    await t.expect(Selector('td').withText('OK').exists).notOk('OK-Findings sollten mit "Nur Probleme"-Filter ausgeblendet sein');
});

test('Domain-Suche filtert Findings nach FQDN', async t => {
    // Suche über URL – zuverlässiger als Formularübermittlung in CI
    await t.navigateTo(`${BASE_URL}/findings?search=expired.badssl.com`);

    await t
        .expect(Selector('td').withText('expired.badssl.com').exists).ok('expired.badssl.com sollte in den gefilterten Findings sein')
        .expect(Selector('td').withText('google.com').exists).notOk('google.com sollte nicht in den nach expired.badssl.com gefilterten Findings sein');
});

test('Leere Suche zeigt Hinweismeldung', async t => {
    // Suche über URL – zuverlässiger als Formularübermittlung in CI
    await t.navigateTo(`${BASE_URL}/findings?search=nichtvorhandenexyz123`);

    await t.expect(Selector('.alert-info').exists).ok('Keine Hinweismeldung bei leerem Suchergebnis');
});

test('Status-Badges entsprechen den Fixture-Werten', async t => {
    // new → bg-danger, known → bg-warning, resolved → bg-success
    await t
        .expect(Selector('.badge.bg-danger').exists).ok('Status "new" Badge (bg-danger) fehlt')
        .expect(Selector('.badge.bg-warning').exists).ok('Status "known" Badge (bg-warning) fehlt')
        .expect(Selector('.badge.bg-success').exists).ok('Status "resolved" Badge (bg-success) fehlt');
});

// ──────────────────────────────────────────────
// Auditor sieht Findings (read-only)
// ──────────────────────────────────────────────
fixture('Findings – Auditor (read-only)')
    .page(`${BASE_URL}/login`)
    .beforeEach(async t => {
        await loginAsAuditor(t);
        await t.navigateTo(`${BASE_URL}/findings`);
    });

test('Auditor kann Findings lesen', async t => {
    await t
        .expect(Selector('h2').withText('Findings').exists).ok('Auditor kann Findings-Seite nicht aufrufen')
        .expect(Selector('table').exists).ok('Findings-Tabelle für Auditor nicht sichtbar');
});

test('Auditor sieht keinen Lösch-Button in der Findings-Tabelle', async t => {
    await t
        .expect(Selector('form.domain-delete-form').exists).notOk('Auditor darf keinen Lösch-Button sehen');
});

// ──────────────────────────────────────────────
// Admin: Domain direkt aus Findings löschen
// ──────────────────────────────────────────────
fixture('Findings – Domain löschen (Admin)')
    .page(`${BASE_URL}/login`)
    .beforeEach(async t => {
        await loginAsAdmin(t);
    });

test('Admin kann Domain direkt aus der Findings-Tabelle löschen', async t => {
    // Zunächst eine Domain anlegen, die anschließend gelöscht werden soll
    const newFqdn = 'e2e-delete-test.example.com';

    await t.navigateTo(`${BASE_URL}/domains/new`);
    await t.wait(500);
    await t
        .typeText(Selector('#fqdn'), newFqdn)
        .typeText(Selector('#port'), '443')
        .click(Selector('button[type="submit"]'));
    await t.wait(1000);

    // Scan simulieren ist im E2E-Kontext nicht möglich, daher prüfen wir nur
    // dass der Lösch-Button auf der Findings-Seite für Admins grundsätzlich sichtbar ist
    await t.navigateTo(`${BASE_URL}/findings`);
    await t.wait(1000);

    await t
        .expect(Selector('form.domain-delete-form').exists).ok('Lösch-Button für Admin in Findings-Tabelle nicht vorhanden');
});
