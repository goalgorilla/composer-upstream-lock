<?php

declare(strict_types=1);

namespace OpenSocial\ComposerUpstreamLock;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Locker;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Util\HttpDownloader;

class Plugin implements PluginInterface, EventSubscriberInterface {

  protected Composer $composer;
  protected IOInterface $io;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() : array {
    return [
      'pre-pool-create' => 'limitAllowedPackageVersions',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io) : void {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * {@inheritdoc}
   */
  public function deactivate(Composer $composer, IOInterface $io) : void {}

  /**
   * {@inheritdoc}
   */
  public function uninstall(Composer $composer, IOInterface $io) : void {}

  public function limitAllowedPackageVersions(PrePoolCreateEvent $event) : void {
    $lock_file = getenv('COMPOSER_UPSTREAM_LOCK_FILE');
    if (!is_string($lock_file) || $lock_file === "") {
      $this->io->info("No upstream lock file specified, skipped constraining versions.");
      $this->io->info("Specify an upstream lock file by specifying the `COMPOSER_UPSTREAM_LOCK_FILE` environment variable.");
      return;
    }

    // If nothing is being required then we're only installing from an existing
    // lock file (e.g. during a deployment) and we're done.
    $requires = array_filter(
      $event->getRequest()->getRequires(),
      fn (string $package_name) => !PackageList::isInfrastructurePackage($package_name),
      ARRAY_FILTER_USE_KEY
    );
    if ($requires === []) {
      $this->io->info("Installing from existing lock file, skipped constraining versions from an upstream lock file.");
      return;
    }

    $this->io->write("Using upstream lock file '{$lock_file}' to lock versions for known packages.");

    // The packages from our versioned lock-file.
    $http_downloader = getenv("COMPOSER_UPSTREAM_LOCK_ALLOW_HTTP") ? new HttpDownloader($this->io, $this->composer->getConfig()) : NULL;
    $constraint_repository = $this->getVersionConstraintRepository($lock_file, $http_downloader, $this->io);

    // Find the information for the package in which we're running the
    // composer.json command because it's not in the lock file but Composer
    // needs it.
    $root_package_sets = [];
    foreach ($event->getRepositories() as $repository) {
      if ($repository instanceof RootPackageRepository) {
        $root_package_sets[] = $repository->getPackages();
      }
      elseif ($repository instanceof PlatformRepository) {
        $root_package_sets[] = $repository->getPackages();
      }
    }

    $packages = new PackageList($this->io, array_merge(...$root_package_sets));

    foreach ($event->getPackages() as $package) {
      // We only need to make a decision about a package once so if we've done
      // so, then we can continue.
      if (isset($packages[$package->getName()])) {
        continue;
      }

      // Find the version of the package in our upstream lock file. There should
      // be only a single version of the package there.
      $candidate = $constraint_repository->findPackage($package->getName(), "*");

      // If this package is managed by our upstream project then we lock it to
      // the version we've found.
      if ($candidate !== NULL) {
        $packages[$candidate->getName()] = $candidate;
        continue;
      }

      // If this package is not managed by our upstream lockfile then we simply
      // keep the package in the list of options.
      // This happens without specifying the name as key because there might be
      // multiple package options in the original list that we want to preserve.
      $packages[] = $package;
    }

    // Go through the things that should be updated in this request and if
    // they're in our external lock-file, lock them to that version.
    foreach ($requires as $package_name => $constraint) {
      $candidate = $constraint_repository->findPackage($package_name, $constraint);
      if ($candidate !== NULL) {
        $this->io->info("Locking package {$package_name} to {$candidate->getVersion()} based on upstream lock file.",);
        $packages[$package_name] = $candidate;
        // Merge the dependency tree into our package list.
        $packages->addDependencyPackages($constraint_repository, $candidate);
      }
    }

    // Overwrite the list of package options in the request with our own
    // filtered list.
    $event->setPackages($packages->toArray());
  }

  private function getVersionConstraintRepository(string $lock_file, ?HttpDownloader $http_downloader = null, ?IOInterface $io = null) : LockArrayRepository {
    $constraintFile = new JsonFile($lock_file, $http_downloader, $io);
    $composerJson = file_get_contents(getcwd() . "/composer.json");
    assert($composerJson !== FALSE);
    $locker = new Locker($this->io, $constraintFile, $this->composer->getInstallationManager(), $composerJson);
    return $locker->getLockedRepository(TRUE);
  }

}
