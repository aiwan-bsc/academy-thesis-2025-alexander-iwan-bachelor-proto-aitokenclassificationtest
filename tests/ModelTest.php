<?php

namespace tests;

use AiModels\AiModel;
use AiModels\piiranha;
use AiModels\piiSensitiveNerGerman;
use PHPUnit\Framework\TestCase;

require '../AiModels/AiModel.php';
require '../AiModels/piiSensitiveNerGerman.php';
require '../AiModels/piiranha.php';

class ModelTest extends TestCase
{

    private static AiModel $piiranha;
    private static AiModel $piiSensitiveNerGerman;
    private static string $testString = "";


    public static function setUpBeforeClass() : void
    {
        self::$piiranha = new piiranha();
        self::$piiSensitiveNerGerman = new piiSensitiveNerGerman();

        $jsonString = file_get_contents("data/test_data.json");
        self::$testString = json_decode($jsonString, true)[0]['text'];
    }

    public function setUp(): void{
    }

    /**
     * @throws \Exception
     */
    public function testSpeed()
    {
        $start = microtime(true);
        $testRuns = 10;
        $expectedTimePerRun = 3;

        for($i = 0; $i<$testRuns; $i++){
            $this->runPiiranha();
        }

        $stop = microtime(true);
        $diff = number_format($stop - $start, 3);

        self::assertLessThan($testRuns*$expectedTimePerRun, $diff,
            "Erwartete Zeit war ".($testRuns*$expectedTimePerRun)." Sekunden ".
            "bei ".$expectedTimePerRun." Sekunden pro Durchlauf mit ".$testRuns." Durchläufen");
    }


    /**
     * @throws \Exception
     */
    public function testDetection()
    {

    }

    /**
     * @throws \Exception
     */
    public function runPiiranha(): void
    {
        self::$piiranha->getOutput(self::$testString);
    }

    /**
     * @throws \Exception
     */
    public function runPiiSensNerGerman($input): void
    {
        self::$piiSensitiveNerGerman->getOutput(self::$testString);
    }

}