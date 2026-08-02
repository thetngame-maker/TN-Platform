const http = require('http');
const { URL } = require('url');
const { getGameById, getGameByPath } = require('./games/registry');

const originalCreateServer = http.createServer.bind(http);

function sendHtml(res, html) {
  const body = Buffer.from(html);
  res.writeHead(200, {
    'Content-Type': 'text/html; charset=utf-8',
    'Content-Length': body.length,
    'Cache-Control': 'no-store',
    Connection: 'close',
  });
  res.end(body);
}

http.createServer = function modularCreateServer(listener) {
  return originalCreateServer(async (req, res) => {
    try {
      const url = new URL(req.url || '/', 'http://localhost');
      let game = getGameByPath(url.pathname);

      // Backward compatibility for older Roku packages.
      if (!game && url.pathname === '/') {
        game = getGameById(url.searchParams.get('game'));
      }

      if (req.method === 'GET' && game) {
        sendHtml(res, game.renderController({
          roomCode: url.searchParams.get('room') || '',
          url,
        }));
        return;
      }

      return listener(req, res);
    } catch (error) {
      console.error(error);
      res.statusCode = 500;
      res.end('Server error');
    }
  });
};

require('./account-signin-v2.1-launcher.js');
