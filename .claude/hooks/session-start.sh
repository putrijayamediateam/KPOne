#!/bin/bash
# SessionStart hook for Claude Code cloud sessions: PHP 8.4 (via Docker), Composer and Node packages,
# and Wayfinder routes, so tests, linters and the build run. Synthetic/dev use only; never touches a real .env.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

REPO="${CLAUDE_PROJECT_DIR:-$(pwd)}"
PHP_IMAGE=serversideup/php:8.4-cli
cd "$REPO"

# 1. Docker daemon. The cloud container has PHP 8.3; KPOne needs 8.4, which runs in a container.
if ! docker info >/dev/null 2>&1; then
  (dockerd >/tmp/dockerd.log 2>&1 &)
  for _ in $(seq 1 30); do docker info >/dev/null 2>&1 && break; sleep 1; done
fi
docker image inspect "$PHP_IMAGE" >/dev/null 2>&1 || docker pull -q "$PHP_IMAGE" >/dev/null
docker image inspect postgres:18 >/dev/null 2>&1 || docker pull -q postgres:18 >/dev/null

# 2. `kphp`: run PHP 8.4 against the repo. Pass settings with KPHP_ENV_FILE, never a real .env.
cat >/usr/local/bin/kphp <<EOF
#!/bin/sh
docker info >/dev/null 2>&1 || { (dockerd >/tmp/dockerd.log 2>&1 &); sleep 5; }
exec docker run --rm --network host --user 0:0 \${KPHP_ENV_FILE:+--env-file "\$KPHP_ENV_FILE"} \
  -v "$REPO":/app -w /app --entrypoint php $PHP_IMAGE "\$@"
EOF
chmod +x /usr/local/bin/kphp

# 3. Composer packages. The proxy blocks GitHub archive downloads but allows git clones, so packages are
#    cloned from source at their locked commits. phpstan/phpstan ships only as an archive and is skipped;
#    PHPStan runs in CI.
export COMPOSER_ALLOW_SUPERUSER=1
if [ ! -f vendor/composer/installed.php ] || [ composer.lock -nt vendor/composer/installed.php ]; then
  composer config -g use-github-api false
  composer config -g github-protocols https
  env -u GH_TOKEN -u GITHUB_TOKEN composer install --no-interaction --no-progress --no-scripts \
    --ignore-platform-reqs --prefer-source >/tmp/composer-install.log 2>&1 || true

  python3 - <<'PY'
import json, os, re, subprocess
lock = json.load(open('composer.lock'))
pkgs = [p for p in lock['packages'] + lock['packages-dev'] if 'source' in p]
cache = os.path.expanduser('~/.cache/composer/vcs')
entries = {d.lower(): d for d in os.listdir(cache)} if os.path.isdir(cache) else {}
for p in pkgs:
    url, ref = p['source']['url'], p['source']['reference']
    dest = os.path.join('vendor', p['name'])
    src = entries.get(re.sub(r'[^a-zA-Z0-9.]', '-', url).lower())
    src = os.path.join(cache, src) if src else url
    if not os.path.isdir(os.path.join(dest, '.git')):
        subprocess.run(['rm', '-rf', dest])
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        subprocess.run(['git', 'clone', '-q', '--no-checkout'] + (['--shared'] if src != url else []) + [src, dest], check=True)
    subprocess.run(['git', '-C', dest, 'checkout', '-q', ref], check=True)
os.makedirs('vendor/bin', exist_ok=True)
for p in pkgs:
    for b in p.get('bin', []):
        link = os.path.join('vendor/bin', os.path.basename(b))
        if os.path.lexists(link):
            os.remove(link)
        os.symlink(os.path.join('..', p['name'], b), link)
os.makedirs('vendor/composer', exist_ok=True)
json.dump({'packages': [dict(p, **{'install-path': '../' + p['name'], 'installation-source': 'source'}) for p in pkgs],
           'dev': True, 'dev-package-names': [p['name'] for p in lock['packages-dev'] if 'source' in p]},
          open('vendor/composer/installed.json', 'w'), indent=1)
print('composer packages:', len(pkgs))
PY
  php -r "Phar::loadPhar('$(readlink -f "$(command -v composer)")','composer.phar'); copy('phar://composer.phar/src/Composer/InstalledVersions.php','vendor/composer/InstalledVersions.php');"
  docker run --rm --user 0:0 -v "$REPO":/app -w /app --entrypoint composer "$PHP_IMAGE" dump-autoload --no-scripts -q
  kphp -r '
$lock = json_decode(file_get_contents("composer.lock"), true);
$dev = array_column($lock["packages-dev"], "name"); $v = [];
foreach (array_merge($lock["packages"], $lock["packages-dev"]) as $p) {
  $v[$p["name"]] = ["pretty_version" => $p["version"], "version" => $p["version_normalized"] ?? $p["version"],
    "reference" => $p["source"]["reference"] ?? null, "type" => $p["type"] ?? "library",
    "install_path" => "/app/vendor/".$p["name"], "aliases" => [], "dev_requirement" => in_array($p["name"], $dev, true)];
}
$d = ["root" => ["name" => "__root__", "pretty_version" => "dev-main", "version" => "dev-main", "reference" => null,
  "type" => "project", "install_path" => "/app", "aliases" => [], "dev" => true], "versions" => $v];
file_put_contents("vendor/composer/installed.php", "<?php return ".var_export($d, true).";\n");'
fi

# 4. Node packages and Wayfinder routes (needed by vue-tsc, ESLint, the frontend tests and the build).
if [ ! -d node_modules ] || [ package-lock.json -nt node_modules ]; then
  npm ci --no-audit --no-fund >/dev/null
fi
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" DB_CONNECTION=sqlite DB_DATABASE=:memory: \
  docker run --rm --user 0:0 -e APP_KEY -e DB_CONNECTION -e DB_DATABASE -v "$REPO":/app -w /app \
  --entrypoint php "$PHP_IMAGE" artisan wayfinder:generate --with-form >/dev/null

echo "KPOne session setup done: run PHP with 'kphp artisan ...' or 'kphp vendor/bin/phpunit ...'."
