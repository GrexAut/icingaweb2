<?php

/* Icinga Web 2 | (c) 2021 Icinga GmbH | GPLv2 */

namespace Icinga\Clicommands;

use Icinga\Application\Logger;
use Icinga\Cli\Command;
use Icinga\Web\StyleSheet;
use Less_Parser;

class LessParserCommand extends Command
{
    protected $defaultActionName = 'parse';

    /**
     * Parse a given less file or all less files for the given module
     *
     * Usage:
     *
     *   icingacli lessparser [OPTIONS]
     *
     * OPTIONS:
     *
     *   --module=<moduleName>  Parse all less files for the given module. This is required.
     *
     *   --file=<less File>      Parse only this less file for the given module
     *
     *   --save                  Store the parser css results to a file
     */
    public function parseAction()
    {
        $module = $this->params->getRequired('module');
        if (! $this->app->getModuleManager()->hasLoaded($module)) {
            Logger::error('Module "%s" is not loaded yet. Please enable it first.', $module);

            return;
        }

        $styleSheet = StyleSheet::collectModuleCss($module);
        $lessCompiler = $styleSheet->getLessCompiler();
        $source = '';

        foreach ($lessCompiler->getLessFiles(true) as $lessFile) {
            if (empty($lessFile)) {
                continue;
            }

            $source .= file_get_contents($lessFile);
        }

        $requestedFile = $this->params->get('file');
        foreach ($lessCompiler->getModuleLessFiles($module) as $moduleLessFile) {
            if ($requestedFile
                && (
                    basename($moduleLessFile) !== $requestedFile
                    && basename($moduleLessFile, '.less') !== $requestedFile
                )
            ) {
                continue;
            }

            $source .= file_get_contents($moduleLessFile);
        }

        $result = $lessCompiler->getParser()->compile($source);
        $saveResult = $this->params->get('save');

        if ($saveResult) {
            $baseDir = $this->app->getBaseDir('public');
            $file = $baseDir . '/css/parser-result.css';
            if (! file_put_contents($file, $result)) {
                Logger::error('Parser css result cannot be saved in the "%s" file.', $file);
            }
        } else {
            echo $result;
        }
    }
}
