# Composer Upstream Lock

The Composer Upstream Lock plugin provides the ability for a composer project to use an external 
lock file to lock packages. This is useful in case you have a known set of tested dependencies 
for a specific project but might only want to deploy a subset of those packages; or in case you 
want to add other packages without updating the lock file for the entire project.

This makes it easy to build variations on a specific project without accidentally upgrading to an 
untested package.

## Installation
Add the plugin to your `require-dev` and allow it to execute code:

```shell
composer config 'allow-plugins.goalgorilla/composer-upstream-lock' true
composer require --dev goalgorilla/composer-upstream-lock
```

The composer config is run before the require so that the above lines can be added to automated 
build systems that construct a `composer.json` file.

## Usage
By default, the plugin will do nothing. You can verify this by running the `install` or `update` 
command in very verbose mode by specifying `-vv`. This will show info messages from the plugin.

To specify a lock file with which `composer require` for a specific package (or an initial 
`composer install` without a lock file present) should run, specify an external lock file in the 
`COMPOSER_UPSTREAM_LOCK_FILE` environment variable:

```shell 
COMPOSER_UPSTREAM_LOCK_FILE=../../path/to/shared/composer.lock composer install
COMPOSER_UPSTREAM_LOCK_FILE=/absolute/paths/work/too/lock.json composer require some/package
```

The lock file can also be an HTTP URL to an external file. However, to be able to download the 
file the `COMPOSER_UPSTREAM_LOCK_ALLOW_HTTP` environment variable must be set to a truthy value.

```shell
COMPOSER_UPSTREAM_LOCK_ALLOW_HTTP=1 COMPOSER_UPSTREAM_LOCK_FILE=https://my-shared-repo.example.com/lockfiles/2024.1/composer.lock composer require some/package
```
