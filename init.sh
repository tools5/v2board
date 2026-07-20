#!/bin/bash

set -Eeuo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
cd "$project_dir"

ADAPTERMAN_VERSION="0.7.1"

assert_supported_php() {
    if ! command -v php >/dev/null 2>&1; then
        echo "PHP CLI is required." >&2
        exit 1
    fi
    if ! php -r 'exit(PHP_VERSION_ID >= 80100 && PHP_VERSION_ID < 90000 ? 0 : 1);'; then
        echo "PHP 8.1-8.x is required; detected PHP $(php -r 'echo PHP_VERSION;')." >&2
        exit 1
    fi
}

download_file() {
    local url="$1"
    local destination="$2"

    if command -v curl >/dev/null 2>&1; then
        curl --fail --silent --show-error --location "$url" --output "$destination"
        return
    fi
    if command -v wget >/dev/null 2>&1; then
        wget --quiet "$url" --output-document="$destination"
        return
    fi

    echo "Neither curl nor wget is available; install Composer manually." >&2
    exit 1
}

resolve_composer() {
    if command -v composer >/dev/null 2>&1; then
        COMPOSER=(composer)
        return
    fi
    if [ -f "$project_dir/composer.phar" ]; then
        COMPOSER=(php "$project_dir/composer.phar")
        return
    fi

    local installer
    local signature_file
    local expected_signature
    local actual_signature
    installer="$(mktemp "${TMPDIR:-/tmp}/composer-setup.XXXXXX.php")"
    signature_file="$(mktemp "${TMPDIR:-/tmp}/composer-signature.XXXXXX")"
    download_file "https://getcomposer.org/installer" "$installer"
    download_file "https://composer.github.io/installer.sig" "$signature_file"
    expected_signature="$(tr -d '\r\n' < "$signature_file")"
    actual_signature="$(php -r 'echo hash_file("sha384", $argv[1]);' "$installer")"
    if [ -z "$expected_signature" ] || [ "$expected_signature" != "$actual_signature" ]; then
        rm -f "$installer" "$signature_file"
        echo "Composer installer signature verification failed." >&2
        exit 1
    fi

    php "$installer" --quiet --install-dir="$project_dir" --filename=composer.phar
    rm -f "$installer" "$signature_file"
    COMPOSER=(php "$project_dir/composer.phar")
}

adapterman_declared() {
    php -r '
        $config = json_decode(file_get_contents("composer.json"), true);
        exit(is_array($config) && isset($config["require"]["joanhey/adapterman"]) ? 0 : 1);
    '
}

adapterman_version_is_expected() {
    php -r '
        require "vendor/autoload.php";
        $package = "joanhey/adapterman";
        if (!class_exists("Adapterman\\Adapterman")
            || !class_exists("Composer\\InstalledVersions")
            || !Composer\InstalledVersions::isInstalled($package)) {
            exit(1);
        }
        $version = ltrim((string) Composer\InstalledVersions::getPrettyVersion($package), "vV");
        exit($version === $argv[1] ? 0 : 1);
    ' "$ADAPTERMAN_VERSION"
}

assert_supported_php

if [ ! -f composer.lock ]; then
    echo "composer.lock is required for a reproducible installation." >&2
    exit 1
fi
if ! adapterman_declared; then
    echo "joanhey/adapterman $ADAPTERMAN_VERSION must be declared in composer.json." >&2
    exit 1
fi

resolve_composer
"${COMPOSER[@]}" install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

if ! adapterman_version_is_expected; then
    echo "composer.lock must install joanhey/adapterman $ADAPTERMAN_VERSION." >&2
    exit 1
fi

php artisan v2board:install

if [ -f "/etc/init.d/bt" ]; then
    chown -R www "$project_dir"
fi
