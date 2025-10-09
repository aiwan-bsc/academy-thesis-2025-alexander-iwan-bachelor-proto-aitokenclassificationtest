<?php

namespace toolkits;

use AiModels\AiModel;
use DOMDocument;
use DOMXPath;
use Exception;
use FilesystemIterator;
use Mpdf;
use Mpdf\MpdfException;
use PhpParser\Node\Expr\Cast\Object_;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use setasign\Fpdi\PdfParser\PdfParserException;
use Smalot\PdfParser\Exception\MissingCatalogException;
use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\Command;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Imagine\Imagick;
use thiagoalessio\TesseractOCR\TesseractOcrException;


class PDFToolkit
{
    private const string SAVE_PATH = __DIR__ . "/../uploads/";
    private const string TEMP_PATH = __DIR__ . "/../temp/";
    private const string OUTPUT_PATH = __DIR__ . "/../uploads/output/";
    private const string STANDARD_PARSING_TYPE = "parse";
    private array $pdf = array(
        'filePath' => '',
        'text' => ''
    );
    private AiModel $aiModel;

    /**
     * @throws Exception
     */
    public function __construct(AiModel $givenModel, ?array $pdfFile = null)
    {
        if ($pdfFile !== null) {
            $this->loadPDF($pdfFile);
        }
        $this->aiModel = $givenModel;
    }

    public function setAiModel(AiModel $aiModel): void
    {
        $this->aiModel = $aiModel;
    }

    /**
     * @throws Exception
     */
    public function loadPDF(array $pdfFile): bool
    {
        $this->pdf['filePath'] = $this->savePdfToFolder($pdfFile);
        $this->pdf['text'] = '';
        return true;
    }

