const colorClash = require('./color-clash/controller');

const games = new Map([
  [colorClash.id, colorClash],
]);

function getGameById(id) {
  return games.get(String(id || '').toLowerCase()) || null;
}

function getGameByPath(pathname) {
  for (const game of games.values()) {
    if (game.controllerPath === pathname) return game;
  }
  return null;
}

module.exports = {
  games,
  getGameById,
  getGameByPath,
};
