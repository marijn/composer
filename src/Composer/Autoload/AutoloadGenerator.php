<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Autoload;

use Composer\Installer\InstallationManager;
use Composer\Json\JsonFile;
use Composer\Package\Loader\JsonLoader;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AutoloadGenerator
{
    public function dump(RepositoryInterface $localRepo, PackageInterface $package, InstallationManager $installationManager, $targetDir)
    {
        $autoloadFile = file_get_contents(__DIR__.'/ClassLoader.php');

        $autoloadFile .= <<<'EOF'

// autoload.php generated by Composer

function init() {
    $loader = new ClassLoader();

    $map = require __DIR__.'/autoload_namespaces.php';

    foreach ($map as $namespace => $path) {
        $loader->add($namespace, $path);
    }

    $loader->register();

    return $loader;
}

return init();
EOF;

        $namespacesFile = <<<'EOF'
<?php

// autoload_namespace.php generated by Composer

return array(

EOF;

        // build package => install path map
        $packageMap = array();
        foreach ($localRepo->getPackages() as $package) {
            $packageMap[] = array(
                $package,
                $installationManager->getInstallPath($package)
            );
        }

        // add main package
        $packageMap[] = array($package, '');

        $autoloads = $this->parseAutoloads($packageMap);

        if (isset($autoloads['psr-0'])) {
            foreach ($autoloads['psr-0'] as $def) {
                $exportedPrefix = var_export($def['namespace'], true);
                $exportedPath = var_export($def['path'], true);
                $namespacesFile .= "    $exportedPrefix => dirname(dirname(__DIR__)).$exportedPath,\n";
            }
        }

        $namespacesFile .= ");\n";

        file_put_contents($targetDir.'/autoload.php', $autoloadFile);
        file_put_contents($targetDir.'/autoload_namespaces.php', $namespacesFile);
    }

    /**
     * Compiles an ordered list of namespace => path mappings
     *
     * @param array $packageMap array of array(package, installDir-relative-to-composer.json)
     * @return array array('psr-0' => array(array('namespace' => 'Foo', 'path' => 'installDir')))
     */
    public function parseAutoloads(array $packageMap)
    {
        $autoloads = array();
        foreach ($packageMap as $item) {
            list($package, $installPath) = $item;

            if (null !== $package->getTargetDir()) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
            }

            foreach ($package->getAutoload() as $type => $mapping) {
                foreach ($mapping as $namespace => $path) {
                    $autoloads[$type][] = array(
                        'namespace'   => $namespace,
                        'path'      => ($installPath ? '/'.$installPath : '').'/'.$path,
                    );
                }
            }
        }

        foreach ($autoloads as $type => $maps) {
            usort($autoloads[$type], function ($a, $b) {
                return strcmp($b['namespace'], $a['namespace']);
            });
        }

        return $autoloads;
    }

    /**
     * Registers an autoloader based on an autoload map returned by parseAutoloads
     *
     * @param array $autoloads see parseAutoloads return value
     * @return ClassLoader
     */
    public function createLoader(array $autoloads)
    {
        $loader = new ClassLoader();

        if (isset($autoloads['psr-0'])) {
            foreach ($autoloads['psr-0'] as $def) {
                $loader->add($def['namespace'], '.'.$def['path']);
            }
        }

        return $loader;
    }
}
