/* Zombie Attack 3 - Game Client */

const socket = io();
let gameState = null;
let selectedTerritory = null;
let secondTerritory = null;  // for attack/fortify target
let deployRemaining = 0;

// ── Socket Setup ────────────────────────────────────────────────────────────

socket.emit('join_game', { game_id: GAME_ID });

socket.on('game_update', (state) => {
    gameState = state;
    renderMap();
    renderTurnInfo();
    renderPlayerList();
    renderActions();
});

socket.on('combat_result', (result) => {
    let msg = `Combat: Lost ${result.att_losses} attacker, ${result.def_losses} defender.`;
    if (result.zombie_gains > 0) msg += ` +${result.zombie_gains} zombies!`;
    if (result.captured) msg += ' Territory captured!';
    if (result.zombie_takeover) msg += ' ZOMBIES TAKE OVER!';
    addLog(msg);
});

socket.on('zombie_moves', (data) => {
    for (const m of data.moves) {
        let msg = `🧟 Zombies move from ${m.from} → ${m.to} (${m.forces || '?'} forces, ${m.type})`;
        if (m.outcome) msg += ` [${m.outcome}]`;
        addLog(msg);
    }
});

socket.on('game_over', (data) => {
    const winner = gameState?.players?.[data.winner_id];
    const name = winner ? winner.username : 'Unknown';
    addLog(`🏆 GAME OVER! Winner: ${name}`);
    alert(`Game Over! Winner: ${name}`);
});

socket.on('chat_message', (data) => {
    addChat(data.username, data.message);
});

socket.on('error', (data) => {
    addLog(`⚠ ${data.message}`);
});

// ── Initial Load ────────────────────────────────────────────────────────────

async function loadState() {
    const resp = await fetch(`/api/game/${GAME_ID}/state`);
    gameState = await resp.json();
    renderMap();
    renderTurnInfo();
    renderPlayerList();
    renderActions();
}
loadState();

// ── Map Rendering ───────────────────────────────────────────────────────────

function renderMap() {
    if (!gameState) return;
    const svg = document.getElementById('map-svg');
    const linesGroup = document.getElementById('adjacency-lines');
    const terrGroup = document.getElementById('territories');

    linesGroup.innerHTML = '';
    terrGroup.innerHTML = '';

    const territories = gameState.territories;
    const players = gameState.players;

    // Draw adjacency lines
    const drawn = new Set();
    for (const [key, t] of Object.entries(territories)) {
        for (const adj of t.adjacent) {
            const pairKey = [key, adj].sort().join('-');
            if (drawn.has(pairKey)) continue;
            drawn.add(pairKey);
            const other = territories[adj];
            if (!other) continue;
            const line = svgEl('line', {
                x1: t.x, y1: t.y, x2: other.x, y2: other.y,
                class: 'adjacency-line'
            });
            linesGroup.appendChild(line);
        }
    }

    // Draw territories
    for (const [key, t] of Object.entries(territories)) {
        const g = svgEl('g', {});

        let color = NEUTRAL_COLOR;
        if (t.is_zombie) {
            color = ZOMBIE_COLOR;
        } else if (t.owner_id && players[t.owner_id]) {
            color = players[t.owner_id].color;
        }

        const radius = Math.max(18, Math.min(30, 15 + Math.sqrt(t.forces) * 2));
        const circle = svgEl('circle', {
            cx: t.x, cy: t.y, r: radius,
            fill: color, stroke: '#1a1a2e', 'stroke-width': 2,
            class: 'territory-circle',
            'data-key': key,
        });

        if (key === selectedTerritory) {
            circle.setAttribute('stroke', '#ffffff');
            circle.setAttribute('stroke-width', '3');
        } else if (key === secondTerritory) {
            circle.setAttribute('stroke', '#ff6b6b');
            circle.setAttribute('stroke-width', '3');
        }

        circle.addEventListener('click', () => onTerritoryClick(key));
        g.appendChild(circle);

        // Forces count
        const forces = svgEl('text', {
            x: t.x, y: t.y + 4,
            class: 'territory-forces',
        });
        forces.textContent = t.forces;
        g.appendChild(forces);

        // Name label
        const label = svgEl('text', {
            x: t.x, y: t.y - radius - 5,
            class: t.is_zombie ? 'zombie-label' : 'territory-label',
        });
        label.textContent = t.is_zombie ? '🧟 ' + t.name : t.name;
        g.appendChild(label);

        terrGroup.appendChild(g);
    }
}

