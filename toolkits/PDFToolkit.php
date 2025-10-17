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
        'text' => '',
        'images' => array(),
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


            $uniqid = uniqid();
            $outputPath = self::TEMP_PATH.$uniqid;
            /*$command = sprintf("pdfimages -png %s %s", $this->pdf['filePath'], self::TEMP_PATH . "imageParseOutput");
            exec($command);*/


            // pdftohtml mit -zoom 1 gibt positionen von Bildern aus (Angaben sind leider gerundet auf ganze zahlen)
            // -zoom 1 weil die html standardmäßig auf 1.5 gezoomt is (??????)

            $command = sprintf("pdftohtml -xml -zoom 1 %s %s", $this->pdf['filePath'], $outputPath);
            exec($command);

            $xmlFile = new DOMDocument();
            $xmlFile->load($outputPath.".xml");
            $xPath = new DOMXPath($xmlFile);
            $imagesInPdf = array();

            $langCommand = new Command();
            $langCommand->options[] = "-l deu"; //Deutsche Sprache prüfen, um Umlaute und 'ß' abzufangen

            $pages = $xPath->query('//page');
            foreach ($pages as $pageIndex => $page) {
                var_dump($page);
                $pageNumber = $pageIndex + 1;
                $images = $xPath->query('.//image', $page);
                foreach ($images as $imageIndex => $imageNode) {
                    try {
                        $imagesInPdf[$imageIndex]['x'] = $imageNode->getAttribute('left');
                        $imagesInPdf[$imageIndex]['y'] = $imageNode->getAttribute('top');
                        $imagesInPdf[$imageIndex]['w'] = $imageNode->getAttribute('width');
                        $imagesInPdf[$imageIndex]['h'] = $imageNode->getAttribute('height');
                        $imagesInPdf[$imageIndex]['page'] = $pageNumber;

                        $imagesInPdf[$imageIndex]['text'] = "\n" . new TesseractOCR($imageNode->getAttribute('src'), $langCommand)->run();
                        $text .= $imagesInPdf[$imageIndex]['text'] . " ";
                    } catch (TesseractOCRException $e) {

                    }
                }
            }



            /*$imgStartIndex = 0;
            while(file_exists($outputPath."-".str_pad($imgStartIndex, 3, "0", STR_PAD_LEFT).".png")){
                $langCommand = new Command();
                $langCommand->options[] = "-l deu"; //Deutsche Sprache prüfen, um Umlaute und 'ß' abzufangen
                try{
                    $imageFile = $outputPath."-".str_pad($imgStartIndex, 3, "0", STR_PAD_LEFT).".png";

                    $imageObject = $xPath->query("/page/image[src".$imageFile."]");
                    $imagesInPdf[$imgStartIndex]['x'] = $imageObject->getAttribute('left');
                    $imagesInPdf[$imgStartIndex]['y'] = $imageObject->getAttribute('top');
                    $imagesInPdf[$imgStartIndex]['w'] = $imageObject->getAttribute('length');
                    $imagesInPdf[$imgStartIndex]['h'] = $imageObject->getAttribute('height');
                    $imagesInPdf[$imgStartIndex]['page'] = $xPath->query("/page/image[src".$imageFile."]/parent::page")->getAttribute('number');

                    $imagesInPdf[$imgStartIndex]['text'] = "\n".new TesseractOCR($imageFile, $langCommand)->run();
                    $text .= $imagesInPdf[$imgStartIndex]['text']." ";

                } catch (TesseractOCRException $e) {
                    // KEIN TEXT IM BILD GEFUNDEN, ALLES IN ORDNUNG, KEIN ERROR NOTWENDIG >:(
                }
                $imgStartIndex++;
            }*/
            var_dump($imagesInPdf);
            $this->pdf["images"] = $imagesInPdf;
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

        //2.
        $this->drawCensorBoxes($coordinatesArray);

        //3.
        $this->flattenPdf(
            self::OUTPUT_PATH.basename($this->pdf['filePath'], '.pdf')."_blackBoxes.pdf",
            self::OUTPUT_PATH.basename($this->pdf['filePath'], '.pdf')."_flattened.pdf");

        self::deleteTemp();
    }


    private function getCoordinatesOfStrings(array $blacklist): array
    {
        /*
            1 inch = 72 points  //TODO: ist das immer so?
            1 inch = 25.4 mm
            Also, mm = points * (25.4 / 72)
         */
        $pointToMm = 25.4 / 72;

        // 1. Bounding-Box HTML generieren, um Positionen zu finden
        $outputHtmlFile = self::TEMP_PATH . uniqid() . '.html';
        $command = sprintf('pdftotext -bbox %s %s', escapeshellarg($this->pdf['filePath']), $outputHtmlFile);
        shell_exec($command);

        // 2. Entstandene HTML parsen
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
                $wordData[] = [
                    'text' => mb_convert_encoding($wordNode->nodeValue, 'iso-8859-1'),  //Encoding fixen, um Umlaute wiederherzustellen
                    'xMin' => (float) $wordNode->getAttribute('xmin'),
                    'yMin' => (float) $wordNode->getAttribute('ymin'),
                    'xMax' => (float) $wordNode->getAttribute('xmax'),
                    'yMax' => (float) $wordNode->getAttribute('ymax'),
                ];
            }

            // 3. $blacklist in den Worten suchen
            foreach ($blacklist as $searchText) {
                $searchWords = explode(' ', $searchText);
                $numSearchWords = count($searchWords);

                for ($i = 0; $i <= count($wordData) - $numSearchWords; $i++) {
                    $phraseWords = array_slice($wordData, $i, $numSearchWords);
                    $phraseText = implode(' ', array_column($phraseWords, 'text'));

                    if (stristr(strtolower($phraseText), strtolower($searchText))) {
                        // Bei Match Bounding Box kalkulieren
                        $firstWord = $phraseWords[0];
                        $lastWord = $phraseWords[$numSearchWords - 1];

                        $bbox = [
                            'xMin' => $firstWord['xMin'],
                            'yMin' => min(array_column($phraseWords, 'yMin')),
                            'xMax' => $lastWord['xMax'],
                            'yMax' => max(array_column($phraseWords, 'yMax')),
                        ];

                        // 4. PT zu mm Konvertierung für mPDF
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

                        $i += $numSearchWords - 1;
                    }
                }
            }
        }

        //Bildtexte durchsuchen
        foreach($this->pdf['images'] as $imageIndex => $image) {
            foreach ($blacklist as $searchText) {
                $searchWords = explode(' ', $searchText);
                foreach($searchWords as $wordIndex => $word) {
                    if(array_key_exists('text', $image) &&
                        stristr(strtolower($image['text']), strtolower($word))) {
                        echo "prüfe ob ".$word." im Bildtext ".$image['text']." vorkommt <br>";
                        $foundLocations[$image['page']][] = [
                            'x' => $image['x'] * $pointToMm,
                            'y' => $image['y'] * $pointToMm,
                            'w' => $image['w'] * $pointToMm,
                            'h' => $image['h'] * $pointToMm,
                            'text' => $searchText
                        ];
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
    private function flattenPDF(string $sourcePdfPath, string $finalPdfPath): void{

        try {
            $imagick = new \Imagick();
            // WICHTIG: Auflösung vor dem Lesen der Datei setzen!
            // 300 DPI ist ein guter Kompromiss zwischen Qualität und Dateigröße.
            $imagick->setResolution(300, 300);

            // Lade alle Seiten aus dem PDF mit schwarzen Boxen
            $imagick->readImage($sourcePdfPath);

            $imagick->setImageFormat('pdf');

            // Speichere alle Seiten
            $imagick->writeImages($finalPdfPath, true);

            $imagick->clear();
        } catch (Exception $e) {
            // Fehlerbehandlung
            echo 'Fehler beim Abflachen der PDF: ' . $e->getMessage();
        }
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
            if($pageNumber > 1) $pdf->AddPage();
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
        }
        $pdf->Output(self::OUTPUT_PATH . basename($this->pdf['filePath'], '.pdf')."_blackBoxes.pdf", 'F');
    }

    private static function deleteTemp() : void
    {
        //return;
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