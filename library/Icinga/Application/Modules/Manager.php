<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Application\Modules;

use Icinga\Application\ApplicationBootstrap;
use Icinga\Application\Icinga;
use Icinga\Application\Logger;
use Icinga\Data\DataArray\ArrayDatasource;
use Icinga\Data\SimpleQuery;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\SystemPermissionException;
use Icinga\Exception\ProgrammingError;
use Icinga\Exception\NotReadableError;

/**
 * Module manager that handles detecting, enabling and disabling of modules
 *
 * Modules can have 3 states:
 * * installed, module exists but is disabled
 * * enabled, module enabled and should be loaded
 * * loaded, module enabled and loaded via the autoloader
 *
 */
class Manager
{
    /**
     * Namespace for module permissions
     *
     * @var string
     */
    const MODULE_PERMISSION_NS = 'module/';

    /**
     * Array of all installed module's base directories
     *
     * @var array
     */
    private $installedBaseDirs = array();

    /**
     * Array of all enabled modules base dirs
     *
     * @var array
     */
    private $enabledDirs       = array();

    /**
     * Array of all module names that have been loaded
     *
     * @var array
     */
    private $loadedModules     = array();

    /**
     * Reference to Icinga::app
     *
     * @var Icinga
     */
    private $app;

    /**
     * The directory that is used to detect enabled modules
     *
     * @var string
     */
    private $enableDir;

    /**
     * All paths to look for installed modules that can be enabled
     *
     * @var array
     */
    private $modulePaths        = array();

    /**
     * Whether we loaded all enabled modules
     *
     * @var bool
     */
    private $loadedAllEnabledModules = false;

    /**
     *  Create a new instance of the module manager
     *
     *  @param ApplicationBootstrap $app
     *  @param string               $enabledDir     Enabled modules location. The application maintains symlinks within
     *                                              the given path
     *  @param array                $availableDirs  Installed modules location
     **/
    public function __construct($app, $enabledDir, array $availableDirs)
    {
        $this->app = $app;
        $this->modulePaths = $availableDirs;
        $this->enableDir = $enabledDir;
    }

    /**
     * Query interface for the module manager
     *
     * @return SimpleQuery
     */
    public function select()
    {
        $source = new ArrayDatasource($this->getModuleInfo());
        return $source->select();
    }

    /**
     * Check for enabled modules
     *
     * Update the internal $enabledDirs property with the enabled modules.
     *
     * @throws ConfigurationError If module dir does not exist, is not a directory or not readable
     */
    private function detectEnabledModules()
    {
        if (! file_exists($parent = dirname($this->enableDir))) {
            return;
        }
        if (! is_readable($parent)) {
            throw new NotReadableError(
                'Cannot read enabled modules. Config directory "%s" is not readable',
                $parent
            );
        }

        if (! file_exists($this->enableDir)) {
            return;
        }
        if (! is_dir($this->enableDir)) {
            throw new NotReadableError(
                'Cannot read enabled modules. Module directory "%s" is not a directory',
                $this->enableDir
            );
        }
        if (! is_readable($this->enableDir)) {
            throw new NotReadableError(
                'Cannot read enabled modules. Module directory "%s" is not readable',
                $this->enableDir
            );
        }
        if (($dh = opendir($this->enableDir)) !== false) {
            $isPhar = substr($this->enableDir, 0, 8) === 'phar:///';
            $this->enabledDirs = array();
            while (($file = readdir($dh)) !== false) {
                if ($file[0] === '.' || $file === 'README') {
                    continue;
                }

                $link = $this->enableDir . DIRECTORY_SEPARATOR . $file;
                if (! $isPhar && ! is_link($link)) {
                    Logger::warning(
                        'Found invalid module in enabledModule directory "%s": "%s" is not a symlink',
                        $this->enableDir,
                        $link
                    );
                    continue;
                }

                $dir = $isPhar ? $link : realpath($link);
                if ($dir !== false && is_dir($dir)) {
                    $this->enabledDirs[$file] = $dir;
                } else {
                    $this->enabledDirs[$file] = null;

                    Logger::warning(
                        'Found invalid module in enabledModule directory "%s": "%s" points to non existing path "%s"',
                        $this->enableDir,
                        $link,
                        $dir
                    );
                }

                ksort($this->enabledDirs);
            }
            closedir($dh);
        }
    }

