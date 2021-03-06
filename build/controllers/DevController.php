<?php

namespace build\controllers;

use Yii;
use yii\base\InvalidParamException;
use yii\console\Controller;
use yii\helpers\Console;
use yii\helpers\FileHelper;

/**
 * Class DevController
 * @package build\controllers
 */
class DevController extends Controller
{
    public $defaultAction = 'all';

    /**
     * @var bool whether to use HTTP when cloning github repositories
     */
    public $useHttp = false;
    /**
     * @var string
     */
    public $app = 'git@github.com:ejsoft/project.git';
    /**
     * @var array
     */
    public $modules = [];
    /**
     * @var array
     */
    public $excludeDirs = [];

    /**
     * Install all modules and app
     */
    public function actionAll()
    {
        if (!$this->confirm('Install all applications and all modules now?')) {
            return 1;
        }

        foreach ($this->modules as $mod => $repo) {
            $ret = $this->actionModule($mod);
            if ($ret !== 0) {
                return $ret;
            }
        }

        $ret = $this->actionSite($this->app);
        if ($ret !== 0) {
            return $ret;
        }

        return 0;
    }

    /**
     * Runs a command in all modules and application directories
     *
     * Can be used to run e.g. `git pull`.
     *
     *     ./build/build dev/run git pull
     *
     * @param string $command the command to run
     */
    public function actionRun($command)
    {
        $command = implode(' ', func_get_args());

        // root of the dev repo
        $base = dirname(dirname(__DIR__));
        $dirs = $this->listSubDirs("$base/project/modules");
        $dirs = array_merge($dirs, $this->listSubDirs("$base/project/site"));
        asort($dirs);

        $oldcwd = getcwd();
        foreach ($dirs as $dir) {
            $displayDir = substr($dir, strlen($base));
            $this->stdout("Running '$command' in $displayDir...\n", Console::BOLD);
            chdir($dir);
            passthru($command);
            $this->stdout("done.\n", Console::BOLD, Console::FG_GREEN);
        }
        chdir($oldcwd);
    }

    /**
     * @param null $repo
     *
     * @return int
     */
    public function actionSite($repo = null)
    {
        // root of the dev repo
        $base = dirname(dirname(__DIR__));
        $appDir = "$base/project/site";

        if (!file_exists($appDir)) {
            if (empty($repo)) {
                $repo = $this->app;
                if ($this->useHttp) {
                    $repo = str_replace('git@github.com:', 'https://github.com/', $repo);
                }
            }

            $this->stdout("cloning site from '$repo'...\n", Console::BOLD);
            passthru('git clone ' . escapeshellarg($repo) . ' ' . $appDir);
            $this->stdout("done.\n", Console::BOLD, Console::FG_GREEN);
        }

        // cleanup
        $this->stdout("cleaning up site vendor directory...\n", Console::BOLD);
        $this->cleanupVendorDir($appDir . '/protected');
        $this->stdout("done.\n", Console::BOLD, Console::FG_GREEN);

        // composer update
        $this->stdout("updating composer for site...\n", Console::BOLD);
        chdir($appDir);
        passthru('composer update --prefer-dist');
        $this->stdout("done.\n", Console::BOLD, Console::FG_GREEN);

        // link directories
        $this->stdout("linking cms and modules to site vendor dir...\n", Console::BOLD);
        $this->linkCmsAndModules($appDir . '/protected', $base);
        $this->stdout("done.\n", Console::BOLD, Console::FG_GREEN);

        return 0;
    }

