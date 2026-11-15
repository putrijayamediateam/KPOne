import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
test('the global throttle alias isolates numeric routes without overriding named dispatch', () => {
    assert.match(read('bootstrap/app.php'), /'throttle'\s*=>\s*RouteIsolatedThrottle::class/);
    const source = read('app/Http/Middleware/RouteIsolatedThrottle.php');
    assert.match(source, /extends ThrottleRequests/);
    assert.match(source, /function resolveRequestSignature/);
    assert.match(source, /parent::resolveRequestSignature\(\$request\)/);
    assert.match(source, /\$route->uri\(\)/);
    assert.doesNotMatch(source, /function\s+(?:handle|handleRequest|handleRequestUsingNamedLimiter|resolveMaxAttempts)\s*\(/);
});

test('application sources cannot bypass the alias with a framework numeric throttle', () => {
    const walk = (dir) => readdirSync(dir, { withFileTypes: true }).flatMap((e) => e.isDirectory() ? walk(`${dir}/${e.name}`) : e.name.endsWith('.php') ? [`${dir}/${e.name}`] : []);

    for (const path of ['app', 'routes', 'bootstrap'].flatMap(walk)) {
        const source = read(path);
        assert.doesNotMatch(source, /ThrottleRequests(?:WithRedis)?::(?:with|class)\s*(?:\(|\.\s*['"]:\d)/, path);
        assert.doesNotMatch(source, /ThrottleRequests(?:WithRedis)?:\s*\d/, path);
    }
});
