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

assert_supported_php

if [ ! -d ".git" ]; then
    echo "Please deploy using Git." >&2
    exit 1
fi
if ! command -v git >/dev/null 2>&1; then
    echo "Git is not installed. Install Git and try again." >&2
    exit 1
fi

GIT=(git -c "safe.directory=$project_dir")
if ! current_branch="$("${GIT[@]}" symbolic-ref --quiet --short HEAD)"; then
    echo "The repository is in detached HEAD state; switch to a branch before updating." >&2
    exit 1
fi
if [ -n "$("${GIT[@]}" status --porcelain --untracked-files=normal)" ]; then
    echo "The working tree has local changes. Commit, stash, or remove them before updating." >&2
    exit 1
fi

upstream="$("${GIT[@]}" rev-parse --abbrev-ref --symbolic-full-name '@{upstream}' 2>/dev/null || true)"
if [ -z "$upstream" ]; then
    update_remote="${V2BOARD_UPDATE_REMOTE:-origin}"
    update_branch="${V2BOARD_UPDATE_BRANCH:-master}"
else
    update_remote="${upstream%%/*}"
    update_branch="${upstream#*/}"
fi
if [ -z "$update_remote" ] || [ -z "$update_branch" ] || [ "$update_remote" = "$update_branch" ]; then
    echo "Unable to determine the update remote and branch." >&2
    exit 1
fi

echo "Updating $current_branch from $update_remote/$update_branch using fast-forward only."
"${GIT[@]}" fetch --prune "$update_remote" "$update_branch"
"${GIT[@]}" merge --ff-only FETCH_HEAD

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

adapterman_available() {
    php -r '
        require "vendor/autoload.php";
        exit(class_exists("Adapterman\\Adapterman") ? 0 : 1);
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

if [ ! -f composer.lock ]; then
    echo "composer.lock is required for a reproducible update." >&2
    exit 1
fi

if ! adapterman_declared; then
    echo "joanhey/adapterman $ADAPTERMAN_VERSION must be declared in composer.json." >&2
    exit 1
fi
webman_was_running=0
if [ -f "$project_dir/workerman.webman.php.pid" ]; then
    if ! adapterman_available; then
        echo "Webman appears to be running, but its AdapterMan dependency is unavailable." >&2
        exit 1
    fi
    WEBMAN_PHP=(php)
    if [ -f "$project_dir/cli-php.ini" ]; then
        WEBMAN_PHP=(php -c "$project_dir/cli-php.ini")
    fi
    if ! "${WEBMAN_PHP[@]}" -m | grep --quiet --ignore-case '^pcntl$'; then
        echo "Webman is running, but the selected CLI PHP configuration does not load pcntl." >&2
        exit 1
    fi
    "${WEBMAN_PHP[@]}" "$project_dir/webman.php" stop
    webman_was_running=1
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

php artisan v2board:update

if [ "$webman_was_running" -eq 1 ]; then
    echo "Webman was stopped for the update. Restart it after reviewing the update output."
fi

if [ -f "/etc/init.d/bt" ]; then
    chown -R www "$project_dir"
fi
