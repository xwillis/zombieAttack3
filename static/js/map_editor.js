/* Zombie Attack 3 - Map Editor */

let territories = {};  // key -> {name, x, y, adjacent: []}
let nextId = 1;
let mode = 'add';  // add, link, delete
let linkFirst = null;  // first territory selected for linking
const SVG_NS = 'http://www.w3.org/2000/svg';

// ── SVG Click Handler ───────────────────────────────────────────────────────

document.getElementById('editor-svg').addEventListener('click', (e) => {
    if (mode !== 'add') return;
    const svg = document.getElementById('editor-svg');
    const pt = svg.createSVGPoint();
    pt.x = e.clientX;
    pt.y = e.clientY;
    const svgPt = pt.matrixTransform(svg.getScreenCTM().inverse());

    const key = `t${nextId}`;
    const name = prompt('Territory name:', `Territory ${nextId}`);
    if (!name) return;

    territories[key] = {
        name: name,
        x: Math.round(svgPt.x),
        y: Math.round(svgPt.y),
        adjacent: [],
    };
    nextId++;
    renderEditor();
});

// ── Mode ────────────────────────────────────────────────────────────────────

function setMode(m) {
    mode = m;
    linkFirst = null;
    document.getElementById('btn-add-mode').className = `btn ${m === 'add' ? 'btn-primary' : 'btn-secondary'}`;
    document.getElementById('btn-link-mode').className = `btn ${m === 'link' ? 'btn-primary' : 'btn-secondary'}`;
    document.getElementById('btn-delete-mode').className = `btn ${m === 'delete' ? 'btn-danger' : 'btn-secondary'}`;
    setStatus(m === 'add' ? 'Click canvas to add territory' :
              m === 'link' ? 'Click two territories to link them' :
              'Click a territory to delete it');
}

// ── Rendering ───────────────────────────────────────────────────────────────

function renderEditor() {
    const linesGroup = document.getElementById('editor-lines');
    const terrGroup = document.getElementById('editor-territories');
    linesGroup.innerHTML = '';
    terrGroup.innerHTML = '';

    // Draw links
    const drawn = new Set();
    for (const [key, t] of Object.entries(territories)) {
        for (const adj of t.adjacent) {
            const pairKey = [key, adj].sort().join('-');
            if (drawn.has(pairKey)) continue;
            drawn.add(pairKey);
            const other = territories[adj];
            if (!other) continue;
            const line = document.createElementNS(SVG_NS, 'line');
            line.setAttribute('x1', t.x);
            line.setAttribute('y1', t.y);
            line.setAttribute('x2', other.x);
            line.setAttribute('y2', other.y);
            line.setAttribute('class', 'adjacency-line');
            linesGroup.appendChild(line);
        }
    }

    // Draw territories
    for (const [key, t] of Object.entries(territories)) {
        const g = document.createElementNS(SVG_NS, 'g');

        const circle = document.createElementNS(SVG_NS, 'circle');
        circle.setAttribute('cx', t.x);
        circle.setAttribute('cy', t.y);
        circle.setAttribute('r', 20);
        circle.setAttribute('fill', linkFirst === key ? '#4ecca3' : '#3498db');
        circle.setAttribute('stroke', '#1a1a2e');
        circle.setAttribute('stroke-width', 2);
        circle.setAttribute('class', 'territory-circle');
        circle.addEventListener('click', (e) => {
            e.stopPropagation();
            onEditorTerritoryClick(key);
        });
        g.appendChild(circle);

        const label = document.createElementNS(SVG_NS, 'text');
        label.setAttribute('x', t.x);
        label.setAttribute('y', t.y - 25);
        label.setAttribute('class', 'territory-label');
        label.textContent = t.name;
        g.appendChild(label);

        const idLabel = document.createElementNS(SVG_NS, 'text');
        idLabel.setAttribute('x', t.x);
        idLabel.setAttribute('y', t.y + 4);
        idLabel.setAttribute('class', 'territory-forces');
        idLabel.textContent = t.adjacent.length;
        g.appendChild(idLabel);

        terrGroup.appendChild(g);
    }

    // Update count
    document.getElementById('territory-count').textContent = Object.keys(territories).length;

    // Update list
    const listEl = document.getElementById('territory-list');
    listEl.innerHTML = Object.entries(territories).map(([key, t]) =>
        `<div class="territory-list-item">
            <span>${t.name} (${t.adjacent.length} links)</span>
            <button class="btn btn-danger btn-sm" onclick="deleteTerritory('${key}')">×</button>
        </div>`
    ).join('');
}

// ── Territory Click ─────────────────────────────────────────────────────────

function onEditorTerritoryClick(key) {
    if (mode === 'delete') {
        deleteTerritory(key);
    } else if (mode === 'link') {
        if (!linkFirst) {
            linkFirst = key;
            setStatus(`Selected ${territories[key].name}. Click another to link.`);
            renderEditor();
        } else if (linkFirst === key) {
            linkFirst = null;
            setStatus('Link cancelled.');
            renderEditor();
        } else {
            // Toggle link
            if (territories[linkFirst].adjacent.includes(key)) {
                // Remove link
                territories[linkFirst].adjacent = territories[linkFirst].adjacent.filter(a => a !== key);
                territories[key].adjacent = territories[key].adjacent.filter(a => a !== linkFirst);
                setStatus(`Unlinked ${territories[linkFirst].name} ↔ ${territories[key].name}`);
            } else {
                // Add link
                territories[linkFirst].adjacent.push(key);
                territories[key].adjacent.push(linkFirst);
                setStatus(`Linked ${territories[linkFirst].name} ↔ ${territories[key].name}`);
            }
            linkFirst = null;
            renderEditor();
        }
    }
}

function deleteTerritory(key) {
    // Remove all references
    for (const [k, t] of Object.entries(territories)) {
        t.adjacent = t.adjacent.filter(a => a !== key);
    }
    delete territories[key];
    setStatus(`Deleted territory.`);
    renderEditor();
}

// ── Save Map ────────────────────────────────────────────────────────────────

async function saveMap() {
    const name = document.getElementById('map-name').value.trim();
    if (!name) {
        setStatus('Please enter a map name.');
        return;
    }
    if (Object.keys(territories).length < 6) {
        setStatus('Need at least 6 territories.');
        return;
    }

    // Check all territories have at least 1 link
    for (const [key, t] of Object.entries(territories)) {
        if (t.adjacent.length === 0) {
            setStatus(`${t.name} has no links. Link all territories.`);
            return;
        }
    }

    const mapData = { territories: territories };
    try {
        const resp = await fetch('/api/maps', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: name, map_data: mapData }),
        });
        const data = await resp.json();
        if (data.success) {
            setStatus(`Map "${data.name}" saved! You can now use it when creating games.`);
        } else {
            setStatus(`Error: ${data.error}`);
        }
    } catch (e) {
        setStatus('Failed to save map.');
    }
}

function clearMap() {
    if (Object.keys(territories).length > 0 && !confirm('Clear all territories?')) return;
    territories = {};
    nextId = 1;
    linkFirst = null;
    renderEditor();
    setStatus('Map cleared.');
}

function setStatus(msg) {
    document.getElementById('editor-status').textContent = msg;
}

// Initial render
setMode('add');
renderEditor();
