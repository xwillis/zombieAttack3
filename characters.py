"""Character definitions for Zombie Attack 3.

Each character has unique abilities that modify game mechanics:
- zombie_aggro_range: how many spaces away zombies will detect and move toward this player
- dead_to_zombie_ratio: fraction of player's dead forces added to zombie forces in combat
- infects_opponent_dead: if True, opponent's dead forces also become zombies
- double_move_end_of_turn: can move 1 set of forces twice at end of turn
- double_move_distance: can move 1 set of forces twice as far, once per turn
"""

from dataclasses import dataclass


@dataclass(frozen=True)
class Character:
    key: str
    name: str
    description: str
    zombie_aggro_range: int = 5
    dead_to_zombie_ratio: float = 1.0
    infects_opponent_dead: bool = False
    double_move_end_of_turn: bool = False
    double_move_distance: bool = False


CHARACTERS = {
    "scout": Character(
        key="scout",
        name="Scout",
        description="A balanced fighter with standard abilities. "
        "Zombies detect you within 5 spaces.",
        zombie_aggro_range=5,
    ),
    "beacon": Character(
        key="beacon",
        name="Beacon",
        description="Your presence attracts the undead from farther away. "
        "Zombies detect you within 6 spaces.",
        zombie_aggro_range=6,
    ),
    "ghost": Character(
        key="ghost",
        name="Ghost",
        description="You move silently, harder for zombies to detect. "
        "Zombies only detect you within 4 spaces.",
        zombie_aggro_range=4,
    ),
    "tactician": Character(
        key="tactician",
        name="Tactician",
        description="A master strategist who can move one set of forces "
        "twice at the end of each turn.",
        double_move_end_of_turn=True,
    ),
    "ranger": Character(
        key="ranger",
        name="Ranger",
        description="A swift scout who can move one set of forces twice "
        "as far, once per turn.",
        double_move_distance=True,
    ),
    "medic": Character(
        key="medic",
        name="Medic",
        description="Your medical expertise means only 50%% of your dead "
        "forces are added to the zombie horde when fighting.",
        dead_to_zombie_ratio=0.5,
    ),
    "plague": Character(
        key="plague",
        name="Plague",
        description="A cursed warrior. Your dead forces become zombies, and "
        "your opponent's dead forces in battle become zombies too.",
        infects_opponent_dead=True,
    ),
}


def get_character(key: str) -> Character:
    """Get a character by its key. Returns Scout if key is invalid."""
    return CHARACTERS.get(key, CHARACTERS["scout"])


def get_all_characters() -> list[dict]:
    """Return all characters as a list of dicts for the frontend."""
    return [
        {
            "key": c.key,
            "name": c.name,
            "description": c.description,
            "zombie_aggro_range": c.zombie_aggro_range,
            "dead_to_zombie_ratio": c.dead_to_zombie_ratio,
            "infects_opponent_dead": c.infects_opponent_dead,
            "double_move_end_of_turn": c.double_move_end_of_turn,
            "double_move_distance": c.double_move_distance,
        }
        for c in CHARACTERS.values()
    ]