    /**
     * Try to set all enabled modules in loaded sate
     *
     * @return  $this
     * @see     Manager::loadModule()
     */
    public function loadEnabledModules()
    {
        if (! $this->loadedAllEnabledModules) {
            foreach ($this->listEnabledModules() as $name) {
                $this->loadModule($name);
            }

            $this->loadedAllEnabledModules = true;
        }

        return $this;
    }

    /**
     * Whether we loaded all enabled modules
     *
     * @return bool
     */
    public function loadedAllEnabledModules()
    {
        return $this->loadedAllEnabledModules;
    }


    /**
     * Try to load the module and register it in the application
     *
     * @param string $name    The name of the module to load
     * @param mixed  $basedir Optional module base directory
     *
     * @return $this
     */
    public function loadModule($name, $basedir = null)
    {
        if ($this->hasLoaded($name)) {
            return $this;
        }

        $module = null;
        if ($basedir === null) {
            $module = new Module($this->app, $name, $this->getModuleDir($name));
        } else {
            $module = new Module($this->app, $name, $basedir);
        }

        if ($name !== 'ipl' && $name !== 'reactbundle') {
            $module->register();
        }

        $this->loadedModules[$name] = $module;
        return $this;
    }

    /**
     * Set the given module to the enabled state
     *
     * @param   string $name                The module to enable
     * @param   bool   $force               Whether to ignore unmet dependencies
     *
     * @return  $this
     * @throws  ConfigurationError          When trying to enable a module that is not installed
     * @throws  SystemPermissionException   When insufficient permissions for the application exist
     */
    public function enableModule($name, $force = false)
    {
        if (! $this->hasInstalled($name)) {
            throw new ConfigurationError(
                'Cannot enable module "%s". Module is not installed.',
                $name
            );
        }

        if (strtolower(substr($name, 0, 18)) === 'icingaweb2-module-') {
            throw new ConfigurationError(
                'Cannot enable module "%s": Directory name does not match the module\'s name.'
                . ' Please rename the module to "%s" before enabling.',
                $name,
                substr($name, 18)
            );
        }

        if ($this->hasUnmetDependencies($name)) {
            if ($force) {
                Logger::warning(t('Enabling module "%s" although it has unmet dependencies'), $name);
            } else {
                throw new ConfigurationError(
                    t('Module "%s" can\'t be enabled. Module has unmet dependencies'),
                    $name
                );
            }
        }

        clearstatcache(true);
        $target = $this->installedBaseDirs[$name];
        $link = $this->enableDir . DIRECTORY_SEPARATOR . $name;

        if (! is_dir($this->enableDir) && !@mkdir($this->enableDir, 02770, true)) {
            $error = error_get_last();
            throw new SystemPermissionException(
                'Failed to create enabledModules directory "%s" (%s)',
                $this->enableDir,
                $error['message']
            );
        } elseif (! is_writable($this->enableDir)) {
            throw new SystemPermissionException(
                'Cannot enable module "%s". Check the permissions for the enabledModules directory: %s',
                $name,
                $this->enableDir
            );
        }

        $this->loadedAllEnabledModules = false;

        if (file_exists($link) && is_link($link)) {
            return $this;
        }

        if (! @symlink($target, $link)) {
            $error = error_get_last();
            if (strstr($error["message"], "File exists") === false) {
                throw new SystemPermissionException(
                    'Cannot enable module "%s" at %s due to file system errors. '
                    . 'Please check path and mounting points because this is not a permission error. '
                    . 'Primary error was: %s',
                    $name,
                    $this->enableDir,
                    $error['message']
                );
            }
        }

        $this->enabledDirs[$name] = $link;
        $this->loadModule($name);
        return $this;
    }

