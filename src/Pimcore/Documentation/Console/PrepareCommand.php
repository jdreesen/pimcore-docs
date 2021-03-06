<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Documentation\Console;

use GitWrapper\GitWrapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class PrepareCommand extends Command
{
    /**
     * @var string
     */
    private $baseDir;

    /**
     * @var SymfonyStyle
     */
    private $io;

    /**
     * @var Filesystem
     */
    private $fs;

    public function __construct($name = null)
    {
        $this->fs      = new Filesystem();
        $this->baseDir = realpath(__DIR__ . '/../../../..');

        parent::__construct($name);
    }

    protected function configure()
    {
        $defaultConfig = $this->makePathRelative(
            $this->baseDir . '/config/pimcore5.json',
            getcwd(),
            true
        );

        $defaultBuildDir = $this->makePathRelative(
            $this->baseDir . '/build',
            getcwd()
        );

        $this
            ->setName('prepare')
            ->setDescription('Prepare filesystem to generate docs')
            ->addArgument(
                'sourceDir',
                InputArgument::REQUIRED,
                'Path to doc/ directory in the repository'
            )
            ->addArgument(
                'buildDir',
                InputArgument::OPTIONAL,
                'Path to the output directory where results should be built to.',
                $defaultBuildDir
            )
            ->addOption(
                'config-file', 'c',
                InputOption::VALUE_REQUIRED,
                'The config file to use',
                $defaultConfig
            )
            ->addOption(
                'clear-build-dir', 'C',
                InputOption::VALUE_NONE,
                'Remove and re-create build directory if it exists. Use with care!'
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sourceDir = $input->getArgument('sourceDir');
        $sourceDir = rtrim($sourceDir, '/\\');

        if ($this->fs->exists($sourceDir)) {
            $this->io->writeln(sprintf('Using source directory <comment>%s</comment>', $sourceDir));
        } else {
            $this->io->error(sprintf('The source path "%s" was not found', $sourceDir));

            return 2;
        }

        $configFile = $input->getOption('config-file');
        if (!$this->fs->exists($configFile) && !$this->fs->exists($configFile)) {
            $this->io->error(sprintf('The config file "%s" does not exist.', $configFile));

            return 3;
        }

        $buildDir = $input->getArgument('buildDir');
        $buildDir = rtrim($buildDir, '/\\');

        if ($this->fs->exists($buildDir)) {
            if ($input->getOption('clear-build-dir')) {
                $this->io->writeln(sprintf('Cleaning up build dir <comment>%s</comment>', $buildDir));
                $this->fs->remove($buildDir);
                $this->fs->mkdir($buildDir);

            } else {
                $this->io->error(sprintf(
                    'The build dir "%s" already exists and the --clear-build-dir option was not passed.',
                    rtrim($buildDir, '/\\')
                ));

                return 4;
            }
        } else {
            $this->fs->mkdir($buildDir);
        }

        $this->copyDocs($sourceDir, $buildDir);
        $this->createConfigFile($sourceDir, $buildDir, $configFile);
    }

    private function copyDocs(string $sourceDir, string $buildDir)
    {
        $this->io->writeln(sprintf('Copying <comment>%s</comment> to work dir', $sourceDir));

        $target = $buildDir . '/docs';

        $this->fs->mkdir($target);
        $this->fs->mirror($sourceDir, $target, null, [
            'override' => true,
            'delete'   => true
        ]);

        $this->renameReadmeFiles($target);
        $this->rewriteReadmeLinks($target);
    }

    private function renameReadmeFiles(string $directory)
    {
        $this->writeSection('Renaming README.md files to _index.md');

        $finder = new Finder();
        $finder
            ->files()
            ->in($directory)
            ->name('README.md');

        foreach ($finder as $file) {
            $source = $file->getPathname();
            $target = $file->getPath() . '/_index.md';

            if ($this->fs->exists($target)) {
                throw new \RuntimeException(sprintf(
                    'File %s already exists (tried to rename from %s)',
                    $target,
                    $source
                ));
            }

            $this->io->writeln(sprintf(
                'Moving <comment>%s</comment> to <comment>%s</comment>',
                $this->makePathRelative($source, $directory),
                $this->makePathRelative($target, $directory, true)
            ));

            $this->fs->rename($source, $target);
        }
    }

    private function rewriteReadmeLinks(string $directory)
    {
        $this->writeSection('Rewriting links to README.md files to _index.md');

        $finder = new Finder();
        $finder
            ->files()
            ->in($directory)
            ->name('*.md');

        foreach ($finder as $file) {
            $changed  = false;
            $contents = file_get_contents($file->getRealPath());

            // match all links to an internal README.md and rewrite them to _index.md
            if (preg_match_all('/\[(?P<text>([^\]]*))\]\((?P<link>([^\)]*)README\.md)\)/', $contents, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    // do not touch links with a scheme (e.g. external links to README.md files)
                    if (false !== strpos($match['link'], '://')) {
                        continue;
                    }

                    $link = preg_replace('/README.md$/', '_index.md', $match['link']);

                    $this->io->writeln(sprintf(
                        'Rewriting link from <comment>%s</comment> to <comment>%s</comment> in <comment>%s</comment>',
                        $match['link'],
                        $link,
                        $file->getRelativePathname()
                    ));

                    $replacement = str_replace($match['link'], $link, $match[0]);
                    $contents    = str_replace($match[0], $replacement, $contents);
                    $changed     = true;
                }
            }

            if ($changed) {
                $this->fs->dumpFile($file->getRealPath(), $contents);
            }
        }
    }

    private function createConfigFile(string $sourceDir, string $buildDir, string $configFile)
    {
        $this->writeSection('Creating config file');

        $targetConfigFile = $buildDir . '/config.json';

        $this->io->writeln(sprintf(
            'Creating config file <comment>%s</comment> from <comment>%s</comment>',
            $targetConfigFile,
            $configFile
        ));

        // read config file
        $config = $this->readJson($configFile);

        // read git version info from source and from pimcore-docs
        $config = $this->addVersionConfig($config, $sourceDir);

        $this->fs->dumpFile(
            $targetConfigFile,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function addVersionConfig(array $config, string $sourceDir): array
    {
        $config['build_versions'] = [
            'source' => $this->readGitVersionInfo($sourceDir),
            'docs'   => $this->readGitVersionInfo($this->baseDir)
        ];

        return $config;
    }

    private function readGitVersionInfo(string $dir): string
    {
        $git = new GitWrapper();
        $git = $git->workingCopy($dir);

        return trim($git->run(['rev-parse', 'HEAD'])->getOutput());
    }

    private function readJson(string $path): array
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException(sprintf('File %s does not exist', $path));
        }

        $content = file_get_contents($path);
        if (!$content) {
            throw new \RuntimeException(sprintf('Failed to read content from file %s', $path));
        }

        $json = json_decode($content, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \RuntimeException(sprintf(
                'Failed to decode JSON from file %s: %s',
                $path,
                json_last_error_msg()
            ));
        }

        return $json;
    }

    private function makePathRelative(string $endPath, string $startPath, bool $forceFile = false): string
    {
        $path = $this->fs->makePathRelative($endPath, $startPath);

        if (is_file($endPath) || $forceFile) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private function writeSection($message)
    {
        $this->io->section(sprintf('<fg=white>%s</>', $message));
    }
}
