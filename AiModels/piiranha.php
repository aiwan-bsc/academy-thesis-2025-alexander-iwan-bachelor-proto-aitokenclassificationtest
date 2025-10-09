<?php


namespace AiModels;

use Codewithkyrian\Transformers\Transformers;
use Exception;
use toolkits\AiToolkit;
use function codewithkyrian\transformers\pipelines\pipeline;

include_once "./toolkits/AiToolkit.php";

class piiranha implements AiModel
{

    public string $name = 'iiiorg_piiranha_onnx' {
        get {
            return $this->name;
        }
    }
    public string $path = __DIR__ . '/Models' {
        get {
            return $this->path;
        }
    }
    private const int INPUT_TOKEN_SPLIT = 25;

    /**
     * @throws Exception
     */
    public function getOutput(string $input): array
    {
        Transformers::setup()
            ->setCacheDir($this->path)
            ->apply();

        try {
            /*$tokenizer = AutoTokenizer::fromPretrained($this->name, __DIR__."/Models");
            $inputArray = $tokenizer($input, padding: true, truncation:true);
            var_dump($inputArray['input_ids']);
            var_dump($inputArray['attention_mask']);
            var_dump($inputArray['token_type_ids']);
            die();*/
            $inputArray = AiToolkit::tokenizeInputStringIntoArray($input, self::INPUT_TOKEN_SPLIT);
            //$tokenizer($input, padding: true, truncation: true);
            $output = array();
            $pipe = pipeline("ner", $this->name, false);

            foreach ($inputArray as $inputFromArray) {
                foreach ($pipe($inputFromArray) as $aiSingleOutput) {
                    $output[] = $aiSingleOutput;
                }
            }

            return AiToolkit::cleanAiResponse($output);
        } catch (\Codewithkyrian\Transformers\Exceptions\UnsupportedTaskException $e) {
            throw new Exception($e->getMessage());
        }
    }

}