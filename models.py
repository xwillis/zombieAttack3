"""Database models for Zombie Attack 3."""

from datetime import datetime, timezone

from flask_sqlalchemy import SQLAlchemy
from flask_login import UserMixin
from werkzeug.security import generate_password_hash, check_password_hash

db = SQLAlchemy()


class User(UserMixin, db.Model):
    __tablename__ = "users"

    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(64), unique=True, nullable=False, index=True)
    password_hash = db.Column(db.String(256), nullable=False)
    created_at = db.Column(db.DateTime, default=lambda: datetime.now(timezone.utc))

    game_players = db.relationship("GamePlayer", back_populates="user", lazy="dynamic")
    created_maps = db.relationship("Map", back_populates="creator", lazy="dynamic")

    def set_password(self, password):
        self.password_hash = generate_password_hash(password)

    def check_password(self, password):
        return check_password_hash(self.password_hash, password)

    def active_game_count(self):
        return GamePlayer.query.filter(
            GamePlayer.user_id == self.id,
            GamePlayer.game.has(Game.status.in_(["waiting", "active"])),
        ).count()

    def can_join_game(self):
        return self.active_game_count() < 3


class Game(db.Model):
    __tablename__ = "games"

    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(128), nullable=False)
    status = db.Column(db.String(20), default="waiting")  # waiting, active, finished
    map_id = db.Column(db.Integer, db.ForeignKey("maps.id"), nullable=False)
    current_turn_user_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=True)
    turn_number = db.Column(db.Integer, default=0)
    turn_phase = db.Column(db.String(20), default="deploy")  # deploy, attack, fortify
    max_players = db.Column(db.Integer, default=4)
    created_at = db.Column(db.DateTime, default=lambda: datetime.now(timezone.utc))
    winner_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=True)

    map = db.relationship("Map", back_populates="games")
    players = db.relationship("GamePlayer", back_populates="game", lazy="joined")
    territories = db.relationship("Territory", back_populates="game", lazy="joined")
    current_turn_user = db.relationship("User", foreign_keys=[current_turn_user_id])
    winner = db.relationship("User", foreign_keys=[winner_id])

    def get_alive_players(self):
        return [p for p in self.players if p.is_alive]

    def get_player(self, user_id):
        for p in self.players:
            if p.user_id == user_id:
                return p
        return None

    def get_player_territories(self, user_id):
        return [t for t in self.territories if t.owner_id == user_id and not t.is_zombie]

    def get_zombie_territories(self):
        return [t for t in self.territories if t.is_zombie]


class GamePlayer(db.Model):
    __tablename__ = "game_players"

    id = db.Column(db.Integer, primary_key=True)
    game_id = db.Column(db.Integer, db.ForeignKey("games.id"), nullable=False)
    user_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=False)
    character_type = db.Column(db.String(20), default="scout")
    is_alive = db.Column(db.Boolean, default=True)
    color = db.Column(db.String(20), default="#3498db")
    turn_order = db.Column(db.Integer, default=0)
    # Tracks per-turn special ability usage
    ability_used = db.Column(db.Boolean, default=False)

    game = db.relationship("Game", back_populates="players")
    user = db.relationship("User", back_populates="game_players")

    __table_args__ = (db.UniqueConstraint("game_id", "user_id"),)


class Map(db.Model):
    __tablename__ = "maps"

    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(128), nullable=False)
    creator_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=True)
    is_default = db.Column(db.Boolean, default=False)
    map_data = db.Column(db.Text, nullable=False)  # JSON: territories + adjacencies
    created_at = db.Column(db.DateTime, default=lambda: datetime.now(timezone.utc))

    creator = db.relationship("User", back_populates="created_maps")
    games = db.relationship("Game", back_populates="map")


class Territory(db.Model):
    __tablename__ = "territories"

    id = db.Column(db.Integer, primary_key=True)
    game_id = db.Column(db.Integer, db.ForeignKey("games.id"), nullable=False)
    territory_key = db.Column(db.String(64), nullable=False)
    owner_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=True)
    forces = db.Column(db.Integer, default=0)
    is_zombie = db.Column(db.Boolean, default=False)

    game = db.relationship("Game", back_populates="territories")
    owner = db.relationship("User")

    __table_args__ = (db.UniqueConstraint("game_id", "territory_key"),)
