#!/bin/bash

if [ ! -d ".git" ]; then
  echo "Please deploy using Git."
  exit 1
fi

if ! command -v git &> /dev/null; then
    echo "Git is not installed! Please install git and try again."
    exit 1
fi

repo_root="$(pwd)"

add_safe_directory() {
  local dir="$1"

  git config --global --get-all safe.directory | grep -Fx "$dir" > /dev/null ||     git config --global --add safe.directory "$dir"
}

add_safe_directory "$repo_root"
add_safe_directory "$repo_root/public/assets/admin"

git fetch --all && git reset --hard origin/main && git pull origin main
rm -rf composer.lock composer.phar
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
php composer.phar update -vvv
git submodule update --init --recursive --force
for bundle in public/assets/admin/assets/index-*.js; do
  [ -f "$bundle" ] && php scripts/patch-admin-snell.php "$bundle"
done
php artisan xboard:update

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www $(pwd);
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data
fi