    /**
     * @param $module
     * @param null $repo
     * @param bool $useComposer
     *
     * @return int
     */
    public function actionModule($module, $repo = null, $useComposer = true)
    {
        // root of the dev repo
        $base = dirname(dirname(__DIR__));
        $moduleDir = "$base/project/modules/$module";
        echo $moduleDir . PHP_EOL;
        if (!file_exists($moduleDir)) {
            if (empty($repo)) {
                if (isset($this->modules[$module])) {
                    $repo = $this->modules[$module];
                    if ($this->useHttp) {
                        $repo = str_replace('git@github.com:', 'https://github.com/', $repo);
                    }
                } else {
                    $this->stderr("Repo argument is required for module '$module'.\n", Console::FG_RED);
                    return 1;
                }
            }

            $this->stdout("cloning module repo '$module' from '$repo'...\n", Console::BOLD);
            passthru('git clone ' . escapeshellarg($repo) . ' ' . $moduleDir);
            if (is_dir($moduleDir)) {
                $this->stdout("done.\n", Console::BOLD, Console::FG_GREEN);
            } else {
                $this->stdout("error cloning repo: $repo\n", Console::BOLD, Console::FG_RED);
                return -1;
            }
        }

        // cleanup
        $this->stdout("cleaning up module '$module' vendor directory...\n", Console::BOLD);
        $this->cleanupVendorDir($moduleDir);
        $this->stdout("done.\n", Console::BOLD, Console::FG_GREEN);

        if ($useComposer) {
            // composer update
            $this->stdout("updating composer for module '$module'...\n", Console::BOLD);
            chdir($moduleDir);
            passthru('composer update --prefer-dist');
            $this->stdout("done.\n", Console::BOLD, Console::FG_GREEN);
        }
        // link directories
        $this->stdout("linking cms and modules to '$module' vendor dir...\n", Console::BOLD);
        $this->linkCmsAndModules($moduleDir, $base);
        $this->stdout("done.\n", Console::BOLD, Console::FG_GREEN);

        return 0;
    }

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        $options = parent::options($actionID);
        if (in_array($actionID, ['module', 'site', 'all'], true)) {
            $options[] = 'useHttp';
        }
        return $options;
    }


    /**
     * Remove all symlinks in the vendor subdirectory of the directory specified
     *
     * @param string $dir base directory
     */
    protected function cleanupVendorDir($dir)
    {
        if (is_link($link = "$dir/vendor/ejsoft/core")) {
            $this->stdout("Removing symlink $link.\n");
            $this->unlink($link);
        }
        $modules = $this->findDirs("$dir/vendor/ejsoft");
        foreach ($modules as $module) {
            echo "$dir/vendor/ejsoft/ease-$module" . PHP_EOL;
            if (is_link($link = "$dir/vendor/ejsoft/ease-$module")) {
                $this->stdout("Removing symlink $link.\n");
                $this->unlink($link);
            }
        }
    }

    /**
     * Creates symlinks to framework and module sources for the application
     *
     * @param string $dir  application directory
     * @param string $base Yii sources base directory
     *
     * @return int
     */
    protected function linkCmsAndModules($dir, $base)
    {
        if (is_dir($link = "$dir/vendor/ejsoft/core")) {
            $this->stdout("Removing dir $link.\n");
            FileHelper::removeDirectory($link);
            $this->stdout("Creating symlink for $link.\n");
            symlink("$base/src", $link);
        }
        $modules = $this->findDirs("$dir/vendor/ejsoft");
        foreach ($modules as $mod) {
            if (is_dir($link = "$dir/vendor/ejsoft/ease-$mod")) {
                $this->stdout("Removing dir $link.\n");
                FileHelper::removeDirectory($link);
                $this->stdout("Creating symlink for $link.\n");
                if (!file_exists("$base/project/modules/$mod")) {
                    $ret = $this->actionModule($mod, null, false);
                    if ($ret !== 0) {
                        return $ret;
                    }
                }
                symlink("$base/project/modules/$mod", $link);
            }
        }
    }

    /**
     * Properly removes symlinked directory under Windows, MacOS and Linux
     *
     * @param string $file path to symlink
     */
    protected function unlink($file)
    {
        if (is_dir($file) && DIRECTORY_SEPARATOR === '\\') {
            rmdir($file);
        } else {
            unlink($file);
        }
    }

    /**
     * Get a list of subdirectories for directory specified
     *
     * @param string $dir directory to read
     *
     * @return array list of subdirectories
     */
    protected function listSubDirs($dir)
    {
        $list = [];
        $handle = opendir($dir);
        if ($handle === false) {
            throw new InvalidParamException("Unable to open directory: $dir");
        }
        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            // ignore hidden directories
            if ($file[0] === '.') {
                continue;
            }
            if (is_dir("$dir/$file")) {
                $list[] = "$dir/$file";
            }
        }
        closedir($handle);
        return $list;
    }

    /**
     * Finds linkable applications
     *
     * @param string $dir directory to search in
     *
     * @return array list of applications command can link
     */
    protected function findDirs($dir)
    {
        $list = [];
        $handle = @opendir($dir);
        if ($handle === false) {
            return [];
        }
        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path) && preg_match('/^ease-(.*)$/', $file, $matches)) {
                $list[] = $matches[1];
            }
        }
        closedir($handle);

        foreach ($list as $i => $e) {
            if ($e === 'composer' || in_array($e, $this->excludeDirs)) {
                unset($list[$i]);
            }
        }

        return $list;
    }
}