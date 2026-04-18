// Office Heist — a small puzzle/stealth game.
// Top-down office navigation with a patrolling guard.
// Enter a cubicle (mouse view) -> click the computer (login view) -> log in to win.

(() => {
  "use strict";

  // ---------- Constants ----------
  const CANVAS_W = 960;
  const CANVAS_H = 640;

  // Credentials the player must enter to win.
  const CREDENTIALS = { username: "admin", password: "hunter2" };

  // Colors
  const COLORS = {
    floor: "#cfd3dc",
    floorAlt: "#c1c6d0",
    wall: "#3a445c",
    wallDark: "#2b3244",
    cubicleWall: "#6b5a44",
    cubicleWallDark: "#4a3f2e",
    desk: "#8e6b45",
    deskEdge: "#4a3620",
    player: "#5aa9ff",
    playerOutline: "#0b3d72",
    guard: "#f04848",
    guardOutline: "#6a0c0c",
    cone: "rgba(255, 80, 80, 0.22)",
    coneEdge: "rgba(255, 80, 80, 0.45)",
    door: "#7cf3a3",
    hint: "#ffe680",
  };

  // Player config
  const PLAYER = {
    radius: 12,
    speed: 170, // px/sec
  };

  // Guard config
  const GUARD = {
    radius: 14,
    speed: 75, // px/sec along patrol
    viewDistance: 180,
    viewAngle: Math.PI / 3.2, // ~56deg FOV
  };

  // ---------- State ----------
  const state = {
    mode: "office", // "office" | "cubicle" | "login" | "gameover" | "win"
    activeCubicle: null, // id of cubicle currently occupied
    keys: new Set(),
    player: { x: 140, y: 320, dir: 0 },
    guard: {
      patrolT: 0, // param along patrol path
      x: 0,
      y: 0,
      dir: 0,
    },
    lastTime: performance.now(),
    detectionMeter: 0, // 0..1 "suspicion"; instant loss when >= 1
  };

  // ---------- Geometry ----------
  // Room bounds (inside outer walls)
  const ROOM = { x: 20, y: 20, w: CANVAS_W - 40, h: CANVAS_H - 40 };

  // The "island" of 4 cubicles sits in the middle of the room.
  // Each cubicle is a 3-walled box opening toward the center-most side is
  // unnecessary here; we open each cubicle to the outside so the player can
  // walk in from the surrounding hallway. The 4 cubicles share a central core.
  const ISLAND = { cx: CANVAS_W / 2, cy: CANVAS_H / 2, w: 300, h: 220 };

  // Each cubicle occupies one quadrant of the island and opens outward.
  // "opening" indicates which side of the cubicle is the door (the side facing
  // outward from the island).
  //   TL cubicle opens to the LEFT (west)
  //   TR cubicle opens to the RIGHT (east)
  //   BL cubicle opens to the LEFT (west)
  //   BR cubicle opens to the RIGHT (east)
  // Desks sit along the inner wall of each cubicle, with the computer on top.
  const CUBICLES = buildCubicles();

  function buildCubicles() {
    const halfW = ISLAND.w / 2;
    const halfH = ISLAND.h / 2;
    const cx = ISLAND.cx;
    const cy = ISLAND.cy;

    // Each cubicle is a rect.
    const TL = { id: "TL", x: cx - halfW, y: cy - halfH, w: halfW, h: halfH, opens: "W" };
    const TR = { id: "TR", x: cx,         y: cy - halfH, w: halfW, h: halfH, opens: "E" };
    const BL = { id: "BL", x: cx - halfW, y: cy,         w: halfW, h: halfH, opens: "W" };
    const BR = { id: "BR", x: cx,         y: cy,         w: halfW, h: halfH, opens: "E" };
    const cubicles = [TL, TR, BL, BR];

    // Precompute each cubicle's walls (as rectangles) leaving the opening clear.
    // Wall thickness:
    const T = 6;
    // Opening size (door) along the open side:
    const DOOR = 48;

    for (const c of cubicles) {
      c.walls = [];
      c.doorRect = null;
      // Four potential walls: N,S,E,W
      const N = { x: c.x,         y: c.y,           w: c.w, h: T };
      const S = { x: c.x,         y: c.y + c.h - T, w: c.w, h: T };
      const W = { x: c.x,         y: c.y,           w: T,   h: c.h };
      const E = { x: c.x + c.w-T, y: c.y,           w: T,   h: c.h };

      // The "opens" side becomes a wall with a gap centered on it.
      const cutWallWithDoor = (rect, axis) => {
        // axis: 'x' or 'y' — along which the wall runs
        if (axis === "x") {
          const gapStart = rect.x + rect.w / 2 - DOOR / 2;
          return [
            { x: rect.x, y: rect.y, w: gapStart - rect.x, h: rect.h },
            { x: gapStart + DOOR, y: rect.y, w: rect.x + rect.w - (gapStart + DOOR), h: rect.h },
          ];
        } else {
          const gapStart = rect.y + rect.h / 2 - DOOR / 2;
          return [
            { x: rect.x, y: rect.y, w: rect.w, h: gapStart - rect.y },
            { x: rect.x, y: gapStart + DOOR, w: rect.w, h: rect.y + rect.h - (gapStart + DOOR) },
          ];
        }
      };

      switch (c.opens) {
        case "N": {
          c.walls.push(...cutWallWithDoor(N, "x"));
          c.walls.push(S, W, E);
          c.doorRect = { x: c.x + c.w/2 - DOOR/2, y: c.y - 2, w: DOOR, h: T + 4 };
          break;
        }
        case "S": {
          c.walls.push(N);
          c.walls.push(...cutWallWithDoor(S, "x"));
          c.walls.push(W, E);
          c.doorRect = { x: c.x + c.w/2 - DOOR/2, y: c.y + c.h - T - 2, w: DOOR, h: T + 4 };
          break;
        }
        case "E": {
          c.walls.push(N, S, W);
          c.walls.push(...cutWallWithDoor(E, "y"));
          c.doorRect = { x: c.x + c.w - T - 2, y: c.y + c.h/2 - DOOR/2, w: T + 4, h: DOOR };
          break;
        }
        case "W":
        default: {
          c.walls.push(N, S, E);
          c.walls.push(...cutWallWithDoor(W, "y"));
          c.doorRect = { x: c.x - 2, y: c.y + c.h/2 - DOOR/2, w: T + 4, h: DOOR };
          break;
        }
      }

      // Desk sits along the wall OPPOSITE the opening, inside the cubicle.
      const DESK_DEPTH = 30;
      const DESK_MARGIN = 16;
      if (c.opens === "W") {
        c.desk = { x: c.x + c.w - DESK_DEPTH - DESK_MARGIN, y: c.y + 18, w: DESK_DEPTH, h: c.h - 36 };
      } else if (c.opens === "E") {
        c.desk = { x: c.x + DESK_MARGIN, y: c.y + 18, w: DESK_DEPTH, h: c.h - 36 };
      } else if (c.opens === "N") {
        c.desk = { x: c.x + 18, y: c.y + c.h - DESK_DEPTH - DESK_MARGIN, w: c.w - 36, h: DESK_DEPTH };
      } else {
        c.desk = { x: c.x + 18, y: c.y + DESK_MARGIN, w: c.w - 36, h: DESK_DEPTH };
      }

      // Interior "occupancy" rectangle (a little inside the walls) to detect
      // when the player is inside this cubicle.
      c.interior = { x: c.x + T, y: c.y + T, w: c.w - 2*T, h: c.h - 2*T };
    }
    return cubicles;
  }

  // Guard patrol path — a rectangle around the island with some margin.
  const PATROL = (() => {
    const m = 70; // margin around island
    const r = {
      x: ISLAND.cx - ISLAND.w/2 - m,
      y: ISLAND.cy - ISLAND.h/2 - m,
      w: ISLAND.w + m*2,
      h: ISLAND.h + m*2,
    };
    // Build 4 corner points (clockwise)
    const pts = [
      { x: r.x,         y: r.y },
      { x: r.x + r.w,   y: r.y },
      { x: r.x + r.w,   y: r.y + r.h },
      { x: r.x,         y: r.y + r.h },
    ];
    // segment lengths
    const segs = [];
    let total = 0;
    for (let i = 0; i < pts.length; i++) {
      const a = pts[i], b = pts[(i+1) % pts.length];
      const len = Math.hypot(b.x - a.x, b.y - a.y);
      segs.push({ a, b, len, start: total });
      total += len;
    }
    return { pts, segs, total, rect: r };
  })();

  // Place guard at start of patrol.
  function initGuard() {
    state.guard.patrolT = 0;
    advanceGuard(0);
  }

  function advanceGuard(dt) {
    state.guard.patrolT = (state.guard.patrolT + GUARD.speed * dt) % PATROL.total;
    let t = state.guard.patrolT;
    for (const s of PATROL.segs) {
      if (t <= s.len) {
        const f = t / s.len;
        state.guard.x = s.a.x + (s.b.x - s.a.x) * f;
        state.guard.y = s.a.y + (s.b.y - s.a.y) * f;
        state.guard.dir = Math.atan2(s.b.y - s.a.y, s.b.x - s.a.x);
        return;
      }
      t -= s.len;
    }
  }

  // ---------- Collision ----------
  function rectsAll() {
    // Collect all solid rectangles (outer walls + cubicle walls).
    const T = 10;
    const outer = [
      { x: 0, y: 0, w: CANVAS_W, h: ROOM.y },                      // top
      { x: 0, y: ROOM.y + ROOM.h, w: CANVAS_W, h: CANVAS_H - (ROOM.y + ROOM.h) }, // bottom
      { x: 0, y: 0, w: ROOM.x, h: CANVAS_H },                      // left
      { x: ROOM.x + ROOM.w, y: 0, w: CANVAS_W - (ROOM.x + ROOM.w), h: CANVAS_H }, // right
    ];
    const rects = [...outer];
    for (const c of CUBICLES) {
      for (const w of c.walls) rects.push(w);
    }
    return rects;
  }
  const SOLIDS = rectsAll();

  function circleRectCollide(cx, cy, r, rect) {
    const nx = Math.max(rect.x, Math.min(cx, rect.x + rect.w));
    const ny = Math.max(rect.y, Math.min(cy, rect.y + rect.h));
    const dx = cx - nx, dy = cy - ny;
    return (dx*dx + dy*dy) < (r*r);
  }

  function collidesAny(cx, cy, r) {
    for (const rect of SOLIDS) {
      if (circleRectCollide(cx, cy, r, rect)) return true;
    }
    return false;
  }

  function pointInRect(px, py, rect) {
    return px >= rect.x && py >= rect.y && px < rect.x + rect.w && py < rect.y + rect.h;
  }

  // Line-vs-rect intersection (used for guard line-of-sight).
  function segmentIntersectsRect(x1, y1, x2, y2, r) {
    // Quick reject
    if (Math.max(x1, x2) < r.x || Math.min(x1, x2) > r.x + r.w) return false;
    if (Math.max(y1, y2) < r.y || Math.min(y1, y2) > r.y + r.h) return false;

    // Test against 4 edges
    const edges = [
      [r.x, r.y, r.x + r.w, r.y],
      [r.x + r.w, r.y, r.x + r.w, r.y + r.h],
      [r.x + r.w, r.y + r.h, r.x, r.y + r.h],
      [r.x, r.y + r.h, r.x, r.y],
    ];
    for (const [a, b, c, d] of edges) {
      if (segSegIntersect(x1, y1, x2, y2, a, b, c, d)) return true;
    }
    // Fully inside?
    if (pointInRect(x1, y1, r) || pointInRect(x2, y2, r)) return true;
    return false;
  }

  function segSegIntersect(x1, y1, x2, y2, x3, y3, x4, y4) {
    const d = (x1 - x2) * (y3 - y4) - (y1 - y2) * (x3 - x4);
    if (d === 0) return false;
    const t = ((x1 - x3) * (y3 - y4) - (y1 - y3) * (x3 - x4)) / d;
    const u = -((x1 - x2) * (y1 - y3) - (y1 - y2) * (x1 - x3)) / d;
    return t >= 0 && t <= 1 && u >= 0 && u <= 1;
  }

  function lineOfSight(ax, ay, bx, by) {
    // Blocked by any wall rect
    for (const rect of SOLIDS) {
      if (segmentIntersectsRect(ax, ay, bx, by, rect)) return false;
    }
    return true;
  }

  // ---------- Input ----------
  const canvas = document.getElementById("office");
  const ctx = canvas.getContext("2d");

  const keyMap = {
    "ArrowUp": "up", "KeyW": "up",
    "ArrowDown": "down", "KeyS": "down",
    "ArrowLeft": "left", "KeyA": "left",
    "ArrowRight": "right", "KeyD": "right",
  };

  window.addEventListener("keydown", (e) => {
    // Let the login form handle typing when it's focused.
    const target = e.target;
    const isTyping = target && (target.tagName === "INPUT" || target.tagName === "TEXTAREA");

    if (state.mode === "login") {
      if (e.key === "Escape") {
        e.preventDefault();
        hideLogin();
      }
      // Enter is handled by form submit naturally.
      return;
    }

    if (state.mode === "cubicle" && e.key === "Escape") {
      e.preventDefault();
      leaveCubicle();
      return;
    }

    if (state.mode === "office") {
      if (!isTyping && keyMap[e.code]) {
        e.preventDefault();
        state.keys.add(keyMap[e.code]);
      }
      if (e.key === "Enter") {
        // If standing next to a door, enter the cubicle.
        const cub = nearbyCubicleEntry();
        if (cub) enterCubicle(cub);
      }
    }
  });

  window.addEventListener("keyup", (e) => {
    if (keyMap[e.code]) state.keys.delete(keyMap[e.code]);
  });

  // Mouse: click on cubicle doors also enters them (works in office mode).
  canvas.addEventListener("click", (e) => {
    if (state.mode !== "office") return;
    const rect = canvas.getBoundingClientRect();
    const mx = (e.clientX - rect.left) * (canvas.width / rect.width);
    const my = (e.clientY - rect.top)  * (canvas.height / rect.height);
    for (const c of CUBICLES) {
      if (pointInRect(mx, my, c.doorRect)) {
        // Only enter if the player is reasonably close.
        const dx = state.player.x - (c.doorRect.x + c.doorRect.w/2);
        const dy = state.player.y - (c.doorRect.y + c.doorRect.h/2);
        if (Math.hypot(dx, dy) < 80) enterCubicle(c);
        break;
      }
    }
  });

  // Cubicle / login controls
  const $ = (id) => document.getElementById(id);
  const officeCanvas = canvas;
  const cubicleView = $("cubicle-view");
  const loginView = $("login-view");
  const viewLabel = $("view-label");
  const statusEl = $("status");
  const modal = $("modal");
  const modalTitle = $("modal-title");
  const modalText = $("modal-text");
  const modalBtn = $("modal-btn");

  $("monitor").addEventListener("click", showLogin);
  $("leave-cubicle").addEventListener("click", leaveCubicle);
  $("login-cancel").addEventListener("click", hideLogin);
  $("login-form").addEventListener("submit", onLoginSubmit);
  modalBtn.addEventListener("click", restart);

  // Show hint note with the real credentials.
  $("note-user").textContent = CREDENTIALS.username;
  $("note-pass").textContent = CREDENTIALS.password;

  function nearbyCubicleEntry() {
    for (const c of CUBICLES) {
      // Consider "entering" when inside the door rect or just inside the
      // cubicle interior.
      if (pointInRect(state.player.x, state.player.y, c.doorRect)) return c;
      if (pointInRect(state.player.x, state.player.y, c.interior)) return c;
    }
    return null;
  }

  function enterCubicle(c) {
    if (state.mode !== "office") return;
    state.activeCubicle = c.id;
    state.mode = "cubicle";
    state.keys.clear();
    officeCanvas.classList.add("hidden");
    cubicleView.classList.remove("hidden");
    viewLabel.textContent = `View: Cubicle ${c.id}`;
    statusEl.textContent = "Click the computer to log in.";
  }

  function leaveCubicle() {
    if (state.mode !== "cubicle") return;
    state.mode = "office";
    state.activeCubicle = null;
    cubicleView.classList.add("hidden");
    officeCanvas.classList.remove("hidden");
    viewLabel.textContent = "View: Office";
    statusEl.textContent = "Avoid the guard. Break into a computer.";
    // Bump the player a step out of the door so they don't re-enter.
    nudgePlayerOut();
  }

  function nudgePlayerOut() {
    const c = CUBICLES.find(x => x.id === state.activeCubicle) ||
              nearbyCubicleEntry();
    if (!c) return;
    const step = 36;
    if (c.opens === "W") state.player.x = c.x - step;
    else if (c.opens === "E") state.player.x = c.x + c.w + step;
    else if (c.opens === "N") state.player.y = c.y - step;
    else if (c.opens === "S") state.player.y = c.y + c.h + step;
  }

  function showLogin() {
    if (state.mode !== "cubicle") return;
    state.mode = "login";
    loginView.classList.remove("hidden");
    viewLabel.textContent = "View: Computer";
    statusEl.textContent = "Enter the correct credentials.";
    $("login-message").textContent = "";
    $("login-message").className = "login-message";
    $("username").value = "";
    $("password").value = "";
    setTimeout(() => $("username").focus(), 0);
  }

  function hideLogin() {
    if (state.mode !== "login") return;
    state.mode = "cubicle";
    loginView.classList.add("hidden");
    viewLabel.textContent = `View: Cubicle ${state.activeCubicle}`;
    statusEl.textContent = "Click the computer to log in.";
  }

  function onLoginSubmit(e) {
    e.preventDefault();
    const u = $("username").value.trim();
    const p = $("password").value;
    const msg = $("login-message");
    if (u === CREDENTIALS.username && p === CREDENTIALS.password) {
      msg.textContent = "Access granted. You downloaded the files!";
      msg.className = "login-message ok";
      setTimeout(() => winGame(), 600);
    } else {
      msg.textContent = "Access denied. Try again.";
      msg.className = "login-message err";
      // Shake effect
      const form = $("login-form");
      form.animate(
        [
          { transform: "translateX(0)" },
          { transform: "translateX(-8px)" },
          { transform: "translateX(8px)" },
          { transform: "translateX(-4px)" },
          { transform: "translateX(0)" },
        ],
        { duration: 250, easing: "ease-in-out" }
      );
    }
  }

  function loseGame(reason) {
    if (state.mode === "gameover" || state.mode === "win") return;
    state.mode = "gameover";
    modalTitle.textContent = "You got caught!";
    modalText.textContent = reason || "The guard spotted you.";
    modalBtn.textContent = "Try again";
    modal.classList.remove("hidden");
  }

  function winGame() {
    state.mode = "win";
    modalTitle.textContent = "You're in!";
    modalText.textContent = "You successfully logged into the machine. Mission complete.";
    modalBtn.textContent = "Play again";
    modal.classList.remove("hidden");
  }

  function restart() {
    // Reset state.
    state.mode = "office";
    state.activeCubicle = null;
    state.keys.clear();
    state.player.x = 140;
    state.player.y = 320;
    state.detectionMeter = 0;
    initGuard();
    modal.classList.add("hidden");
    officeCanvas.classList.remove("hidden");
    cubicleView.classList.add("hidden");
    loginView.classList.add("hidden");
    viewLabel.textContent = "View: Office";
    statusEl.textContent = "Avoid the guard. Break into a computer.";
  }

  // ---------- Update ----------
  function update(dt) {
    if (state.mode !== "office") return;

    // Movement
    let vx = 0, vy = 0;
    if (state.keys.has("left")) vx -= 1;
    if (state.keys.has("right")) vx += 1;
    if (state.keys.has("up")) vy -= 1;
    if (state.keys.has("down")) vy += 1;
    if (vx !== 0 || vy !== 0) {
      const n = Math.hypot(vx, vy);
      vx /= n; vy /= n;
      state.player.dir = Math.atan2(vy, vx);
      const nx = state.player.x + vx * PLAYER.speed * dt;
      const ny = state.player.y + vy * PLAYER.speed * dt;
      // Try axis-separated movement to allow sliding along walls.
      if (!collidesAny(nx, state.player.y, PLAYER.radius)) state.player.x = nx;
      if (!collidesAny(state.player.x, ny, PLAYER.radius)) state.player.y = ny;
    }

    // Clamp (just in case)
    state.player.x = Math.max(ROOM.x + PLAYER.radius, Math.min(ROOM.x + ROOM.w - PLAYER.radius, state.player.x));
    state.player.y = Math.max(ROOM.y + PLAYER.radius, Math.min(ROOM.y + ROOM.h - PLAYER.radius, state.player.y));

    // Guard patrol
    advanceGuard(dt);

    // Detection check — player is seen if inside the vision cone AND has LoS.
    const seen = playerSeenByGuard();
    if (seen) {
      state.detectionMeter = Math.min(1, state.detectionMeter + dt * 1.8);
      if (state.detectionMeter >= 1) {
        loseGame("The guard spotted you!");
      }
    } else {
      state.detectionMeter = Math.max(0, state.detectionMeter - dt * 1.0);
    }
  }

  function playerSeenByGuard() {
    const g = state.guard;
    const p = state.player;
    const dx = p.x - g.x;
    const dy = p.y - g.y;
    const dist = Math.hypot(dx, dy);
    if (dist > GUARD.viewDistance) return false;
    const ang = Math.atan2(dy, dx);
    let da = ang - g.dir;
    while (da > Math.PI) da -= 2 * Math.PI;
    while (da < -Math.PI) da += 2 * Math.PI;
    if (Math.abs(da) > GUARD.viewAngle / 2) return false;
    // If the player is hidden inside a cubicle (interior), consider them out of sight
    // even if the geometric line is clear — thematically they're behind a partition.
    for (const c of CUBICLES) {
      if (pointInRect(p.x, p.y, c.interior)) return false;
    }
    return lineOfSight(g.x, g.y, p.x, p.y);
  }

  // ---------- Render ----------
  function render() {
    if (state.mode !== "office") return;
    const w = canvas.width, h = canvas.height;
    ctx.clearRect(0, 0, w, h);

    // Floor (checkerboard)
    drawFloor();

    // Outer walls
    ctx.fillStyle = COLORS.wall;
    ctx.fillRect(0, 0, w, ROOM.y);
    ctx.fillRect(0, ROOM.y + ROOM.h, w, h - (ROOM.y + ROOM.h));
    ctx.fillRect(0, 0, ROOM.x, h);
    ctx.fillRect(ROOM.x + ROOM.w, 0, w - (ROOM.x + ROOM.w), h);

    // Cubicles
    for (const c of CUBICLES) drawCubicle(c);

    // Doors highlight
    for (const c of CUBICLES) drawDoor(c);

    // Patrol path (subtle)
    drawPatrolPath();

    // Guard vision cone + guard
    drawGuard();

    // Player
    drawPlayer();

    // Detection meter
    drawDetection();

    // Hint
    ctx.fillStyle = COLORS.hint;
    ctx.font = "13px ui-sans-serif, system-ui, sans-serif";
    ctx.textAlign = "left";
    ctx.fillText("WASD / Arrow keys to move  •  Click a cubicle door or press Enter near one to go in", 28, h - 24);
  }

  function drawFloor() {
    const tile = 40;
    for (let y = ROOM.y; y < ROOM.y + ROOM.h; y += tile) {
      for (let x = ROOM.x; x < ROOM.x + ROOM.w; x += tile) {
        const alt = (((x / tile) + (y / tile)) & 1) === 0;
        ctx.fillStyle = alt ? COLORS.floor : COLORS.floorAlt;
        ctx.fillRect(x, y, tile, tile);
      }
    }
  }

  function drawCubicle(c) {
    // Draw walls
    ctx.fillStyle = COLORS.cubicleWall;
    for (const w of c.walls) ctx.fillRect(w.x, w.y, w.w, w.h);

    // Darker top/edge on walls for a bit of depth
    ctx.fillStyle = COLORS.cubicleWallDark;
    for (const w of c.walls) {
      if (w.h <= 8) ctx.fillRect(w.x, w.y, w.w, 2); // horizontal wall top edge
      if (w.w <= 8) ctx.fillRect(w.x, w.y, 2, w.h); // vertical wall left edge
    }

    // Desk
    ctx.fillStyle = COLORS.desk;
    ctx.fillRect(c.desk.x, c.desk.y, c.desk.w, c.desk.h);
    ctx.strokeStyle = COLORS.deskEdge;
    ctx.lineWidth = 2;
    ctx.strokeRect(c.desk.x + 1, c.desk.y + 1, c.desk.w - 2, c.desk.h - 2);

    // Monitor on desk (little rectangle)
    const d = c.desk;
    const mw = 14, mh = 14;
    let mx = d.x + d.w / 2 - mw/2;
    let my = d.y + d.h / 2 - mh/2;
    ctx.fillStyle = "#0b1c2c";
    ctx.fillRect(mx, my, mw, mh);
    ctx.strokeStyle = "#7fd2ff";
    ctx.lineWidth = 1;
    ctx.strokeRect(mx + 0.5, my + 0.5, mw - 1, mh - 1);

    // Label
    ctx.fillStyle = "rgba(20,20,30,0.7)";
    ctx.font = "bold 14px ui-sans-serif, system-ui, sans-serif";
    ctx.textAlign = "center";
    ctx.fillText(c.id, c.x + c.w / 2, c.y + c.h / 2 + 5);
  }

  function drawDoor(c) {
    // Draw a faint glowing strip across the door rect.
    const d = c.doorRect;
    ctx.save();
    ctx.globalAlpha = 0.6;
    ctx.fillStyle = COLORS.door;
    ctx.fillRect(d.x, d.y, d.w, d.h);
    ctx.globalAlpha = 1;
    ctx.restore();
  }

  function drawPatrolPath() {
    ctx.save();
    ctx.setLineDash([6, 8]);
    ctx.strokeStyle = "rgba(255, 80, 80, 0.35)";
    ctx.lineWidth = 2;
    const r = PATROL.rect;
    ctx.strokeRect(r.x, r.y, r.w, r.h);
    ctx.restore();
  }

  function drawGuard() {
    const g = state.guard;
    // Vision cone (sampled)
    const dir = g.dir;
    const half = GUARD.viewAngle / 2;
    const steps = 36;
    const gx = g.x, gy = g.y;

    ctx.save();
    const grad = ctx.createRadialGradient(gx, gy, 10, gx, gy, GUARD.viewDistance);
    grad.addColorStop(0, "rgba(255, 90, 90, 0.35)");
    grad.addColorStop(1, "rgba(255, 90, 90, 0.0)");
    ctx.fillStyle = grad;

    ctx.beginPath();
    ctx.moveTo(gx, gy);
    for (let i = 0; i <= steps; i++) {
      const a = dir - half + (GUARD.viewAngle * i / steps);
      // Raycast against walls to shorten the cone when blocked.
      const end = raycastToWalls(gx, gy, a, GUARD.viewDistance);
      ctx.lineTo(end.x, end.y);
    }
    ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = COLORS.coneEdge;
    ctx.lineWidth = 1;
    ctx.stroke();
    ctx.restore();

    // Guard body
    ctx.beginPath();
    ctx.arc(gx, gy, GUARD.radius, 0, Math.PI * 2);
    ctx.fillStyle = COLORS.guard;
    ctx.fill();
    ctx.strokeStyle = COLORS.guardOutline;
    ctx.lineWidth = 2;
    ctx.stroke();

    // Facing indicator
    ctx.beginPath();
    ctx.moveTo(gx, gy);
    ctx.lineTo(gx + Math.cos(dir) * (GUARD.radius + 8), gy + Math.sin(dir) * (GUARD.radius + 8));
    ctx.strokeStyle = "#fff";
    ctx.lineWidth = 2;
    ctx.stroke();
  }

  function raycastToWalls(x0, y0, ang, maxDist) {
    // Step along the ray and test against solid rects.
    const step = 4;
    let x = x0, y = y0;
    let dx = Math.cos(ang), dy = Math.sin(ang);
    let dist = 0;
    while (dist < maxDist) {
      x += dx * step; y += dy * step; dist += step;
      for (const r of SOLIDS) {
        if (pointInRect(x, y, r)) return { x, y, dist };
      }
    }
    return { x, y, dist: maxDist };
  }

  function drawPlayer() {
    const p = state.player;
    ctx.beginPath();
    ctx.arc(p.x, p.y, PLAYER.radius, 0, Math.PI * 2);
    ctx.fillStyle = COLORS.player;
    ctx.fill();
    ctx.strokeStyle = COLORS.playerOutline;
    ctx.lineWidth = 2;
    ctx.stroke();
    // Facing indicator
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
    ctx.lineTo(p.x + Math.cos(p.dir) * (PLAYER.radius + 6), p.y + Math.sin(p.dir) * (PLAYER.radius + 6));
    ctx.strokeStyle = "#fff";
    ctx.lineWidth = 2;
    ctx.stroke();
  }

  function drawDetection() {
    if (state.detectionMeter <= 0) return;
    const x = 28, y = 28, w = 220, h = 10;
    ctx.fillStyle = "rgba(0,0,0,0.5)";
    ctx.fillRect(x - 2, y - 2, w + 4, h + 4);
    ctx.fillStyle = "#ff5a5a";
    ctx.fillRect(x, y, w * state.detectionMeter, h);
    ctx.fillStyle = "#fff";
    ctx.font = "12px ui-sans-serif, system-ui, sans-serif";
    ctx.textAlign = "left";
    ctx.fillText("Detection", x, y - 6);
  }

  // ---------- Main loop ----------
  function frame(now) {
    const dt = Math.min(0.05, (now - state.lastTime) / 1000);
    state.lastTime = now;
    update(dt);
    render();
    requestAnimationFrame(frame);
  }

  // ---------- Kickoff ----------
  initGuard();
  requestAnimationFrame(frame);
})();
