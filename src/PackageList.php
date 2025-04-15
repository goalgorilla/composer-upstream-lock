<?php

declare(strict_types=1);

namespace OpenSocial\ComposerUpstreamLock;

use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Repository\LockArrayRepository;

class PackageList implements \ArrayAccess {

  public function __construct(
    protected IOInterface $io,
    protected array $packages = [],
  ) {
  }

  public static function isInfrastructurePackage(string $name) : bool {
    // Virtual packages that are used for platform requirements.
    // See https://getcomposer.org/doc/01-basic-usage.md.
    return $name === "php"
      || $name === "hhvm"
      || $name === "composer-plugin-api"
      || $name === "composer-runtime-api"
      || str_starts_with($name, "ext-")
      || str_starts_with($name, "lib-")
    ;
  }

  public function addDependencyPackages(LockArrayRepository $repository, BasePackage $package) : void {
    foreach ($package->getRequires() as $link) {
      $package_name = $link->getTarget();

      if (self::isInfrastructurePackage($package_name)) {
        continue;
      }

      // Skip packages we've locked before since we'll have already traversed
      // their tree.
      if (isset($this[$package_name])) {
        continue;
      }

      $found_package = $repository->findPackage($package_name, $link->getConstraint());
      // It's possible that a package is virtual (e.g.
      // `psr/http-factory-implementation`), in that case we add all the
      // packages that provide it as option instead.
      if ($found_package === NULL) {
        $providers = $repository->getProviders($package_name);
        if ($providers === []) {
          throw new \Exception("Package {$link->getSource()} which was present in the upstream lock file requires $package_name but it was not found in the upstream lock file. This indicates the upstream lock file is corrupt.");
        }

        foreach ($providers as $provider_info) {
          $provider = $repository->findPackage($provider_info['name'], "*");
          if ($provider === NULL) {
            // This should never happen.
            throw new \RuntimeException("Repository for upstream lock file said {$provider_info['name']} provided $package_name but the repository didn't contain the actual package.");
          }
          $found_packages[$provider->getName()] = $provider;
        }
      }
      else {
        $found_packages[$package_name] = $found_package;
      }

      // For every package that we found (either the required package directly
      // or all providers for the virtual package) make sure we lock their
      // dependencies as well.
      foreach ($found_packages as $name => $found_package) {
        $this->io->info("Locking package {$name} to {$found_package->getVersion()} (dependency of {$package->getName()} based on upstream lock file.",);
        $this[$name] = $found_package;
        $this->addDependencyPackages($repository, $found_package);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function offsetExists(mixed $offset): bool {
    return isset($this->packages[$offset]);
  }

  /**
   * {@inheritdoc}
   */
  public function offsetGet(mixed $offset): BasePackage {
    return $this->packages[$offset];
  }

  /**
   * {@inheritdoc}
   */
  public function offsetSet(mixed $offset, mixed $value): void {
    assert($value instanceof BasePackage);
    $this->packages[$offset] = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function offsetUnset(mixed $offset): void {
    unset($this->packages[$offset]);
  }

  /**
   * Get the array of packages in this package list.
   *
   * @return \Composer\Package\BasePackage[]
   *   The array of packages.
   */
  public function toArray() : array {
    return array_values($this->packages);
  }

}
