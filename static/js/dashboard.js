/* Dashboard player search */

let searchTimeout;

function searchPlayers(query) {
    clearTimeout(searchTimeout);
    const container = document.getElementById('search-results');

    if (query.length < 2) {
        container.innerHTML = '';
        return;
    }

    searchTimeout = setTimeout(async () => {
        try {
            const resp = await fetch('/api/players/search?q=' + encodeURIComponent(query));
            const players = await resp.json();
            container.innerHTML = players.map(p =>
                `<div class="search-result-item">
                    <span>${escapeHtml(p.username)}</span>
                    <span class="text-muted">ID: ${p.id}</span>
                </div>`
            ).join('');

            if (players.length === 0) {
                container.innerHTML = '<div class="search-result-item text-muted">No players found.</div>';
            }
        } catch (e) {
            container.innerHTML = '<div class="search-result-item text-muted">Search failed.</div>';
        }
    }, 300);
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
