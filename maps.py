"""Map system for Zombie Attack 3.

Maps are stored as JSON with the following structure:
{
    "territories": {
        "territory_key": {
            "name": "Display Name",
            "x": 100,   // x position for rendering
            "y": 200,   // y position for rendering
            "adjacent": ["other_key1", "other_key2"]
        }
    }
}
"""

import json
from collections import deque


def validate_map_data(map_data: dict) -> tuple[bool, str]:
    """Validate a map data dict. Returns (is_valid, error_message)."""
    if "territories" not in map_data:
        return False, "Map must have a 'territories' key."

    territories = map_data["territories"]
    if not isinstance(territories, dict):
        return False, "'territories' must be a dict."

    if len(territories) < 6:
        return False, "Map must have at least 6 territories."

    keys = set(territories.keys())
    for key, data in territories.items():
        if "name" not in data or "x" not in data or "y" not in data:
            return False, f"Territory '{key}' missing required fields (name, x, y)."
        if "adjacent" not in data or not isinstance(data["adjacent"], list):
            return False, f"Territory '{key}' must have an 'adjacent' list."
        if len(data["adjacent"]) == 0:
            return False, f"Territory '{key}' must have at least one adjacent territory."
        for adj in data["adjacent"]:
            if adj not in keys:
                return False, f"Territory '{key}' references unknown adjacent territory '{adj}'."
            # Check symmetry
            if key not in territories[adj].get("adjacent", []):
                return False, f"Adjacency between '{key}' and '{adj}' is not symmetric."

    # Check connectivity via BFS
    start = next(iter(keys))
    visited = set()
    queue = deque([start])
    visited.add(start)
    while queue:
        node = queue.popleft()
        for adj in territories[node]["adjacent"]:
            if adj not in visited:
                visited.add(adj)
                queue.append(adj)

    if visited != keys:
        return False, "Map is not fully connected."

    return True, ""


def get_adjacency(map_data: dict) -> dict[str, list[str]]:
    """Extract adjacency list from map data."""
    territories = map_data["territories"]
    return {key: data["adjacent"] for key, data in territories.items()}


def bfs_distance(adjacency: dict[str, list[str]], start: str, max_dist: int) -> dict[str, int]:
    """BFS from start, returning {territory_key: distance} up to max_dist."""
    distances = {start: 0}
    queue = deque([start])
    while queue:
        node = queue.popleft()
        d = distances[node]
        if d >= max_dist:
            continue
        for adj in adjacency.get(node, []):
            if adj not in distances:
                distances[adj] = d + 1
                queue.append(adj)
    return distances


# ── Default Maps ────────────────────────────────────────────────────────────

SMALL_MAP = {
    "territories": {
        "north_plains": {"name": "North Plains", "x": 300, "y": 50, "adjacent": ["north_hills", "central_forest", "north_coast"]},
        "north_hills": {"name": "North Hills", "x": 500, "y": 80, "adjacent": ["north_plains", "east_mountains", "central_forest"]},
        "north_coast": {"name": "North Coast", "x": 100, "y": 100, "adjacent": ["north_plains", "west_swamp"]},
        "west_swamp": {"name": "West Swamp", "x": 80, "y": 250, "adjacent": ["north_coast", "central_forest", "south_jungle"]},
        "central_forest": {"name": "Central Forest", "x": 300, "y": 220, "adjacent": ["north_plains", "north_hills", "west_swamp", "east_mountains", "south_desert", "south_jungle"]},
        "east_mountains": {"name": "East Mountains", "x": 520, "y": 220, "adjacent": ["north_hills", "central_forest", "east_valley", "south_desert"]},
        "east_valley": {"name": "East Valley", "x": 580, "y": 350, "adjacent": ["east_mountains", "south_desert"]},
        "south_desert": {"name": "South Desert", "x": 400, "y": 380, "adjacent": ["central_forest", "east_mountains", "east_valley", "south_jungle", "south_ruins"]},
        "south_jungle": {"name": "South Jungle", "x": 200, "y": 400, "adjacent": ["west_swamp", "central_forest", "south_desert", "south_ruins"]},
        "south_ruins": {"name": "South Ruins", "x": 300, "y": 500, "adjacent": ["south_desert", "south_jungle", "south_coast"]},
        "south_coast": {"name": "South Coast", "x": 300, "y": 580, "adjacent": ["south_ruins", "south_island"]},
        "south_island": {"name": "South Island", "x": 400, "y": 620, "adjacent": ["south_coast"]},
    }
}

