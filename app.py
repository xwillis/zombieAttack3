"""Zombie Attack 3 - Multiplayer Online Game.

Flask application with authentication, game management, real-time
Socket.IO communication, and player lookup.
"""

import json
import os
import secrets

from flask import Flask, render_template, request, redirect, url_for, flash, jsonify
from flask_login import LoginManager, login_user, logout_user, login_required, current_user
from flask_socketio import SocketIO, join_room, leave_room, emit

from models import db, User, Game, GamePlayer, Map, Territory
from characters import get_all_characters, get_character, CHARACTERS
from maps import validate_map_data, seed_default_maps
from engine import (
    setup_game,
    calculate_deploy_count,
    deploy_forces,
    end_deploy_phase,
    attack_territory,
    end_attack_phase,
    fortify_move,
    tactician_extra_move,
    ranger_extended_move,
    end_turn,
    get_game_state,
)

# ── App Setup ────────────────────────────────────────────────────────────────

app = Flask(__name__)
app.config["SECRET_KEY"] = os.environ.get("SECRET_KEY", secrets.token_hex(32))
app.config["SQLALCHEMY_DATABASE_URI"] = os.environ.get(
    "DATABASE_URL", "sqlite:///zombieattack3.db"
)
app.config["SQLALCHEMY_TRACK_MODIFICATIONS"] = False

db.init_app(app)
socketio = SocketIO(app, cors_allowed_origins="*")

login_manager = LoginManager()
login_manager.init_app(app)
login_manager.login_view = "login"


@login_manager.user_loader
def load_user(user_id):
    return db.session.get(User, int(user_id))


# ── Initialize DB ────────────────────────────────────────────────────────────

with app.app_context():
    db.create_all()
    seed_default_maps(db, Map)


# ── Auth Routes ──────────────────────────────────────────────────────────────

@app.route("/")
def index():
    if current_user.is_authenticated:
        return redirect(url_for("dashboard"))
    return redirect(url_for("login"))


@app.route("/register", methods=["GET", "POST"])
def register():
    if current_user.is_authenticated:
        return redirect(url_for("dashboard"))
    if request.method == "POST":
        username = request.form.get("username", "").strip()
        password = request.form.get("password", "")
        if len(username) < 3 or len(username) > 64:
            flash("Username must be 3-64 characters.", "error")
            return render_template("register.html")
        if len(password) < 4:
            flash("Password must be at least 4 characters.", "error")
            return render_template("register.html")
        if User.query.filter_by(username=username).first():
            flash("Username already taken.", "error")
            return render_template("register.html")
        user = User(username=username)
        user.set_password(password)
        db.session.add(user)
        db.session.commit()
        login_user(user)
        return redirect(url_for("dashboard"))
    return render_template("register.html")


@app.route("/login", methods=["GET", "POST"])
def login():
    if current_user.is_authenticated:
        return redirect(url_for("dashboard"))
    if request.method == "POST":
        username = request.form.get("username", "").strip()
        password = request.form.get("password", "")
        user = User.query.filter_by(username=username).first()
        if user and user.check_password(password):
            login_user(user)
            return redirect(url_for("dashboard"))
        flash("Invalid username or password.", "error")
    return render_template("login.html")


@app.route("/logout")
@login_required
def logout():
    logout_user()
    return redirect(url_for("login"))


# ── Dashboard ────────────────────────────────────────────────────────────────

@app.route("/dashboard")
@login_required
def dashboard():
    active_games = (
        Game.query.join(GamePlayer)
        .filter(
            GamePlayer.user_id == current_user.id,
            Game.status.in_(["waiting", "active"]),
        )
        .all()
    )
    finished_games = (
        Game.query.join(GamePlayer)
        .filter(
            GamePlayer.user_id == current_user.id,
            Game.status == "finished",
        )
        .order_by(Game.id.desc())
        .limit(10)
        .all()
    )
    maps = Map.query.all()
    return render_template(
        "dashboard.html",
        active_games=active_games,
        finished_games=finished_games,
        maps=maps,
        can_join=current_user.can_join_game(),
        active_count=current_user.active_game_count(),
    )


# ── Player Lookup ────────────────────────────────────────────────────────────

