FROM ghcr.io/railwayapp/nixpacks:ubuntu-1745885067

ENTRYPOINT ["/bin/bash", "-l", "-c"]
WORKDIR /app/


COPY .nixpacks/nixpkgs-e24b4c09e963677b1beea49d411cd315a024ad3a.nix .nixpacks/nixpkgs-e24b4c09e963677b1beea49d411cd315a024ad3a.nix
RUN nix-env -if .nixpacks/nixpkgs-e24b4c09e963677b1beea49d411cd315a024ad3a.nix && nix-collect-garbage -d

COPY .nixpacks/assets /assets/
ARG GIT_SHA NIXPACKS_METADATA PORT
ENV GIT_SHA=$GIT_SHA NIXPACKS_METADATA=$NIXPACKS_METADATA PORT=$PORT

# setup phase
# noop

# install phase
COPY . /app/.
RUN  mkdir -p /var/log/nginx && mkdir -p /var/cache/nginx
RUN  composer install --ignore-platform-reqs




# start
COPY . /app

CMD ["node /assets/scripts/prestart.mjs /app/nginx.template.conf /nginx.conf && (php-fpm -y /assets/php-fpm.conf & nginx -c /nginx.conf)"]