MEDIUM_MAP = {
    "territories": {
        # Northwest region
        "nw_castle": {"name": "NW Castle", "x": 80, "y": 50, "adjacent": ["nw_village", "n_bridge"]},
        "nw_village": {"name": "NW Village", "x": 80, "y": 150, "adjacent": ["nw_castle", "w_forest", "n_bridge"]},
        "n_bridge": {"name": "North Bridge", "x": 250, "y": 80, "adjacent": ["nw_castle", "nw_village", "ne_tower", "n_lake"]},
        "n_lake": {"name": "North Lake", "x": 350, "y": 50, "adjacent": ["n_bridge", "ne_tower", "ne_fields"]},
        # Northeast region
        "ne_tower": {"name": "NE Tower", "x": 500, "y": 60, "adjacent": ["n_bridge", "n_lake", "ne_fields", "e_cliffs"]},
        "ne_fields": {"name": "NE Fields", "x": 550, "y": 150, "adjacent": ["n_lake", "ne_tower", "e_cliffs", "ce_plains"]},
        # West region
        "w_forest": {"name": "West Forest", "x": 60, "y": 270, "adjacent": ["nw_village", "w_cave", "cw_marsh"]},
        "w_cave": {"name": "West Cave", "x": 50, "y": 370, "adjacent": ["w_forest", "sw_beach"]},
        # Central region
        "cw_marsh": {"name": "CW Marsh", "x": 200, "y": 250, "adjacent": ["w_forest", "c_fortress", "sw_beach", "nw_village"]},
        "c_fortress": {"name": "Central Fortress", "x": 320, "y": 280, "adjacent": ["cw_marsh", "ce_plains", "s_valley", "n_lake"]},
        "ce_plains": {"name": "CE Plains", "x": 450, "y": 260, "adjacent": ["ne_fields", "c_fortress", "e_cliffs", "se_port"]},
        # East region
        "e_cliffs": {"name": "East Cliffs", "x": 580, "y": 250, "adjacent": ["ne_tower", "ne_fields", "ce_plains", "e_shore"]},
        "e_shore": {"name": "East Shore", "x": 600, "y": 370, "adjacent": ["e_cliffs", "se_port"]},
        # Southwest region
        "sw_beach": {"name": "SW Beach", "x": 80, "y": 470, "adjacent": ["w_cave", "cw_marsh", "s_canyon"]},
        "s_canyon": {"name": "South Canyon", "x": 200, "y": 450, "adjacent": ["sw_beach", "s_valley", "s_river"]},
        "s_valley": {"name": "South Valley", "x": 320, "y": 400, "adjacent": ["c_fortress", "s_canyon", "se_port", "s_river"]},
        # Southeast region
        "se_port": {"name": "SE Port", "x": 500, "y": 400, "adjacent": ["ce_plains", "e_shore", "s_valley", "se_docks"]},
        "se_docks": {"name": "SE Docks", "x": 550, "y": 500, "adjacent": ["se_port", "s_ruins"]},
        # South region
        "s_river": {"name": "South River", "x": 280, "y": 520, "adjacent": ["s_canyon", "s_valley", "s_ruins", "s_wasteland"]},
        "s_ruins": {"name": "South Ruins", "x": 420, "y": 540, "adjacent": ["se_docks", "s_river", "s_wasteland"]},
        "s_wasteland": {"name": "South Wasteland", "x": 350, "y": 610, "adjacent": ["s_river", "s_ruins", "s_island"]},
        "s_island": {"name": "South Island", "x": 350, "y": 680, "adjacent": ["s_wasteland"]},
    }
}