    /**
     * Disable the given module and remove its enabled state
     *
     * @param   string $name                The name of the module to disable
     *
     * @return  $this
     *
     * @throws  ConfigurationError          When the module is not installed or it's not a symlink
     * @throws  SystemPermissionException   When insufficient permissions for the application exist
     */
    public function disableModule($name)
    {
        if (! $this->hasEnabled($name)) {
            throw new ConfigurationError(
                'Cannot disable module "%s". Module is not installed.',
                $name
            );
        }

        if (! is_writable($this->enableDir)) {
            throw new SystemPermissionException(
                'Cannot disable module "%s". Check the permissions for the enabledModules directory: %s',
                $name,
                $this->enableDir
            );
        }

        $link = $this->enableDir . DIRECTORY_SEPARATOR . $name;
        if (! is_link($link)) {
            throw new ConfigurationError(
                'Cannot disable module %s at %s. '
                . 'It looks like you have installed this module manually and moved it to your module folder. '
                . 'In order to dynamically enable and disable modules, you have to create a symlink to '
                . 'the enabledModules folder.',
                $name,
                $this->enableDir
            );
        }

        if (is_link($link)) {
            if (! @unlink($link)) {
                $error = error_get_last();
                throw new SystemPermissionException(
                    'Cannot enable module "%s" at %s due to file system errors. '
                    . 'Please check path and mounting points because this is not a permission error. '
                    . 'Primary error was: %s',
                    $name,
                    $this->enableDir,
                    $error['message']
                );
            }
        }

        unset($this->enabledDirs[$name]);
        return $this;
    }

    /**
     * Return the directory of the given module as a string, optionally with a given sub directoy
     *
     * @param   string $name    The module name to return the module directory of
     * @param   string $subdir  The sub directory to append to the path
     *
     * @return  string
     *
     * @throws  ProgrammingError When the module is not installed or existing
     */
    public function getModuleDir($name, $subdir = '')
    {
        if ($this->hasLoaded($name)) {
            return $this->getModule($name)->getBaseDir() . $subdir;
        }

        if ($this->hasEnabled($name)) {
            return $this->enabledDirs[$name]. $subdir;
        }

        if ($this->hasInstalled($name)) {
            return $this->installedBaseDirs[$name] . $subdir;
        }

        throw new ProgrammingError(
            'Trying to access uninstalled module dir: %s',
            $name
        );
    }

    /**
     * Return true when the module with the given name is installed, otherwise false
     *
     * @param   string $name The module to check for being installed
     *
     * @return  bool
     */
    public function hasInstalled($name)
    {
        if (!count($this->installedBaseDirs)) {
            $this->detectInstalledModules();
        }
        return array_key_exists($name, $this->installedBaseDirs);
    }

    /**
     * Return true when the given module is in enabled state, otherwise false
     *
     * @param   string $name The module to check for being enabled
     *
     * @return  bool
     */
    public function hasEnabled($name)
    {
        return array_key_exists($name, $this->enabledDirs);
    }

    /**
     * Return true when the module is in loaded state, otherwise false
     *
     * @param   string $name The module to check for being loaded
     *
     * @return  bool
     */
    public function hasLoaded($name)
    {
        return array_key_exists($name, $this->loadedModules);
    }

    /**
     * Check if a module with the given name is enabled
     *
     * Passing a version constraint also verifies that the module's version matches.
     *
     * @param string $name
     * @param string $version
     *
     * @return bool
     */
    public function has($name, $version = null)
    {
        if (! $this->hasEnabled($name)) {
            return false;
        } elseif ($version === null || $version === true) {
            return true;
        }

        $operator = '=';
        if (preg_match('/^([<>=]{1,2})\s*v?((?:[\d.]+)(?:\D+)?)$/', $version, $match)) {
            $operator = $match[1];
            $version = $match[2];
        }

        $modVersion = ltrim($this->getModule($name)->getVersion(), 'v');
        return version_compare($modVersion, $version, $operator);
    }

    /**
     * Get the currently loaded modules
     *
     * @return  Module[]
     */
    public function getLoadedModules()
    {
        return $this->loadedModules;
    }

    /**
     * Get a module
     *
     * @param   string  $name           Name of the module
     * @param   bool    $assertLoaded   Whether or not to throw an exception if the module hasn't been loaded
     *
     * @return  Module
     * @throws  ProgrammingError        If the module hasn't been loaded
     */
    public function getModule($name, $assertLoaded = true)
    {
        if ($this->hasLoaded($name)) {
            return $this->loadedModules[$name];
        } elseif (! (bool) $assertLoaded) {
            return new Module($this->app, $name, $this->getModuleDir($name));
        }
        throw new ProgrammingError(
            'Can\'t access module %s because it hasn\'t been loaded',
            $name
        );
    }

