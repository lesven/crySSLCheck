/**
 * Authentication helpers for TestCafe E2E tests.
 *
 * Provides reusable login/logout actions and role-based setup.
 * Uses fixture data from fixtures/users.json.
 */

const users = require('../fixtures/users.json');

const BASE_URL = process.env.APP_URL || 'http://localhost:8000';

/**
 * Login with given credentials.
 * @param {import('testcafe').TestController} t
 * @param {string} username
 * @param {string} password
 */
async function loginWith(t, username, password) {
    await t
        .navigateTo(`${BASE_URL}/login`)
        .typeText('#username', username, { replace: true })
        .typeText('#password', password, { replace: true })
        .click('[type="submit"]');
}

/**
 * Login as admin user.
 * @param {import('testcafe').TestController} t
 */
async function loginAsAdmin(t) {
    await loginWith(t, users.admin.username, users.admin.password);
}

/**
 * Login as auditor user.
 * @param {import('testcafe').TestController} t
 */
async function loginAsAuditor(t) {
    await loginWith(t, users.auditor.username, users.auditor.password);
}

/**
 * Logout from the current session.
 * @param {import('testcafe').TestController} t
 */
async function logout(t) {
    await t.navigateTo(`${BASE_URL}/logout`);
}

module.exports = { loginWith, loginAsAdmin, loginAsAuditor, logout, users, BASE_URL };
