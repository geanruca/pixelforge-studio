# The deployment script configured in Laravel Forge for this site, kept here
# so the way the app is released is part of the repository.

cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# The frontend build generates its route helpers from the application's
# routes. Drop the previous release's route and config cache first, or the
# build would not know about routes added in this release. (Learned the hard
# way: the first deploy that added routes failed at this step.)
$FORGE_PHP artisan optimize:clear

# The box is small and shared: cap Node's heap while Vite builds.
export NODE_OPTIONS=--max-old-space-size=512
npm ci --no-audit --no-fund
npm run build

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan optimize

touch /tmp/fpmlock 2>/dev/null || true
( flock -w 10 9 || exit 1
    echo 'Reloading PHP FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9</tmp/fpmlock

# Workers pick up the new code after finishing their current job.
$FORGE_PHP artisan queue:restart

# Supervised by Forge alongside this script:
#   php artisan queue:work --queue=submissions,images,default --sleep=1 --tries=3 --max-time=3600   (2 processes)
#   * * * * * php artisan schedule:run
