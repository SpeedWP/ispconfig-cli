<?php
namespace GDM\ISPConfigCli;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use \GDM\Helpers\ISPConfig;

class Command extends \Symfony\Component\Console\Command\Command
{
    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     *
     * @var callable
     */
    public $tableHeaders  = array('Setting', 'Value');
    public $repeatHeaders = 10;

    /**
     * {@inheritDoc}
     */
    public function run(InputInterface $input, OutputInterface $output)
    {
        $this->input  = $input;
        $this->output = $output;

        $this->setHelperSet($this->getApplication()->getHelperSet());
        return parent::run($input, $output);
    }

    /**
     * @return InputInterface
     */
    public function getInput()
    {
        return $this->input;
    }

    /**
     * @return OutputInterface
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * Outputs message, wrapped in "error" style
     *
     * @param string $message
     * @return AbstractCommand
     */
    public function error($message, $writeLine = true)
    {
        if ($writeLine) {
            $this->getOutput()->getErrorOutput()->writeln($message);
        } else {
            $this->getOutput()->getErrorOutput()->write($message);
        }
    }

    /**
     * Outputs message, wrapped in "success" style
     *
     * @param string $message
     * @return AbstractCommand
     */
    public function success($message, $writeLine = true)
    {
        return $this->output('<info>' . $message . '</info>', $writeLine);
    }

    /**
     * Outputs message
     *
     * @param string $message
     * @return AbstractCommand
     */
    public function info($message, $writeLine = true)
    {
        return $this->output($message, $writeLine);
    }

    /**
     * Outputs message
     *
     * @param string $message
     * @return AbstractCommand
     */
    public function output($message, $writeLine = true)
    {
        if ($writeLine) {
            $this->getOutput()->writeln($message);
        } else {
            $this->getOutput()->write($message);
        }

        return $this;
    }

    /**
     *
     * @return \GDM\ISPConfig\SoapClient
     */
    public function soapClient()
    {
        return ISPConfig::getInstance()->getSoapClient();
    }

    public function printLastSoapClientError()
    {
        return ISPConfig::getInstance();
    }

    public function printLastError()
    {
        $lastEx = ISPConfig::getInstance()->getSoapClient()->getLastException();
        if ($lastEx) {
            if (OutputInterface::VERBOSITY_VERBOSE <= $this->getOutput()->getVerbosity()) {
                $this->getOutput()->writeln(str_replace("<br>", PHP_EOL, "Error: " . $lastEx->getMessage()));
            }
            if (OutputInterface::VERBOSITY_VERY_VERBOSE <= $this->getOutput()->getVerbosity()) {
                $this->getOutput()->writeln($lastEx->getTraceAsString());
            }
        } else {
            $this->getOutput()->writeln("No error message");
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $arguments = $input->getArguments();
        if (isset($arguments['command']) && $arguments['command'] == $this->getName()) {
            unset($arguments['command']);
        }
        $argString = "";
        if (OutputInterface::VERBOSITY_VERY_VERBOSE <= $this->getOutput()->getVerbosity()) {
            $argString = "(" . implode(", ", $arguments) . ")";
        }
        array_walk($arguments, function(&$argument) {
            $sArgument = \GDM\Framework\Types\String::create($argument);
            if (($sArgument->startsWith('[') && $sArgument->endsWith(']') ) || ($sArgument->startsWith('{') && $sArgument->endsWith('}') )) {
                $sArgument->replace("/'/", '"');
                $argument = json_decode((string) $sArgument);
            }
        });
        $this->info("Running " . $this->getName() . $argString . " ", false);
        $result = 2;

        $cmdOutput = call_user_func_array(array($this->soapClient(), $this->getName()), $arguments);
        if ($cmdOutput !== false) {
            $this->success("Completed");
            $result = $this->onSuccess($input, $output, $cmdOutput);
        } else {
            $this->error("Failed");
            $result = $this->onFailure($input, $output, $cmdOutput);
        }
        return $result;
    }

    protected function onSuccess(InputInterface $input, OutputInterface $output, $cmdOutput)
    {
        if (is_array($cmdOutput)) {
            $cmdOutput = array_slice($cmdOutput, 0, 5);
            $headers   = $this->tableHeaders;
            if (is_array($cmdOutput) && count($cmdOutput)) {
                $headers = array();
                foreach (array_keys($cmdOutput[0]) as $key) {
                    $headers[] = ucfirst(str_replace('_', ' ', $key));
                }
            }
            $this->createTable($headers, $cmdOutput, 10)->render();
        } else {
            $this->info($cmdOutput);
        }
        return 0;
    }

    protected function onFailure(InputInterface $input, OutputInterface $output, $cmdOutput)
    {
        $this->printLastError();
        return 1;
    }

    /**
     *
     * @param array $values Rows to insert into table
     * @return \Symfony\Component\Console\Helper\TableHelper
     */
    protected function buildTable(array $values)
    {
        $rows = array();
        $i    = 0;
        foreach ($values as $key => $value) {
            $i++;
//            if (empty($key) && is_array($value)) {
//                if ($i == 1) {
//                    $this->tableHeaders = array_keys($value);
//                }
//            } else
            if (is_array($value)) {
                $value = implode("\n", array_map(function ($v, $k) {
                        return $k . ': ' . $v;
                    }, $value, array_keys($value)));
            } elseif (is_object($value)) {
                if (method_exists($value, "__toString")) {
                    $value = (string) $value;
                } else {
                    $value = print_r($value, true);
                }
            }
            $rows[] = array($key, $value);
            if ($this->repeatHeaders > 0 && $i % $this->repeatHeaders == 0) {
                $rows[] = new \Symfony\Component\Console\Helper\TableSeparator();
                $rows[] = $this->tableHeaders;
                $rows[] = new \Symfony\Component\Console\Helper\TableSeparator();
            }
        }
        $table = new \Symfony\Component\Console\Helper\Table($this->getOutput());
        return $table->
                setStyle('borderless')->
                setHeaders($this->tableHeaders)->
                setRows($rows);
    }

    /**
     *
     * @param array $values Rows to insert into table
     * @return \Symfony\Component\Console\Helper\TableHelper
     */
    protected function createTable($headers = array(), $rows = array(), $repeatHeaders = 0)
    {
        if ($repeatHeaders > 0 && count($rows) > $repeatHeaders) {
            $rowsChunked = array_chunk($rows, $repeatHeaders);
            $header      = array(
                new \Symfony\Component\Console\Helper\TableSeparator(),
                $headers,
                new \Symfony\Component\Console\Helper\TableSeparator(),
            );
            $rows        = $rowsChunked[0];
            foreach ($rowsChunked as $i => $chunk) {
                echo $i . PHP_EOL;
                if ($i != 0) {
                    $rows = array_merge($rows, $header, $chunk);
                }
            }
        }
        $table = new \Symfony\Component\Console\Helper\Table($this->getOutput());
        return $table->
                setStyle('borderless')->
                setHeaders($headers)->
                setRows($rows);
    }
}
