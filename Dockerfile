FROM composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer

FROM php:8.4.27-cli-bookworm@sha256:54b467ede1db96c66675e7b78457dd43729d0e7be31978d4a1e5c069ceabcf76

RUN set -eux; \
    snapshot=20261029T000000Z; \
    for archive in debian debian-security; do \
        case "$archive" in debian) suites='bookworm bookworm-updates' ;; *) suites='bookworm-security' ;; esac; \
        printf 'Types: deb\nURIs: https://snapshot.debian.org/archive/%s/%s\nSuites: %s\nComponents: main\nSigned-By: /usr/share/keyrings/debian-archive-keyring.gpg\nCheck-Valid-Until: no\n\n' "$archive" "$snapshot" "$suites"; \
    done > /etc/apt/sources.list.d/debian.sources; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        openssl=3.0.22-1~deb12u2 \
        poppler-utils=22.12.0-2+deb12u3 \
        python3-venv=3.11.2-1+b1 \
        qpdf=11.3.0-1+deb12u1; \
    rm -rf /var/lib/apt/lists/*

COPY docker/pyhanko-requirements.txt /opt/pyhanko/requirements.txt

RUN set -eux; \
    python3 -m venv /opt/pyhanko; \
    /opt/pyhanko/bin/pip install --no-cache-dir --no-deps --require-hashes -r /opt/pyhanko/requirements.txt; \
    ln -s /opt/pyhanko/bin/pyhanko /usr/local/bin/pyhanko

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    HOME=/tmp

RUN set -eux; \
    commit=f27e4064be107b8a662f370a9acdcecd786da9f7; \
    directory=/opt/implementations/pdf-signer; \
    git init -q "$directory"; \
    git -C "$directory" fetch -q --depth 1 https://github.com/SignerPHP/pdf-signer.git "$commit"; \
    git -C "$directory" checkout -q FETCH_HEAD; \
    test "$(git -C "$directory" rev-parse HEAD)" = "$commit"; \
    composer install --working-dir="$directory" --no-dev --no-interaction --no-progress --prefer-dist; \
    rm -rf "$directory/.git" "$COMPOSER_HOME"

WORKDIR /app