@app.route("/api/players/search")
@login_required
def search_players():
    q = request.args.get("q", "").strip()
    if len(q) < 2:
        return jsonify([])
    users = (
        User.query.filter(
            User.username.ilike(f"%{q}%"),
            User.id != current_user.id,
        )
        .limit(10)
        .all()
    )
    return jsonify([{"id": u.id, "username": u.username} for u in users])


# ── Game Creation & Joining ──────────────────────────────────────────────────

@app.route("/game/create", methods=["POST"])
@login_required
def create_game():
    if not current_user.can_join_game():
        flash("You already have 3 active games. Finish one first.", "error")
        return redirect(url_for("dashboard"))

    game_name = request.form.get("game_name", "").strip() or f"{current_user.username}'s game"
    map_id = request.form.get("map_id", type=int)
    max_players = request.form.get("max_players", 4, type=int)
    max_players = max(2, min(4, max_players))

    game_map = db.session.get(Map, map_id)
    if not game_map:
        flash("Invalid map selected.", "error")
        return redirect(url_for("dashboard"))

    game = Game(name=game_name, map_id=map_id, max_players=max_players)
    db.session.add(game)
    db.session.flush()

    gp = GamePlayer(game_id=game.id, user_id=current_user.id)
    db.session.add(gp)
    db.session.commit()

    return redirect(url_for("character_select", game_id=game.id))


@app.route("/game/<int:game_id>/join", methods=["POST"])
@login_required
def join_game(game_id):
    if not current_user.can_join_game():
        flash("You already have 3 active games.", "error")
        return redirect(url_for("dashboard"))

    game = db.session.get(Game, game_id)
    if not game or game.status != "waiting":
        flash("Game not available.", "error")
        return redirect(url_for("dashboard"))

    if game.get_player(current_user.id):
        return redirect(url_for("character_select", game_id=game.id))

    if len(game.players) >= game.max_players:
        flash("Game is full.", "error")
        return redirect(url_for("dashboard"))

    gp = GamePlayer(game_id=game.id, user_id=current_user.id)
    db.session.add(gp)
    db.session.commit()

    socketio.emit("player_joined", {
        "user_id": current_user.id,
        "username": current_user.username,
    }, room=f"game_{game_id}")

    return redirect(url_for("character_select", game_id=game.id))


@app.route("/game/<int:game_id>/invite", methods=["POST"])
@login_required
def invite_player(game_id):
    game = db.session.get(Game, game_id)
    if not game or game.status != "waiting":
        return jsonify({"error": "Game not available."}), 400

    if not game.get_player(current_user.id):
        return jsonify({"error": "You're not in this game."}), 403

    target_id = request.json.get("user_id")
    target = db.session.get(User, target_id)
    if not target:
        return jsonify({"error": "Player not found."}), 404

    if not target.can_join_game():
        return jsonify({"error": f"{target.username} already has 3 active games."}), 400

    if game.get_player(target_id):
        return jsonify({"error": f"{target.username} is already in this game."}), 400

    if len(game.players) >= game.max_players:
        return jsonify({"error": "Game is full."}), 400

    gp = GamePlayer(game_id=game.id, user_id=target_id)
    db.session.add(gp)
    db.session.commit()

    socketio.emit("player_joined", {
        "user_id": target_id,
        "username": target.username,
    }, room=f"game_{game_id}")

    return jsonify({"success": True, "username": target.username})


# ── Character Selection ──────────────────────────────────────────────────────

@app.route("/game/<int:game_id>/characters")
@login_required
def character_select(game_id):
    game = db.session.get(Game, game_id)
    if not game:
        flash("Game not found.", "error")
        return redirect(url_for("dashboard"))
    gp = game.get_player(current_user.id)
    if not gp:
        flash("You're not in this game.", "error")
        return redirect(url_for("dashboard"))
    return render_template(
        "character_select.html",
        game=game,
        characters=get_all_characters(),
        current_character=gp.character_type,
    )


