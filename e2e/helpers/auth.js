/**
 * Authentication helpers for TestCafe E2E tests.
 *
 * Uses TestCafe Role API for reliable session management across all tests.
 * Roles are set up once per run and the session is cached, avoiding repeated
 * CSRF form submissions and cookie-clearing race conditions in CI.
 *
 * Uses fixture data from fixtures/users.json.
 */

const { Role, Selector } = require('testcafe');
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
    await t.useRole(Role.anonymous());
    // useRole(anonymous) already navigates back to the fixture page URL.
    // Do NOT call navigateTo('/login') again – the double navigation to the
    // same URL causes a race condition in CI headless Chrome where the second
    // page load wipes out text that was already typed into the form fields.
    // Since all unauthenticated requests redirect to /login via access_control,
    // we always end up on the login form regardless of the fixture page URL.
    const usernameInput = Selector('#username');
    await t.expect(usernameInput.exists).ok({ timeout: 10000 });
    await t
        .typeText(usernameInput, username, { replace: true })
        .typeText('#password', password, { replace: true })
        .click('[type="submit"]');
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