LARGE_MAP = {
    "territories": {
        # Row 1 (top)
        "r1c1": {"name": "Frozen Tundra", "x": 50, "y": 30, "adjacent": ["r1c2", "r2c1"]},
        "r1c2": {"name": "Ice Caverns", "x": 150, "y": 30, "adjacent": ["r1c1", "r1c3", "r2c1", "r2c2"]},
        "r1c3": {"name": "Snow Peaks", "x": 250, "y": 30, "adjacent": ["r1c2", "r1c4", "r2c2", "r2c3"]},
        "r1c4": {"name": "Glacier Pass", "x": 350, "y": 30, "adjacent": ["r1c3", "r1c5", "r2c3", "r2c4"]},
        "r1c5": {"name": "Northern Watch", "x": 450, "y": 30, "adjacent": ["r1c4", "r1c6", "r2c4", "r2c5"]},
        "r1c6": {"name": "Arctic Fort", "x": 550, "y": 30, "adjacent": ["r1c5", "r2c5", "r2c6"]},
        # Row 2
        "r2c1": {"name": "Highland Moor", "x": 50, "y": 120, "adjacent": ["r1c1", "r1c2", "r2c2", "r3c1"]},
        "r2c2": {"name": "Misty Forest", "x": 150, "y": 120, "adjacent": ["r1c2", "r1c3", "r2c1", "r2c3", "r3c1", "r3c2"]},
        "r2c3": {"name": "Wolf Den", "x": 250, "y": 120, "adjacent": ["r1c3", "r1c4", "r2c2", "r2c4", "r3c2", "r3c3"]},
        "r2c4": {"name": "Mountain Pass", "x": 350, "y": 120, "adjacent": ["r1c4", "r1c5", "r2c3", "r2c5", "r3c3", "r3c4"]},
        "r2c5": {"name": "Eagle's Nest", "x": 450, "y": 120, "adjacent": ["r1c5", "r1c6", "r2c4", "r2c6", "r3c4", "r3c5"]},
        "r2c6": {"name": "Storm Tower", "x": 550, "y": 120, "adjacent": ["r1c6", "r2c5", "r3c5", "r3c6"]},
        # Row 3
        "r3c1": {"name": "Western Fort", "x": 50, "y": 210, "adjacent": ["r2c1", "r2c2", "r3c2", "r4c1"]},
        "r3c2": {"name": "Dark Swamp", "x": 150, "y": 210, "adjacent": ["r2c2", "r2c3", "r3c1", "r3c3", "r4c1", "r4c2"]},
        "r3c3": {"name": "Central Keep", "x": 250, "y": 210, "adjacent": ["r2c3", "r2c4", "r3c2", "r3c4", "r4c2", "r4c3"]},
        "r3c4": {"name": "Grand Plaza", "x": 350, "y": 210, "adjacent": ["r2c4", "r2c5", "r3c3", "r3c5", "r4c3", "r4c4"]},
        "r3c5": {"name": "Eastern Market", "x": 450, "y": 210, "adjacent": ["r2c5", "r2c6", "r3c4", "r3c6", "r4c4", "r4c5"]},
        "r3c6": {"name": "Coastal Cliff", "x": 550, "y": 210, "adjacent": ["r2c6", "r3c5", "r4c5", "r4c6"]},
        # Row 4
        "r4c1": {"name": "Vine Jungle", "x": 50, "y": 300, "adjacent": ["r3c1", "r3c2", "r4c2", "r5c1"]},
        "r4c2": {"name": "Ancient Ruins", "x": 150, "y": 300, "adjacent": ["r3c2", "r3c3", "r4c1", "r4c3", "r5c1", "r5c2"]},
        "r4c3": {"name": "River Crossing", "x": 250, "y": 300, "adjacent": ["r3c3", "r3c4", "r4c2", "r4c4", "r5c2", "r5c3"]},
        "r4c4": {"name": "Open Fields", "x": 350, "y": 300, "adjacent": ["r3c4", "r3c5", "r4c3", "r4c5", "r5c3", "r5c4"]},
        "r4c5": {"name": "Wind Plains", "x": 450, "y": 300, "adjacent": ["r3c5", "r3c6", "r4c4", "r4c6", "r5c4", "r5c5"]},
        "r4c6": {"name": "Sea Tower", "x": 550, "y": 300, "adjacent": ["r3c6", "r4c5", "r5c5", "r5c6"]},
        # Row 5
        "r5c1": {"name": "Sand Dunes", "x": 50, "y": 390, "adjacent": ["r4c1", "r4c2", "r5c2", "r6c1"]},
        "r5c2": {"name": "Oasis", "x": 150, "y": 390, "adjacent": ["r4c2", "r4c3", "r5c1", "r5c3", "r6c1", "r6c2"]},
        "r5c3": {"name": "Desert Fort", "x": 250, "y": 390, "adjacent": ["r4c3", "r4c4", "r5c2", "r5c4", "r6c2", "r6c3"]},
        "r5c4": {"name": "Badlands", "x": 350, "y": 390, "adjacent": ["r4c4", "r4c5", "r5c3", "r5c5", "r6c3", "r6c4"]},
        "r5c5": {"name": "Lava Fields", "x": 450, "y": 390, "adjacent": ["r4c5", "r4c6", "r5c4", "r5c6", "r6c4", "r6c5"]},
        "r5c6": {"name": "Volcano", "x": 550, "y": 390, "adjacent": ["r4c6", "r5c5", "r6c5", "r6c6"]},
        # Row 6 (bottom)
        "r6c1": {"name": "Southern Marsh", "x": 50, "y": 480, "adjacent": ["r5c1", "r5c2", "r6c2", "r7c1"]},
        "r6c2": {"name": "Sunken City", "x": 150, "y": 480, "adjacent": ["r5c2", "r5c3", "r6c1", "r6c3", "r7c1", "r7c2"]},
        "r6c3": {"name": "Lost Temple", "x": 250, "y": 480, "adjacent": ["r5c3", "r5c4", "r6c2", "r6c4", "r7c2", "r7c3"]},
        "r6c4": {"name": "Bone Yard", "x": 350, "y": 480, "adjacent": ["r5c4", "r5c5", "r6c3", "r6c5", "r7c3", "r7c4"]},
        "r6c5": {"name": "Ash Wastes", "x": 450, "y": 480, "adjacent": ["r5c5", "r5c6", "r6c4", "r6c6", "r7c4", "r7c5"]},
        "r6c6": {"name": "Fire Island", "x": 550, "y": 480, "adjacent": ["r5c6", "r6c5", "r7c5", "r7c6"]},
        # Row 7 (bottom edge)
        "r7c1": {"name": "Coral Reef", "x": 50, "y": 560, "adjacent": ["r6c1", "r6c2", "r7c2"]},
        "r7c2": {"name": "Pirate Cove", "x": 150, "y": 560, "adjacent": ["r6c2", "r6c3", "r7c1", "r7c3"]},
        "r7c3": {"name": "Treasure Isle", "x": 250, "y": 560, "adjacent": ["r6c3", "r6c4", "r7c2", "r7c4"]},
        "r7c4": {"name": "Dead Man's Bay", "x": 350, "y": 560, "adjacent": ["r6c4", "r6c5", "r7c3", "r7c5"]},
        "r7c5": {"name": "Kraken Deep", "x": 450, "y": 560, "adjacent": ["r6c5", "r6c6", "r7c4", "r7c6"]},
        "r7c6": {"name": "Edge of World", "x": 550, "y": 560, "adjacent": ["r6c6", "r7c5"]},
    }
}

DEFAULT_MAPS = [
    {"name": "Outpost (12 territories)", "data": SMALL_MAP},
    {"name": "Continent (22 territories)", "data": MEDIUM_MAP},
    {"name": "World War (42 territories)", "data": LARGE_MAP},
]


def seed_default_maps(db, Map):
    """Create default maps in the database if they don't exist."""
    for m in DEFAULT_MAPS:
        existing = Map.query.filter_by(name=m["name"], is_default=True).first()
        if not existing:
            new_map = Map(
                name=m["name"],
                is_default=True,
                map_data=json.dumps(m["data"]),
            )
            db.session.add(new_map)
    db.session.commit()