function svgEl(tag, attrs) {
    const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (const [k, v] of Object.entries(attrs)) {
        el.setAttribute(k, v);
    }
    return el;
}

// ── Territory Click ─────────────────────────────────────────────────────────

function onTerritoryClick(key) {
    const phase = gameState.turn_phase;
    const isMyTurn = gameState.current_turn_user_id === MY_USER_ID;

    if (!isMyTurn) {
        selectedTerritory = key;
        secondTerritory = null;
        renderMap();
        renderTerritoryInfo(key);
        return;
    }

    if (phase === 'deploy') {
        selectedTerritory = key;
        secondTerritory = null;
        renderMap();
        renderTerritoryInfo(key);
        renderActions();
    } else if (phase === 'attack' || phase === 'fortify') {
        if (!selectedTerritory) {
            selectedTerritory = key;
        } else if (key === selectedTerritory) {
            selectedTerritory = null;
            secondTerritory = null;
        } else {
            secondTerritory = key;
        }
        renderMap();
        if (selectedTerritory) renderTerritoryInfo(selectedTerritory);
        renderActions();
    }
}

// ── Render Functions ────────────────────────────────────────────────────────

function renderTurnInfo() {
    if (!gameState) return;
    const el = document.getElementById('turn-info');
    const current = gameState.players[gameState.current_turn_user_id];
    const isMe = gameState.current_turn_user_id === MY_USER_ID;

    if (gameState.status === 'finished') {
        const winner = gameState.players[gameState.winner_id];
        el.innerHTML = `<strong>Game Over!</strong><br>Winner: ${winner ? winner.username : 'None'}`;
        return;
    }

    el.innerHTML = `
        <div>Turn ${gameState.turn_number} &middot; Phase: <strong>${gameState.turn_phase}</strong></div>
        <div class="${isMe ? 'turn-indicator' : ''}">
            ${isMe ? '▶ YOUR TURN' : `Waiting: ${current ? current.username : '?'}`}
        </div>
        ${gameState.turn_phase === 'deploy' && isMe
            ? `<div class="mt-1">Deploy: <span class="deploy-badge">${deployRemaining}</span></div>`
            : ''}
    `;

    if (gameState.turn_phase === 'deploy' && isMe && deployRemaining === 0) {
        deployRemaining = gameState.deploy_count;
    }
}

function renderTerritoryInfo(key) {
    const el = document.getElementById('territory-info');
    if (!key || !gameState?.territories[key]) {
        el.innerHTML = '<p class="text-muted">Click a territory.</p>';
        return;
    }
    const t = gameState.territories[key];
    const owner = t.is_zombie ? '🧟 Zombie' :
                  (t.owner_id && gameState.players[t.owner_id]) ? gameState.players[t.owner_id].username :
                  'Neutral';
    el.innerHTML = `
        <div class="info-name">${t.name}</div>
        <div>Owner: ${owner}</div>
        <div class="info-forces">Forces: ${t.forces}</div>
    `;
}

function renderPlayerList() {
    if (!gameState) return;
    const el = document.getElementById('player-list');
    let html = '';
    for (const [uid, p] of Object.entries(gameState.players)) {
        const isTurn = parseInt(uid) === gameState.current_turn_user_id;
        html += `<div class="player-item ${p.is_alive ? '' : 'eliminated'}">
            <span class="player-color" style="background:${p.color}"></span>
            <span>${p.username} (${p.character})</span>
            ${isTurn ? '<span class="turn-indicator">◀</span>' : ''}
        </div>`;
    }
    el.innerHTML = html;
}

