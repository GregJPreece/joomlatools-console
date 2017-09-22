<?php
/**
 * @copyright	Copyright (C) 2007 - 2016 Johan Janssens and Timble CVBA. (http://www.timble.net)
 * @license		Mozilla Public License, version 2.0
 * @link		http://github.com/joomlatools/joomlatools-console for the canonical source repository
 */

namespace Joomlatools\Console\Command\Site;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Joomlatools\Console\Command\Database;

class Listing extends Database\AbstractDatabase
{
    protected function configure()
    {
        $this
            ->setName('site:list')
            ->setDescription('List Joomla sites')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                'The output format (txt or json)',
                'txt'
            )
            ->addOption(
                'www',
                null,
                InputOption::VALUE_REQUIRED,
                "Web server root",
                '/var/www'
            )
            ->setHelp('List Joomla sites running on this box');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        define('_JEXEC', true);
        define('JPATH_BASE', true);
        define('JPATH_PLATFORM', true);

        $docroot = $input->getOption('www');

        if (!file_exists($docroot)) {
            throw new \RuntimeException(sprintf('Web server root \'%s\' does not exist.', $docroot));
        }

        $dir = new \DirectoryIterator($docroot);
        $sites = array();

        $canonical = function($version) {
            if (isset($version->RELEASE)) {
                return 'v' . $version->RELEASE . '.' . $version->DEV_LEVEL;
            }

            // Joomla 3.5 and up uses constants instead of properties in JVersion
            $className = get_class($version);
            if (defined("$className::RELEASE")) {
                return 'v'. $version::RELEASE . '.' . $version::DEV_LEVEL;
            }

            return 'unknown';
        };

        foreach ($dir as $fileinfo)
        {
            $code = $application = null;

            if ($fileinfo->isDir() && !$fileinfo->isDot())
            {
                $files = array(
                    'joomla-cms'           => $fileinfo->getPathname() . '/libraries/cms/version/version.php',
                    'joomla-cms-new'       => $fileinfo->getPathname() . '/libraries/src/Version.php', // 3.8+
                    'joomlatools-platform' => $fileinfo->getPathname() . '/lib/libraries/cms/version/version.php',
                    'joomla-1.5'           => $fileinfo->getPathname() . '/libraries/joomla/version.php'
                );

                foreach ($files as $type => $file)
                {
                    if (file_exists($file))
                    {
                        $code        = $file;
                        $application = $type;

                        break;
                    }
                }

                if (!is_null($code) && file_exists($code))
                {
                    $identifier = uniqid();

                    $source = file_get_contents($code);
                    $source = preg_replace('/<\?php/', '', $source, 1);

                    $pattern     = $application == 'joomla-cms-new' ? '/class Version/i' : '/class JVersion/i';
                    $replacement = $application == 'joomla-cms-new' ? 'class Version' . $identifier : 'class JVersion' . $identifier;

                    $source = preg_replace($pattern, $replacement, $source);

                    eval($source);

                    $class   = $application == 'joomla-cms-new' ? '\\Joomla\\CMS\\Version'.$identifier : 'JVersion'.$identifier;
                    $version = new $class();

                    $sites[] = (object) array(
                        'name'    => $fileinfo->getFilename(),
                        'docroot' => $docroot . '/' . $fileinfo->getFilename() . '/' . ($application == 'joomlatools-platform' ? 'web' : ''),
                        'type'    => $application == 'joomla-cms-new' ? 'joomla-cms' : $application,
                        'version' => $canonical($version)
                    );
                }
            }
        }

        if (!in_array($input->getOption('format'), array('txt', 'json'))) {
            throw new \InvalidArgumentException(sprintf('Unsupported format "%s".', $input->getOption('format')));
        }

        switch ($input->getOption('format'))
        {
            case 'json':
                $result = new \stdClass();
                $result->command = $input->getArgument('command');
                $result->data    = $sites;

                $options = (version_compare(phpversion(),'5.4.0') >= 0 ? JSON_PRETTY_PRINT : 0);
                $string  = json_encode($result, $options);
                break;
            case 'txt':
            default:
                $lines = array();
                foreach ($sites as $i => $site) {
                    $lines[] = sprintf("<info>%s. %s</info> (%s %s)", ($i+1), $site->name, $site->type, $site->version);
                }

                $string = implode("\n", $lines);
                break;
        }

        $output->writeln($string);
    }
}
