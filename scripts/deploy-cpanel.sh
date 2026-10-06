#!/bin/bash
# cPanel executes this script from the checked-out repository.
set -euo pipefail
estate_source="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
estate_target="${1:-${HOME:?cPanel home directory is unavailable}/public_html/Estate}"
case "$estate_target" in
  /*) ;;
  *) printf '%s\n' 'Deployment target must be an absolute directory.' >&2; exit 1 ;;
esac
for estate_dir in "$estate_target" "$estate_target/app" "$estate_target/assets" "$estate_target/storage"; do
  if [ -L "$estate_dir" ]; then
    printf 'Refusing a symlink deployment directory: %s\n' "$estate_dir" >&2
    exit 1
  fi
  mkdir -p -- "$estate_dir"
done
estate_target="$(cd -- "$estate_target" && pwd -P)"
# Explicit application allowlist. Never copy Git metadata, secrets, or runtime data.
estate_files=(
  index.php api.php install.php .htaccess
  app/websites.php app/core.php app/billing.php app/config.example.php app/.htaccess
  assets/website.js assets/app.js assets/style.css assets/favicon.svg
  assets/hero.webp assets/property-1.jpg assets/property-2.jpg assets/property-3.jpg
  storage/.htaccess
)
# Check the entire set before copying any application file.
for estate_file in "${estate_files[@]}"; do
  if [ ! -f "$estate_source/$estate_file" ] || [ -L "$estate_source/$estate_file" ]; then
    printf 'Missing or unsafe source file: %s\n' "$estate_file" >&2
    exit 1
  fi
  if [ -L "$estate_target/$estate_file" ]; then
    printf 'Refusing to overwrite symlink: %s\n' "$estate_file" >&2
    exit 1
  fi
done
if [ "$estate_source" = "$estate_target" ]; then
  printf '%s\n' 'Repository already serves from the Estate document directory. Updated HEAD is in place.'
  exit 0
fi
for estate_file in "${estate_files[@]}"; do
  cp -- "$estate_source/$estate_file" "$estate_target/$estate_file"
done
printf 'Estate deployed to %s\n' "$estate_target"
printf '%s\n' 'Existing app/config.php and uploaded files were preserved.'
