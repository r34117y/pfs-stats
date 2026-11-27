// Run against a disposable local database/account. This test imports the supplied tournament.
// npm install --prefix /tmp/club-browser playwright
// PLAYWRIGHT_MODULE=/tmp/club-browser/node_modules/playwright CLUB_IMPORT_AUTH_FILE=... \
// CLUB_IMPORT_HH=... CLUB_IMPORT_STANDINGS=... node tests/Browser/club-tournament-import.cjs
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const auth = JSON.parse(fs.readFileSync(process.env.CLUB_IMPORT_AUTH_FILE, 'utf8'));
    const hh = process.env.CLUB_IMPORT_HH;
    const standings = process.env.CLUB_IMPORT_STANDINGS;
    assert.ok(hh && standings, 'Supply both tournament files');
    const baseURL = process.env.CLUB_IMPORT_BASE_URL || 'http://localhost:8081';
    const organizationId = process.env.CLUB_IMPORT_ORGANIZATION_ID || '4';
    const browser = await chromium.launch({
        executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome',
        headless: true,
        args: ['--no-sandbox'],
    });
    try {
        const context = await browser.newContext({ baseURL });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('/user/tournament-results/add');
        await page.locator('#input-email').fill(auth.email);
        await page.locator('#input-password').fill(auth.password);
        await Promise.all([
            page.waitForURL(url => !url.pathname.endsWith('/login')),
            page.locator('button[type=submit]').click(),
        ]);
        errors.length = 0; // Only collect errors on the import page under test.
        await page.goto('/user/tournament-results/add');
        const organization = page.locator('select[x-model=selectedOrganizationId]');
        await organization.selectOption(organizationId);
        const beforeList = await (await context.request.get(`/api/tournaments?org=${organizationId}`)).json();
        const beforeIds = new Set(beforeList.tournaments.map(tournament => tournament.id));
        const input = page.locator('input[type=file]');
        const preprocessURL = '/api/user/tournament-results/preprocess-club-results';
        const importURL = '/api/user/tournament-results/import-club-results';
        const upload = async files => {
            const response = page.waitForResponse(r => r.url().endsWith(preprocessURL));
            await input.setInputFiles(files);
            return response;
        };
        // HH alone cannot distinguish absence from an awarded pause.
        assert.equal((await upload(hh)).status(), 400);
        const response = await upload([standings, hh]); // File order must not matter.
        assert.equal(response.status(), 200);
        const modal = page.locator('.modal.is-active');
        await modal.waitFor({ state: 'visible' });
        const heading = await modal.locator('h2').innerText();
        assert.ok(heading.startsWith('Blubry'));
        assert.ok(await modal.locator('tbody tr').count() > 0);
        // Cancelling must not write the tournament.
        await modal.getByRole('button', { name: 'Anuluj', exact: true }).click();
        assert.equal((await upload([hh, standings])).status(), 200);
        const imported = page.waitForResponse(r => r.url().endsWith(importURL));
        await modal.locator('button[data-preview-action=confirm]').click();
        const importResponse = await imported;
        assert.equal(importResponse.status(), 200, await importResponse.text());
        const payload = await importResponse.json();
        assert.ok(payload.tournamentId > 0);
        assert.ok(!beforeIds.has(payload.tournamentId));
        const afterList = await (await context.request.get(`/api/tournaments?org=${organizationId}`)).json();
        assert.ok(afterList.tournaments.some(tournament => tournament.id === payload.tournamentId), 'Import must invalidate the primed tournament list cache');
        assert.equal(payload.legacyTournamentId, undefined);
        await page.locator('.message.is-success:visible').waitFor();
        await page.getByRole('link', { name: heading, exact: true }).waitFor();
        assert.equal((await upload([hh, standings])).status(), 409);
        const multipart = {
            organizationId,
            results: { name: 'resultsHH.txt', mimeType: 'text/plain', buffer: fs.readFileSync(hh) },
            standings: { name: 'results.txt', mimeType: 'text/plain', buffer: fs.readFileSync(standings) },
        };
        assert.equal((await context.request.post(importURL, { multipart })).status(), 409);
        assert.equal((await context.request.post(importURL, { multipart: { organizationId } })).status(), 400);
        assert.equal((await context.request.post(importURL, { multipart: { ...multipart, organizationId: '0' } })).status(), 400);
        assert.equal((await context.request.post(importURL, { multipart: { ...multipart, organizationId: '21' } })).status(), 403);
        assert.deepEqual(errors, []);
        if (process.env.CLUB_IMPORT_SCREENSHOT) await page.screenshot({ path: process.env.CLUB_IMPORT_SCREENSHOT, fullPage: true });
        const detail = await page.goto(`/tournaments/${payload.tournamentId}`);
        assert.equal(detail.status(), 200);
        console.log(JSON.stringify({ status: 'passed', tournamentId: payload.tournamentId, heading, players: payload.playersCount, games: payload.gamesCount, checks: ['login', 'missing final standings', 'preview', 'cancel', 'confirm', 'recent imports', 'duplicate prevention', 'missing file', 'invalid organization', 'permissions', 'tournament page', 'cache publication'] }));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
