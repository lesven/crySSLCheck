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
    // Properly clear TestCafe's role system state so cached role cookies are
    // not silently re-injected during subsequent actions.
    await t.useRole(Role.anonymous());
    await t.navigateTo(`${BASE_URL}/login`);

    // Wait for the login form to be present in the DOM.
    const usernameInput = Selector('#username');
    await t.expect(usernameInput.exists).ok({ timeout: 10000 });

    // Extra stabilisation pause: in CI headless Chrome, useRole(anonymous) +
    // navigateTo in quick succession can leave the page in a transitional state
    // where the DOM is present but a pending navigation / re-render is about to
    // replace it.  A short wait lets the browser settle.
    await t.wait(2000);
    // Re-verify the form is still there after the wait (catches late page reload).
    await t.expect(usernameInput.exists).ok({ timeout: 5000 });

    // Fill form values AND submit in a single synchronous JavaScript execution.
    // This is the only approach that is immune to race conditions between
    // "fill" and "submit" — there is zero time gap where a concurrent page
    // navigation could wipe values.  form.submit() also bypasses HTML5
    // constraint validation, so empty-value edge-cases cannot block the POST.
    const fillAndSubmit = ClientFunction((u, p) => {
        const form = document.querySelector('form');
        form.querySelector('#username').value = u;
        form.querySelector('#password').value = p;
        form.submit();
    });
    await fillAndSubmit(username, password);

    // form.submit() triggers a navigation that TestCafe may not automatically
    // track (unlike click-triggered submissions).  Wait for the target page
    // to fully load by checking for a known DOM element.
    await t.expect(Selector('#username').exists).ok({ timeout: 15000 });
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

/**
 * Fill multiple form fields by setting their DOM values directly.
 *
 * On CI headless Chrome, TestCafe's typeText is unreliable on freshly
 * navigated pages: late-loading CDN resources (Bootstrap CSS/JS) can trigger
 * a re-render that clears already-typed text. Setting .value directly via
 * ClientFunction is atomic and immune to this race condition.
 *
 * @param {Object<string, string>} fields  Mapping of CSS selector → value
 */
const fillFields = ClientFunction((fields) => {
    for (const [selector, value] of Object.entries(fields)) {
        const el = document.querySelector(selector);
        if (el) el.value = value;
    }
});

/**
 * Submit the first form on the page via JavaScript.
 *
 * In CI headless Chrome, TestCafe's click() on a submit button after
 * ClientFunction-based field filling is unreliable — the click either
 * doesn't register or triggers before the DOM state is fully committed.
 * form.submit() called from a ClientFunction is synchronous and bypasses
 * HTML5 constraint validation, guaranteeing the POST is sent.
 */
const submitForm = ClientFunction(() => {
    document.querySelector('form').submit();
});

module.exports = { loginWith, loginAsAdmin, loginAsAuditor, logout, fillFields, submitForm, users, BASE_URL };
