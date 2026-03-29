# Zombie Attack 3

Multiplayer online territory strategy game. Take over the world while minding the zombies.

Because they will kill you. Legit don't forget about them.

## Setup

```bash
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
python app.py
```

Then open http://localhost:5000 in your browser.

## How to Play

1. **Register/Login** - Create an account or log in
2. **Create or Join a Game** - Pick a map and invite up to 4 players (max 3 concurrent games)
3. **Choose a Character** - Each has unique abilities (see below)
4. **Play** - Deploy forces → Attack → Fortify → End Turn
5. **Watch out for Zombies** - The NPC Zombie forces grow from battle casualties

## Zombie Mechanics

- When fighting zombies, your dead forces are added to the zombie army
- If zombies win a battle, ALL remaining player forces become zombies
- At end of each turn, zombies move toward the nearest player within their aggro range
- If multiple players are nearby, zombies split proportionally based on force count

## Characters

- **Scout** - Standard rules, zombie aggro range 5
- **Beacon** - Zombie aggro range 6 (attracts zombies from farther)
- **Ghost** - Zombie aggro range 4 (harder for zombies to detect)
- **Tactician** - Can move 1 set of forces twice at end of turn
- **Ranger** - Can move 1 set of forces twice as far, once per turn
- **Medic** - Only 50% of dead forces added to zombies
- **Plague** - Dead forces become zombies, AND opponent's dead forces too

## Custom Maps

Use the Map Editor to create custom maps with at least 6 connected territories.

## Player Lookup

Search for other players by username from the dashboard or game lobby to invite them.