function renderActions() {
    const el = document.getElementById('action-panel');
    if (!gameState || gameState.status === 'finished') {
        el.innerHTML = '<a href="/dashboard" class="btn btn-secondary">Back to Dashboard</a>';
        return;
    }

    const isMyTurn = gameState.current_turn_user_id === MY_USER_ID;
    if (!isMyTurn) {
        el.innerHTML = '<p class="text-muted">Waiting for opponent...</p>';
        return;
    }

    const phase = gameState.turn_phase;
    let html = '';

    if (phase === 'deploy') {
        const t = selectedTerritory ? gameState.territories[selectedTerritory] : null;
        const canDeploy = t && t.owner_id === MY_USER_ID && !t.is_zombie && deployRemaining > 0;
        html += `<button class="btn btn-primary" ${canDeploy ? '' : 'disabled'}
                    onclick="doDeploy()">Deploy to Selected (${deployRemaining} left)</button>`;
        html += `<button class="btn btn-secondary" onclick="doEndDeploy()">End Deploy Phase</button>`;
    } else if (phase === 'attack') {
        const canAttack = selectedTerritory && secondTerritory;
        html += `<button class="btn btn-danger" ${canAttack ? '' : 'disabled'}
                    onclick="doAttack()">Attack!</button>`;
        html += `<button class="btn btn-secondary" onclick="doEndAttack()">End Attack Phase</button>`;
        html += `<button class="btn btn-secondary" onclick="doEndTurn()">End Turn</button>`;
    } else if (phase === 'fortify') {
        const canFortify = selectedTerritory && secondTerritory;
        html += `<div class="form-group">
            <label>Forces to move</label>
            <input type="number" id="fortify-count" min="1" value="1" style="width:80px;">
        </div>`;
        html += `<button class="btn btn-primary" ${canFortify ? '' : 'disabled'}
                    onclick="doFortify()">Move Forces</button>`;

        // Tactician ability
        if (MY_CHARACTER === 'tactician') {
            html += `<button class="btn btn-secondary" ${canFortify ? '' : 'disabled'}
                        onclick="doAbility('tactician_move')">Tactician: Extra Move</button>`;
        }
        // Ranger ability
        if (MY_CHARACTER === 'ranger') {
            html += `<button class="btn btn-secondary" ${canFortify ? '' : 'disabled'}
                        onclick="doAbility('ranger_move')">Ranger: Extended Move</button>`;
        }

        html += `<button class="btn btn-secondary" onclick="doEndTurn()">End Turn</button>`;
    }

    el.innerHTML = html;
}

// ── Game Actions ────────────────────────────────────────────────────────────

function doDeploy() {
    if (!selectedTerritory || deployRemaining <= 0) return;
    socket.emit('deploy', {
        game_id: GAME_ID,
        territory_key: selectedTerritory,
        count: 1,
    });
    deployRemaining--;
    renderTurnInfo();
    renderActions();
}

function doEndDeploy() {
    socket.emit('end_deploy', { game_id: GAME_ID });
    deployRemaining = 0;
}

function doAttack() {
    if (!selectedTerritory || !secondTerritory) return;
    socket.emit('attack', {
        game_id: GAME_ID,
        from_key: selectedTerritory,
        to_key: secondTerritory,
    });
}

function doEndAttack() {
    socket.emit('end_attack', { game_id: GAME_ID });
    selectedTerritory = null;
    secondTerritory = null;
}

function doFortify() {
    if (!selectedTerritory || !secondTerritory) return;
    const count = parseInt(document.getElementById('fortify-count')?.value || '1');
    socket.emit('fortify', {
        game_id: GAME_ID,
        from_key: selectedTerritory,
        to_key: secondTerritory,
        count: count,
    });
    selectedTerritory = null;
    secondTerritory = null;
}

function doAbility(abilityType) {
    if (!selectedTerritory || !secondTerritory) return;
    const count = parseInt(document.getElementById('fortify-count')?.value || '1');
    socket.emit('use_ability', {
        game_id: GAME_ID,
        ability_type: abilityType,
        from_key: selectedTerritory,
        to_key: secondTerritory,
        count: count,
    });
}

function doEndTurn() {
    socket.emit('end_turn', { game_id: GAME_ID });
    selectedTerritory = null;
    secondTerritory = null;
    deployRemaining = 0;
}

// ── Chat ────────────────────────────────────────────────────────────────────

function sendChat() {
    const input = document.getElementById('chat-input');
    const msg = input.value.trim();
    if (!msg) return;
    socket.emit('chat_message', { game_id: GAME_ID, message: msg });
    input.value = '';
}

function addChat(username, message) {
    const box = document.getElementById('chat-box');
    const div = document.createElement('div');
    div.className = 'chat-msg';
    div.innerHTML = `<span class="chat-user">${escapeHtml(username)}:</span> ${escapeHtml(message)}`;
    box.appendChild(div);
    box.scrollTop = box.scrollHeight;
}

function addLog(message) {
    addChat('System', message);
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
