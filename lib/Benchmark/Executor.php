<?php

/*
 * This file is part of the PHP Bench package
 *
 * (c) Daniel Leech <daniel@dantleech.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpBench\Benchmark;

use Symfony\Component\Process\Process;
use PhpBench\BenchmarkInterface;

/**
 * This class generates a benchmarking script and places it in the systems
 * temp. directory and then executes it. The generated script then returns the
 * time taken to execute the benchmark and the memory consumed.
 */
class Executor
{
    /**
     * @var string
     */
    private $bootstrap;

    /**
     * @var string
     */
    private $configDir;

    /**
     * @param string $configPath
     * @param string $bootstrap
     */
    public function __construct($configPath, $bootstrap)
    {
        $this->configDir = dirname($configPath);
        $this->bootstrap = $bootstrap;
    }

    /**
     * @param BenchmarkInterface $benchmark
     * @param string $subject
     * @param int $revolutions
     * @param string[] $beforeMethods
     * @param array $parameters
     */
    public function execute(BenchmarkInterface $benchmark, $subject, $revolutions = 0, $beforeMethods = array(), $afterMethods = array(), array $parameters = array())
    {
        $refl = new \ReflectionClass($benchmark);

        $template = file_get_contents(__DIR__ . '/template/runner.template');

        $tokens = array(
            '{{ bootstrap }}' => $this->getBootstrapPath(),
            '{{ class }}' => $refl->getName(),
            '{{ file }}' => $refl->getFileName(),
            '{{ subject }}' => $subject,
            '{{ revolutions }}' => $revolutions,
            '{{ beforeMethods }}' => var_export($beforeMethods, true),
            '{{ afterMethods }}' => var_export($afterMethods, true),
            '{{ parameters }}' => var_export($parameters, true),
        );

        foreach ($beforeMethods as $beforeMethod) {
            if (!$refl->hasMethod($beforeMethod)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown before method "%s" in benchmark class "%s"',
                    $beforeMethod, $refl->getName()
                ));
            }
        }

        foreach ($afterMethods as $afterMethod) {
            if (!$refl->hasMethod($afterMethod)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown after method "%s" in benchmark class "%s"',
                    $afterMethod, $refl->getName()
                ));
            }
        }

        $script = str_replace(
            array_keys($tokens),
            array_values($tokens),
            $template
        );

        $scriptPath = tempnam(sys_get_temp_dir(), 'PhpBench');
        file_put_contents($scriptPath, $script);

        $process = new Process('php ' . $scriptPath);
        $process->run();
        unlink($scriptPath);

        if (false === $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Could not execute benchmark subject: %s %s %s',
                $process->getErrorOutput(),
                $process->getOutput(),
                $script
            ));
        }

        $result = json_decode($process->getOutput(), true);

        if (null === $result) {
            throw new \Exception(sprintf(
                'Could not decode executor result, got: %s',
                $process->getOutput()
            ));
        }

        return $result;
    }

    private function getBootstrapPath()
    {
        if (!$this->bootstrap) {
            return;
        }

        // if the path is absolute, return it unmodified
        if ('/' === substr($this->bootstrap, 0, 1)) {
            return $this->bootstrap;
        }

        return $this->configDir . '/' . $this->bootstrap;
    }
}
