#!/usr/bin/env sh
set -eu

mkdir -p \
  storage/app/public/uploaded \
  storage/app/public/converted \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/testing \
  storage/framework/views \
  bootstrap/cache

if [ "${WAIT_FOR_DB:-false}" = "true" ]; then
  echo "Waiting for database ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}"
  until php -r '$host=getenv("DB_HOST") ?: "127.0.0.1"; $port=getenv("DB_PORT") ?: "3306"; $db=getenv("DB_DATABASE") ?: ""; $user=getenv("DB_USERNAME") ?: ""; $pass=getenv("DB_PASSWORD") ?: ""; try { new PDO("mysql:host=$host;port=$port;dbname=$db", $user, $pass, [PDO::ATTR_TIMEOUT => 3]); exit(0); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }'; do
    sleep 3
  done
fi

if [ "${WAIT_FOR_REDIS:-false}" = "true" ]; then
  echo "Waiting for Redis ${REDIS_HOST:-127.0.0.1}:${REDIS_PORT:-6379}"
  until php -r '$host=getenv("REDIS_HOST") ?: "127.0.0.1"; $port=(int)(getenv("REDIS_PORT") ?: 6379); $timeout=3; $socket=@fsockopen($host, $port, $errno, $errstr, $timeout); if ($socket) { fclose($socket); exit(0); } fwrite(STDERR, $errstr.PHP_EOL); exit(1);'; do
    sleep 3
  done
fi

exec "$@"
