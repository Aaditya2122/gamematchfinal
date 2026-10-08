-- GameMatch 3.9 personalization
-- Safe: creates only the played_games table.
CREATE TABLE IF NOT EXISTS played_games (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NOT NULL,
    played_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_played_user_game (user_id, game_id),
    KEY idx_played_user (user_id),
    KEY idx_played_game (game_id)
);
