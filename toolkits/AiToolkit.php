<?php

namespace toolkits;
use Codewithkyrian\Transformers\PreTrainedTokenizers\AutoTokenizer;

class AiToolkit
{
    public static function cleanAiResponse(array $outputArray): array{
        if (empty($outputArray)) {
            return [];
        }

        $groupedEntities = [];
        $currentEntity = null;

        // Indizes des $entities-Arrays neu aufbauen,
        // da die Original-Indizes (33, 34, 35 ...) Lücken haben.
        $outputArray = array_values($outputArray);

        foreach ($outputArray as $i => $entity) {
            $entityType = $entity['entity'];
            $word = $entity['word'];
            $index = $entity['index'];

            // 'O' = "Outside", keine relevante Entität.
            // auch "leere" Tokens oder Bullet-Point '●' weg
            if ($entityType === 'O' || empty(trim($word, " \t\n\r\0\x0B.,;:-_●"))) {
                continue;
            }

            // Beginn einer neuen Entität?
            if ($currentEntity === null) {
                $currentEntity = [
                    'type' => $entityType,
                    'text' => $word,
                    'last_index' => $index
                ];
            } // Gehört dieses Token zur vorherigen Entität?
            else if ($entityType === $currentEntity['type'] && $index === $currentEntity['last_index'] + 1) {
                $currentEntity['text'] .= $word; // Wort anhängen
                $currentEntity['last_index'] = $index; // Index aktualisieren
            } // Andernfalls ist die alte Entität beendet und eine neue beginnt.
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

    public static function tokenizeInputStringIntoArray(string $input, int $outputTokenSize): array
    {
            $inputArray = array();
            $inputArrayPrepare = explode(" ", $input);
            $stringBuffer = '';
            for ($i = 0; $i < count($inputArrayPrepare); $i++) {
                $stringBuffer .= $inputArrayPrepare[$i] . " ";
                if ($i % $outputTokenSize === 0) {
                    $inputArray[] = $stringBuffer;
                    $stringBuffer = '';
                }
            }
            return $inputArray;
    }
}