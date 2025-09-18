<?php

use Smalot\PdfParser\Parser;
use Codewithkyrian\Transformers\Transformers;
use function codewithkyrian\transformers\pipelines\pipeline;

class PDFToolkit
{
    private const SAVE_PATH = __DIR__ ."/uploads/";
    private const AI_MODEL = 'pii-sensitive-ner-german_onnx';
    private const AI_MODEL_PATH = __DIR__ .'/Models/';
    private array $pdf = array(
        'filePath'  => '',
        'text'      => ''
    );

    public function __construct(?array $pdfFile = null)
    {
        Transformers::setup()
            ->setCacheDir(self::AI_MODEL_PATH)
            ->apply();

        if ($pdfFile !== null) {
            var_dump($pdfFile);
            $this->loadPDF($pdfFile);
        }
    }

    /**
     * @throws Exception
     */
    public function loadPDF(array $pdfFile) :bool
    {
        $this->pdf['filePath'] = $this->savePdfToFolder($pdfFile);
        $this->pdf['text'] = '';
        return true;
    }

    /**
     * @throws Exception
     */
    private function savePdfToFolder(array $pdfFile) :string
    {
        $targetPath = self::SAVE_PATH . basename($pdfFile["name"]);
        if(move_uploaded_file($pdfFile["tmp_name"], $targetPath)){
            return $targetPath;
        }else{
            throw new Exception("Fehler beim Speichern der PDF");
        }
    }

    /**
     * @throws Exception
     */
    public function extractTextFromPdf() :string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($this->pdf['filePath']);
        $text = $pdf->getText();

        if (empty(trim($text))) {
            throw new Exception("Es konnte kein Text in der PDF gefunden werden");
        }else{
            $this->pdf['text'] = $text;
            return $text;
        }
    }

    /**
     * @throws Exception
     */
    public function inputIntoAI(?string $text = null) :array
    {
        if(!is_null($text)){
            return $this->getAIResponse($text);
        }else{
            if($this->pdf['text'] !== ''){
                return $this->getAIResponse($this->pdf['text']);
            }
            return $this->getAIResponse($this->extractTextFromPdf());
        }
    }

    public function getCensoredTextFromWordList(array $blacklist, ?string $textToCensor = null) :string
    {
        if(!is_null($textToCensor)){
            return str_ireplace($blacklist, '(zensiert)', $textToCensor);
        }
        return str_ireplace($blacklist, '(zensiert)', $this->pdf['text']);
    }
    /**
     * @throws Exception
     */
    private function getAIResponse(string $text) :array
    {
        try {
            $pipe = pipeline("ner", self::AI_MODEL, false);
            return $this->parseAIResponse($pipe($text));
        } catch (\Codewithkyrian\Transformers\Exceptions\UnsupportedTaskException $e) {
            throw new Exception($e->getMessage());
        }
    }

    private function parseAIResponse(array $response) :array
    {
        if (empty($response)) {
            return [];
        }

        $groupedEntities = [];
        $currentEntity = null;

        // Wir müssen die Indizes des $entities-Arrays neu aufbauen,
        // da die Original-Indizes (33, 34, 35...) Lücken haben.
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
        return array_unique($groupedEntities);
    }
}