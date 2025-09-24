<?php

use Codewithkyrian\Transformers\Transformers;
use function codewithkyrian\transformers\pipelines\pipeline;

class piiranha implements AiModel
{

    private const int INPUT_TOKEN_SPLIT = 100;
    /**
     * @throws Exception
     */
    public function getOutput(string $input): array
    {
        Transformers::setup()
            ->setCacheDir($this->path)
            ->apply();

        try {
            $inputArray = array();

            $inputArrayPrepare = explode(" ", $input);
            $stringBuffer = '';
            for($i = 0; $i < count($inputArrayPrepare); $i++) {
                $stringBuffer .= $inputArrayPrepare[$i]." ";
                if($i % self::INPUT_TOKEN_SPLIT === 0){
                    $inputArray[] = $stringBuffer;
                    $stringBuffer = '';
                }
            }

            $output = array();
            $pipe = pipeline("ner", $this->name, false);

            foreach ($inputArray as $inputFromArray) {
                foreach($pipe($inputFromArray) as $aiSingleOutput){
                    $output[] = $aiSingleOutput;
                }
            }

            return $this->groupAiEntities($output);
        } catch (\Codewithkyrian\Transformers\Exceptions\UnsupportedTaskException $e) {
            throw new Exception($e->getMessage());
        }
    }

    public string $name = 'iiiorg_piiranha_onnx' {
        get {
            return $this->name;
        }
    }
    public string $path = __DIR__.'/Models' {
        get {
            return $this->path;
        }
    }

    private function groupAiEntities(array $response) :array
    {
        if (empty($response)) {
            return [];
        }

        $groupedEntities = [];
        $currentEntity = null;

        // Wir müssen die Indizes des $entities-Arrays neu aufbauen,
        // da die Original-Indizes (33, 34, 35 ...) Lücken haben.
        $response = array_values($response);

        foreach ($response as $i => $entity) {
            $entityType = $entity['entity'];
            $word = $entity['word'];
            $index = $entity['index'];

            // 'O' bedeutet "Outside" und ist keine relevante Entität.
            // Wir filtern auch "leere" Tokens wie einzelne Satzzeichen oder den Bullet-Point '●'.
            if ($entityType === 'O' || empty(trim($word, " \t\n\r\0\x0B.,;:-_●"))) {
                continue;
            }

            // Ist dies der Beginn einer neuen Entität?
            if ($currentEntity === null) {
                $currentEntity = [
                    'type' => $entityType,
                    'text' => $word,
                    'last_index' => $index
                ];
            }
            // Gehört dieses Token zur vorherigen Entität?
            else if ($entityType === $currentEntity['type'] && $index === $currentEntity['last_index'] + 1) {
                $currentEntity['text'] .= $word; // Wort anhängen
                $currentEntity['last_index'] = $index; // Index aktualisieren
            }
            // Andernfalls ist die alte Entität beendet und eine neue beginnt.
            else {
                // Speichere die abgeschlossene Entität
                $groupedEntities[] = trim($currentEntity['text']);

                // Beginne die neue Entität
                $currentEntity = [
                    'type' => $entityType,
                    'text' => $word,
                    'last_index' => $index
                ];
            }
        }

        // Nicht vergessen, die allerletzte Entität nach der Schleife hinzuzufügen
        if ($currentEntity !== null) {
            $groupedEntities[] = trim($currentEntity['text']);
        }

        // Duplikate entfernen und zurückgeben
        return array_values(array_unique($groupedEntities));
    }
}