@app.route("/game/<int:game_id>/characters/select", methods=["POST"])
@login_required
def select_character(game_id):
    game = db.session.get(Game, game_id)
    if not game or game.status != "waiting":
        flash("Cannot change character now.", "error")
        return redirect(url_for("dashboard"))

    gp = game.get_player(current_user.id)
    if not gp:
        flash("You're not in this game.", "error")
        return redirect(url_for("dashboard"))

    char_key = request.form.get("character", "scout")
    if char_key not in CHARACTERS:
        char_key = "scout"

    gp.character_type = char_key
    db.session.commit()

    socketio.emit("character_selected", {
        "user_id": current_user.id,
        "character": char_key,
    }, room=f"game_{game_id}")

    return redirect(url_for("character_select", game_id=game.id))


@app.route("/game/<int:game_id>/start", methods=["POST"])
@login_required
def start_game(game_id):
    game = db.session.get(Game, game_id)
    if not game or game.status != "waiting":
        flash("Cannot start this game.", "error")
        return redirect(url_for("dashboard"))

    if len(game.players) < 2:
        flash("Need at least 2 players to start.", "error")
        return redirect(url_for("character_select", game_id=game.id))

    setup_game(game)

    socketio.emit("game_started", {"game_id": game.id}, room=f"game_{game_id}")

    return redirect(url_for("game_view", game_id=game.id))


# ── Game View ────────────────────────────────────────────────────────────────

@app.route("/game/<int:game_id>")
@login_required
def game_view(game_id):
    game = db.session.get(Game, game_id)
    if not game:
        flash("Game not found.", "error")
        return redirect(url_for("dashboard"))
    gp = game.get_player(current_user.id)
    if not gp:
        flash("You're not in this game.", "error")
        return redirect(url_for("dashboard"))
    if game.status == "waiting":
        return redirect(url_for("character_select", game_id=game.id))

    char = get_character(gp.character_type)
    return render_template("game.html", game=game, player=gp, character=char)


@app.route("/api/game/<int:game_id>/state")
@login_required
def api_game_state(game_id):
    game = db.session.get(Game, game_id)
    if not game:
        return jsonify({"error": "Game not found."}), 404
    if not game.get_player(current_user.id):
        return jsonify({"error": "Not in this game."}), 403
    return jsonify(get_game_state(game))


# ── Joinable Games List ──────────────────────────────────────────────────────

@app.route("/games/open")
@login_required
def open_games():
    games = Game.query.filter(Game.status == "waiting").all()
    result = []
    for g in games:
        if not g.get_player(current_user.id) and len(g.players) < g.max_players:
            result.append({
                "id": g.id,
                "name": g.name,
                "players": len(g.players),
                "max_players": g.max_players,
                "map_name": g.map.name,
            })
    return render_template("open_games.html", games=result)


# ── Map Editor ───────────────────────────────────────────────────────────────

@app.route("/maps/editor")
@login_required
def map_editor():
    return render_template("map_editor.html")


@app.route("/api/maps", methods=["POST"])
@login_required
def create_map():
    data = request.get_json()
    if not data:
        return jsonify({"error": "No data provided."}), 400

    name = data.get("name", "").strip()
    if not name or len(name) > 128:
        return jsonify({"error": "Map name must be 1-128 characters."}), 400

    map_data = data.get("map_data")
    if not map_data:
        return jsonify({"error": "No map data provided."}), 400

    valid, err = validate_map_data(map_data)
    if not valid:
        return jsonify({"error": err}), 400

    new_map = Map(
        name=name,
        creator_id=current_user.id,
        is_default=False,
        map_data=json.dumps(map_data),
    )
    db.session.add(new_map)
    db.session.commit()
    return jsonify({"success": True, "map_id": new_map.id, "name": new_map.name})


@app.route("/api/maps")
@login_required
def list_maps():
    maps = Map.query.all()
    return jsonify([
        {
            "id": m.id,
            "name": m.name,
            "is_default": m.is_default,
            "creator": m.creator.username if m.creator else "System",
        }
        for m in maps
    ])


# ── Socket.IO Game Events ───────────────────────────────────────────────────

@socketio.on("join_game")
def handle_join_game(data):
    game_id = data.get("game_id")
    if game_id:
        join_room(f"game_{game_id}")
        emit("player_connected", {
            "user_id": current_user.id,
            "username": current_user.username,
        }, room=f"game_{game_id}")