    /**
     * Return an array containing information objects for each available module
     *
     * Each entry has the following fields
     * * name, name of the module as a string
     * * path, path where the module is located as a string
     * * installed, whether the module is installed or not as a boolean
     * * enabled, whether the module is enabled or not as a boolean
     * * loaded, whether the module is loaded or not as a boolean
     *
     * @return array
     */
    public function getModuleInfo()
    {
        $info = array();

        $installed = $this->listInstalledModules();
        foreach ($installed as $name) {
            $info[$name] = (object) array(
                'name'      => $name,
                'path'      => $this->installedBaseDirs[$name],
                'installed' => true,
                'enabled'   => $this->hasEnabled($name),
                'loaded'    => $this->hasLoaded($name)
            );
        }

        $enabled = $this->listEnabledModules();
        foreach ($enabled as $name) {
            $info[$name] = (object) array(
                'name'      => $name,
                'path'      => $this->enabledDirs[$name],
                'installed' => $this->enabledDirs[$name] !== null,
                'enabled'   => true,
                'loaded'    => $this->hasLoaded($name)
            );
        }

        return $info;
    }

    /**
     * Check if the given module has unmet dependencies
     *
     * @param string $name
     *
     * @return bool
     */
    public function hasUnmetDependencies($name)
    {
        $module = $this->getModule($name, false);

        $requiredMods = $module->getRequiredModules();

        if (isset($requiredMods['monitoring'], $requiredMods['icingadb'])) {
            if (! $this->has('monitoring', $requiredMods['monitoring'])
                && ! $this->has('icingadb', $requiredMods['icingadb'])
            ) {
                return true;
            }

            unset($requiredMods['monitoring'], $requiredMods['icingadb']);
        }

        foreach ($requiredMods as $moduleName => $moduleVersion) {
            if (! $this->has($moduleName, $moduleVersion)) {
                return true;
            }
        }

        $libraries = Icinga::app()->getLibraries();

        $requiredLibs = $module->getRequiredLibraries();
        foreach ($requiredLibs as $libraryName => $libraryVersion) {
            if (! $libraries->has($libraryName, $libraryVersion)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return an array containing all enabled module names as strings
     *
     * @return array
     */
    public function listEnabledModules()
    {
        if (count($this->enabledDirs) === 0) {
            $this->detectEnabledModules();
        }

        return array_keys($this->enabledDirs);
    }

    /**
     * Return an array containing all loaded module names as strings
     *
     * @return array
     */
    public function listLoadedModules()
    {
        return array_keys($this->loadedModules);
    }

    /**
     * Return an array of module names from installed modules
     *
     * Calls detectInstalledModules() if no module discovery has been performed yet
     *
     * @return  array
     *
     * @see     detectInstalledModules()
     */
    public function listInstalledModules()
    {
        if (!count($this->installedBaseDirs)) {
            $this->detectInstalledModules();
        }

        if (count($this->installedBaseDirs)) {
            return array_keys($this->installedBaseDirs);
        }

        return array();
    }

    /**
     * Detect installed modules from every path provided in modulePaths
     *
     * @param   array   $availableDirs      Installed modules location
     *
     * @return $this
     */
    public function detectInstalledModules(array $availableDirs = null)
    {
        $modulePaths = $availableDirs !== null ? $availableDirs : $this->modulePaths;
        foreach ($modulePaths as $basedir) {
            $canonical = realpath($basedir);
            if ($canonical === false) {
                Logger::warning('Module path "%s" does not exist', $basedir);
                continue;
            }
            if (!is_dir($canonical)) {
                Logger::error('Module path "%s" is not a directory', $canonical);
                continue;
            }
            if (!is_readable($canonical)) {
                Logger::error('Module path "%s" is not readable', $canonical);
                continue;
            }
            if (($dh = opendir($canonical)) !== false) {
                while (($file = readdir($dh)) !== false) {
                    if ($file[0] === '.') {
                        continue;
                    }
                    if (is_dir($canonical . '/' . $file)) {
                        if (! array_key_exists($file, $this->installedBaseDirs)) {
                            $this->installedBaseDirs[$file] = $canonical . '/' . $file;
                        } else {
                            Logger::debug(
                                'Module "%s" already exists in installation path "%s" and is ignored.',
                                $canonical . '/' . $file,
                                $this->installedBaseDirs[$file]
                            );
                        }
                    }
                }
                closedir($dh);
            }
        }
        ksort($this->installedBaseDirs);
        return $this;
    }

    /**
     * Get the directories where to look for installed modules
     *
     * @return array
     */
    public function getModuleDirs()
    {
        return $this->modulePaths;
    }
}
