"""Game engine for Zombie Attack 3.

Handles turn flow, combat resolution, zombie AI movement, territory
setup, and character ability hooks.
"""

import json
import math
import random
from collections import defaultdict

from models import db, Game, GamePlayer, Territory, Map
from characters import get_character
from maps import get_adjacency, bfs_distance

# ── Constants ────────────────────────────────────────────────────────────────

STARTING_FORCES_PER_TERRITORY = 25
STARTING_DEPLOY = 10
ZOMBIE_STARTING_FORCES = 10
PLAYER_COLORS = ["#3498db", "#e74c3c", "#2ecc71", "#f39c12"]


# ── Game Setup ───────────────────────────────────────────────────────────────

def setup_game(game: Game):
    """Initialize territories and assign starting positions for all players.

    Called when the game transitions from 'waiting' to 'active'.
    """
    map_data = json.loads(game.map.map_data)
    territory_keys = list(map_data["territories"].keys())
    random.shuffle(territory_keys)

    players = game.get_alive_players()
    num_players = len(players)

    # Assign colors
    for i, gp in enumerate(players):
        gp.color = PLAYER_COLORS[i % len(PLAYER_COLORS)]
        gp.turn_order = i

    # Distribute territories: round-robin among players, remainder become neutral
    territories_per_player = max(5, len(territory_keys) // (num_players + 1))  # +1 for zombies

    assigned = 0
    for i, gp in enumerate(players):
        for j in range(territories_per_player):
            idx = i * territories_per_player + j
            if idx < len(territory_keys):
                t = Territory(
                    game_id=game.id,
                    territory_key=territory_keys[idx],
                    owner_id=gp.user_id,
                    forces=STARTING_FORCES_PER_TERRITORY,
                    is_zombie=False,
                )
                db.session.add(t)
                assigned += 1

    # Assign one zombie territory per player
    zombie_start = assigned
    for i in range(num_players):
        idx = zombie_start + i
        if idx < len(territory_keys):
            t = Territory(
                game_id=game.id,
                territory_key=territory_keys[idx],
                owner_id=None,
                forces=ZOMBIE_STARTING_FORCES,
                is_zombie=True,
            )
            db.session.add(t)
            assigned += 1

    # Remaining territories become neutral (unoccupied, 0 forces)
    for idx in range(assigned, len(territory_keys)):
        t = Territory(
            game_id=game.id,
            territory_key=territory_keys[idx],
            owner_id=None,
            forces=0,
            is_zombie=False,
        )
        db.session.add(t)

    # Set first player's turn
    game.status = "active"
    game.turn_number = 1
    game.turn_phase = "deploy"
    game.current_turn_user_id = players[0].user_id
    db.session.commit()


# ── Deploy Phase ─────────────────────────────────────────────────────────────

def calculate_deploy_count(game: Game, user_id: int) -> int:
    """Calculate how many forces a player can deploy this turn."""
    player_territories = game.get_player_territories(user_id)
    # Base: 1 per 3 territories, minimum 3
    return max(3, len(player_territories) // 3)


def deploy_forces(game: Game, user_id: int, territory_key: str, count: int) -> dict:
    """Deploy forces to a territory during the deploy phase."""
    if game.current_turn_user_id != user_id:
        return {"error": "Not your turn."}
    if game.turn_phase != "deploy":
        return {"error": "Not in deploy phase."}

    territory = Territory.query.filter_by(
        game_id=game.id, territory_key=territory_key
    ).first()
    if not territory or territory.owner_id != user_id or territory.is_zombie:
        return {"error": "You don't own this territory."}

    if count < 1:
        return {"error": "Must deploy at least 1 force."}

    territory.forces += count
    db.session.commit()
    return {"success": True, "territory_key": territory_key, "new_forces": territory.forces}


def end_deploy_phase(game: Game, user_id: int) -> dict:
    """Transition from deploy to attack phase."""
    if game.current_turn_user_id != user_id or game.turn_phase != "deploy":
        return {"error": "Cannot end deploy phase."}
    game.turn_phase = "attack"
    db.session.commit()
    return {"success": True, "phase": "attack"}


# ── Attack Phase ─────────────────────────────────────────────────────────────

def resolve_combat(attacker_forces: int, defender_forces: int) -> tuple[int, int]:
    """Risk-style combat: attacker rolls up to 3 dice, defender up to 2.

    Returns (attacker_losses, defender_losses).
    """
    att_dice = min(attacker_forces - 1, 3)  # must leave 1 behind
    def_dice = min(defender_forces, 2)

    if att_dice <= 0 or def_dice <= 0:
        return 0, 0

    att_rolls = sorted([random.randint(1, 6) for _ in range(att_dice)], reverse=True)
    def_rolls = sorted([random.randint(1, 6) for _ in range(def_dice)], reverse=True)

    att_losses = 0
    def_losses = 0
    for a, d in zip(att_rolls, def_rolls):
        if a > d:
            def_losses += 1
        else:
            att_losses += 1

    return att_losses, def_losses


def attack_territory(game: Game, user_id: int, from_key: str, to_key: str) -> dict:
    """Execute an attack from one territory to an adjacent one.

    Handles zombie combat mechanics and character abilities.
    """
    if game.current_turn_user_id != user_id:
        return {"error": "Not your turn."}
    if game.turn_phase != "attack":
        return {"error": "Not in attack phase."}

    map_data = json.loads(game.map.map_data)
    adjacency = get_adjacency(map_data)

    if to_key not in adjacency.get(from_key, []):
        return {"error": "Territories are not adjacent."}

    att_territory = Territory.query.filter_by(game_id=game.id, territory_key=from_key).first()
    def_territory = Territory.query.filter_by(game_id=game.id, territory_key=to_key).first()

    if not att_territory or att_territory.owner_id != user_id or att_territory.is_zombie:
        return {"error": "You don't own the attacking territory."}
    if not def_territory:
        return {"error": "Target territory not found."}
    if def_territory.owner_id == user_id and not def_territory.is_zombie:
        return {"error": "Cannot attack your own territory."}
    if att_territory.forces <= 1:
        return {"error": "Need at least 2 forces to attack."}

    # Get attacker's character
    attacker_gp = game.get_player(user_id)
    attacker_char = get_character(attacker_gp.character_type)

    is_vs_zombie = def_territory.is_zombie
    att_losses, def_losses = resolve_combat(att_territory.forces, def_territory.forces)

    # Apply character abilities to casualties
    zombie_gains = 0

    if is_vs_zombie:
        # Player vs Zombie: player's dead are added to zombie forces
        zombie_dead_ratio = attacker_char.dead_to_zombie_ratio
        zombie_gains = math.floor(att_losses * zombie_dead_ratio)
    elif attacker_char.infects_opponent_dead:
        # Plague character: both sides' dead become zombies
        zombie_gains = att_losses + def_losses
    else:
        # Player vs Player: attacker's dead added to nearby zombie per standard rules
        # (only if fighting zombies do dead join zombie forces directly)
        pass

    att_territory.forces -= att_losses
    def_territory.forces -= def_losses

    result = {
        "success": True,
        "from_key": from_key,
        "to_key": to_key,
        "att_losses": att_losses,
        "def_losses": def_losses,
        "zombie_gains": zombie_gains,
    }

    # Add casualties to zombie forces in the battle (when fighting zombies)
    if is_vs_zombie and zombie_gains > 0:
        def_territory.forces += zombie_gains

    # Handle Plague character: spawn/add zombie forces to nearest zombie territory
    if not is_vs_zombie and attacker_char.infects_opponent_dead and zombie_gains > 0:
        _add_to_nearest_zombie(game, map_data, to_key, zombie_gains)

    # Check if defender is eliminated
    if def_territory.forces <= 0:
        if is_vs_zombie:
            # Zombie wins check: if attacker ran out, remaining forces become zombie
            if att_territory.forces <= 1:
                # Zombie wins - all remaining attacker forces become zombies
                zombie_bonus = att_territory.forces
                att_territory.forces = 0
                att_territory.owner_id = None
                att_territory.is_zombie = True
                att_territory.forces = zombie_bonus
                def_territory.forces = 0
                result["zombie_takeover"] = True
            else:
                # Player captures zombie territory
                def_territory.is_zombie = False
                def_territory.owner_id = user_id
                move_in = min(att_territory.forces - 1, 3)
                def_territory.forces = move_in
                att_territory.forces -= move_in
                result["captured"] = True
        else:
            # Player captures player territory
            old_owner = def_territory.owner_id
            def_territory.owner_id = user_id
            def_territory.is_zombie = False
            move_in = min(att_territory.forces - 1, 3)
            def_territory.forces = move_in
            att_territory.forces -= move_in
            result["captured"] = True

            # Check if the defending player has been eliminated
            if old_owner:
                _check_player_elimination(game, old_owner)

    # If zombie wins (defender still alive and attacker depleted)
    if is_vs_zombie and att_territory.forces <= 0:
        # All remaining forces become zombie
        att_territory.owner_id = None
        att_territory.is_zombie = True
        result["zombie_takeover"] = True

    db.session.commit()

    # Check win condition
    winner = _check_win_condition(game)
    if winner:
        result["game_over"] = True
        result["winner_id"] = winner

    return result


def _add_to_nearest_zombie(game: Game, map_data: dict, from_key: str, count: int):
    """Add zombie forces to the nearest zombie territory."""
    adjacency = get_adjacency(map_data)
    zombie_territories = game.get_zombie_territories()
    if not zombie_territories:
        # No zombie territories exist; create one at from_key neighbor
        return

    # BFS from from_key to find nearest zombie
    best_dist = float("inf")
    best_zt = None
    for zt in zombie_territories:
        distances = bfs_distance(adjacency, from_key, 20)
        d = distances.get(zt.territory_key, float("inf"))
        if d < best_dist:
            best_dist = d
            best_zt = zt
    if best_zt:
        best_zt.forces += count


def end_attack_phase(game: Game, user_id: int) -> dict:
    """Transition from attack to fortify phase."""
    if game.current_turn_user_id != user_id or game.turn_phase != "attack":
        return {"error": "Cannot end attack phase."}
    game.turn_phase = "fortify"
    db.session.commit()
    return {"success": True, "phase": "fortify"}


# ── Fortify Phase ────────────────────────────────────────────────────────────

def fortify_move(game: Game, user_id: int, from_key: str, to_key: str, count: int) -> dict:
    """Move forces between two owned territories during fortify phase."""
    if game.current_turn_user_id != user_id or game.turn_phase != "fortify":
        return {"error": "Cannot fortify now."}

    map_data = json.loads(game.map.map_data)
    adjacency = get_adjacency(map_data)

    # Check connectivity (territories must be connected through owned territories)
    if not _are_connected_through_owned(game, user_id, from_key, to_key, adjacency):
        return {"error": "Territories are not connected through your territories."}

    from_t = Territory.query.filter_by(game_id=game.id, territory_key=from_key).first()
    to_t = Territory.query.filter_by(game_id=game.id, territory_key=to_key).first()

    if not from_t or from_t.owner_id != user_id or from_t.is_zombie:
        return {"error": "You don't own the source territory."}
    if not to_t or to_t.owner_id != user_id or to_t.is_zombie:
        return {"error": "You don't own the destination territory."}
    if count < 1 or count >= from_t.forces:
        return {"error": "Invalid number of forces to move."}

    # Ranger ability: double distance check (handled at route level)
    gp = game.get_player(user_id)
    char = get_character(gp.character_type)

    from_t.forces -= count
    to_t.forces += count
    db.session.commit()
    return {"success": True, "from_key": from_key, "to_key": to_key, "count": count}


def _are_connected_through_owned(game, user_id, from_key, to_key, adjacency):
    """Check if two territories are connected through the player's owned territories."""
    from collections import deque

    owned_keys = {t.territory_key for t in game.get_player_territories(user_id)}
    if from_key not in owned_keys or to_key not in owned_keys:
        return False

    visited = set()
    queue = deque([from_key])
    visited.add(from_key)
    while queue:
        node = queue.popleft()
        if node == to_key:
            return True
        for adj in adjacency.get(node, []):
            if adj in owned_keys and adj not in visited:
                visited.add(adj)
                queue.append(adj)
    return False


def _path_distance(adjacency, from_key, to_key, owned_keys):
    """Calculate shortest path distance between two territories through owned territories."""
    from collections import deque

    visited = {from_key: 0}
    queue = deque([from_key])
    while queue:
        node = queue.popleft()
        if node == to_key:
            return visited[node]
        for adj in adjacency.get(node, []):
            if adj in owned_keys and adj not in visited:
                visited[adj] = visited[node] + 1
                queue.append(adj)
    return float("inf")


# ── Tactician Ability: Double Move ───────────────────────────────────────────

def tactician_extra_move(game: Game, user_id: int, from_key: str, to_key: str, count: int) -> dict:
    """Tactician's special ability: move one set of forces twice at end of turn."""
    gp = game.get_player(user_id)
    char = get_character(gp.character_type)
    if not char.double_move_end_of_turn:
        return {"error": "Your character doesn't have this ability."}
    if gp.ability_used:
        return {"error": "You already used your ability this turn."}

    result = fortify_move(game, user_id, from_key, to_key, count)
    if "success" in result:
        gp.ability_used = True
        db.session.commit()
    return result


# ── Ranger Ability: Double Distance ──────────────────────────────────────────

def ranger_extended_move(game: Game, user_id: int, from_key: str, to_key: str, count: int) -> dict:
    """Ranger's special ability: move forces twice as far, once per turn.

    This allows a fortify move that can traverse up to double the normal
    maximum connected distance.
    """
    gp = game.get_player(user_id)
    char = get_character(gp.character_type)
    if not char.double_move_distance:
        return {"error": "Your character doesn't have this ability."}
    if gp.ability_used:
        return {"error": "You already used your ability this turn."}

    # The move is allowed even if territories aren't directly adjacent,
    # as long as there's a path through owned territories
    result = fortify_move(game, user_id, from_key, to_key, count)
    if "success" in result:
        gp.ability_used = True
        db.session.commit()
    return result


# ── End Turn & Zombie AI ─────────────────────────────────────────────────────

def end_turn(game: Game, user_id: int) -> dict:
    """End the current player's turn, run zombie AI, advance to next player."""
    if game.current_turn_user_id != user_id:
        return {"error": "Not your turn."}
    if game.turn_phase not in ("attack", "fortify"):
        return {"error": "Complete deploy phase first."}

    # Reset ability usage for current player
    gp = game.get_player(user_id)
    if gp:
        gp.ability_used = False

    # Run zombie movement AI
    zombie_moves = _run_zombie_ai(game)

    # Advance to next alive player
    alive_players = sorted(game.get_alive_players(), key=lambda p: p.turn_order)
    if not alive_players:
        game.status = "finished"
        db.session.commit()
        return {"error": "No players alive."}

    current_idx = None
    for i, p in enumerate(alive_players):
        if p.user_id == user_id:
            current_idx = i
            break

    if current_idx is None:
        next_player = alive_players[0]
    else:
        next_idx = (current_idx + 1) % len(alive_players)
        next_player = alive_players[next_idx]
        if next_idx == 0:
            game.turn_number += 1

    game.current_turn_user_id = next_player.user_id
    game.turn_phase = "deploy"
    db.session.commit()

    # Check win condition
    winner = _check_win_condition(game)

    return {
        "success": True,
        "next_user_id": next_player.user_id,
        "turn_number": game.turn_number,
        "zombie_moves": zombie_moves,
        "game_over": winner is not None,
        "winner_id": winner,
    }


def _run_zombie_ai(game: Game) -> list[dict]:
    """Execute zombie movement at the end of a turn.

    For each zombie territory, find the nearest player territories within
    aggro range. If multiple player groups are nearby, split zombie forces
    proportionally based on force count within range.
    """
    map_data = json.loads(game.map.map_data)
    adjacency = get_adjacency(map_data)

    zombie_territories = game.get_zombie_territories()
    if not zombie_territories:
        return []

    # Build a lookup of territory_key -> Territory
    territory_lookup = {t.territory_key: t for t in game.territories}

    # Determine per-player aggro ranges
    player_aggro = {}
    for gp in game.get_alive_players():
        char = get_character(gp.character_type)
        player_aggro[gp.user_id] = char.zombie_aggro_range

    moves = []

    for zt in zombie_territories:
        if zt.forces <= 1:
            continue

        # Find all player territories within the max aggro range from this zombie
        max_range = max(player_aggro.values()) if player_aggro else 5
        distances = bfs_distance(adjacency, zt.territory_key, max_range)

        # Group nearby player forces by player, respecting each player's aggro range
        player_forces_nearby = defaultdict(int)  # player_id -> total forces in range
        player_nearest = {}  # player_id -> (territory_key, distance)

        for tk, dist in distances.items():
            if dist == 0:
                continue
            t = territory_lookup.get(tk)
            if not t or t.is_zombie or t.owner_id is None:
                continue

            # Check if this player's aggro range includes this distance
            pid = t.owner_id
            if pid in player_aggro and dist <= player_aggro[pid]:
                player_forces_nearby[pid] += t.forces
                if pid not in player_nearest or dist < player_nearest[pid][1]:
                    player_nearest[pid] = (tk, dist)

        if not player_forces_nearby:
            continue

        total_nearby = sum(player_forces_nearby.values())
        if total_nearby == 0:
            continue

        # Split zombie forces proportionally
        movable = zt.forces - 1  # leave 1 behind
        if movable <= 0:
            continue

        for pid, forces in player_forces_nearby.items():
            proportion = forces / total_nearby
            to_send = max(1, math.floor(movable * proportion))
            to_send = min(to_send, zt.forces - 1)

            if to_send <= 0:
                continue

            # Move toward the nearest player territory of this player
            target_key = player_nearest[pid][0]
            next_step = _find_next_step(adjacency, zt.territory_key, target_key)

            if next_step is None:
                continue

            next_t = territory_lookup.get(next_step)
            if next_t is None:
                continue

            if next_t.is_zombie:
                # Merge with existing zombie territory
                next_t.forces += to_send
                zt.forces -= to_send
                moves.append({
                    "from": zt.territory_key,
                    "to": next_step,
                    "forces": to_send,
                    "type": "merge",
                })
            elif next_t.owner_id is not None:
                # Attack the player territory
                zombie_attack_result = _zombie_auto_attack(
                    game, zt, next_t, to_send, territory_lookup
                )
                moves.append(zombie_attack_result)
            else:
                # Move into neutral territory
                next_t.is_zombie = True
                next_t.owner_id = None
                next_t.forces = to_send
                zt.forces -= to_send
                moves.append({
                    "from": zt.territory_key,
                    "to": next_step,
                    "forces": to_send,
                    "type": "occupy",
                })

    db.session.flush()
    return moves


def _zombie_auto_attack(game, zombie_territory, player_territory, zombie_forces, territory_lookup):
    """Handle zombie attacking a player territory.

    Dead player forces are added to the zombie forces in the battle.
    If zombie wins, all remaining player forces become zombies.
    """
    result = {
        "from": zombie_territory.territory_key,
        "to": player_territory.territory_key,
        "type": "attack",
        "zombie_forces": zombie_forces,
    }

    # Get defender's character for dead-to-zombie ratio
    defender_gp = game.get_player(player_territory.owner_id)
    if defender_gp:
        defender_char = get_character(defender_gp.character_type)
        dead_ratio = defender_char.dead_to_zombie_ratio
    else:
        dead_ratio = 1.0

    att_forces = zombie_forces
    def_forces = player_territory.forces
    total_zombie_gains = 0

    # Resolve multiple rounds of combat
    while att_forces > 0 and def_forces > 0:
        att_dice = min(att_forces, 3)
        def_dice = min(def_forces, 2)

        att_rolls = sorted([random.randint(1, 6) for _ in range(att_dice)], reverse=True)
        def_rolls = sorted([random.randint(1, 6) for _ in range(def_dice)], reverse=True)

        for a, d in zip(att_rolls, def_rolls):
            if a > d:
                def_forces -= 1
                # Defender's dead are added to zombie forces (modified by character)
                zombie_gain = math.floor(1 * dead_ratio)
                att_forces += zombie_gain
                total_zombie_gains += zombie_gain
            else:
                att_forces -= 1

    result["att_remaining"] = att_forces
    result["def_remaining"] = def_forces
    result["zombie_gains"] = total_zombie_gains

    zombie_territory.forces -= zombie_forces

    if def_forces <= 0:
        # Zombie wins: all remaining player forces become zombies
        bonus = player_territory.forces  # should be 0 but just in case
        player_territory.forces = att_forces + bonus
        player_territory.owner_id = None
        player_territory.is_zombie = True
        result["outcome"] = "zombie_wins"

        if player_territory.owner_id is not None:
            _check_player_elimination(game, player_territory.owner_id)
    else:
        # Player survives
        player_territory.forces = def_forces
        result["outcome"] = "player_survives"

    return result


def _find_next_step(adjacency, from_key, target_key):
    """BFS to find the next step from from_key toward target_key."""
    from collections import deque

    if from_key == target_key:
        return None

    visited = {from_key: None}
    queue = deque([from_key])
    while queue:
        node = queue.popleft()
        if node == target_key:
            # Trace back to find first step
            step = node
            while visited[step] != from_key:
                step = visited[step]
            return step
        for adj in adjacency.get(node, []):
            if adj not in visited:
                visited[adj] = node
                queue.append(adj)
    return None


# ── Player Elimination & Win Condition ───────────────────────────────────────

def _check_player_elimination(game: Game, user_id: int):
    """Check if a player has been eliminated (owns no territories)."""
    territories = game.get_player_territories(user_id)
    if len(territories) == 0:
        gp = game.get_player(user_id)
        if gp:
            gp.is_alive = False


def _check_win_condition(game: Game) -> int | None:
    """Check if only one player remains alive. Returns winner user_id or None."""
    alive = game.get_alive_players()
    if len(alive) == 1:
        game.status = "finished"
        game.winner_id = alive[0].user_id
        db.session.commit()
        return alive[0].user_id
    if len(alive) == 0:
        game.status = "finished"
        db.session.commit()
        return None
    return None


# ── Game State Serialization ─────────────────────────────────────────────────

def get_game_state(game: Game) -> dict:
    """Serialize the full game state for the frontend."""
    map_data = json.loads(game.map.map_data)

    territories = {}
    for t in game.territories:
        tdata = map_data["territories"].get(t.territory_key, {})
        territories[t.territory_key] = {
            "name": tdata.get("name", t.territory_key),
            "x": tdata.get("x", 0),
            "y": tdata.get("y", 0),
            "adjacent": tdata.get("adjacent", []),
            "owner_id": t.owner_id,
            "forces": t.forces,
            "is_zombie": t.is_zombie,
        }

    players = {}
    for gp in game.players:
        players[gp.user_id] = {
            "username": gp.user.username,
            "character": gp.character_type,
            "color": gp.color,
            "is_alive": gp.is_alive,
            "turn_order": gp.turn_order,
            "ability_used": gp.ability_used,
        }

    current_player = game.get_player(game.current_turn_user_id)
    deploy_count = 0
    if current_player and game.turn_phase == "deploy":
        deploy_count = calculate_deploy_count(game, game.current_turn_user_id)

    return {
        "game_id": game.id,
        "game_name": game.name,
        "status": game.status,
        "turn_number": game.turn_number,
        "turn_phase": game.turn_phase,
        "current_turn_user_id": game.current_turn_user_id,
        "deploy_count": deploy_count,
        "territories": territories,
        "players": players,
        "winner_id": game.winner_id,
        "map_name": game.map.name,
    }