@socketio.on("leave_game")
def handle_leave_game(data):
    game_id = data.get("game_id")
    if game_id:
        leave_room(f"game_{game_id}")


@socketio.on("deploy")
def handle_deploy(data):
    game_id = data.get("game_id")
    game = db.session.get(Game, game_id)
    if not game:
        emit("error", {"message": "Game not found."})
        return

    result = deploy_forces(
        game, current_user.id,
        data.get("territory_key"),
        data.get("count", 1),
    )
    if "error" in result:
        emit("error", {"message": result["error"]})
    else:
        emit("game_update", get_game_state(game), room=f"game_{game_id}")


@socketio.on("end_deploy")
def handle_end_deploy(data):
    game_id = data.get("game_id")
    game = db.session.get(Game, game_id)
    if not game:
        return

    result = end_deploy_phase(game, current_user.id)
    if "error" not in result:
        emit("game_update", get_game_state(game), room=f"game_{game_id}")
    else:
        emit("error", {"message": result["error"]})


@socketio.on("attack")
def handle_attack(data):
    game_id = data.get("game_id")
    game = db.session.get(Game, game_id)
    if not game:
        return

    result = attack_territory(
        game, current_user.id,
        data.get("from_key"),
        data.get("to_key"),
    )
    if "error" in result:
        emit("error", {"message": result["error"]})
    else:
        emit("combat_result", result, room=f"game_{game_id}")
        emit("game_update", get_game_state(game), room=f"game_{game_id}")


@socketio.on("end_attack")
def handle_end_attack(data):
    game_id = data.get("game_id")
    game = db.session.get(Game, game_id)
    if not game:
        return

    result = end_attack_phase(game, current_user.id)
    if "error" not in result:
        emit("game_update", get_game_state(game), room=f"game_{game_id}")
    else:
        emit("error", {"message": result["error"]})


@socketio.on("fortify")
def handle_fortify(data):
    game_id = data.get("game_id")
    game = db.session.get(Game, game_id)
    if not game:
        return

    result = fortify_move(
        game, current_user.id,
        data.get("from_key"),
        data.get("to_key"),
        data.get("count", 1),
    )
    if "error" in result:
        emit("error", {"message": result["error"]})
    else:
        emit("game_update", get_game_state(game), room=f"game_{game_id}")


@socketio.on("use_ability")
def handle_ability(data):
    game_id = data.get("game_id")
    game = db.session.get(Game, game_id)
    if not game:
        return

    ability_type = data.get("ability_type")
    if ability_type == "tactician_move":
        result = tactician_extra_move(
            game, current_user.id,
            data.get("from_key"),
            data.get("to_key"),
            data.get("count", 1),
        )
    elif ability_type == "ranger_move":
        result = ranger_extended_move(
            game, current_user.id,
            data.get("from_key"),
            data.get("to_key"),
            data.get("count", 1),
        )
    else:
        result = {"error": "Unknown ability."}

    if "error" in result:
        emit("error", {"message": result["error"]})
    else:
        emit("game_update", get_game_state(game), room=f"game_{game_id}")


@socketio.on("end_turn")
def handle_end_turn(data):
    game_id = data.get("game_id")
    game = db.session.get(Game, game_id)
    if not game:
        return

    result = end_turn(game, current_user.id)
    if "error" in result:
        emit("error", {"message": result["error"]})
    else:
        if result.get("zombie_moves"):
            emit("zombie_moves", {"moves": result["zombie_moves"]}, room=f"game_{game_id}")
        emit("game_update", get_game_state(game), room=f"game_{game_id}")
        if result.get("game_over"):
            emit("game_over", {
                "winner_id": result["winner_id"],
            }, room=f"game_{game_id}")


@socketio.on("chat_message")
def handle_chat(data):
    game_id = data.get("game_id")
    message = data.get("message", "").strip()
    if game_id and message:
        emit("chat_message", {
            "user_id": current_user.id,
            "username": current_user.username,
            "message": message,
        }, room=f"game_{game_id}")


# ── Run ──────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    socketio.run(app, host="0.0.0.0", port=5000, debug=True)
