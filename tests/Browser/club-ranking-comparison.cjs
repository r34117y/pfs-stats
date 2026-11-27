// Read-only browser regression: Sanidyn must compare with Amsonia, then navigate to it.
// PLAYWRIGHT_MODULE=/tmp/club-browser/node_modules/playwright node tests/Browser/club-ranking-comparison.cjs
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');

(async () => {
    const baseURL = process.env.CLUB_RANKING_BASE_URL || 'http://localhost:8081';
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const waitRanking = () => page.waitForResponse(response => new URL(response.url()).pathname === '/api/ranking');
        const response = waitRanking();
        await page.goto(`${baseURL}/?org=4`);
        const ranking = await (await response).json();
        assert.equal(ranking.lastTournamentName, 'Blubry565_Sanidyn');
        assert.equal(ranking.comparisonTournamentName, 'Blubry564_Amsonia');
        assert.equal(ranking.previousTournamentId, ranking.comparisonTournamentId);
        assert.equal(ranking.comparisonAvailable, true);
        const leader = ranking.rows.find(row => row.nameShow === 'Jakub Błażejewski');
        assert.equal(leader.rankDelta, '2.70');
        assert.equal(ranking.rows.find(row => row.nameShow === 'Adam Grzelec').rankDelta, '0.00');
        await page.locator('.ranking-comparison').filter({ hasText: 'Blubry564_Amsonia' }).waitFor({ state: 'visible' });
        const leaderRow = page.getByRole('row').filter({ hasText: 'Jakub Błażejewski' });
        assert.equal(await leaderRow.locator('td').nth(4).innerText(), '+2.70');
        const previousResponse = waitRanking();
        await page.getByRole('button', { name: 'Poprzedni turniej', exact: true }).click();
        const previous = await (await previousResponse).json();
        assert.equal(previous.lastTournamentName, 'Blubry564_Amsonia');
        assert.equal(previous.comparisonTournamentName, 'Blubry563_Kabeltaw');
        assert.equal(previous.gameCountsAvailable, false);
        assert.ok(previous.rows.every(row => row.numberOfGames == null));
        await page.getByText('Źródło nie podaje liczby partii w tym zestawieniu.', { exact: true }).waitFor({ state: 'visible' });
        assert.equal(await page.getByRole('row').filter({ hasText: 'Jakub Błażejewski' }).locator('td').nth(3).innerText(), '-');
        const nextResponse = waitRanking();
        await page.getByRole('button', { name: 'Następny turniej', exact: true }).click();
        assert.equal((await (await nextResponse).json()).lastTournamentName, 'Blubry565_Sanidyn');
        assert.deepEqual(errors, []);
        if (process.env.CLUB_RANKING_SCREENSHOT) await page.screenshot({ path: process.env.CLUB_RANKING_SCREENSHOT, fullPage: true });
        console.log(JSON.stringify({ baseURL, current: ranking.lastTournamentName, comparison: ranking.comparisonTournamentName, leaderDelta: leader.rankDelta, previousNavigation: previous.lastTournamentName, missingGamesShownAsUnknown: true, pageErrors: errors }));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
