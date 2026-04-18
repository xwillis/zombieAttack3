# Office Heist

A tiny browser-based puzzle/stealth game. Your goal: sneak past the patrolling
guard, duck into one of the four cubicles on the central island, and log into
the computer using the correct credentials.

## How to play

1. Open `index.html` in any modern browser (Chrome, Firefox, Safari, Edge).
   - No build step, no dependencies. Pure HTML + CSS + vanilla JavaScript.
   - You can also serve it with any static server, e.g.
     `python3 -m http.server` and visit `http://localhost:8000/`.

## Controls

- **Move** (top-down office view): `W` `A` `S` `D` or the arrow keys.
- **Enter a cubicle**: walk up to a glowing green doorway and either press
  `Enter` or click the doorway.
- **Use the computer** (cubicle view): click the monitor with your mouse.
- **Type credentials** (computer view): use your keyboard. Press `Enter` (or
  click *Log in*) to submit. Press `Esc` or click *Cancel* to back out.
- **Leave a cubicle**: press `Esc` or click *Leave cubicle*.

## Rules

- A guard patrols a rectangular path around the cubicle island.
- If you step into the guard's red vision cone for too long, **you lose**.
- The cubicles themselves act as cover — once you're inside, the guard can't
  see you.
- Enter the correct credentials on any cubicle computer to win. There's a
  sticky note on the desk with a strong hint.

## Project structure

- `index.html` — markup for the three views (office, cubicle, login) and the
  game-over modal.
- `styles.css` — styling for the HUD, cubicle scene, and login screen.
- `game.js` — game logic: top-down rendering, input, guard AI + vision cone,
  view switching, and login validation.

## Credits

Built with vanilla web tech. No external assets or libraries.