    /**
     * @throws Exception
     */
    private function savePdfToFolder(array $pdfFile): string
    {
        $targetPath = self::SAVE_PATH.basename($pdfFile["name"]);
        if (move_uploaded_file($pdfFile["tmp_name"], $targetPath)) {
            return $targetPath;
        } else {
            throw new Exception("Fehler beim Speichern der PDF");
        }
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public function extractTextFromPdf(?string $type = self::STANDARD_PARSING_TYPE): string
    {
        $text = "";
        if($type === 'parse'){
            $parser = new Parser();
            $pdf = $parser->parseFile($this->pdf['filePath']);
            $text = $pdf->getText();
        }else if($type === 'ocr'){
            $pdf = new \Spatie\PdfToImage\Pdf($this->pdf['filePath']);
            for($i = 1; $i <= $pdf->pageCount(); $i++){
                $pdf->selectPage($i);
                $pdf->save(self::TEMP_PATH."temp.jpg");

                $langCommand = new Command();
                $langCommand->options[] = "-l deu"; //Deutsche Sprache prüfen, um Umlaute und 'ß' abzufangen
                $text .= new TesseractOCR(self::TEMP_PATH."temp.jpg", $langCommand)->run()." ";
            }
        }else if($type === 'mixed'){
            $parser = new Parser();
            $pdf = $parser->parseFile($this->pdf['filePath']);
            $text = $pdf->getText();

            $command = sprintf("pdfimages -png %s %s", $this->pdf['filePath'], self::TEMP_PATH . "imageParseOutput");
            exec($command);

            $imgStartIndex = 0;
            while(file_exists(self::TEMP_PATH . "imageParseOutput-".str_pad($imgStartIndex, 3, "0", STR_PAD_LEFT).".png")){
                $langCommand = new Command();
                $langCommand->options[] = "-l deu"; //Deutsche Sprache prüfen, um Umlaute und 'ß' abzufangen
                try{
                    $text .= "\n".new TesseractOCR(self::TEMP_PATH . "imageParseOutput-".str_pad($imgStartIndex, 3, "0", STR_PAD_LEFT).".png", $langCommand)
                            ->run()." ";
                } catch (TesseractOCRException $e) {
                    // KEIN TEXT IM BILD GEFUNDEN, ALSO ALLES GUT
                }
                $imgStartIndex++;
            };
        }

        if (empty(trim($text))) {
            throw new Exception("Es konnte kein Text in der PDF gefunden werden");
        } else {
            $this->pdf['text'] = $text;
            return $text;
        }
    }

    /**
     * @throws Exception
     */
    public function inputIntoAI(?string $text = null): array
    {
        if (!is_null($text)) {
            return $this->aiModel->getOutput($text);
        } else {
            if ($this->pdf['text'] !== '') {
                return $this->aiModel->getOutput($this->pdf['text']);
            }
            return $this->aiModel->getOutput($this->extractTextFromPdf());
        }
    }

    public function getCensoredTextFromWordList(array $blacklist, ?string $textToCensor = null): string
    {
        if (!is_null($textToCensor)) {
            return str_ireplace($blacklist, '(zensiert)', $textToCensor);
        }
        return str_ireplace($blacklist, '(zensiert)', $this->pdf['text']);
    }


    /**
     * @throws MpdfException
     */
    public function createCensoredPdfWithBlacklistWithHTML(array $blacklist): void
    {
        // 1. Mit "pdftohtml [input] [output] -c -s" Befehl die PDF-Datei zu HTML konvertieren
        // ---- ENTWEDER ----
        // 2. HTML in MPDF laden
        // 3. MPDF Overwrite nutzen

        // ---- ODER ----
        // b. HTML direkt bearbeiten (ohne Bilder etc kaputt zu machen)
        // c. MPDF mit neuem HTML füttern

        // 1.
        $command = sprintf("pdftohtml -c %s %s", $this->pdf['filePath'], self::TEMP_PATH . "output.html");
        print($command);
        exec($command);

        // 2.
        $newPDF = new Mpdf\Mpdf();
        $newPDF->percentSubset = 0;

        $pageStartIndex = 1;
        do{
            //TODO: Weiße Seite entfernen
            $html = str_replace("page-break-after: always;",
                "",
                file_get_contents(self::TEMP_PATH . "output-".$pageStartIndex.".html"));
            $newPDF->WriteHTML($html);
            $pageStartIndex++;
        }while(file_exists(self::TEMP_PATH . "output-".$pageStartIndex.".html"));

        //$newPDF->WriteHTML(file_get_contents(self::TEMP_PATH . "output-html.html"));
        $newPDF->Output(self::TEMP_PATH . "temp.pdf", "F");

        // 3.
        $replacementArray = array_map(function ($blacklistedString) {
            return str_repeat("█", strlen($blacklistedString));
        }, $blacklist);

        $mpdf = new \Mpdf\Mpdf();
        $mpdf->OverWrite(self::TEMP_PATH . "temp.pdf",
            $blacklist,
            $replacementArray,
            "F",
            self::OUTPUT_PATH . basename($this->pdf['filePath'], '.pdf') . "_zensiert.pdf");

        self::deleteTemp();
    }

    /**
     * @throws MissingCatalogException
     * @throws Exception
     */
    public function createCensoredPdfWithBlacklistWithBoxes(array $blacklist): void
    {

        // 1. Informationen holen, wo sind die Texte?
        //      1.1. Strings zusammensetzen
        // 2. x-,y-Koordinaten nutzen, um Bounding Boxes zu zeichnen / füllen
        // 3. PDF flatten (Evtl. Bild-PDF speichern)


        //1.
        $coordinatesArray = $this->getCoordinatesOfStrings($blacklist);
        var_dump($coordinatesArray);

        //2.
        $this->drawCensorBoxes($coordinatesArray);

        //3.
        $this->flattenPdf();

        self::deleteTemp();
    }


    private function getCoordinatesOfStrings(array $blacklist): array
    {
        var_dump($blacklist);
        // 1. Generate positional data with Poppler's pdftotext
        $outputHtmlFile = self::TEMP_PATH . uniqid() . '.html';
        $command = sprintf('pdftotext -bbox %s %s', escapeshellarg($this->pdf['filePath']), $outputHtmlFile);
        shell_exec($command);

        // 2. Parse the output HTML
        $dom = new DOMDocument();
        @$dom->loadHTMLFile($outputHtmlFile);
        $xpath = new DOMXPath($dom);

        $foundLocations = [];
        $pages = $xpath->query('//page');

        foreach ($pages as $pageIndex => $page) {
            $pageNumber = $pageIndex + 1;
            $words = $xpath->query('.//word', $page);
            $wordData = [];
            foreach ($words as $wordNode) {
                var_dump(mb_convert_encoding($wordNode->nodeValue, 'iso-8859-1'));
                $wordData[] = [
                    'text' => mb_convert_encoding($wordNode->nodeValue, 'iso-8859-1'),
                    'xMin' => (float) $wordNode->getAttribute('xmin'),
                    'yMin' => (float) $wordNode->getAttribute('ymin'),
                    'xMax' => (float) $wordNode->getAttribute('xmax'),
                    'yMax' => (float) $wordNode->getAttribute('ymax'),
                ];
            }

            // 3. Search for the multi-word strings
            foreach ($blacklist as $searchText) {
                $searchWords = explode(' ', $searchText);
                $numSearchWords = count($searchWords);

                for ($i = 0; $i <= count($wordData) - $numSearchWords; $i++) {
                    $phraseWords = array_slice($wordData, $i, $numSearchWords);
                    $phraseText = implode(' ', array_column($phraseWords, 'text'));

                    if (strtolower($phraseText) == strtolower($searchText)) {
                        // Match found! Calculate the bounding box for the whole phrase.
                        $firstWord = $phraseWords[0];
                        $lastWord = $phraseWords[$numSearchWords - 1];

                        $bbox = [
                            'xMin' => $firstWord['xMin'],
                            'yMin' => min(array_column($phraseWords, 'yMin')), // Handle text on slight slant
                            'xMax' => $lastWord['xMax'],
                            'yMax' => max(array_column($phraseWords, 'yMax')),
                        ];

                        // 4. Convert coordinates from points to mm
                        $pointToMm = 25.4 / 72;
                        $x_mm = $bbox['xMin'] * $pointToMm;
                        $y_mm = $bbox['yMin'] * $pointToMm;
                        $width_mm = ($bbox['xMax'] - $bbox['xMin']) * $pointToMm;
                        $height_mm = ($bbox['yMax'] - $bbox['yMin']) * $pointToMm;

                        $foundLocations[$pageNumber][] = [
                            'x' => $x_mm,
                            'y' => $y_mm,
                            'w' => $width_mm,
                            'h' => $height_mm,
                            'text' => $searchText
                        ];

                        // Skip the words we just matched
                        $i += $numSearchWords - 1;
                    }
                }
            }
        }

        return $foundLocations;

    }

    /**
     * @throws PdfParserException
     * @throws MpdfException
     */
    private function flattenPDF(): void{
        //TODO: funktioniert nicht, Text bleibt hinter schwarzen Boxen :(
        $pdf = new Mpdf\Mpdf();
        $pageCount = $pdf->setSourceFile(self::OUTPUT_PATH . basename($this->pdf['filePath'], '.pdf')."_blackBoxes.pdf");
        for($pageNo = 1; $pageNo <= $pageCount; $pageNo++){
            $templateId = $pdf->importPage($pageNo);
            // Get the size of the imported page
            $size = $pdf->getTemplateSize($templateId);

            // Add a page to the new document with the same size
            $pdf->AddPage();

            // Use the imported page as a template
            $pdf->useTemplate($templateId);
        }

        $pdf->Output(self::OUTPUT_PATH."FADSHJKDASHDKJASDHSKJD.pdf", 'F');

    }

    /**
     * @throws PdfParserException
     * @throws MpdfException
     */
    private function drawCensorBoxes(array $coordinates): void
    {
        $pdf = new Mpdf\Mpdf();
        $pdf->setSourceFile($this->pdf['filePath']);

        $pageNumber = 1;
        foreach ($coordinates as $page) {
            $tplID = $pdf->importPage($pageNumber++);
            $pdf->useTemplate($tplID);
            foreach($page as $word){
                $pdf->RoundedRect(
                    $word['x'],
                    $word['y'],
                    $word['w'],
                    $word['h'],
                    0,
                'F');
            }
            $pdf->AddPage();
        }

        $pdf->Output(self::OUTPUT_PATH . basename($this->pdf['filePath'], '.pdf')."_blackBoxes.pdf", 'F');
    }

    private static function deleteTemp() : void
    {
        $dir = self::TEMP_PATH;
        if(file_exists($dir)){
            $di = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
            $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ( $ri as $file ) {
                $file->isDir() ?  rmdir($file) : unlink($file);
            }
        }

    }
}