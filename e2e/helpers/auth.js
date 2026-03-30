/**
 * Authentication helpers for TestCafe E2E tests.
 *
 * Uses TestCafe Role API for reliable session management across all tests.
 * Roles are set up once per run and the session is cached, avoiding repeated
 * CSRF form submissions and cookie-clearing race conditions in CI.
 *
 * Uses fixture data from fixtures/users.json.
 */

const { Role, Selector, ClientFunction } = require('testcafe');
const users = require('../fixtures/users.json');

const BASE_URL = process.env.APP_URL || 'http://localhost:8443';

/**
 * TestCafe Role for the admin user.
 * Performs login once; subsequent useRole() calls restore the cached session.
 */
const adminRole = Role(`${BASE_URL}/login`, async t => {
    await t
        .typeText('#username', users.admin.username, { replace: true })
        .typeText('#password', users.admin.password, { replace: true })
        .click('[type="submit"]');
});

/**
 * TestCafe Role for the auditor user.
 * Performs login once; subsequent useRole() calls restore the cached session.
 */
const auditorRole = Role(`${BASE_URL}/login`, async t => {
    await t
        .typeText('#username', users.auditor.username, { replace: true })
        .typeText('#password', users.auditor.password, { replace: true })
        .click('[type="submit"]');
});

/**
 * Login as admin user.
 * Uses TestCafe Role API – restores the cached admin session on subsequent calls.
 * @param {import('testcafe').TestController} t
 */
async function loginAsAdmin(t) {
    await t.useRole(adminRole);
}

/**
 * Login as auditor user.
 * Uses TestCafe Role API – restores the cached auditor session on subsequent calls.
 * @param {import('testcafe').TestController} t
 */
async function loginAsAuditor(t) {
    await t.useRole(auditorRole);
}

/**
 * Login with arbitrary credentials (e.g. for testing invalid-credentials scenarios).
 * Switches to anonymous role first to clear any existing session, then submits
 * the login form manually.
 * @param {import('testcafe').TestController} t
 * @param {string} username
 * @param {string} password
 */
async function loginWith(t, username, password) {
    // Clear any existing session, then navigate to a fresh login page.
    await t.deleteCookies();
    await t.navigateTo(`${BASE_URL}/login`);

    const usernameInput = Selector('#username');
    await t.expect(usernameInput.exists).ok({ timeout: 10000 });

    // Set form values directly in the DOM via ClientFunction instead of
    // typeText.  On CI headless Chrome, typeText is prone to a race condition
    // where late-loading CDN resources (Bootstrap CSS/JS) trigger a re-layout
    // or soft page refresh that clears already-typed text.  ClientFunction
    // executes synchronously in the browser context after the page is stable,
    // so the values stick reliably.
    const fillForm = ClientFunction((u, p) => {
        document.getElementById('username').value = u;
        document.getElementById('password').value = p;
    });
    await fillForm(username, password);

    // Submit the form via button click (HTML5 required validation passes
    // because the values are already set).
    await t.click('[type="submit"]');
}

/**
 * Logout from the current session.
 * Uses Role.anonymous() instead of navigating to /logout to avoid
 * Symfony's SameOriginCsrfTokenManager rejecting TestCafe's proxy Referer header.
 * @param {import('testcafe').TestController} t
 */
async function logout(t) {
    await t.useRole(Role.anonymous());
}

module.exports = { loginWith, loginAsAdmin, loginAsAuditor, logout, users, BASE_URL };
