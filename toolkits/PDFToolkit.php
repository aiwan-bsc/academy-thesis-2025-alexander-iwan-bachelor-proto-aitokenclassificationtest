<?php

namespace toolkits;

use AiModels\AiModel;
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
                //$pdf->setPage($i);
                $pdf->save(self::TEMP_PATH."temp.jpg");
                //$pdf->saveImage(self::TEMP_PATH."temp.jpg");

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

        //self::deleteTemp();
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
        var_dump($coordinatesArray['page'][0]);

        //2.
        $this->drawCensorBoxes($coordinatesArray);

        //3.
        //$this->flattenPdf();

        //self::deleteTemp();
    }

    /**
     * @throws MissingCatalogException
     * @throws Exception
     */
    private function getCoordinatesOfStrings(array $blacklist): array{
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($this->pdf['filePath']);

        //Alle Seiten durchgehen, Wörter finden und mit Positionen speichern
        $textCoordinates = [];
        $pages = $pdf->getPages();
        for($i = 0; $i < count($pages); $i++) {
            $page = $pages[$i];
            $dataTm = $page->getDataTm();
            $textCoordinates['page'][$i] = $this->groupTextIntoWords($dataTm, $blacklist);
        }
        return $textCoordinates;
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
        foreach ($coordinates['page'] as $page) {
            $tplID = $pdf->importPage($pageNumber++);
            $pdf->useTemplate($tplID);
            //TODO: startX usw. sind Pixelwerte, MPDF will aber mm-Werte :(
            foreach($page as $word){
                $pdf->RoundedRect(
                    $word['startX'] / 2.02,
                    $word['startY'] / 2.02,
                    ($word['endX'] - $word['startX']) / 2.02,
                    $word['startY'] + 10 / 2.02,
                    0,
                'F');
            }
            $pdf->AddPage();
        }

        $pdf->Output(self::OUTPUT_PATH . basename($this->pdf['filePath'], '.pdf')."_blackBoxes.pdf", 'F');
    }
/*
    private function cleanCoordinates(array $dataTm): array{
        var_dump($dataTm);
        $cleanedArray = array();
        $tempWord = [];
        for($i = 0; $i <= count($dataTm); $i++){
            // $dataTm[x][0] = transformations-matrix in 0-3, x,y-Werte in jeweils 4 und 5
            // $dataTm[x][1] = Gefundener String

            //Leerzeichen
            if(trim($dataTm[$i][1]) === ''){
                if($tempWord !== ""){
                    $cleanedArray[] = $tempWord;

                    $tempWord = "";
                }
                continue;
            }


            //Ein einzelner Buchstabe
            //jetzt darauf achten, ob es Teil eines Worts ist, oder ein gewollter einzelner Buchstabe
            if(strlen(trim($dataTm[$i][1])) === 1){
                $tempWord[0][4] = $dataTm[$i][0][4];
                $tempWord[0][5] = $dataTm[$i][0][5];
                $tempWord[1] = trim($dataTm[$i][1]);
            }
        }
        return[];
    }*/
/*
* Groups text fragments from a PDF parser into whole words with their coordinates.
*
* @param array $textFragments The raw array from the Smalot PDFParser library.
* @return array An array of words, where each element is an associative array
* containing 'text', 'startX', 'startY', 'endX', and 'endY'.
*/
    function groupTextIntoWords(array $textFragments, ?array $blacklist = null): array
    {
        // --- Step 1: Define Tolerances ---
        // Tolerance for considering characters to be on the same line.
        // Adjust this value based on your document's line spacing.
        $Y_TOLERANCE = 5.0;

        // Tolerance for the horizontal gap between characters in the same word.
        // Adjust this based on your document's font and character spacing.
        $X_TOLERANCE = 10.0;


        // --- Step 2: Sort the text fragments in reading order ---
        // - Sort by Y-coordinate descending (top to bottom)
        // - Then by X-coordinate ascending (left to right)
        usort($textFragments, function ($a, $b) {
            $Y_TOLERANCE = 5.0;
            $X_TOLERANCE = 10.0;
            $y_a = (float) $a[0][5];
            $y_b = (float) $b[0][5];
            $x_a = (float) $a[0][4];
            $x_b = (float) $b[0][4];

            // If Y-coordinates are significantly different, sort by Y descending
            if (abs($y_a - $y_b) > $Y_TOLERANCE) {
                return ($y_b > $y_a) ? 1 : -1;
            }

            // Otherwise, they are on the same line, so sort by X ascending
            return ($x_a < $x_b) ? -1 : 1;
        });

        // --- Step 3: Iterate and Group ---
        $words = [];
        $currentWord = null;
        $previousFragment = null;

        foreach ($textFragments as $fragment) {
            $text = $fragment[1];
            $x = (float) $fragment[0][4];
            $y = (float) $fragment[0][5];

            // Skip empty spaces, as they are our primary delimiter
            if (trim($text) === '') {
                // If we were building a word, this space ends it.
                if ($currentWord !== null) {
                    if($blacklist !== null && in_array($currentWord['text'], $blacklist)) {
                        $words[] = $currentWord;
                    }
                    $currentWord = null;
                }
                $previousFragment = null; // Reset previous fragment after a space
                continue;
            }

            if ($currentWord === null) {
                // Start a new word
                $currentWord = [
                    'text'   => $text,
                    'startX' => $x,
                    'startY' => $y,
                    'endX'   => $x, // Will be updated
                    'endY'   => $y, // Will be updated
                ];
            } else {
                $prev_x = (float) $previousFragment[0][4];
                $prev_y = (float) $previousFragment[0][5];

                // Check for word break conditions
                $isNewLine = abs($y - $prev_y) > $Y_TOLERANCE;
                $isTooFarHorizontally = ($x - $prev_x) > $X_TOLERANCE;

                if ($isNewLine || $isTooFarHorizontally) {
                    // End the previous word
                    $words[] = $currentWord;

                    // Start a new word with the current fragment
                    $currentWord = [
                        'text'   => $text,
                        'startX' => $x,
                        'startY' => $y,
                        'endX'   => $x,
                        'endY'   => $y,
                    ];
                } else {
                    // Append to the current word
                    $currentWord['text'] .= $text;
                    // Update the end coordinates to the current fragment's position
                    $currentWord['endX'] = $x;
                    $currentWord['endY'] = $y;
                }
            }
            $previousFragment = $fragment;
        }

        // --- Step 4: Add the last word ---
        // Don't forget the very last word in the loop if it exists
        if ($currentWord !== null) {
            $words[] = $currentWord;
        }

        return $words;
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