<?php

namespace AiModels;

use Codewithkyrian\Transformers\Transformers;
use Exception;
use toolkits\AiToolkit;
use function codewithkyrian\transformers\pipelines\pipeline;
include_once "./toolkits/AiToolkit.php";
class piiSensitiveNerGerman implements AiModel
{

    /**
     * @throws Exception
     */
    public function getOutput(string $input): array
    {
        Transformers::setup()
            ->setCacheDir($this->path)
            ->apply();


        try {
            $pipe = pipeline("ner", $this->name, false);
            return AiToolkit::cleanAiResponse($pipe($input));
            //return $pipe($input);
        } catch (\Codewithkyrian\Transformers\Exceptions\UnsupportedTaskException $e) {
            throw new Exception($e->getMessage());
        }
    }

    public string $name = 'piiSensitiveNerGerman_Onnx' {
        get {
            return $this->name;
        }
    }
    public string $path = __DIR__ . '/Models' {
        get {
            return $this->path;
        }
    }
}